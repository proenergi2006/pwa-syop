<?php

namespace App\Http\Controllers;

use App\Models\AclUser;
use App\Services\ApprovalMail;
use App\Services\ForecastDefault;
use App\Services\HariLibur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Forecast Customer untuk BM, khusus cabang BM sendiri (acl_user.id_wilayah): isi forecast harian per customer dan ajukan request tambahan qty ke OM.
 * Sumber: customer-forecast.php, forecast-load.php (tampilan), forecast-default.php (default), forecast-save.php (simpan sel), forecast-request-save.php (request qty).
 * Berbeda dengan OM, periode BM hanya terkunci setelah DISETUJUI CEO (APPROVED); selama menunggu CEO BM masih bisa mengubah.
 * Default forecast (cermin realisasi loading bulan lalu) tampil abu-abu untuk customer yang belum punya isian, dan disimpan ke database
 * begitu BM mengubah salah satu selnya (aturan fcAdoptDefault web). Periode 1 = tanggal 1-14, periode 2 = tanggal 15 sampai akhir bulan.
 * pro_forecast.qty adalah "angka depan" dalam KL (8 = 8000 liter).
 * Riwayat request (forecast-request-history-bm.php): hanya request yang diajukan BM itu sendiri (bm_pic), lengkap dengan waktu BM, OM, dan CEO.
 */
class ForecastBmController extends Controller
{
    private const NAMA_BULAN = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    private static function cabang(Request $r): int
    {
        return (int) $r->user()->id_wilayah;
    }

    private static function label(object $c): string
    {
        $kode = trim((string) ($c->kode_pelanggan ?? ''));

        return $kode !== '' ? "($kode) {$c->nama_customer}" : (string) $c->nama_customer;
    }

    private static function disetujui(int $cabang, int $bulan, int $tahun, int $periode): bool
    {
        return DB::table('pro_forecast_approval')->where('id_wilayah', $cabang)->where('bulan', $bulan)->where('tahun', $tahun)->where('periode', $periode)
            ->orderByDesc('id_approval')->value('status') === 'APPROVED';
    }

    // ---------- notifikasi: periode cabang BM yang ditolak CEO ----------

    private function revisi(AclUser $u)
    {
        $now = (int) date('Y') * 12 + (int) date('n');

        return DB::table('pro_forecast_approval as a')
            ->join(DB::raw('(select max(id_approval) as mid from pro_forecast_approval group by id_wilayah, bulan, tahun, periode) m'), 'm.mid', '=', 'a.id_approval')
            ->where('a.id_wilayah', (int) $u->id_wilayah)->where('a.status', 'REJECTED')->whereRaw('(a.tahun * 12 + a.bulan) >= ?', [$now]);
    }

    public function waitingCount(AclUser $u): int
    {
        return $this->revisi($u)->count();
    }

    /** @return array<int,string> id_approval => "P1 November 2026" */
    public function waitingItems(AclUser $u): array
    {
        $out = [];
        foreach ($this->revisi($u)->orderByDesc('a.id_approval')->limit(100)->get(['a.id_approval', 'a.periode', 'a.bulan', 'a.tahun']) as $r) {
            $out[$r->id_approval] = "Forecast P{$r->periode} ".(self::NAMA_BULAN[(int) $r->bulan] ?? $r->bulan).' ditolak CEO';
        }

        return $out;
    }

    // ---------- data halaman ----------

    public function data(Request $r): JsonResponse
    {
        $cabang = self::cabang($r);
        $nama = $cabang ? DB::table('pro_master_cabang')->where('id_master', $cabang)->value('nama_cabang') : null;
        if (! $nama) {
            return response()->json(['message' => 'Akun Anda belum terhubung ke cabang.'], 404);
        }
        $bulan = min(max((int) $r->query('bulan', date('n')), 1), 12);
        $tahun = min(max((int) $r->query('tahun', date('Y')), 2000), 2100);
        $hari = (int) date('t', mktime(0, 0, 0, $bulan, 1, $tahun));
        $periode = (int) $r->query('periode', 1) === 2 ? 2 : 1;
        [$lo, $hi] = ForecastDefault::range($bulan, $tahun, $periode);
        $awal = sprintf('%04d-%02d-01', $tahun, $bulan);
        $akhir = sprintf('%04d-%02d-%02d', $tahun, $bulan, $hari);

        $review = [];
        foreach ([1, 2] as $p) {
            $a = DB::table('pro_forecast_approval')->where('id_wilayah', $cabang)->where('bulan', $bulan)->where('tahun', $tahun)->where('periode', $p)
                ->orderByDesc('id_approval')->first(['status', 'catatan_ceo', 'ceo_date']);
            $review[$p] = ['status' => $a->status ?? '', 'catatan_ceo' => ($a && $a->status === 'REJECTED') ? $a->catatan_ceo : null, 'ceo_date' => ($a && $a->status === 'REJECTED') ? $a->ceo_date : null];
        }

        $def = ForecastDefault::tampil($cabang, $bulan, $tahun, $periode); // [id_customer => [hari => qty]]
        $cond = '(c.id_customer in (select id_customer from pro_po_customer where poc_approved = 1 and tanggal_poc >= date_sub(curdate(), interval 6 month))
                 or c.id_customer in (select id_customer from pro_forecast where tanggal between ? and ?)'.($def ? ' or c.id_customer in ('.implode(',', array_map('intval', array_keys($def))).')' : '').')';
        $cust = DB::select("select c.id_customer, c.nama_customer, c.kode_pelanggan from pro_customer c where c.id_wilayah = ? and $cond order by c.nama_customer asc", [$cabang, $awal, $akhir]);

        $fc = [];
        foreach (DB::table('pro_forecast as f')->join('pro_customer as c', 'c.id_customer', '=', 'f.id_customer')->where('c.id_wilayah', $cabang)
            ->whereBetween('f.tanggal', [$awal, $akhir])->get(['f.id_customer', 'f.tanggal', 'f.qty']) as $x) {
            $fc[$x->id_customer][(int) date('j', strtotime($x->tanggal))] = (float) $x->qty;
        }

        $customers = [];
        $totHari = array_fill($lo, $hi - $lo + 1, 0.0);
        $totBulan = 0.0;
        foreach ($cust as $c) {
            $vals = [];
            $isDef = [];
            $sum = 0.0;
            for ($d = 1; $d <= $hari; $d++) {
                $v = $fc[$c->id_customer][$d] ?? null;
                $totBulan += (float) $v;
                if ($d < $lo || $d > $hi) {
                    continue;
                }
                $dflt = false;
                if ($v === null || $v == 0.0) { // 0 = penanda dihapus, tampil kosong
                    $v = null;
                    if (isset($def[(int) $c->id_customer][$d])) {
                        $v = $def[(int) $c->id_customer][$d];
                        $dflt = true;
                        $totBulan += $v;
                    }
                }
                $vals[] = $v;
                $isDef[] = $dflt;
                $sum += (float) $v;
                $totHari[$d] += (float) $v;
            }
            $customers[] = ['id' => (int) $c->id_customer, 'nama' => self::label($c), 'hari' => $vals, 'default' => $isDef, 'total' => $sum];
        }

        $libur = HariLibur::tahun($tahun);
        $kal = [];
        for ($d = $lo; $d <= $hi; $d++) {
            $ymd = sprintf('%04d-%02d-%02d', $tahun, $bulan, $d);
            $dow = (int) date('N', strtotime($ymd));
            $kal[] = ['d' => $d, 'tanggal' => $ymd, 'dow' => $dow, 'akhir_pekan' => $dow >= 6, 'libur' => $libur[$ymd]['name'] ?? null];
        }

        return response()->json([
            'cabang' => ['id' => $cabang, 'nama' => $nama], 'bulan' => $bulan, 'tahun' => $tahun, 'periode' => $periode, 'jumlah_hari' => $hari, 'hari_awal' => $lo, 'hari_akhir' => $hi,
            'terkunci' => $review[$periode]['status'] === 'APPROVED', 'review' => $review,
            'kalender' => $kal, 'customers' => $customers, 'total_hari' => array_values($totHari), 'total_periode' => array_sum($totHari), 'total_bulan' => $totBulan,
            'ada_default' => (bool) $def, 'tanggal_default' => ForecastDefault::pemicu($bulan, $tahun, $periode),
        ]);
    }

    /** Cari customer cabang BM yang belum tampil (nama atau kode). */
    public function customer(Request $r): JsonResponse
    {
        $q = trim((string) $r->query('q', ''));
        $rows = DB::table('pro_customer')->where('id_wilayah', self::cabang($r))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('nama_customer', 'like', "%{$q}%")->orWhere('kode_pelanggan', 'like', "%{$q}%")))
            ->orderBy('nama_customer')->limit(30)->get(['id_customer as id', 'nama_customer', 'kode_pelanggan']);

        return response()->json(['data' => $rows->map(fn ($c) => ['id' => (int) $c->id, 'nama' => self::label($c)])]);
    }

    // ---------- isi forecast ----------

    /** Simpan satu atau beberapa sel (customer + tanggal): qty kosong menghapus barisnya; qty 0 disimpan sebagai penanda. */
    public function isi(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_customer'     => ['required', 'integer'],
            'items'           => ['required', 'array', 'min:1', 'max:40'],
            'items.*.tanggal' => ['required', 'date_format:Y-m-d'],
            'items.*.qty'     => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ], ['items.*.qty.min' => 'Qty tidak valid.', 'items.*.qty.numeric' => 'Qty tidak valid.']);
        $cabang = self::cabang($request);
        $cust = DB::table('pro_customer')->where('id_customer', $data['id_customer'])->first(['id_customer', 'id_wilayah']);
        if (! $cust || (int) $cust->id_wilayah !== $cabang) {
            return response()->json(['message' => 'Customer tidak ditemukan di cabang Anda.'], 404);
        }
        foreach ($data['items'] as $it) {
            $ts = strtotime($it['tanggal']);
            if (self::disetujui($cabang, (int) date('n', $ts), (int) date('Y', $ts), (int) date('j', $ts) <= 14 ? 1 : 2)) {
                return response()->json(['message' => 'Forecast periode ini sudah disetujui CEO dan tidak bisa diubah.', 'code' => 'LOCKED'], 409);
            }
        }
        DB::transaction(function () use ($data) {
            foreach ($data['items'] as $it) {
                ForecastDefault::adopt((int) $data['id_customer'], $it['tanggal']);
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
        [$lo, $hi] = ForecastDefault::range($b, $y, (int) date('j', $first) <= 14 ? 1 : 2);
        $rows = DB::table('pro_forecast')->where('id_customer', $data['id_customer'])->whereBetween('tanggal', [sprintf('%04d-%02d-%02d', $y, $b, $lo), sprintf('%04d-%02d-%02d', $y, $b, $hi)])
            ->orderBy('tanggal')->get(['tanggal', 'qty']);

        // nilai tersimpan satu periode ini: layar memakainya untuk menandai default yang kini sudah tersimpan
        return response()->json(['message' => 'Forecast tersimpan.', 'total' => (float) $rows->sum('qty'), 'tersimpan' => $rows->map(fn ($x) => ['tanggal' => $x->tanggal, 'qty' => (float) $x->qty])]);
    }

    // ---------- request tambahan qty ke OM ----------

    public function requestStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_customer' => ['required', 'integer'],
            'tanggal'     => ['required', 'date_format:Y-m-d'],
            'qty_request' => ['required', 'integer', 'min:1', 'max:1000000'],
            'alasan'      => ['required', 'string', 'max:2000'],
        ], ['alasan.required' => 'Semua field wajib diisi!', 'qty_request.required' => 'Semua field wajib diisi!', 'qty_request.min' => 'Qty harus lebih dari 0.']);
        $cabang = self::cabang($request);
        $cust = DB::table('pro_customer as c')->leftJoin('pro_master_cabang as m', 'm.id_master', '=', 'c.id_wilayah')->where('c.id_customer', $data['id_customer'])
            ->first(['c.id_customer', 'c.id_wilayah', 'c.nama_customer', 'm.nama_cabang']);
        if (! $cust || (int) $cust->id_wilayah !== $cabang) {
            return response()->json(['message' => 'Customer tidak ditemukan di cabang Anda!'], 404);
        }
        $alasan = trim($data['alasan']);
        $id = DB::table('pro_forecast_request')->insertGetId([
            'id_customer' => $data['id_customer'], 'tanggal' => $data['tanggal'], 'qty_request' => $data['qty_request'], 'alasan' => $alasan,
            'status' => 'WAITING_OM', 'bm_pic' => $request->user()->id_user, 'bm_tanggal' => now(),
        ]);

        $body = '<p>Yth. Bapak/Ibu Operational Manager,</p><p>Branch Manager telah mengajukan <b>Request Penambahan Forecast</b> dengan detail:</p>'
            ."<table cellpadding='6' cellspacing='0' border='1' style='border-collapse:collapse'>"
            .'<tr><td><b>Cabang</b></td><td>'.e($cust->nama_cabang ?: '-').'</td></tr>'
            .'<tr><td><b>Customer</b></td><td>'.e($cust->nama_customer).'</td></tr>'
            .'<tr><td><b>Tanggal Forecast</b></td><td>'.date('d M Y', strtotime($data['tanggal'])).'</td></tr>'
            .'<tr><td><b>Qty Request</b></td><td><b>'.number_format($data['qty_request']).' KL</b></td></tr>'
            .'<tr><td><b>Alasan</b></td><td>'.nl2br(e($alasan)).'</td></tr>'
            .'<tr><td><b>Status</b></td><td><b>MENUNGGU REVIEW OM</b></td></tr></table>'
            .'<p>Silakan lakukan <b>review dan persetujuan</b> melalui sistem.</p><p>Terima kasih,<br><b>Sistem Forecast Pro Energi</b></p>';
        ApprovalMail::toRoles([6], null, '[BM] Request Penambahan Forecast – Menunggu Review OM', $body);

        return response()->json(['message' => 'Request berhasil diajukan dan dikirim ke OM.', 'id' => $id], 201);
    }

    /** Riwayat request qty yang diajukan BM ini. */
    public function requests(Request $r): JsonResponse
    {
        $per = min(max((int) $r->query('per_page', 20), 1), (int) config('orca.per_page_max'));
        $page = DB::table('pro_forecast_request as r')->join('pro_customer as c', 'c.id_customer', '=', 'r.id_customer')->join('pro_master_cabang as mc', 'mc.id_master', '=', 'c.id_wilayah')->where('r.bm_pic', $r->user()->id_user)
            ->orderByDesc('r.id_request')
            ->select(['r.id_request as id', 'r.tanggal', 'r.qty_request as qty', 'r.alasan', 'r.status', 'r.om_catatan', 'r.ceo_catatan', 'r.created_at', 'r.bm_tanggal', 'r.om_tanggal', 'r.ceo_tanggal', 'c.nama_customer', 'mc.nama_cabang'])->paginate($per);

        return response()->json([
            'data' => $page->items(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage()],
        ]);
    }
}
