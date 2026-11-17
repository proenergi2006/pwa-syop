<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StepUp;
use App\Models\AclUser;
use App\Services\ApprovalMail;
use App\Services\LegacyFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Verifikasi Sales Confirmation (SC, pro_sales_confirmation) untuk Branch Manager (peran 7), per cabangnya.
 * Sumber: pro_sales_confirmation.php + datatable (daftar), sales_confirmation_form.php, __get_sales_confirmation.php (form),
 * __sc_get_data_po.php (data PO), sales_confirmation_action.php (aksi BM).
 * disposisi: 1 Adm Finance, 2 BM, 3 OM, 4 MGR Finance, 5 CFO. BM melihat disposisi > 1 di cabangnya; menunggu BM = disposisi 2, flag_approval 0, bm_result 0.
 * BM setuju: PO customer disetujui (poc_approved, po_notif), plafon kredit dicadangkan (credit_limit_reserved + riwayat AR untuk PO sejak 14/10/2025),
 * credit_limit_temp customer = add_cl SC. BM tolak: SC kembali ke Adm Finance (disposisi 1).
 */
class SalesConfirmationController extends Controller
{
    use StepUp;

    private const POSISI = [1 => 'Adm Finance', 2 => 'BM', 3 => 'OM', 4 => 'MGR Finance', 5 => 'CFO'];
    private const ROLE = [7 => 'BM', 6 => 'OM', 4 => 'CFO', 15 => 'MGR Finance'];
    private const TIPE_BISNIS = [
        1 => 'Agriculture & Forestry / Horticulture', 2 => 'Business & Information', 3 => 'Construction/Utilities/Contracting', 4 => 'Education',
        5 => 'Finance & Insurance', 6 => 'Food & hospitally', 7 => 'Gaming', 8 => 'Health Services', 9 => 'Motor Vehicle',
        11 => 'Natural Resources / Environmental', 12 => 'Personal Service', 13 => 'Manufacture',
    ];
    private const SUPPLY = [1 => 'Supply', 2 => 'Not Supply'];

    private function base(AclUser $u)
    {
        return DB::table('pro_sales_confirmation as n')
            ->join('pro_po_customer as a', 'a.id_poc', '=', 'n.id_poc')
            ->join('pro_customer as b', 'a.id_customer', '=', 'b.id_customer')
            ->join('pro_master_kabupaten as c', 'b.kab_customer', '=', 'c.id_kab')
            ->join('pro_master_provinsi as d', 'b.prov_customer', '=', 'd.id_prov')
            ->leftJoin('acl_user as e_po', 'a.id_marketing', '=', 'e_po.id_user')
            ->join('acl_user as e_cust', 'b.id_marketing', '=', 'e_cust.id_user')
            ->join('pro_penawaran as f', 'f.id_penawaran', '=', 'a.id_penawaran')
            ->leftJoin('pro_sales_confirmation_approval as s', 's.id_sales', '=', 'n.id')
            ->where('n.disposisi', '>', 1)->where('n.id_wilayah', $u->id_wilayah);
    }

    private function waiting($q)
    {
        return $q->where('n.disposisi', 2)->where('n.flag_approval', 0)->where('s.bm_result', 0);
    }

    public function waitingCount(AclUser $u): int
    {
        return (int) $this->waiting($this->base($u))->count();
    }

    /** @return array<int,string> id SC => nomor PO - customer */
    public function waitingItems(AclUser $u): array
    {
        return $this->waiting($this->base($u))->orderByDesc('n.id')->limit(500)->get(['n.id', 'a.nomor_poc', 'b.nama_customer'])
            ->mapWithKeys(fn ($x) => [(int) $x->id => $x->nomor_poc.' - '.$x->nama_customer])->all();
    }

    private static function tgl($v): ?string
    {
        return $v && ! str_starts_with((string) $v, '0000') ? date('d/m/Y H:i:s', strtotime($v)) : null;
    }

    private static function status(object $x): string
    {
        $role = self::ROLE[(int) $x->role_approved] ?? '';
        if ((int) $x->flag_approval === 1) {
            return trim('Terverifikasi '.$role).' ('.self::tgl($x->tgl_approved).' WIB)';
        }
        if ((int) $x->flag_approval === 2) {
            return trim('Ditolak '.$role).' ('.self::tgl($x->tgl_approved).' WIB)';
        }

        return isset(self::POSISI[(int) $x->disposisi]) ? 'Verifikasi '.self::POSISI[(int) $x->disposisi] : '-';
    }

    private static function harga($v): string
    {
        return rtrim(rtrim(number_format((float) $v, 6, '.', ''), '0'), '.');
    }

    public function index(Request $r): JsonResponse
    {
        $u = $r->user();
        $q = $this->base($u);
        if (($term = trim((string) $r->query('q', ''))) !== '') {
            $q->where(fn ($w) => $w->where('b.nama_customer', 'like', '%'.$term.'%')->orWhere('a.nomor_poc', 'like', '%'.$term.'%'));
        }
        if ($r->query('filter') === 'menunggu') {
            $this->waiting($q);
        }
        $page = $q->orderByRaw('(n.disposisi = 2 and n.flag_approval = 0 and s.bm_result = 0) desc')->orderByDesc('n.id')
            ->paginate(min(50, max(1, (int) $r->query('per_page', 20))), ['n.*', 'a.tanggal_poc', 'a.harga_poc', 'a.volume_poc', 'a.nomor_poc', 'b.nama_customer', 'b.kode_pelanggan', 's.bm_result',
                DB::raw('COALESCE(e_po.fullname, e_cust.fullname) as marketing')]);

        return response()->json([
            'data' => collect($page->items())->map(fn ($x) => [
                'id' => (int) $x->id, 'customer' => ($x->kode_pelanggan ? $x->kode_pelanggan.' - ' : '').$x->nama_customer, 'marketing' => $x->marketing,
                'nomor_po' => $x->nomor_poc, 'tanggal_po' => $x->tanggal_poc ? date('d/m/Y', strtotime($x->tanggal_poc)) : null,
                'volume' => (int) $x->volume_poc, 'harga' => self::harga($x->harga_poc), 'status' => self::status($x),
                'menunggu' => (int) $x->disposisi === 2 && (int) $x->flag_approval === 0 && (int) $x->bm_result === 0,
                'ditolak' => (int) $x->flag_approval === 2,
            ])->all(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    private function find(Request $r, int $id): ?object
    {
        return $this->base($r->user())->join('pro_master_produk as pr', 'a.produk_poc', '=', 'pr.id_master')->where('n.id', $id)->first([
            'n.*', 's.*', 'n.id as id', 'a.nomor_poc', 'a.tanggal_poc', 'a.harga_poc', 'a.volume_poc', 'a.keterangan as ket_poc', 'a.lampiran_poc', 'a.lampiran_poc_ori', 'a.id_penawaran',
            'a.created_time as po_dibuat', 'a.created_by as po_dibuat_oleh', 'b.kode_pelanggan', 'b.nama_customer', 'b.credit_limit as cl_customer', 'b.tipe_bisnis', 'b.tipe_bisnis_lain',
            'b.jenis_payment', 'b.top_payment', 'b.id_marketing', 'f.nomor_surat', 'f.volume_tawar', 'f.refund_tawar', 'f.jenis_payment as tawar_payment', 'f.jangka_waktu',
            'pr.jenis_produk', 'pr.merk_dagang', DB::raw('COALESCE(e_po.fullname, e_cust.fullname) as marketing'),
        ]);
    }

    private static function tahap($ringkas, $hasil, $pic, $tgl): array
    {
        return ['ringkasan' => $ringkas, 'hasil' => $hasil ? (self::SUPPLY[(int) $hasil] ?? null) : null, 'oleh' => $pic ?: null, 'tanggal' => self::tgl($tgl)];
    }

    public function show(Request $r, int $id): JsonResponse
    {
        $x = $this->find($r, $id);
        if (! $x) {
            return response()->json(['message' => 'Sales Confirmation tidak ditemukan.'], 404);
        }
        $tipe = (string) $x->type_customer;
        $coll = DB::table('pro_sales_colleteral')->where('sales_id', $id)->orderBy('id')->get()
            ->map(fn ($c) => ['tanggal' => $c->date ? date('d/m/Y', strtotime($c->date)) : null, 'jumlah' => (float) $c->amount, 'item' => $c->item])->all();
        $refund = DB::table('pro_poc_penerima_refund as p')->join('pro_master_penerima_refund as m', 'p.penerima_refund', '=', 'm.id')->where('p.id_poc', $x->id_poc)
            ->get(['m.nama', 'm.divisi', 'p.persentasi_refund', 'p.terima_refund'])
            ->map(fn ($p) => ['nama' => ucwords((string) $p->nama), 'divisi' => ucwords((string) $p->divisi), 'refund' => (float) $p->persentasi_refund, 'akhir' => (float) $p->terima_refund])->all();
        $term = match ($x->jenis_payment) { 'CREDIT' => 'NET '.$x->top_payment, 'COD' => 'COD', default => 'CBD' };
        $tawarTop = $x->tawar_payment === 'CREDIT' ? $x->jangka_waktu.' Hari' : $x->tawar_payment;
        $lamp = $x->lampiran_unblock ? ['nama' => $x->lampiran_unblock_ori ?: $x->lampiran_unblock] : null;

        return response()->json(['data' => [
            'id' => $id, 'status' => self::status($x), 'bisa_putus' => (int) $x->disposisi === 2 && (int) $x->flag_approval === 0 && (int) $x->bm_result === 0,
            'periode' => $x->period_date ? date('d/m/Y', strtotime($x->period_date)) : null, 'tgl_kirim' => $x->supply_date ? date('d/m/Y', strtotime($x->supply_date)) : null,
            'customer' => [
                'kode' => $x->kode_pelanggan, 'nama' => $x->nama_customer, 'top' => $term, 'credit_limit' => (float) $x->credit_limit, 'credit_limit_temp' => (float) $x->credit_limit_temp,
                'bisnis' => (int) $x->tipe_bisnis === 10 ? $x->tipe_bisnis_lain : (self::TIPE_BISNIS[(int) $x->tipe_bisnis] ?? null), 'marketing' => $x->marketing,
            ],
            'ar' => [
                'po_not_yet' => (float) $x->po_not_yet, 'not_yet' => (float) $x->not_yet, 'ov_07' => (float) $x->ov_up_07, 'ov_30' => (float) $x->ov_under_30,
                'ov_60' => (float) $x->ov_under_60, 'ov_90' => (float) $x->ov_under_90, 'ov_up_90' => (float) $x->ov_up_90, 'sisa' => (float) $x->reminding,
            ],
            'po' => [
                'jenis' => [1 => 'New PO', 2 => 'Parcial PO / Contract'][(int) $x->po_status] ?? '-', 'volume' => (int) $x->volume_poc, 'jumlah' => round((float) $x->harga_poc * (float) $x->volume_poc),
                'nomor' => $x->nomor_poc, 'tanggal' => $x->tanggal_poc ? date('d/m/Y', strtotime($x->tanggal_poc)) : null, 'produk' => trim($x->jenis_produk.' - '.$x->merk_dagang, ' -'),
                'harga' => self::harga($x->harga_poc), 'keterangan' => $x->ket_poc, 'refund_tawar' => (float) $x->refund_tawar, 'penerima_refund' => $refund,
                'lampiran' => $x->lampiran_poc ? ['nama' => $x->lampiran_poc_ori ?: $x->lampiran_poc] : null,
                'penawaran' => ['id' => (int) $x->id_penawaran, 'nomor' => $x->nomor_surat, 'volume' => (int) $x->volume_tawar, 'top' => $tawarTop],
            ],
            'pembayaran' => [
                'tipe' => str_contains($tipe, 'Collateral') || $tipe === '2' ? 'Customer Collateral' : (str_contains($tipe, 'Commitment') || $tipe === '1' ? 'Customer Commitment' : null),
                'tanggal' => $x->customer_date ? date('d/m/Y', strtotime($x->customer_date)) : null, 'jumlah' => (float) $x->customer_amount, 'agunan' => $coll,
            ],
            'jadwal' => ['diajukan' => (int) $x->proposed_status === 1, 'add_top' => $x->add_top, 'add_cl' => (float) $x->add_cl, 'lampiran' => $lamp],
            'finance' => self::tahap($x->adm_summary, $x->adm_result, $x->adm_pic, $x->adm_result_date),
            'bm' => self::tahap($x->bm_summary, $x->bm_result, $x->bm_pic, $x->bm_result_date),
        ]]);
    }

    /** jenis: po (lampiran PO customer, lampiran/<berkas>) atau unblock (lampiran/unblock/<berkas>). */
    public function berkas(Request $r, int $id, string $jenis)
    {
        $x = $this->find($r, $id);
        if (! $x) {
            return response()->json(['message' => 'Sales Confirmation tidak ditemukan.'], 404);
        }
        if ($jenis === 'po') {
            return LegacyFile::response($x->lampiran_poc ? 'lampiran/'.basename($x->lampiran_poc) : null, $x->lampiran_poc_ori ?: 'lampiran-po');
        }

        return LegacyFile::response($x->lampiran_unblock ? 'lampiran/unblock/'.basename($x->lampiran_unblock) : null, $x->lampiran_unblock_ori ?: 'unblock');
    }

    public function decide(Request $r, int $id): JsonResponse
    {
        if (! $this->find($r, $id)) {
            return response()->json(['message' => 'Sales Confirmation tidak ditemukan.'], 404);
        }
        $d = $r->validate(['result' => 'required|in:1,2', 'summary' => 'required|string|max:5000']);
        if ($resp = $this->stepUp($r)) {
            return $resp;
        }
        $u = $r->user();
        $res = (int) $d['result'];
        $note = str_replace(["\r\n", "\r", "\n"], '<br />', htmlspecialchars($d['summary'], ENT_QUOTES));

        return DB::transaction(function () use ($id, $u, $res, $note) {
            $sc = DB::table('pro_sales_confirmation')->where('id', $id)->lockForUpdate()->first();
            $ap = DB::table('pro_sales_confirmation_approval')->where('id_sales', $id)->lockForUpdate()->first();
            if (! $ap || (int) $sc->disposisi !== 2 || (int) $sc->flag_approval !== 0 || (int) $ap->bm_result !== 0) {
                return response()->json(['message' => 'Sales Confirmation ini sudah diputuskan atau belum waktunya Anda memutuskan.', 'code' => 'STATE'], 409);
            }
            DB::table('pro_sales_confirmation_approval')->where('id_sales', $id)->update(['bm_result' => $res, 'bm_summary' => $note, 'bm_result_date' => now(), 'bm_pic' => $u->fullname]);

            if ($res === 1) {
                $poc = DB::table('pro_po_customer')->where('id_poc', $sc->id_poc)->first();
                $total = (float) $sc->po_amount;
                if ($poc->created_time >= '2025-10-14 00:00:00') {
                    DB::table('pro_history_ar_customer')->insert(['id_poc' => $sc->id_poc, 'kategori' => 1, 'keterangan' => 'Generate PO Customer', 'nominal' => -$total, 'created_time' => now(), 'created_by' => $poc->created_by]);
                    DB::table('pro_customer')->where('id_customer', $sc->id_customer)->update(['credit_limit_reserved' => DB::raw('credit_limit_reserved + '.(float) $total)]);
                }
                DB::table('pro_sales_confirmation')->where('id', $id)->update(['flag_approval' => 1, 'role_approved' => 7, 'tgl_approved' => now()]);
                DB::table('pro_customer')->where('id_customer', $sc->id_customer)->update(['credit_limit_temp' => (float) $sc->add_cl]);
                DB::table('pro_po_customer')->where('id_poc', $sc->id_poc)->where('id_customer', $sc->id_customer)->update([
                    'poc_approved' => 1, 'tgl_approved' => now(), 'sm_summary' => $note, 'sm_result' => 1, 'sm_tanggal' => now(), 'sm_pic' => $u->fullname, 'po_notif' => 1,
                ]);
            } else {
                DB::table('pro_sales_confirmation')->where('id', $id)->update(['flag_approval' => 2, 'role_approved' => 7, 'tgl_approved' => now(), 'disposisi' => 1]);
                $mkt = DB::table('acl_user')->where('id_role', 11)->where('is_active', 1)
                    ->where('id_user', DB::table('pro_customer')->where('id_customer', $sc->id_customer)->value('id_marketing'))->value('email_user');
                if ($mkt) {
                    ApprovalMail::send([$mkt], 'Penolakan Sales Confirmation ['.ApprovalMail::stamp().']', e($u->fullname).' menolak Sales Confirmation PO Customer anda');
                }
            }
            $this->riwayat($id);

            return response()->json(['ok' => true, 'status' => $res === 1 ? 'disetujui' : 'ditolak']);
        });
    }

    /** history() di web: foto terakhir SC + hasil Adm Finance dan BM ke pro_sales_confirmation_log (satu baris per SC). */
    private function riwayat(int $id): void
    {
        $row = DB::table('pro_sales_confirmation as sc')->join('pro_sales_confirmation_approval as sca', 'sca.id_sales', '=', 'sc.id')->where('sc.id', $id)->first();
        DB::table('pro_sales_confirmation_log')->where('id_sales', $row->id_sales)->delete();
        DB::table('pro_sales_confirmation_log')->insert([
            'id_sales' => $row->id_sales, 'id_customer' => $row->id_customer, 'id_poc' => $row->id_poc, 'id_wilayah' => $row->id_wilayah,
            'not_yet' => $row->not_yet, 'ov_under_30' => $row->ov_under_30, 'ov_under_60' => $row->ov_under_60, 'ov_under_90' => $row->ov_under_90, 'ov_up_90' => $row->ov_up_90, 'reminding' => $row->reminding,
            'type_customer' => $row->type_customer ?: null, 'customer_items' => (string) $row->customer_items, 'customer_date' => $row->customer_date ?: null, 'customer_amount' => $row->customer_amount,
            'po_status' => (int) $row->po_status, 'po_volume' => (int) $row->po_volume, 'po_amount' => $row->po_amount, 'proposed_status' => (int) $row->proposed_status,
            'add_top' => $row->add_top, 'add_cl' => $row->add_cl, 'supply_date' => $row->supply_date ?: null, 'period_date' => $row->period_date ?: null,
            'adm_result' => $row->adm_result, 'adm_pic' => $row->adm_pic, 'adm_summary' => $row->adm_summary, 'adm_result_date' => $row->adm_result_date ?: null,
            'bm_result' => $row->bm_result, 'bm_pic' => $row->bm_pic, 'bm_summary' => $row->bm_summary, 'bm_result_date' => $row->bm_result_date ?: null,
        ]);
    }
}
