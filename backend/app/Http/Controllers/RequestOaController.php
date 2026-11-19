<?php

namespace App\Http\Controllers;

use App\Services\Accurate\AccurateException;
use App\Services\Accurate\AccurateOaSync;
use App\Services\ApprovalMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verifikasi Request Input OA (pro_po_request_oa), persetujuan CEO.
 * Sumber: verifikasi-request-oa (1).php (daftar), verifikasi-request-oa-ceo-detail.php (detail), verifikasi-request-oa-ceo.php (aksi).
 * status: 1 Menunggu Procurement, 2 Menunggu Input OA (Logistik), 3 Menunggu Approval CEO, 4 Disetujui, 5 Ditolak.
 * Hanya status 3 yang menunggu keputusan CEO. Setuju: komposisi source baru dipakai (Delivery Request, stok virtual, split), ongkos PO real
 * diganti OA baru, Sales Order/Delivery Order Accurate ikut berubah (bila aktif), email ke Logistik cabang. Tolak: dikembalikan ke Procurement
 * (status 1), email ke Purchasing. Kolom oa_request_status pada pro_po_detail hanya ditulis bila ada di database.
 */
class RequestOaController extends Controller
{
    private const T = 'pro_po_request_oa';

    private static function waiting($q)
    {
        return $q->where('a.status', 3);
    }

    public function waitingCount(): int
    {
        return self::waiting(DB::table(self::T.' as a'))->count();
    }

    /** @return array<int,string> id => nomor DO (atau nomor PO bila DO belum ada) */
    public function waitingItems(): array
    {
        return self::waiting(DB::table(self::T.' as a')
            ->leftJoin('pro_pr_detail as c', 'a.id_prd', '=', 'c.id_prd')
            ->leftJoin('pro_po as b', 'a.id_po', '=', 'b.id_po'))
            ->orderByDesc('a.id_master')->limit(500)
            ->get(['a.id_master as id', DB::raw("coalesce(nullif(c.no_do_syop, ''), b.nomor_po) as label")])->pluck('label', 'id')->all();
    }

    /** Catatan disimpan seperti web: di-escape HTML dengan <br />; dibaca kembali apa adanya. */
    private static function plain(?string $s): ?string
    {
        return $s === null ? null : html_entity_decode(str_replace(['<br />', '<br>'], "\n", $s), ENT_QUOTES);
    }

    private static function simpan(string $s): string
    {
        return str_replace(["\r\n", "\r", "\n"], '<br />', htmlspecialchars($s, ENT_QUOTES));
    }

    private static function depot(object $s): string
    {
        return ($s->nama_terminal ?: '-').($s->tanki_terminal ? ' - '.$s->tanki_terminal : '').($s->lokasi_terminal ? ', '.$s->lokasi_terminal : '');
    }

    /** Source lama (jenis 1) dan baru (jenis 2) satu request, lengkap dengan nama depot. */
    private function sources(int $idRequest): array
    {
        $rows = DB::table('pro_po_request_oa_detail as p')->leftJoin('pro_master_terminal as t', 'p.id_terminal', '=', 't.id_master')
            ->where('p.id_request', $idRequest)->orderBy('p.jenis')->orderBy('p.urutan')
            ->get(['p.*', 't.nama_terminal', 't.tanki_terminal', 't.lokasi_terminal']);
        $lama = $baru = [];
        foreach ($rows as $s) {
            $s->nama_depot = self::depot($s);
            ((int) $s->jenis === 1) ? $lama[] = $s : $baru[] = $s;
        }

        return [$lama, $baru];
    }

    private static function porsi(array $list): array
    {
        $depot = $list ? $list[0]->nama_depot : null;

        return [
            'depot' => $depot,
            'items' => array_map(fn ($s) => ['nomor_po' => $s->nomor_po_supplier ?: null, 'volume' => (int) $s->volume, 'harga_beli' => (float) $s->harga_beli], $list),
        ];
    }

    public function index(Request $r): JsonResponse
    {
        $q = DB::table(self::T.' as a')
            ->join('pro_po as b', 'a.id_po', '=', 'b.id_po')
            ->join('pro_pr_detail as c', 'a.id_prd', '=', 'c.id_prd')
            ->join('pro_po_customer_plan as f', 'c.id_plan', '=', 'f.id_plan')
            ->join('pro_po_customer as g', 'f.id_poc', '=', 'g.id_poc')
            ->join('pro_customer as d', 'g.id_customer', '=', 'd.id_customer');
        $tab = $r->query('tab', 'wait');
        if ($tab === 'wait') {
            self::waiting($q);
        } elseif ($tab === 'done') {
            $q->whereIn('a.status', [4, 5]);
        }
        if ($term = trim((string) $r->query('q', ''))) {
            $q->where(fn ($w) => $w->where('b.nomor_po', 'like', "%{$term}%")->orWhere('c.no_do_syop', 'like', "%{$term}%")->orWhere('d.nama_customer', 'like', "%{$term}%"));
        }
        $per = min(max((int) $r->query('per_page', 20), 1), (int) config('orca.per_page_max'));
        $page = $q->orderByRaw('case when a.status = 3 then 0 else 1 end')->orderByDesc('a.created_time')
            ->select(['a.id_master as id', 'a.status', 'a.oa_lama', 'a.oa_baru', 'a.created_time', 'a.created_by', 'b.nomor_po', 'c.no_do_syop', 'd.nama_customer', 'd.kode_pelanggan'])
            ->paginate($per);

        // Source baru tiap request (satu request bisa punya lebih dari satu PO), diambil sekali untuk seluruh halaman.
        $ids = collect($page->items())->pluck('id')->all();
        $src = [];
        if ($ids) {
            foreach (DB::table('pro_po_request_oa_detail as p')->leftJoin('pro_master_terminal as t', 'p.id_terminal', '=', 't.id_master')
                ->where('p.jenis', 2)->whereIn('p.id_request', $ids)->orderBy('p.id_request')->orderBy('p.urutan')
                ->get(['p.id_request', 'p.volume', 't.nama_terminal']) as $s) {
                $src[$s->id_request][] = ['depot' => $s->nama_terminal ?: '-', 'volume' => (int) $s->volume];
            }
        }
        $items = array_map(function ($x) use ($src) {
            $x->depot_baru = $src[$x->id] ?? [];
            $x->selisih = $x->oa_baru !== null ? (int) $x->oa_baru - (int) $x->oa_lama : null;

            return $x;
        }, $page->items());

        return response()->json([
            'data' => $items,
            'waiting' => $this->waitingCount(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage()],
        ]);
    }

    private function row(int $id)
    {
        return DB::table(self::T.' as r')
            ->leftJoin('pro_po_detail as pod', 'r.id_pod', '=', 'pod.id_pod')
            ->leftJoin('pro_po as b', 'r.id_po', '=', 'b.id_po')
            ->leftJoin('pro_pr_detail as c', 'r.id_prd', '=', 'c.id_prd')
            ->leftJoin('pro_po_customer_plan as pl', 'c.id_plan', '=', 'pl.id_plan')
            ->leftJoin('pro_po_customer as poc', 'pl.id_poc', '=', 'poc.id_poc')
            ->leftJoin('pro_customer as d', 'poc.id_customer', '=', 'd.id_customer')
            ->leftJoin('pro_customer_lcr as lcr', 'pl.id_lcr', '=', 'lcr.id_lcr')
            ->leftJoin('pro_penawaran as pen', 'poc.id_penawaran', '=', 'pen.id_penawaran')
            ->leftJoin('pro_master_area as ar', 'pen.id_area', '=', 'ar.id_master')
            ->leftJoin('pro_master_wilayah_angkut as wa', function ($j) {
                $j->on('lcr.id_wil_oa', '=', 'wa.id_master')->on('lcr.prov_survey', '=', 'wa.id_prov')->on('lcr.kab_survey', '=', 'wa.id_kab');
            })
            ->where('r.id_master', $id)
            ->select(['r.*', 'b.nomor_po', 'b.tanggal_po', 'c.no_do_syop', 'c.nomor_lo_pr', 'c.produk', 'd.nama_customer', 'd.kode_pelanggan',
                'ar.nama_area', 'wa.wilayah_angkut', 'pod.tgl_etl_po', 'pod.tgl_kirim_po', 'pod.volume_po', 'pod.ongkos_po']);
    }

    public function show(int $id): JsonResponse
    {
        $r = $this->row($id)->first();
        if (! $r) {
            return response()->json(['message' => 'Request OA tidak ditemukan.'], 404);
        }
        [$lama, $baru] = $this->sources($id);
        $selisih = $r->oa_baru !== null ? (int) $r->oa_baru - (int) $r->oa_lama : null;

        return response()->json([
            'id'          => (int) $r->id_master,
            'status'      => (int) $r->status,
            'can_decide'  => (int) $r->status === 3,
            'nomor_po'    => $r->nomor_po,
            'no_do_syop'  => $r->no_do_syop,
            'nomor_lo_pr' => $r->nomor_lo_pr,
            'customer'    => trim(($r->kode_pelanggan ? $r->kode_pelanggan.' - ' : '').$r->nama_customer, ' -'),
            'produk'      => $r->produk,
            'area'        => $r->nama_area,
            'wilayah_angkut' => $r->wilayah_angkut,
            'volume'      => (int) $r->volume_po,
            'tgl_loading' => $r->tgl_etl_po,
            'tgl_kirim'   => $r->tgl_kirim_po,
            'alasan'      => self::plain($r->alasan_request),
            'dibuat_oleh' => $r->created_by,
            'dibuat_pada' => $r->created_time,
            'procurement_pic' => $r->procurement_pic,
            'procurement_tanggal' => $r->procurement_tanggal,
            'logistik_pic' => $r->logistik_pic,
            'logistik_tanggal' => $r->logistik_tanggal,
            'catatan_logistik' => self::plain($r->catatan_logistik),
            'sebelum'     => self::porsi($lama),
            'sesudah'     => self::porsi($baru),
            'oa_lama'     => $r->oa_lama !== null ? (int) $r->oa_lama : null,
            'oa_baru'     => $r->oa_baru !== null ? (int) $r->oa_baru : null,
            'selisih'     => $selisih,
            'ceo_result'  => $r->ceo_result !== null ? (int) $r->ceo_result : null,
            'ceo_catatan' => self::plain($r->ceo_catatan),
            'ceo_pic'     => $r->ceo_pic,
            'ceo_tanggal' => $r->ceo_tanggal,
        ]);
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'keputusan' => ['required', 'in:approve,reject'],
            'catatan'   => ['nullable', 'string', 'max:2000'],
        ]);
        $user = $request->user();        $note = trim((string) ($data['catatan'] ?? ''));
        if ($data['keputusan'] === 'reject' && $note === '') {
            return response()->json(['message' => 'Alasan penolakan wajib diisi.', 'code' => 'NOTE_REQUIRED'], 422);
        }
        $approve = $data['keputusan'] === 'approve';

        try {
            $info = DB::transaction(function () use ($id, $approve, $note, $user, $request) {
                $row = DB::table(self::T)->where('id_master', $id)->lockForUpdate()->first();
                if (! $row) {
                    abort(response()->json(['message' => 'Request OA tidak ditemukan.'], 404));
                }
                if ((int) $row->status !== 3) {
                    abort(response()->json(['message' => 'Request ini sudah diproses.', 'code' => 'NOT_PENDING'], 409));
                }
                [$lama, $baru] = $this->sources($id);
                $podCols = Schema::hasColumn('pro_po_detail', 'oa_request_status');

                if ($approve) {
                    if (! $baru) {
                        abort(response()->json(['message' => 'Komposisi PO baru tidak ditemukan, tidak bisa disetujui.', 'code' => 'NO_SOURCE'], 422));
                    }
                    $utama = $baru[0];
                    $split = $baru[1] ?? null;

                    DB::table('pro_po_detail')->where('id_pod', $row->id_pod)->update(['ongkos_po_real' => $row->oa_baru, 'terminal_po' => $utama->id_terminal] + ($podCols ? ['oa_request_status' => 4] : []));
                    // Porsi utama menempel pada Delivery Request.
                    DB::table('pro_pr_detail')->where('id_prd', $row->id_prd)->update([
                        'pr_terminal' => $utama->id_terminal, 'pr_vendor' => $utama->id_vendor, 'pr_harga_beli' => $utama->harga_beli,
                        'nomor_po_supplier' => $utama->nomor_po_supplier, 'id_po_supplier' => $utama->id_po_supplier, 'id_po_receive' => $utama->id_po_receive,
                        'vol_potongan' => $split ? $utama->volume : 0, 'is_split' => $split ? 1 : 0,
                    ]);
                    // Porsi split ditulis ulang dari awal.
                    DB::table('new_pro_inventory_potongan_stock')->where('id_prd', $row->id_prd)->delete();
                    if ($split) {
                        DB::table('new_pro_inventory_potongan_stock')->insert([
                            'id_pr' => $row->id_pr, 'id_prd' => $row->id_prd, 'volume' => $split->volume, 'pr_terminal' => $split->id_terminal, 'pr_harga_beli' => $split->harga_beli,
                            'nomor_po_supplier' => $split->nomor_po_supplier, 'id_po_supplier' => $split->id_po_supplier, 'id_po_receive' => $split->id_po_receive,
                        ]);
                    }
                    // Stok virtual ditata ulang: baris lama dihapus, diganti satu baris per porsi.
                    $idProduk = (int) DB::table('new_pro_inventory_depot')->where('id_prd', $row->id_prd)->where('id_jenis', 6)->value('id_produk');
                    DB::table('new_pro_inventory_depot')->where('id_prd', $row->id_prd)->where('id_jenis', 6)->delete();
                    foreach ($baru as $s) {
                        DB::table('new_pro_inventory_depot')->insert([
                            'id_datanya' => 'generate DR', 'id_jenis' => 6, 'id_produk' => $idProduk, 'id_terminal' => $s->id_terminal, 'id_vendor' => $s->id_vendor,
                            'id_po_supplier' => $s->id_po_supplier, 'id_po_receive' => $s->id_po_receive, 'tanggal_inven' => now(),
                            'out_inven_virtual' => $s->volume, 'keterangan' => 'Out Stock Virtual - perubahan source request OA', 'created_time' => now(),
                            'created_ip' => $request->ip(), 'created_by' => $user->fullname, 'id_pr' => $row->id_pr, 'id_prd' => $row->id_prd,
                        ]);
                    }
                    // Accurate (bila aktif): gagal = seluruh perubahan database dibatalkan, sama dengan web.
                    app(AccurateOaSync::class)->apply((int) $row->id_prd, $baru);
                    $status = 4;
                } else {
                    // Ditolak: dikembalikan ke Procurement untuk pilih source ulang.
                    DB::table('pro_po_detail')->where('id_pod', $row->id_pod)->update(['terminal_po' => $lama ? $lama[0]->id_terminal : 0] + ($podCols ? ['oa_request_status' => 1] : []));
                    $status = 1;
                }

                DB::table(self::T)->where('id_master', $id)->where('status', 3)->update([
                    'ceo_result' => $approve ? 1 : 2, 'ceo_catatan' => self::simpan($note), 'ceo_pic' => $user->fullname, 'ceo_tanggal' => now(), 'status' => $status,
                ]);

                return ['row' => $row, 'baru' => $baru];
            });
        } catch (AccurateException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'ACCURATE'], 422);
        }

        $this->email($info['row'], $info['baru'], $approve, $note, $user->fullname);

        return response()->json(['message' => $approve ? 'Request perubahan OA disetujui.' : 'Request perubahan OA ditolak dan dikembalikan ke Procurement.']);
    }

    private function email(object $row, array $baru, bool $approve, string $note, string $pic): void
    {
        $info = DB::table('pro_po_detail as a')->join('pro_po as b', 'a.id_po', '=', 'b.id_po')->join('pro_pr_detail as c', 'a.id_prd', '=', 'c.id_prd')
            ->where('a.id_pod', $row->id_pod)->first(['c.no_do_syop', 'b.id_wilayah']);
        $noDo = $info && $info->no_do_syop ? $info->no_do_syop : '-';
        $daftar = '';
        foreach ($baru as $k) {
            $daftar .= '<li>'.e(self::depot($k)).' &mdash; PO '.e($k->nomor_po_supplier ?: '-').' &mdash; <b>'.number_format($k->volume).' Liter</b></li>';
        }
        $tag = ApprovalMail::stamp();
        if ($approve) {
            $subject = "Perubahan OA Disetujui - DO {$noDo} [{$tag}]";
            $body = '<p>'.e($pic).' telah <b>menyetujui</b> permintaan perubahan Ongkos Angkut pada DO <b>'.e($noDo).'</b>.</p>'
                .'<p><b>Source baru:</b></p><ul>'.$daftar.'</ul>'
                .'<p><b>Ongkos Angkut:</b> '.number_format($row->oa_lama).' &rarr; '.number_format($row->oa_baru).'</p>'
                .($note !== '' ? '<p><b>Catatan CEO:</b> '.e($note).'</p>' : '')
                .'<p>Data source dan Ongkos Angkut pada DO ini sudah diperbarui otomatis. Tidak ada tindakan lanjutan yang diperlukan.</p>';
            ApprovalMail::toRoles([9], $info ? [(int) $info->id_wilayah] : null, $subject, $body);
        } else {
            $subject = "Perubahan OA Ditolak - DO {$noDo} [{$tag}]";
            $body = '<p>'.e($pic).' <b>menolak</b> permintaan perubahan Ongkos Angkut pada DO <b>'.e($noDo).'</b>.</p>'
                .'<p><b>Source yang diajukan:</b></p><ul>'.$daftar.'</ul>'
                .'<p><b>Ongkos Angkut yang diajukan:</b> '.number_format($row->oa_baru).'</p>'
                .($note !== '' ? '<p><b>Alasan penolakan:</b> '.e($note).'</p>' : '')
                .'<p>Permintaan dikembalikan untuk pemilihan source ulang. Mohon pilih PO lain melalui menu <b>Delivery Request</b> pada DR terkait.</p>';
            ApprovalMail::toRoles([5], null, $subject, $body);
        }
    }
}
