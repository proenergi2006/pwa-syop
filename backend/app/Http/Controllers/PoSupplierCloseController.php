<?php

namespace App\Http\Controllers;

use App\Services\Accurate\AccurateException;
use App\Services\Accurate\AccuratePoSupplierSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Close PO Supplier. Sumber: vendor-po-close.php (halaman) dan web/action/vendor-po-new.php (aksi close).
 * Volume close = volume PO dikurangi total volume terima. Cancel PO tidak punya halaman/tombol untuk Procurement di web, jadi tidak dibawa.
 */
class PoSupplierCloseController extends Controller
{
    private const PO = 'new_pro_inventory_vendor_po';

    private static function fail(string $m, string $c, int $s)
    {
        abort(response()->json(['message' => $m, 'code' => $c], $s));
    }

    /** PO yang boleh di-close: tombol Close di web (disetujui CEO, tidak dikembalikan, belum close/cancel). */
    private function poBisaClose(int $id): object
    {
        $po = DB::table(self::PO.' as a')
            ->join('pro_master_produk as b', 'a.id_produk', '=', 'b.id_master')->join('pro_master_vendor as d', 'a.id_vendor', '=', 'd.id_master')->join('pro_master_terminal as e', 'a.id_terminal', '=', 'e.id_master')
            ->where('a.id_master', $id)->first(['a.*', 'b.jenis_produk', 'b.merk_dagang', 'd.nama_vendor', 'e.nama_terminal', 'e.tanki_terminal', 'e.lokasi_terminal']);
        if (! $po) {
            self::fail('PO Supplier tidak ditemukan.', 'NOT_FOUND', 404);
        }
        if ((int) $po->is_close === 1 || (int) $po->is_cancel === 1 || (int) $po->ceo_result !== 1 || (int) ($po->revert_ceo ?? 0) !== 0) {
            self::fail('PO ini belum disetujui atau sudah close/cancel.', 'TIDAK_BISA_CLOSE', 409);
        }

        return $po;
    }

    private static function volumeClose(object $po): float
    {
        return (float) $po->volume_po - (float) DB::table('new_pro_inventory_vendor_po_receive')->where('id_po_supplier', $po->id_master)->sum('volume_terima');
    }

    public function form(int $id): JsonResponse
    {
        $po = $this->poBisaClose($id);

        return response()->json([
            'id' => (int) $po->id_master, 'nomor_po' => $po->nomor_po, 'tanggal' => $po->tanggal_inven, 'vendor' => $po->nama_vendor, 'produk' => $po->jenis_produk.' - '.$po->merk_dagang,
            'terminal' => trim($po->nama_terminal.($po->tanki_terminal ? ' - '.$po->tanki_terminal : '').($po->lokasi_terminal ? ', '.$po->lokasi_terminal : '')),
            'volume_po' => (float) $po->volume_po, 'volume_terima' => (float) $po->volume_po - self::volumeClose($po), 'volume_close' => self::volumeClose($po),
        ]);
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $po = $this->poBisaClose($id);
        $d = $request->validate(['tanggal' => ['required', 'date_format:Y-m-d']]);
        $volume = self::volumeClose($po);
        $sync = app(AccuratePoSupplierSync::class);
        $pakaiAccurate = $sync->enabled() && ! empty($po->id_accurate);
        try {
            DB::transaction(function () use ($po, $d, $volume, $sync, $pakaiAccurate, $request, $id) {
                DB::table(self::PO)->where('id_master', $id)->update(['is_close' => 1, 'tanggal_close' => $d['tanggal'], 'volume_close' => $volume]);
                if ($pakaiAccurate) {
                    $cabang = DB::table('pro_master_cabang')->where('id_master', $request->user()->id_wilayah)->value('nama_cabang');
                    $sync->tutup($po->id_accurate, strtoupper((string) $po->nama_terminal).' - '.$po->lokasi_terminal, $cabang,
                        'CLOSE PO '.date('d/m/Y', strtotime($d['tanggal'])).' - volume close = '.$volume);
                }
            });
        } catch (AccurateException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'ACCURATE'], 502);
        }

        return response()->json(['message' => 'PO di-close.', 'accurate_manual' => ! empty($po->id_accurate) && ! $sync->enabled()]);
    }
}
