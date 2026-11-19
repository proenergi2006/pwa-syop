<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StepUp;
use App\Services\ApprovalMail;
use App\Services\HariLibur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verifikasi Forecast (CEO): persetujuan forecast customer per cabang dan periode (1 = tanggal 1-14, 2 = tanggal 15 sampai akhir bulan),
 * serta request penambahan qty dari OM.
 * Sumber: customer-forecast-ceo.php, forecast-rekap-ceo.php, forecast-load-ceo.php (tampilan), forecast-ceo-approve.php, forecast-ceo-reject.php,
 * forecast-request-approve-ceo.php, forecast-request-reject-ceo.php (aksi).
 * pro_forecast_approval.status: DRAFT, WAITING (menunggu CEO), APPROVED, REJECTED; yang berlaku adalah baris TERAKHIR per cabang/bulan/tahun/periode.
 * pro_forecast_request.status: ... WAITING_CEO (menunggu CEO), APPROVED, REJECTED. Setuju request menambah qty ke pro_forecast (tanggal dan customer yang sama).
 * Berbeda dari web: setuju/tolak hanya berlaku bila statusnya benar-benar WAITING / WAITING_CEO. Kolom alasan_reject dan ceo_date pada
 * pro_forecast_request hanya ditulis bila ada di database (alasan juga disimpan di ceo_catatan).
 */
class ForecastController extends Controller
{
    use StepUp;

    private const NAMA_BULAN = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    /** Baris approval TERAKHIR per cabang/bulan/tahun/periode. */
    private function latest()
    {
        return DB::table('pro_forecast_approval as a')
            ->join(DB::raw('(select max(id_approval) as mid from pro_forecast_approval group by id_wilayah, bulan, tahun, periode) m'), 'm.mid', '=', 'a.id_approval');
    }

    public function waitingCount(): int
    {
        return $this->latest()->where('a.status', 'WAITING')->count();
    }

    /** @return array<int,string> id_approval => "Cabang P1 November 2026" */
    public function waitingItems(): array
    {
        $out = [];
        foreach ($this->latest()->leftJoin('pro_master_cabang as c', 'c.id_master', '=', 'a.id_wilayah')->where('a.status', 'WAITING')
            ->orderByDesc('a.id_approval')->limit(500)->get(['a.id_approval', 'c.nama_cabang', 'a.periode', 'a.bulan', 'a.tahun']) as $r) {
            $out[$r->id_approval] = "{$r->nama_cabang} P{$r->periode} ".(self::NAMA_BULAN[(int) $r->bulan] ?? $r->bulan).' '.$r->tahun;
        }

        return $out;
    }

    public function waitingRequestCount(): int
    {
        return DB::table('pro_forecast_request')->where('status', 'WAITING_CEO')->count();
    }

    /** @return array<int,string> id_request => "Customer dd/mm" */
    public function waitingRequestItems(): array
    {
        $out = [];
        foreach (DB::table('pro_forecast_request as r')->join('pro_customer as c', 'c.id_customer', '=', 'r.id_customer')->where('r.status', 'WAITING_CEO')
            ->orderByDesc('r.id_request')->limit(500)->get(['r.id_request', 'c.nama_customer', 'r.tanggal']) as $r) {
            $out[$r->id_request] = $r->nama_customer.' '.date('d/m', strtotime($r->tanggal));
        }

        return $out;
    }

    /** Semua periode yang menunggu CEO (lintas bulan), dikelompokkan per cabang dan bulan; dipakai notifikasi dan bulan awal halaman. */
    public function menunggu(): JsonResponse
    {
        $groups = [];
        foreach ($this->latest()->leftJoin('pro_master_cabang as c', 'c.id_master', '=', 'a.id_wilayah')->where('a.status', 'WAITING')
            ->orderBy('a.tahun')->orderBy('a.bulan')->orderBy('c.nama_cabang')->orderBy('a.periode')
            ->get(['a.id_wilayah', 'c.nama_cabang', 'a.bulan', 'a.tahun', 'a.periode', 'a.total_forecast']) as $x) {
            $k = "{$x->id_wilayah}-{$x->tahun}-{$x->bulan}";
            $groups[$k] ??= ['cabang' => (int) $x->id_wilayah, 'nama' => $x->nama_cabang, 'bulan' => (int) $x->bulan, 'tahun' => (int) $x->tahun, 'periode' => [], 'total' => 0.0];
            $groups[$k]['periode'][] = (int) $x->periode;
            $groups[$k]['total'] += (float) $x->total_forecast;
        }

        return response()->json(['data' => array_values($groups), 'waiting' => $this->waitingCount(), 'waiting_request' => $this->waitingRequestCount()]);
    }

    private static function periodeParams(Request $r): array
    {
        $bulan = min(max((int) $r->query('bulan', date('n')), 1), 12);
        $tahun = min(max((int) $r->query('tahun', date('Y')), 2000), 2100);

        return [$bulan, $tahun, (int) date('t', mktime(0, 0, 0, $bulan, 1, $tahun))];
    }

    /** Rekap semua cabang: per cabang dan periode (jumlah, status, catatan), dengan yang menunggu CEO di urutan atas. */
    public function rekap(Request $r): JsonResponse
    {
        [$bulan, $tahun, $hari] = self::periodeParams($r);
        $awal = sprintf('%04d-%02d-01', $tahun, $bulan);
        $akhir = sprintf('%04d-%02d-%02d', $tahun, $bulan, $hari);

        // Forecast berjalan per cabang & periode (untuk periode yang belum diajukan).
        $live = [];
        foreach (DB::table('pro_forecast as f')->join('pro_customer as c', 'c.id_customer', '=', 'f.id_customer')->whereBetween('f.tanggal', [$awal, $akhir])
            ->groupBy('c.id_wilayah')
            ->get(['c.id_wilayah', DB::raw('coalesce(sum(case when day(f.tanggal) <= 14 then f.qty end), 0) as t1'), DB::raw('coalesce(sum(case when day(f.tanggal) >= 15 then f.qty end), 0) as t2')]) as $x) {
            $live[$x->id_wilayah] = [1 => (float) $x->t1, 2 => (float) $x->t2];
        }
        $appr = [];
        foreach ($this->latest()->where('a.bulan', $bulan)->where('a.tahun', $tahun)
            ->get(['a.id_wilayah', 'a.periode', 'a.status', 'a.total_forecast', 'a.catatan_om', 'a.catatan_ceo', 'a.ceo_date']) as $x) {
            $appr[$x->id_wilayah][(int) $x->periode] = $x;
        }

        $valid = ['WAITING', 'APPROVED', 'REJECTED'];
        $count = ['waiting' => 0, 'approved' => 0, 'rejected' => 0];
        $grand = 0.0;
        $rows = [];
        foreach (DB::table('pro_master_cabang')->where('is_active', 1)->orderBy('nama_cabang')->get(['id_master', 'nama_cabang']) as $c) {
            $per = [];
            $waiting = [];
            foreach ([1, 2] as $p) {
                $a = $appr[$c->id_master][$p] ?? null;
                $st = ($a && in_array($a->status, $valid, true)) ? $a->status : '';
                $total = $a ? (float) $a->total_forecast : ($live[$c->id_master][$p] ?? 0.0);
                $per[$p] = ['status' => $st, 'total' => $total, 'catatan_om' => $a->catatan_om ?? null, 'catatan_ceo' => $a->catatan_ceo ?? null, 'ceo_date' => $a->ceo_date ?? null];
                $grand += $total;
                if ($st !== '') {
                    $count[strtolower($st)]++;
                }
                if ($st === 'WAITING') {
                    $waiting[] = $p;
                }
            }
            $s1 = $per[1]['status'];
            $s2 = $per[2]['status'];
            $rank = $waiting ? 0 : (($s1 === 'REJECTED' || $s2 === 'REJECTED') ? 1 : (($s1 === 'APPROVED' && $s2 === 'APPROVED') ? 3 : 2));
            $rows[] = ['id' => (int) $c->id_master, 'nama' => $c->nama_cabang, 'per' => $per, 'waiting' => $waiting, 'rank' => $rank];
        }
        usort($rows, fn ($x, $y) => [$x['rank'], $x['nama']] <=> [$y['rank'], $y['nama']]);

        return response()->json([
            'bulan' => $bulan, 'tahun' => $tahun, 'jumlah_hari' => $hari, 'total' => $grand, 'count' => $count,
            'waiting_request' => $this->waitingRequestCount(), 'cabang' => $rows,
        ]);
    }

    /** Satu cabang: review per periode, riwayat penolakan, forecast harian per customer, alokasi purchasing, ringkasan semua cabang. */
    public function cabang(Request $r): JsonResponse
    {
        $cabang = (int) $r->query('cabang');
        $nama = DB::table('pro_master_cabang')->where('id_master', $cabang)->value('nama_cabang');
        if (! $nama) {
            return response()->json(['message' => 'Cabang tidak ditemukan.'], 404);
        }
        [$bulan, $tahun, $hari] = self::periodeParams($r);

        $review = [];
        foreach ([1, 2] as $p) {
            $base = DB::table('pro_forecast_approval')->where('id_wilayah', $cabang)->where('bulan', $bulan)->where('tahun', $tahun)->where('periode', $p);
            $a = (clone $base)->orderByDesc('id_approval')->first(['status', 'total_forecast', 'catatan_om', 'catatan_ceo', 'ceo_pic', 'ceo_date']);
            $review[$p] = [
                'status'      => $a->status ?? '',
                'catatan_om'  => $a->catatan_om ?? null,
                'catatan_ceo' => $a->catatan_ceo ?? null,
                'ceo_pic'     => $a->ceo_pic ?? null,
                'ceo_date'    => $a->ceo_date ?? null,
                'riwayat_tolak' => (clone $base)->where('status', 'REJECTED')->orderByDesc('id_approval')->get(['catatan_ceo', 'ceo_date'])->all(),
            ];
        }

        $awal = sprintf('%04d-%02d-01', $tahun, $bulan);
        $akhir = sprintf('%04d-%02d-%02d', $tahun, $bulan, $hari);
        $cust = [];
        foreach (DB::table('pro_forecast as f')->join('pro_customer as c', 'c.id_customer', '=', 'f.id_customer')
            ->where('c.id_wilayah', $cabang)->whereBetween('f.tanggal', [$awal, $akhir])
            ->orderBy('c.nama_customer')->get(['c.id_customer', 'c.nama_customer', 'f.tanggal', 'f.qty']) as $x) {
            $d = (int) date('j', strtotime($x->tanggal));
            $cust[$x->id_customer]['nama'] = $x->nama_customer;
            $cust[$x->id_customer]['hari'][$d] = (float) $x->qty;
        }
        $totHari = array_fill(1, $hari, 0.0);
        $customers = [];
        $tot1 = $tot2 = 0.0;
        foreach ($cust as $id => $c) {
            $arr = [];
            $sum = 0.0;
            for ($d = 1; $d <= $hari; $d++) {
                $v = $c['hari'][$d] ?? 0.0;
                $arr[] = $v;
                $sum += $v;
                $totHari[$d] += $v;
                $d <= 14 ? $tot1 += $v : $tot2 += $v;
            }
            if ($sum > 0) {
                $customers[] = ['id' => (int) $id, 'nama' => $c['nama'], 'hari' => $arr, 'total' => $sum];
            }
        }
        $totalBulan = $tot1 + $tot2;

        $libur = HariLibur::tahun($tahun);
        $kal = [];
        for ($d = 1; $d <= $hari; $d++) {
            $ymd = sprintf('%04d-%02d-%02d', $tahun, $bulan, $d);
            $dow = (int) date('N', strtotime($ymd));
            $kal[] = ['d' => $d, 'dow' => $dow, 'akhir_pekan' => $dow >= 6, 'libur' => $libur[$ymd]['name'] ?? null];
        }

        $summary = [];
        $grand = 0.0;
        foreach (DB::table('pro_master_cabang as mc')->leftJoin('pro_customer as c', 'c.id_wilayah', '=', 'mc.id_master')
            ->leftJoin('pro_forecast as f', fn ($j) => $j->on('f.id_customer', '=', 'c.id_customer')->whereBetween('f.tanggal', [$awal, $akhir]))
            ->groupBy('mc.id_master', 'mc.nama_cabang')->orderBy('mc.nama_cabang')
            ->get(['mc.id_master', 'mc.nama_cabang', DB::raw('coalesce(sum(f.qty), 0) as total')]) as $s) {
            $summary[] = ['id' => (int) $s->id_master, 'nama' => $s->nama_cabang, 'total' => (float) $s->total];
            $grand += (float) $s->total;
        }

        $al = DB::table('pro_forecast_allocate')->where('id_wilayah', $cabang)->where('bulan', $bulan)->where('tahun', $tahun)->first(['allocate_p1', 'allocate_p2']);
        $a1 = (float) ($al->allocate_p1 ?? 0);
        $a2 = (float) ($al->allocate_p2 ?? 0);

        return response()->json([
            'cabang' => ['id' => $cabang, 'nama' => $nama], 'bulan' => $bulan, 'tahun' => $tahun, 'jumlah_hari' => $hari,
            'review' => [
                1 => $review[1] + ['total' => $tot1, 'can_decide' => $review[1]['status'] === 'WAITING'],
                2 => $review[2] + ['total' => $tot2, 'can_decide' => $review[2]['status'] === 'WAITING'],
            ],
            'kalender' => $kal, 'customers' => $customers, 'total_hari' => array_values($totHari), 'total_bulan' => $totalBulan,
            'ringkasan' => $summary, 'ringkasan_total' => $grand,
            'alokasi' => ['p1' => $a1, 'p2' => $a2, 'total' => $a1 + $a2, 'melebihi' => ($a1 + $a2) > $totalBulan],
        ]);
    }

    /** Daftar request penambahan qty dari OM pada satu bulan (semua cabang). */
    public function requests(Request $r): JsonResponse
    {
        [$bulan, $tahun] = self::periodeParams($r);
        $rows = DB::table('pro_forecast_request as r')->join('pro_customer as c', 'c.id_customer', '=', 'r.id_customer')
            ->leftJoin('pro_master_cabang as w', 'w.id_master', '=', 'c.id_wilayah')
            ->whereMonth('r.tanggal', $bulan)->whereYear('r.tanggal', $tahun)->orderByDesc('r.id_request')
            ->select(['r.id_request as id', 'r.tanggal', 'r.qty_request as qty', 'r.alasan', 'r.status', 'r.om_pic', 'r.om_catatan', 'r.ceo_pic', 'r.ceo_tanggal', 'r.ceo_catatan', 'c.nama_customer', 'w.nama_cabang'])
            ->get()->map(function ($x) {
                $x->can_decide = $x->status === 'WAITING_CEO';

                return $x;
            });

        return response()->json(['bulan' => $bulan, 'tahun' => $tahun, 'waiting' => $this->waitingRequestCount(), 'data' => $rows]);
    }

    private function emailPeriode(object $a, string $verdict, ?string $catatan): void
    {
        $cabang = DB::table('pro_master_cabang')->where('id_master', $a->id_wilayah)->value('nama_cabang');
        $bulanNama = date('F', mktime(0, 0, 0, (int) $a->bulan, 1));
        $setuju = $verdict === 'APPROVED';
        $subject = ($setuju ? 'Forecast Disetujui CEO' : 'Forecast DITOLAK CEO')." - Cabang {$cabang}";
        $catatanHtml = nl2br(e($catatan !== null && $catatan !== '' ? $catatan : '-'));
        $body = '<p>Yth. Bapak/Ibu,</p><p>Forecast berikut telah '.($setuju ? '<b>DISETUJUI OLEH CEO</b>' : "<b style='color:red'>DITOLAK OLEH CEO</b>").':</p>'
            ."<table cellpadding='6' cellspacing='0' border='1' style='border-collapse:collapse'>"
            .'<tr><td><b>Cabang</b></td><td>'.e($cabang).'</td></tr>'
            ."<tr><td><b>Bulan</b></td><td>{$bulanNama} {$a->tahun}</td></tr>"
            ."<tr><td><b>Periode</b></td><td>Periode {$a->periode}</td></tr>"
            .'<tr><td><b>Total Forecast</b></td><td><b>'.number_format($a->total_forecast).' KL</b></td></tr>'
            .'<tr><td><b>Catatan CEO</b></td><td>'.$catatanHtml.'</td></tr></table>'
            .($setuju ? '<p>Silakan ditindaklanjuti sesuai proses selanjutnya.</p>' : '<p>Mohon dilakukan <b>perbaikan forecast</b> sesuai catatan CEO.</p>')
            .'<p>Terima kasih,<br><b>Sistem Forecast Pro Energi</b></p>';
        ApprovalMail::toRoles([6, 5], null, $subject, $body);
    }

    /** Setujui satu atau banyak (cabang, periode) sekaligus; yang tidak sedang menunggu dilewati dan dilaporkan. */
    public function approvePeriode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items'           => ['required', 'array', 'min:1', 'max:60'],
            'items.*.cabang'  => ['required', 'integer'],
            'items.*.periode' => ['required', 'in:1,2'],
            'bulan'           => ['required', 'integer', 'between:1,12'],
            'tahun'           => ['required', 'integer', 'between:2000,2100'],
            'catatan'         => ['nullable', 'string', 'max:2000'],
        ]);
        if ($err = $this->stepUp($request)) {
            return $err;
        }
        $ok = 0;
        $dilewati = [];
        foreach ($data['items'] as $it) {
            $res = DB::transaction(function () use ($it, $data, $request) {
                $a = DB::table('pro_forecast_approval')->where('id_wilayah', $it['cabang'])->where('bulan', $data['bulan'])->where('tahun', $data['tahun'])
                    ->where('periode', $it['periode'])->orderByDesc('id_approval')->lockForUpdate()->first();
                if (! $a || $a->status !== 'WAITING') {
                    return null;
                }
                DB::table('pro_forecast_approval')->where('id_approval', $a->id_approval)->update([
                    'status' => 'APPROVED', 'catatan_ceo' => trim((string) ($data['catatan'] ?? '')), 'ceo_pic' => $request->user()->fullname, 'ceo_date' => now(),
                ]);

                return $a;
            });
            if ($res) {
                $ok++;
                $this->emailPeriode($res, 'APPROVED', $data['catatan'] ?? null);
            } else {
                $dilewati[] = ['cabang' => (int) $it['cabang'], 'periode' => (int) $it['periode']];
            }
        }
        if ($ok === 0) {
            return response()->json(['message' => 'Tidak ada forecast yang menunggu persetujuan.', 'code' => 'NOT_PENDING', 'dilewati' => $dilewati], 409);
        }

        return response()->json(['message' => $ok === 1 ? 'Forecast disetujui.' : "{$ok} forecast disetujui.", 'disetujui' => $ok, 'dilewati' => $dilewati]);
    }

    public function rejectPeriode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cabang'  => ['required', 'integer'],
            'periode' => ['required', 'in:1,2'],
            'bulan'   => ['required', 'integer', 'between:1,12'],
            'tahun'   => ['required', 'integer', 'between:2000,2100'],
            'alasan'  => ['required', 'string', 'max:2000'],
        ], ['alasan.required' => 'Alasan penolakan wajib diisi.']);
        if ($err = $this->stepUp($request)) {
            return $err;
        }
        $a = DB::transaction(function () use ($data, $request) {
            $a = DB::table('pro_forecast_approval')->where('id_wilayah', $data['cabang'])->where('bulan', $data['bulan'])->where('tahun', $data['tahun'])
                ->where('periode', $data['periode'])->orderByDesc('id_approval')->lockForUpdate()->first();
            if (! $a || $a->status !== 'WAITING') {
                return null;
            }
            DB::table('pro_forecast_approval')->where('id_approval', $a->id_approval)->update([
                'status' => 'REJECTED', 'catatan_ceo' => trim($data['alasan']), 'ceo_pic' => $request->user()->fullname, 'ceo_date' => now(),
            ]);

            return $a;
        });
        if (! $a) {
            return response()->json(['message' => 'Forecast ini tidak sedang menunggu persetujuan.', 'code' => 'NOT_PENDING'], 409);
        }
        $this->emailPeriode($a, 'REJECTED', $data['alasan']);

        return response()->json(['message' => 'Forecast ditolak.']);
    }

    public function approveRequest(Request $request, int $id): JsonResponse
    {
        if ($err = $this->stepUp($request)) {
            return $err;
        }
        $req = DB::transaction(function () use ($id, $request) {
            $q = DB::table('pro_forecast_request as r')->join('pro_customer as c', 'c.id_customer', '=', 'r.id_customer')->where('r.id_request', $id)
                ->lockForUpdate()->first(['r.*', 'c.nama_customer']);
            if (! $q || $q->status !== 'WAITING_CEO') {
                return null;
            }
            // Qty ditambahkan ke forecast customer pada tanggal itu (kunci unik customer + tanggal).
            DB::statement('insert into pro_forecast (id_customer, tanggal, qty) values (?, ?, ?) on duplicate key update qty = qty + values(qty)', [$q->id_customer, $q->tanggal, $q->qty_request]);
            DB::table('pro_forecast_request')->where('id_request', $id)->update(['status' => 'APPROVED', 'ceo_pic' => $request->user()->fullname, 'ceo_tanggal' => now()]);

            return $q;
        });
        if (! $req) {
            return response()->json(['message' => 'Request ini tidak sedang menunggu persetujuan CEO.', 'code' => 'NOT_PENDING'], 409);
        }
        $body = '<p>Yth. Bapak/Ibu,</p><p>Request penambahan forecast berikut telah <b>DISETUJUI OLEH CEO</b>:</p>'
            ."<table cellpadding='6' cellspacing='0' border='1' style='border-collapse:collapse'>"
            .'<tr><td><b>Customer</b></td><td>'.e($req->nama_customer).'</td></tr>'
            .'<tr><td><b>Tanggal</b></td><td>'.date('d M Y', strtotime($req->tanggal)).'</td></tr>'
            .'<tr><td><b>Qty Disetujui</b></td><td><b>'.number_format($req->qty_request).'</b></td></tr>'
            .'<tr><td><b>Alasan</b></td><td>'.nl2br(e($req->alasan)).'</td></tr>'
            .'<tr><td><b>Status</b></td><td><b>APPROVED</b></td></tr></table>'
            .'<p>Qty telah otomatis ditambahkan ke forecast customer.</p><p>Terima kasih,<br><b>Sistem Forecast Pro Energi</b></p>';
        ApprovalMail::toRoles([6, 5], null, 'Request Forecast Disetujui CEO', $body);

        return response()->json(['message' => 'Request qty disetujui dan ditambahkan ke forecast.']);
    }

    public function rejectRequest(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:2000']], ['alasan.required' => 'Alasan penolakan wajib diisi.']);
        if ($err = $this->stepUp($request)) {
            return $err;
        }
        $done = DB::transaction(function () use ($id, $data, $request) {
            $q = DB::table('pro_forecast_request')->where('id_request', $id)->lockForUpdate()->first();
            if (! $q || $q->status !== 'WAITING_CEO') {
                return false;
            }
            $alasan = trim($data['alasan']);
            $upd = ['status' => 'REJECTED', 'ceo_pic' => $request->user()->fullname, 'ceo_tanggal' => now(), 'ceo_catatan' => $alasan];
            if (Schema::hasColumn('pro_forecast_request', 'alasan_reject')) {
                $upd['alasan_reject'] = $alasan;
            }
            if (Schema::hasColumn('pro_forecast_request', 'ceo_date')) {
                $upd['ceo_date'] = now();
            }
            DB::table('pro_forecast_request')->where('id_request', $id)->update($upd);

            return true;
        });
        if (! $done) {
            return response()->json(['message' => 'Request ini tidak sedang menunggu persetujuan CEO.', 'code' => 'NOT_PENDING'], 409);
        }

        return response()->json(['message' => 'Request qty ditolak.']);
    }
}
