<?php

namespace App\Http\Controllers;

use App\Services\ApprovalMail;
use App\Support\Terbilang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Verifikasi PO Supplier Crushed Stone (PO Batu), tahap CEO.
 * Sumber: verifikasi-po-crushed-stone.php (+ datatable), verifikasi-po-crushed-stone-detail.php, vendor-po-new-crushed-stone.php.
 * Keputusan CEO: __get_data_po_crushed_stone_ceo2.php dan po-crushed-stone-supplier.php. Beda dari PO minyak: catatan pengembalian TIDAK wajib,
 * tabel gain/loss tidak disentuh (di web dikomentari), email ke Purchasing tanpa nomor PO, dan tidak ada Accurate.
 * disposisi_po: 1 = CFO, 2 = menunggu CEO, 3 = ditolak CFO, 4 = disetujui, 5 = dikembalikan CEO.
 */
class PoBatuController extends Controller
{
    private const PO = 'new_pro_inventory_vendor_po_crushed_stone';
    private const RI = 'new_pro_inventory_vendor_po_crushed_stone_receive';

    private function base()
    {
        return DB::table(self::PO.' as a')
            ->join('pro_master_vendor as b', 'a.id_vendor', '=', 'b.id_master')
            ->join('pro_master_produk as c', 'a.id_produk', '=', 'c.id_master')
            ->join('pro_master_terminal as d', 'a.id_terminal', '=', 'd.id_master')
            ->where('a.is_cancel', 0)->where('a.harga_tebus', '>', 0);
    }

    private static function waiting($q)
    {
        return $q->where('a.disposisi_po', 2)->where('a.cfo_result', 1)
            ->where(fn ($w) => $w->whereNull('a.ceo_result')->orWhere('a.ceo_result', 0))
            ->where(fn ($w) => $w->whereNull('a.revert_cfo')->orWhere('a.revert_cfo', 0));
    }

    public function waitingCount(): int
    {
        return self::waiting($this->base())->count();
    }

    /** @return array<int,string> id => nomor PO (untuk isi notifikasi) */
    public function waitingItems(): array
    {
        return self::waiting($this->base())->orderByDesc('a.id_master')->limit(500)
            ->get(['a.id_master as id', 'a.nomor_po as label'])->pluck('label', 'id')->all();
    }

    private function listCols(): array
    {
        return ['a.id_master as id', 'a.nomor_po', 'a.tanggal_inven as tanggal', 'a.volume_po', 'a.volume_close', 'a.is_close', 'a.harga_tebus', 'a.total_order', 'a.disposisi_po',
            'a.cfo_result', 'a.ceo_result', 'a.revert_cfo', 'a.revert_ceo', 'a.ceo_tanggal', 'a.cfo_tanggal',
            'b.nama_vendor', 'c.jenis_produk', 'c.merk_dagang', 'd.nama_terminal', 'd.tanki_terminal', 'd.lokasi_terminal',
            DB::raw('(select sum(r.volume_bol) from '.self::RI.' r where r.id_po_supplier = a.id_master) as vol_bl'),
            DB::raw('(select sum(r.volume_terima) from '.self::RI.' r where r.id_po_supplier = a.id_master) as vol_terima')];
    }

    public function index(Request $r): JsonResponse
    {
        $q = $this->base();
        $tab = $r->query('tab', 'wait');
        if ($tab === 'wait') {
            self::waiting($q);
        } elseif ($tab === 'done') {
            $q->whereIn('a.disposisi_po', [4, 5])->where('a.ceo_result', 1);
        } else {
            $q->where('a.disposisi_po', '>=', 2);
        }
        if ($term = trim((string) $r->query('q', ''))) {
            $q->where(fn ($w) => $w->where('a.nomor_po', 'like', "%{$term}%")->orWhere('b.nama_vendor', 'like', "%{$term}%")->orWhere('d.nama_terminal', 'like', "%{$term}%"));
        }
        $per = min(max((int) $r->query('per_page', 20), 1), (int) config('orca.per_page_max'));
        $page = $q->orderByDesc('a.tanggal_inven')->orderByDesc('a.id_master')->select($this->listCols())->paginate($per);

        return response()->json([
            'data'    => $page->items(),
            'waiting' => self::waiting($this->base())->count(),
            'meta'    => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage()],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $row = $this->base()
            ->selectRaw('a.*, b.nama_vendor, c.jenis_produk, c.merk_dagang, d.nama_terminal, d.tanki_terminal, d.lokasi_terminal,
                (select sum(rc.volume_bol) from '.self::RI.' rc where rc.id_po_supplier = a.id_master) as volume_bol,
                (select sum(rc.volume_terima) from '.self::RI.' rc where rc.id_po_supplier = a.id_master) as volume_terima')
            ->where('a.id_master', $id)->first();
        if (! $row) {
            return response()->json(['message' => 'PO tidak ditemukan.'], 404);
        }

        $out = (array) $row;
        $out['can_decide'] = $this->canDecide($row);
        $out['gain_loss'] = ($row->volume_ri ?? 0) > 0 ? (float) $row->volume_ri - (float) ($row->volume_bol ?? 0) : null;

        return response()->json($out);
    }

    private function canDecide($row): bool
    {
        return (int) $row->disposisi_po === 2 && (int) $row->cfo_result === 1 && (int) ($row->ceo_result ?? 0) === 0 && (int) ($row->revert_cfo ?? 0) === 0;
    }

    /** Catatan disimpan seperti web: di-escape HTML, baris baru menjadi <br />. */
    private static function note(?string $s): string
    {
        return str_replace(["\r\n", "\r", "\n"], '<br />', htmlspecialchars((string) $s, ENT_QUOTES));
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'keputusan' => ['required', 'in:approve,return'],
            'catatan'   => ['nullable', 'string', 'max:2000'],
        ]);
        $user = $request->user();

        return DB::transaction(function () use ($id, $data, $user) {
            $po = DB::table(self::PO)->where('id_master', $id)->where('is_cancel', 0)->lockForUpdate()->first();
            if (! $po) {
                return response()->json(['message' => 'PO tidak ditemukan.'], 404);
            }
            if (! $this->canDecide($po)) {
                return response()->json(['message' => 'PO ini tidak sedang menunggu verifikasi CEO.', 'code' => 'NOT_PENDING'], 409);
            }

            $note = self::note($data['catatan'] ?? '');
            $now = now();

            if ($data['keputusan'] === 'return') {
                DB::table(self::PO)->where('id_master', $id)->update([
                    'ceo_result' => 1, 'ceo_pic' => $user->fullname, 'ceo_tanggal' => $now,
                    'revert_ceo' => 1, 'revert_ceo_summary' => $note, 'disposisi_po' => 5,
                ]);

                ApprovalMail::toRoles([5], null, 'Pengembalian PO Supplier Crushed Stone [' . ApprovalMail::stamp() . ']',
                    e($user->fullname) . ' meminta anda untuk merevisi ulang PO Crushed Stone');

                return response()->json(['message' => 'PO dikembalikan ke Purchasing.']);
            }

            DB::table(self::PO)->where('id_master', $id)->update([
                'ceo_summary' => $note, 'ceo_result' => 1, 'ceo_pic' => $user->fullname, 'ceo_tanggal' => $now, 'disposisi_po' => 4,
            ]);

            ApprovalMail::toRoles([5], null, 'Persetujuan PO Supplier Crushed Stone [' . ApprovalMail::stamp() . ']',
                e($user->fullname) . ' telah melakukan verifikasi PO Supplier Crushed Stone');

            return response()->json(['message' => 'PO disetujui.']);
        });
    }

    /** Teks tersimpan ter-escape HTML (&amp;, <br />): web menampilkannya sebagai HTML, jadi di cetak diubah ke teks biasa. */
    private static function teks(?string $s): string
    {
        return html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", (string) $s)), ENT_QUOTES);
    }

    /** "1.000" tanpa desimal bila bilangan bulat, selain itu 2 desimal (fmod di template cetak web). */
    private static function pas(float $n): string
    {
        return fmod($n, 1) == 0 ? number_format($n, 0, '.', ',') : number_format($n, 2, '.', ',');
    }

    /**
     * Data cetak PO Supplier Crushed Stone. Sumber: po-crushed-stone-new-terima-syop-view.php (halaman, S&K) dan
     * action/template/po-crushed-stone-supplier-vendor-syop.php (isi). Sub total = harga x volume PO; PPN dan Total Order memakai nilai tersimpan.
     */
    public function cetak(Request $r, int $id): JsonResponse
    {
        $p = DB::table(self::PO.' as a')
            ->join('pro_master_produk as b', 'a.id_produk', '=', 'b.id_master')
            ->join('pro_master_vendor as d', 'a.id_vendor', '=', 'd.id_master')
            ->where('a.id_master', $id)
            ->first(['a.*', 'b.jenis_produk', 'b.merk_dagang', 'd.nama_vendor', 'd.pic_vendor']);
        if (! $p) {
            return response()->json(['message' => 'PO tidak ditemukan.'], 404);
        }
        $hargaDasar = (float) $p->harga_po > 0 ? (float) $p->harga_po : (float) $p->harga_tebus;
        $total = $hargaDasar * (float) $p->volume_po;

        // Halaman 2 (hanya bila ada syarat & ketentuan): satu butir per baris, awalan angka dibuang.
        $syarat = [];
        if (! empty($p->terms_condition)) {
            $raw = htmlspecialchars_decode((string) $p->terms_condition);
            foreach (preg_split('/<br\s*\/?>|\r\n|\n/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) as $line) {
                $t = trim(preg_replace('/^\d+\.\s*/', '', trim($line)));
                if ($t !== '') {
                    $syarat[] = strip_tags($t);
                }
            }
        }

        return response()->json([
            'kepala' => [
                'nomor_po' => $p->nomor_po, 'tanggal' => PoSupplierController::tglIndo($p->tanggal_inven), 'vendor' => $p->nama_vendor, 'pic_vendor' => $p->pic_vendor,
                'terms' => trim($p->terms.' '.$p->terms_day), 'dicetak' => $r->user()->fullname.' '.date('d/m/Y H:i:s').' WIB',
                'penandatangan' => 'Vica Krisdianatha', 'barcode' => '00'.str_pad((string) $id, 6, '0', STR_PAD_LEFT),
            ],
            'baris' => ['item' => $p->jenis_produk, 'deskripsi' => self::teks($p->description), 'qty' => number_format((float) $p->volume_po),
                'harga' => 'IDR '.number_format($hargaDasar, 0, '.', ','), 'pajak' => $p->kd_tax === 'EC' ? '-' : $p->kd_tax, 'jumlah' => 'IDR '.self::pas($total)],
            'catatan' => self::teks($p->keterangan),
            'sub_total' => number_format($total, 2, '.', ','),
            'ppn' => 'IDR '.number_format((float) $p->ppn_11, 2, '.', ','),
            'total_order' => 'IDR '.number_format((float) $p->total_order, 2, '.', ','),
            'terbilang' => Terbilang::inggris((float) $p->total_order),
            'syarat' => $syarat,
        ]);
    }
}
