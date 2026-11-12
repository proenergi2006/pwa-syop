<?php

namespace App\Http\Controllers;

use App\Services\Accurate\AccurateException;
use App\Services\Accurate\AccuratePoSync;
use App\Services\PasswordGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verifikasi PO Supplier tahap CEO (sumber: verifikasi-po*.php, __get_data_po_ceo2.php, po-supplier.php).
 * disposisi_po: 1 = Purchasing/CFO, 2 = menunggu CEO, 4 = disetujui, 5 = dikembalikan ke Purchasing.
 */
class PoController extends Controller
{
    private const PO = 'new_pro_inventory_vendor_po';

    private function base()
    {
        return DB::table(self::PO.' as a')
            ->join('pro_master_vendor as b', 'a.id_vendor', '=', 'b.id_master')
            ->join('pro_master_produk as c', 'a.id_produk', '=', 'c.id_master')
            ->join('pro_master_terminal as d', 'a.id_terminal', '=', 'd.id_master')
            ->where('a.is_cancel', 0);
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

    private function listCols(): array
    {
        return ['a.id_master as id', 'a.nomor_po', 'a.tanggal_inven as tanggal', 'a.volume_po', 'a.harga_tebus', 'a.total_order', 'a.disposisi_po',
            'a.cfo_result', 'a.ceo_result', 'a.revert_cfo', 'a.revert_ceo', 'a.ceo_tanggal', 'a.jenis_kirim',
            'b.nama_vendor', 'c.jenis_produk', 'c.merk_dagang', 'd.nama_terminal', 'd.tanki_terminal'];
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

    private function history(int $id)
    {
        return DB::table('new_pro_inventory_vendor_po_history as h')
            ->join('pro_master_produk as c', 'h.id_produk', '=', 'c.id_master')
            ->join('pro_master_vendor as b', 'h.id_vendor', '=', 'b.id_master')
            ->join('pro_master_terminal as d', 'h.id_terminal', '=', 'd.id_master')
            ->where('h.id_po_supplier', $id)->orderByDesc('h.id_master')
            ->get(['h.*', 'b.nama_vendor', 'c.jenis_produk', 'c.merk_dagang', 'd.nama_terminal', 'd.tanki_terminal', 'd.lokasi_terminal']);
    }

    public function show(int $id): JsonResponse
    {
        $row = $this->base()
            ->selectRaw('a.*, b.nama_vendor, c.jenis_produk, c.merk_dagang, d.nama_terminal, d.tanki_terminal, d.lokasi_terminal,
                (select sum(rc.volume_bol) from new_pro_inventory_vendor_po_receive rc where rc.id_po_supplier = a.id_master) as volume_bol')
            ->where('a.id_master', $id)->first();
        if (! $row) {
            return response()->json(['message' => 'PO tidak ditemukan.'], 404);
        }

        $hist = $this->history($id);
        $last = $hist->first();
        $changes = [];
        if ($last) {
            foreach (['nama_vendor', 'merk_dagang', 'nama_terminal', 'harga_tebus', 'ongkos_angkut', 'kategori_plat', 'subtotal', 'ppn_11', 'pph_22', 'pbbkb', 'nominal_migas', 'total_order', 'kd_tax', 'terms', 'terms_day'] as $f) {
                $a = $last->$f ?? null;
                $b = $row->$f ?? null;
                if (is_numeric($a) && is_numeric($b) ? (float) $a !== (float) $b : trim((string) $a) !== trim((string) $b)) {
                    $changes[$f] = $a;
                }
            }
        }

        $out = (array) $row;
        $out['can_decide'] = $this->canDecide($row);
        $out['harga_berubah'] = Schema::hasColumn(self::PO, 'is_price_changed') ? (int) ($row->is_price_changed ?? 0) === 1 : false;
        $out['sebelumnya'] = $changes; // nilai lama dari pengajuan sebelumnya, hanya untuk field yang berubah
        $out['riwayat'] = $hist->map(fn ($h) => [
            'tanggal' => $h->created_time ?? null, 'nomor_po' => $h->nomor_po, 'nama_vendor' => $h->nama_vendor, 'produk' => $h->jenis_produk.' - '.$h->merk_dagang,
            'volume_po' => $h->volume_po, 'harga_tebus' => $h->harga_tebus, 'subtotal' => $h->subtotal, 'total_order' => $h->total_order,
            'terms' => $h->terms, 'terms_day' => $h->terms_day, 'keterangan' => $h->keterangan,
        ])->values();
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
        if (! $user->tokenCan('*')) {
            $password = (string) $request->input('password', '');
            if ($password === '') {
                return response()->json(['message' => 'Masukkan kata sandi untuk memutuskan.', 'code' => 'STEP_UP_REQUIRED'], 403);
            }
            if (! PasswordGate::check($user, $password, $request)) {
                return PasswordGate::locked($user)
                    ? response()->json(['message' => 'Terlalu banyak salah kata sandi. Coba lagi 15 menit lagi.', 'code' => 'PASSWORD_LOCKED'], 429)
                    : response()->json(['message' => 'Kata sandi salah.', 'code' => 'STEP_UP_FAILED'], 422);
            }
        }
        if ($data['keputusan'] === 'return' && trim((string) ($data['catatan'] ?? '')) === '') {
            return response()->json(['message' => 'Catatan pengembalian wajib diisi.', 'code' => 'NOTE_REQUIRED'], 422);
        }

        try {
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
                DB::table('new_pro_inventory_gain_loss')->where('id_po_supplier', $id)->update([
                    'ceo_result' => 1, 'ceo_summary' => $note, 'disposisi_gain_loss' => 3,
                ]);

                return response()->json(['message' => 'PO dikembalikan ke Purchasing.']);
            }

            // Perubahan harga memerlukan penyesuaian DR/PR dan Accurate; belum didukung di mobile.
            if (Schema::hasColumn(self::PO, 'is_price_changed') && (int) ($po->is_price_changed ?? 0) === 1) {
                return response()->json(['message' => 'PO ini mengubah harga. Persetujuannya perlu lewat web (belum didukung di mobile).', 'code' => 'PRICE_CHANGED'], 409);
            }
            if (! config('orca.po_allow_without_accurate') && $po->id_accurate) {
                return response()->json(['message' => 'Persetujuan PO yang tercatat di Accurate belum didukung di mobile.', 'code' => 'ACCURATE_UNSUPPORTED'], 409);
            }

            DB::table(self::PO)->where('id_master', $id)->update([
                'ceo_summary' => $note, 'ceo_result' => 1, 'ceo_pic' => $user->fullname, 'ceo_tanggal' => $now, 'disposisi_po' => 4,
            ]);
            DB::table('new_pro_inventory_gain_loss')->where('id_po_supplier', $id)->update([
                'ceo_result' => 1, 'ceo_pic' => $user->fullname, 'ceo_summary' => $note, 'ceo_tanggal' => $now, 'disposisi_gain_loss' => 2,
            ]);

            // Accurate aktif: buka kembali PO di Accurate seperti web (cabang = cabang CEO). Gagal = persetujuan dibatalkan.
            $sync = app(AccuratePoSync::class);
            if ($sync->enabled()) {
                $sync->reopen($po->id_accurate, DB::table('pro_master_cabang')->where('id_master', $user->id_wilayah)->value('nama_cabang'));
            }

            return response()->json([
                'message'         => 'PO disetujui.',
                'accurate_manual' => $po->id_accurate && ! $sync->enabled(), // Accurate belum aktif: PO perlu dibuka manual di Accurate
            ]);
            });
        } catch (AccurateException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'ACCURATE'], 502);
        }
    }
}
