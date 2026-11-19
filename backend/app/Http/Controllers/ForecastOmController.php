<?php

namespace App\Http\Controllers;

use App\Services\ApprovalMail;
use App\Services\ForecastDefault;
use App\Services\HariLibur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Forecast Customer untuk OM: isi forecast harian per customer, ajukan ke CEO per cabang dan periode, dan setujui/tolak request tambahan qty dari BM.
 * Sumber: customer-forecast-om.php, forecast-load-om.php, forecast-default.php (tampilan dan aturan), forecast-save-om.php (simpan sel),
 * forecast-approve-om.php (ajukan ke CEO), forecast-request-save.php/-action.php/-list.php (request qty), forecast-customer-om.php (pilih customer).
 * Periode 1 = tanggal 1-14, periode 2 = tanggal 15 sampai akhir bulan. pro_forecast.qty adalah "angka depan" dalam KL (8 = 8000 liter).
 * Periode terkunci bila approval terakhirnya WAITING atau APPROVED (web hanya mengunci di tampilan; di sini juga ditolak di server).
 * Tidak memakai konfirmasi password untuk sesi login wajah (permintaan pemilik): isian, pengajuan, dan keputusan request langsung diproses.
 * Request qty: WAITING_OM (dari BM) -> OM setuju = WAITING_CEO, OM tolak = REJECTED_OM (catatan wajib).
 */
class ForecastOmController extends Controller
{
    private const NAMA_BULAN = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    // ---------- notifikasi ----------

    public function waitingCount(): int
    {
        return DB::table('pro_forecast_request')->where('status', 'WAITING_OM')->count();
    }

    /** @return array<int,string> id_request => "Customer dd/mm" (request dari BM yang menunggu OM) */
    public function waitingItems(): array
    {
        $out = [];
        foreach (DB::table('pro_forecast_request as r')->join('pro_customer as c', 'c.id_customer', '=', 'r.id_customer')->where('r.status', 'WAITING_OM')
            ->orderByDesc('r.id_request')->limit(500)->get(['r.id_request', 'c.nama_customer', 'r.tanggal']) as $r) {
            $out[$r->id_request] = $r->nama_customer.' '.date('d/m', strtotime($r->tanggal));
        }

        return $out;
    }

    /** Periode yang ditolak CEO (approval terakhirnya REJECTED) pada bulan berjalan dan sesudahnya: OM perlu memperbaiki lalu mengajukan ulang. */
    private function revisi()
    {
        $now = (int) date('Y') * 12 + (int) date('n');

        return DB::table('pro_forecast_approval as a')
            ->join(DB::raw('(select max(id_approval) as mid from pro_forecast_approval group by id_wilayah, bulan, tahun, periode) m'), 'm.mid', '=', 'a.id_approval')
            ->leftJoin('pro_master_cabang as c', 'c.id_master', '=', 'a.id_wilayah')
            ->where('a.status', 'REJECTED')->whereRaw('(a.tahun * 12 + a.bulan) >= ?', [$now]);
    }

    public function revisiCount(): int
    {
        return $this->revisi()->count();
    }

    /** @return array<int,string> id_approval => "Cabang P1 November 2026" */
    public function revisiItems(): array
    {
        $out = [];
        foreach ($this->revisi()->orderByDesc('a.id_approval')->limit(200)->get(['a.id_approval', 'c.nama_cabang', 'a.periode', 'a.bulan', 'a.tahun']) as $r) {
            $out[$r->id_approval] = "{$r->nama_cabang} P{$r->periode} ".(self::NAMA_BULAN[(int) $r->bulan] ?? $r->bulan).' '.$r->tahun;
        }

        return $out;
    }

    /** Yang perlu ditindak OM: request dari BM dan periode yang ditolak CEO. */
    public function menunggu(): JsonResponse
    {
        $rev = $this->revisi()->orderBy('a.tahun')->orderBy('a.bulan')->orderBy('c.nama_cabang')->orderBy('a.periode')
            ->get(['a.id_wilayah', 'c.nama_cabang', 'a.bulan', 'a.tahun', 'a.periode', 'a.catatan_ceo']);
        $req = DB::table('pro_forecast_request as r')->join('pro_customer as c', 'c.id_customer', '=', 'r.id_customer')
            ->leftJoin('pro_master_cabang as w', 'w.id_master', '=', 'c.id_wilayah')->where('r.status', 'WAITING_OM')->orderByDesc('r.id_request')->limit(50)
            ->get(['r.id_request as id', 'c.nama_customer', 'w.nama_cabang', 'r.tanggal', 'r.qty_request as qty']);

        return response()->json([
            'revisi' => $rev->map(fn ($x) => ['cabang' => (int) $x->id_wilayah, 'nama' => $x->nama_cabang, 'bulan' => (int) $x->bulan, 'tahun' => (int) $x->tahun, 'periode' => (int) $x->periode, 'catatan_ceo' => $x->catatan_ceo])->all(),
            'requests' => $req, 'waiting_request' => $this->waitingCount(), 'waiting_revisi' => $rev->count(),
        ]);
    }

    // ---------- bantu ----------

    private static function params(Request $r): array
    {
        $bulan = min(max((int) $r->query('bulan', date('n')), 1), 12);
        $tahun = min(max((int) $r->query('tahun', date('Y')), 2000), 2100);

        return [$bulan, $tahun, (int) date('t', mktime(0, 0, 0, $bulan, 1, $tahun))];
    }

    private static function label(object $c): string
    {
        $kode = trim((string) ($c->kode_pelanggan ?? ''));

        return $kode !== '' ? "($kode) {$c->nama_customer}" : (string) $c->nama_customer;
    }

    private static function terkunci(int $cabang, int $bulan, int $tahun, int $periode): bool
    {
        $st = DB::table('pro_forecast_approval')->where('id_wilayah', $cabang)->where('bulan', $bulan)->where('tahun', $tahun)->where('periode', $periode)
            ->orderByDesc('id_approval')->value('status');

        return $st === 'WAITING' || $st === 'APPROVED';
    }

    /** Cabang aktif dan cabang awal (cabang akun OM bila aktif). */
    public function cabang(Request $request): JsonResponse
    {
        $list = DB::table('pro_master_cabang')->where('is_active', 1)->orderBy('nama_cabang')->get(['id_master as id', 'nama_cabang as nama']);
        $own = (int) $request->user()->id_wilayah;

        return response()->json(['data' => $list, 'default' => $list->contains('id', $own) ? $own : (int) ($list->first()->id ?? 0)]);
    }

    /** Customer yang bisa ditambahkan ke tabel (belum tampil): cari nama/kode di cabang itu. */
    public function customer(Request $r): JsonResponse
    {
        $q = trim((string) $r->query('q', ''));
        $rows = DB::table('pro_customer')->where('id_wilayah', (int) $r->query('cabang'))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('nama_customer', 'like', "%{$q}%")->orWhere('kode_pelanggan', 'like', "%{$q}%")))
            ->orderBy('nama_customer')->limit(30)->get(['id_customer as id', 'nama_customer', 'kode_pelanggan']);

        return response()->json(['data' => $rows->map(fn ($c) => ['id' => (int) $c->id, 'nama' => self::label($c)])]);
    }

    // ---------- data halaman ----------

    public function data(Request $r): JsonResponse
    {
        $cabang = (int) $r->query('cabang');
        $nama = DB::table('pro_master_cabang')->where('id_master', $cabang)->where('is_active', 1)->value('nama_cabang');
        if (! $nama) {
            return response()->json(['message' => 'Cabang tidak ditemukan.'], 404);
        }
        [$bulan, $tahun, $hari] = self::params($r);
        $periode = (int) $r->query('periode', 1) === 2 ? 2 : 1;
        [$lo, $hi] = ForecastDefault::range($bulan, $tahun, $periode);
        $awal = sprintf('%04d-%02d-01', $tahun, $bulan);
        $akhir = sprintf('%04d-%02d-%02d', $tahun, $bulan, $hari);

        // status terakhir per periode untuk cabang ini
        $review = [];
        foreach ([1, 2] as $p) {
            $a = DB::table('pro_forecast_approval')->where('id_wilayah', $cabang)->where('bulan', $bulan)->where('tahun', $tahun)->where('periode', $p)
                ->orderByDesc('id_approval')->first(['status', 'catatan_om', 'catatan_ceo', 'ceo_pic', 'ceo_date']);
            $review[$p] = ['status' => $a->status ?? '', 'catatan_om' => $a->catatan_om ?? null, 'catatan_ceo' => $a->catatan_ceo ?? null, 'ceo_pic' => $a->ceo_pic ?? null, 'ceo_date' => $a->ceo_date ?? null];
        }

        // customer: PO disetujui 6 bulan terakhir ATAU sudah punya forecast bulan ini (sama dengan web)
        $cust = DB::select('
            select c.id_customer, c.nama_customer, c.kode_pelanggan, c.credit_limit, c.credit_limit_temp, c.credit_limit_used, c.credit_limit_reserved,
                   g.ov_up_07, g.ov_under_30, g.ov_under_60, g.ov_under_90, g.ov_up_90
            from pro_customer c
                left join pro_customer_admin_arnya g on g.id_customer = c.id_customer
            where c.id_wilayah = ?
              and (c.id_customer in (select id_customer from pro_po_customer where poc_approved = 1 and tanggal_poc >= date_sub(curdate(), interval 6 month))
                or c.id_customer in (select id_customer from pro_forecast where tanggal between ? and ?))
            group by c.id_customer, c.nama_customer, c.kode_pelanggan, c.credit_limit, c.credit_limit_temp, c.credit_limit_used, c.credit_limit_reserved,
                     g.ov_up_07, g.ov_under_30, g.ov_under_60, g.ov_under_90, g.ov_up_90
            order by c.nama_customer asc', [$cabang, $awal, $akhir]);

        $fc = [];
        foreach (DB::table('pro_forecast as f')->join('pro_customer as c', 'c.id_customer', '=', 'f.id_customer')->where('c.id_wilayah', $cabang)
            ->whereBetween('f.tanggal', [$awal, $akhir])->get(['f.id_customer', 'f.tanggal', 'f.qty']) as $x) {
            $fc[$x->id_customer][(int) date('j', strtotime($x->tanggal))] = (float) $x->qty;
        }

        $tot = [1 => 0.0, 2 => 0.0];
        $customers = [];
        $kredit = [];
        $totHari = array_fill($lo, $hi - $lo + 1, 0.0);
        foreach ($cust as $c) {
            $arr = [];
            $sum = 0.0;
            for ($d = 1; $d <= $hari; $d++) {
                $v = $fc[$c->id_customer][$d] ?? 0.0;
                $tot[$d <= 14 ? 1 : 2] += $v;
                if ($d >= $lo && $d <= $hi) {
                    $arr[] = $v > 0 ? $v : null; // 0 = penanda dihapus, tampil kosong
                    $sum += $v;
                    $totHari[$d] += $v;
                }
            }
            $customers[] = ['id' => (int) $c->id_customer, 'nama' => self::label($c), 'hari' => $arr, 'total' => $sum];
            $ar = (float) $c->ov_up_07 + (float) $c->ov_under_30 + (float) $c->ov_under_60 + (float) $c->ov_under_90 + (float) $c->ov_up_90;
            $kredit[] = [
                'nama' => $c->nama_customer,
                'sisa' => (float) $c->credit_limit + (float) $c->credit_limit_temp - (float) $c->credit_limit_used - (float) $c->credit_limit_reserved,
                'ar_total' => $ar, 'ar_over_90' => (float) $c->ov_up_90, 'risiko' => (float) $c->ov_up_90 > 0,
            ];
        }

        // semua cabang: total per periode, status terakhir, catatan penolakan CEO (untuk pengajuan banyak cabang)
        $status = [];
        foreach (DB::table('pro_forecast_approval as a')->join(DB::raw('(select max(id_approval) as mid from pro_forecast_approval where bulan = '.$bulan.' and tahun = '.$tahun.' group by id_wilayah, periode) m'), 'm.mid', '=', 'a.id_approval')
            ->get(['a.id_wilayah', 'a.periode', 'a.status', 'a.catatan_ceo', 'a.ceo_date']) as $s) {
            $status[$s->id_wilayah][(int) $s->periode] = $s;
        }
        $cabangan = [];
        foreach (DB::table('pro_master_cabang as mc')->leftJoin('pro_customer as c', 'c.id_wilayah', '=', 'mc.id_master')
            ->leftJoin('pro_forecast as f', fn ($j) => $j->on('f.id_customer', '=', 'c.id_customer')->whereBetween('f.tanggal', [$awal, $akhir]))
            ->where('mc.is_active', 1)->groupBy('mc.id_master', 'mc.nama_cabang')->orderBy('mc.nama_cabang')
            ->get(['mc.id_master', 'mc.nama_cabang', DB::raw('coalesce(sum(case when day(f.tanggal) <= 14 then f.qty end), 0) as t1'), DB::raw('coalesce(sum(case when day(f.tanggal) >= 15 then f.qty end), 0) as t2'), DB::raw('coalesce(sum(f.qty), 0) as total')]) as $b) {
            $row = ['id' => (int) $b->id_master, 'nama' => $b->nama_cabang, 'total' => (float) $b->total, 'per' => []];
            foreach ([1, 2] as $p) {
                $s = $status[$b->id_master][$p] ?? null;
                $row['per'][$p] = [
                    'total' => (float) ($p === 1 ? $b->t1 : $b->t2), 'status' => $s->status ?? '',
                    'catatan_ceo' => ($s && $s->status === 'REJECTED') ? (string) $s->catatan_ceo : null,
                    'ceo_date' => ($s && $s->status === 'REJECTED') ? $s->ceo_date : null,
                ];
            }
            $cabangan[] = $row;
        }

        $libur = HariLibur::tahun($tahun);
        $kal = [];
        for ($d = $lo; $d <= $hi; $d++) {
            $ymd = sprintf('%04d-%02d-%02d', $tahun, $bulan, $d);
            $dow = (int) date('N', strtotime($ymd));
            $kal[] = ['d' => $d, 'tanggal' => $ymd, 'dow' => $dow, 'akhir_pekan' => $dow >= 6, 'libur' => $libur[$ymd]['name'] ?? null];
        }

        $al = DB::table('pro_forecast_allocate')->where('id_wilayah', $cabang)->where('bulan', $bulan)->where('tahun', $tahun)->first(['allocate_p1', 'allocate_p2']);
        $a1 = (float) ($al->allocate_p1 ?? 0);
        $a2 = (float) ($al->allocate_p2 ?? 0);

        return response()->json([
            'cabang' => ['id' => $cabang, 'nama' => $nama], 'bulan' => $bulan, 'tahun' => $tahun, 'periode' => $periode, 'jumlah_hari' => $hari, 'hari_awal' => $lo, 'hari_akhir' => $hi,
            'terkunci' => $review[$periode]['status'] === 'WAITING' || $review[$periode]['status'] === 'APPROVED',
            'review' => [1 => $review[1] + ['total' => $tot[1]], 2 => $review[2] + ['total' => $tot[2]]],
            'kalender' => $kal, 'customers' => $customers, 'total_hari' => array_values($totHari), 'total_periode' => $tot[$periode], 'total_bulan' => $tot[1] + $tot[2],
            'kredit' => $kredit, 'kredit_sisa' => array_sum(array_column($kredit, 'sisa')), 'kredit_ar' => array_sum(array_column($kredit, 'ar_total')),
            'cabangan' => $cabangan, 'cabangan_total' => array_sum(array_column($cabangan, 'total')),
            'alokasi' => ['p1' => $a1, 'p2' => $a2, 'total' => $a1 + $a2, 'melebihi' => ($a1 + $a2) > ($tot[1] + $tot[2])],
        ]);
    }

    // ---------- isi forecast ----------

    /** Simpan satu atau beberapa sel (customer + tanggal): qty kosong menghapus barisnya; qty 0 disimpan sebagai penanda. */
    public function isi(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_customer'       => ['required', 'integer'],
            'items'             => ['required', 'array', 'min:1', 'max:40'],
            'items.*.tanggal'   => ['required', 'date_format:Y-m-d'],
            'items.*.qty'       => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ], ['items.*.qty.min' => 'Qty tidak valid.', 'items.*.qty.numeric' => 'Qty tidak valid.']);
        $cust = DB::table('pro_customer')->where('id_customer', $data['id_customer'])->first(['id_customer', 'id_wilayah']);
        if (! $cust) {
            return response()->json(['message' => 'Customer tidak ditemukan.'], 404);
        }
        foreach ($data['items'] as $it) {
            $ts = strtotime($it['tanggal']);
            if (self::terkunci((int) $cust->id_wilayah, (int) date('n', $ts), (int) date('Y', $ts), (int) date('j', $ts) <= 14 ? 1 : 2)) {
                return response()->json(['message' => 'Forecast periode ini sudah diajukan dan sedang menunggu approval atau sudah disetujui.', 'code' => 'LOCKED'], 409);
            }
        }
        DB::transaction(function () use ($data) {
            foreach ($data['items'] as $it) {
                $qty = $it['qty'] ?? null;
                if ($qty === null || trim((string) $qty) === '') {
                    DB::table('pro_forecast')->where('id_customer', $data['id_customer'])->where('tanggal', $it['tanggal'])->delete();
                    ForecastDefault::tandaiDihapus((int) $data['id_customer'], $it['tanggal']);
                } else {
                    DB::statement('insert into pro_forecast (id_customer, tanggal, qty) values (?, ?, ?) on duplicate key update qty = values(qty)', [$data['id_customer'], $it['tanggal'], (float) $qty]);
                }
            }
        });
        $first = strtotime($data['items'][0]['tanggal']);
        $b = (int) date('n', $first);
        $y = (int) date('Y', $first);
        $p = (int) date('j', $first) <= 14 ? 1 : 2;
        [$lo, $hi] = ForecastDefault::range($b, $y, $p);
        $total = (float) DB::table('pro_forecast')->where('id_customer', $data['id_customer'])
            ->whereBetween('tanggal', [sprintf('%04d-%02d-%02d', $y, $b, $lo), sprintf('%04d-%02d-%02d', $y, $b, $hi)])->sum('qty');

        return response()->json(['message' => 'Forecast tersimpan.', 'total' => $total]);
    }

    // ---------- ajukan ke CEO ----------

    public function ajukan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cabang'   => ['required', 'array', 'min:1', 'max:30'],
            'cabang.*' => ['integer'],
            'bulan'    => ['required', 'integer', 'between:1,12'],
            'tahun'    => ['required', 'integer', 'between:2000,2100'],
            'periode'  => ['required', 'in:1,2'],
            'catatan'  => ['nullable', 'string', 'max:2000'],
        ], ['cabang.required' => 'Pilih minimal satu cabang.', 'cabang.min' => 'Pilih minimal satu cabang.']);
        $bulan = (int) $data['bulan'];
        $tahun = (int) $data['tahun'];
        $periode = (int) $data['periode'];
        $catatan = trim((string) ($data['catatan'] ?? ''));
        $user = $request->user()->fullname;
        $diajukan = [];
        $dilewati = [];

        foreach (array_values(array_unique(array_map('intval', $data['cabang']))) as $idw) {
            $nama = DB::table('pro_master_cabang')->where('id_master', $idw)->value('nama_cabang') ?: "Cabang {$idw}";
            $total = DB::transaction(function () use ($idw, $bulan, $tahun, $periode, $catatan, $user) {
                $st = DB::table('pro_forecast_approval')->where('id_wilayah', $idw)->where('bulan', $bulan)->where('tahun', $tahun)->where('periode', $periode)
                    ->orderByDesc('id_approval')->lockForUpdate()->value('status');
                if ($st === 'WAITING' || $st === 'APPROVED') {
                    return null;
                }
                $q = DB::table('pro_forecast as f')->join('pro_customer as c', 'c.id_customer', '=', 'f.id_customer')->where('c.id_wilayah', $idw)
                    ->whereMonth('f.tanggal', $bulan)->whereYear('f.tanggal', $tahun);
                $periode === 1 ? $q->whereRaw('day(f.tanggal) <= 14') : $q->whereRaw('day(f.tanggal) >= 15');
                $total = (float) $q->sum('f.qty');
                DB::table('pro_forecast_approval')->insert([
                    'id_wilayah' => $idw, 'bulan' => $bulan, 'tahun' => $tahun, 'periode' => $periode, 'total_forecast' => $total,
                    'catatan_om' => $catatan, 'status' => 'WAITING', 'created_by' => $user,
                ]);

                return $total;
            });
            if ($total === null) {
                $dilewati[] = $nama;
            } else {
                $diajukan[] = ['cabang' => $nama, 'total' => $total];
            }
        }
        if (! $diajukan) {
            return response()->json(['message' => 'Tidak ada cabang yang diajukan. Sudah diajukan / disetujui: '.implode(', ', $dilewati), 'code' => 'NOT_SUBMITTED', 'dilewati' => $dilewati], 409);
        }

        // satu email ke CEO dan Purchasing berisi semua cabang
        $baris = '';
        $grand = 0.0;
        foreach ($diajukan as $d) {
            $grand += $d['total'];
            $baris .= '<tr><td>'.e($d['cabang'])."</td><td style='text-align:right'>".number_format($d['total'], 2).' KL</td></tr>';
        }
        $bulanNama = date('F', mktime(0, 0, 0, $bulan, 1));
        $body = '<p>Yth. Bapak/Ibu,</p><p>Telah diajukan <b>Forecast Cabang</b> untuk <b>'.$bulanNama.' '.$tahun.' - Periode '.$periode.'</b> dengan detail berikut:</p>'
            ."<table cellpadding='6' cellspacing='0' border='1' style='border-collapse:collapse'>"
            ."<tr style='background:#f3f4f6'><td><b>Cabang</b></td><td style='text-align:right'><b>Total Forecast</b></td></tr>".$baris
            ."<tr style='background:#dcfce7'><td><b>Total</b></td><td style='text-align:right'><b>".number_format($grand, 2).' KL</b></td></tr></table>'
            .'<p><b>Catatan OM:</b><br>'.nl2br(e($catatan !== '' ? $catatan : '-')).'</p><p>Mohon untuk dilakukan <b>review dan persetujuan</b> melalui sistem.</p>'
            .'<p>Terima kasih,<br><b>Sistem Pro Energi</b></p>';
        ApprovalMail::toRoles([21, 5], null, 'Persetujuan Forecast Cabang - Periode '.$periode.' ('.count($diajukan).' cabang)', $body);

        return response()->json([
            'message' => count($diajukan).' cabang berhasil diajukan ke CEO.'.($dilewati ? ' Dilewati (sudah diajukan / disetujui): '.implode(', ', $dilewati).'.' : ''),
            'diajukan' => count($diajukan), 'dilewati' => $dilewati,
        ]);
    }

    // ---------- request qty dari BM ----------

    public function requests(Request $r): JsonResponse
    {
        $q = DB::table('pro_forecast_request as r')->join('pro_customer as c', 'c.id_customer', '=', 'r.id_customer')
            ->join('pro_master_cabang as mc', 'mc.id_master', '=', 'c.id_wilayah');
        if ($r->query('tab', 'wait') === 'wait') {
            $q->where('r.status', 'WAITING_OM');
        }
        $per = min(max((int) $r->query('per_page', 20), 1), (int) config('orca.per_page_max'));
        $page = $q->orderByDesc('r.id_request')->select(['r.id_request as id', 'r.tanggal', 'r.qty_request as qty', 'r.alasan', 'r.status', 'r.om_catatan', 'r.ceo_catatan', 'c.nama_customer', 'mc.nama_cabang'])->paginate($per);

        return response()->json([
            'data' => collect($page->items())->map(function ($x) {
                $x->can_decide = $x->status === 'WAITING_OM';

                return $x;
            })->all(),
            'waiting' => $this->waitingCount(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage()],
        ]);
    }

    public function decideRequest(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['keputusan' => ['required', 'in:approve,reject'], 'catatan' => ['nullable', 'string', 'max:2000']]);
        $note = trim((string) ($data['catatan'] ?? ''));
        if ($data['keputusan'] === 'reject' && $note === '') {
            return response()->json(['message' => 'Catatan wajib diisi.', 'code' => 'NOTE_REQUIRED'], 422);
        }
        $ok = DB::transaction(function () use ($id, $data, $note, $request) {
            $q = DB::table('pro_forecast_request')->where('id_request', $id)->lockForUpdate()->first(['status']);
            if (! $q || $q->status !== 'WAITING_OM') {
                return false;
            }
            DB::table('pro_forecast_request')->where('id_request', $id)->update($data['keputusan'] === 'approve'
                ? ['status' => 'WAITING_CEO', 'om_pic' => (string) $request->user()->id_user, 'om_tanggal' => now()]
                : ['status' => 'REJECTED_OM', 'om_pic' => (string) $request->user()->id_user, 'om_tanggal' => now(), 'om_catatan' => $note]);

            return true;
        });
        if (! $ok) {
            return response()->json(['message' => 'Request ini tidak sedang menunggu OM.', 'code' => 'NOT_PENDING'], 409);
        }

        return response()->json(['message' => $data['keputusan'] === 'approve' ? 'Request diteruskan ke CEO.' : 'Request ditolak.']);
    }
}
