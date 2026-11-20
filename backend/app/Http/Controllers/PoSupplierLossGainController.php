<?php

namespace App\Http\Controllers;

use App\Services\Accurate\AccurateException;
use App\Services\Accurate\AccurateLossGainSync;
use App\Services\LegacyFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add Losses & Gain PO Supplier. Sumber: vendor-po-loss-gain.php (form dan rumus) dan web/action/vendor-po-new-loss-gain.php (simpan).
 * Semua angka dihitung ulang di server dari data PO dan RI; kiriman klien tidak dipercaya untuk nilai uang.
 */
class PoSupplierLossGainController extends Controller
{
    private const PO = 'new_pro_inventory_vendor_po';

    private function po(int $id): ?object
    {
        return DB::table(self::PO.' as a')
            ->join('pro_master_produk as b', 'a.id_produk', '=', 'b.id_master')
            ->join('pro_master_vendor as d', 'a.id_vendor', '=', 'd.id_master')
            ->join('pro_master_terminal as e', 'a.id_terminal', '=', 'e.id_master')
            ->where('a.id_master', $id)
            ->first(['a.*', 'b.jenis_produk', 'b.merk_dagang', 'd.nama_vendor', 'd.kode_vendor', 'e.nama_terminal', 'e.tanki_terminal', 'e.lokasi_terminal', 'e.id_cabang']);
    }

    private static function fail(string $m, string $c = 'INVALID', int $s = 422)
    {
        abort(response()->json(['message' => $m, 'code' => $c], $s));
    }

    /** Rumus dari vendor-po-loss-gain.php (PHP awal + hitungTotal() JS), dibulatkan 4 desimal seperti toFixed(4). */
    private function hitung(object $po): array
    {
        $ri = DB::table('new_pro_inventory_vendor_po_receive')->where('id_po_supplier', $po->id_master)->get(['volume_bol', 'volume_terima']);
        $volRi = (float) $ri->sum('volume_terima');
        $volBl = (float) $ri->sum('volume_bol');
        $harga = (float) $po->harga_tebus;
        $hargaRi = $volRi > $volBl && $volRi > 0 ? $volBl * $harga / $volRi : $harga;
        $sub = $volRi * $hargaRi;
        $pbbkb = $sub * (float) $po->nilai_pbbkb / 100;
        $ppn = 11 * $sub / 100;
        $pph = $po->kd_tax === 'EC' ? $sub * 0.3 / 100 : 0.0;

        return [
            'jumlah_ri' => $ri->count(), 'volume_po' => (float) $po->volume_po, 'volume_bl' => $volBl, 'volume_ri' => $volRi,
            'harga_dasar' => $harga, 'harga_ri' => round($hargaRi, 4), 'subtotal' => round($sub, 4), 'ppn_11' => round($ppn, 4), 'pph_22' => round($pph, 4),
            'pbbkb_po' => (float) $po->pbbkb, 'pbbkb' => round($pbbkb, 4), 'total_order' => round($sub + $ppn + $pph + $pbbkb, 4),
            'volume_loss_gain' => $volRi - $volBl,
        ];
    }

    /** Syarat tombol di web: PO disetujui CEO, belum close/cancel, total terima > 0 dan tidak sama dengan volume PO. */
    private function cekBisa(object $po, array $h): void
    {
        if ((int) $po->is_close === 1 || (int) $po->is_cancel === 1 || (int) $po->ceo_result !== 1 || (int) ($po->revert_ceo ?? 0) !== 0) {
            self::fail('PO ini belum disetujui atau sudah close/cancel.', 'PO_TERKUNCI', 409);
        }
        if ($h['volume_ri'] <= 0 || $h['volume_ri'] == $h['volume_po']) {
            self::fail('Losses & Gain hanya dapat dibuat bila volume terima sudah ada dan berbeda dari volume PO.', 'TIDAK_PERLU', 409);
        }
    }

    public function form(int $id): JsonResponse
    {
        $po = $this->po($id);
        if (! $po) {
            return response()->json(['message' => 'PO Supplier tidak ditemukan.'], 404);
        }
        $h = $this->hitung($po);
        $this->cekBisa($po, $h);

        return response()->json($h + [
            'po' => ['id' => (int) $po->id_master, 'nomor_po' => $po->nomor_po, 'tanggal' => $po->tanggal_inven, 'produk' => $po->jenis_produk.' - '.$po->merk_dagang,
                'terminal' => trim($po->nama_terminal.($po->tanki_terminal ? ' - '.$po->tanki_terminal : '').($po->lokasi_terminal ? ', '.$po->lokasi_terminal : '')),
                'vendor' => $po->nama_vendor, 'kd_tax' => $po->kd_tax, 'keterangan' => $po->keterangan, 'punya_accurate' => ! empty($po->id_accurate)],
            'jenis_default' => $h['volume_loss_gain'] >= 0 ? 1 : 2,
        ]);
    }

    public function akun(Request $r): JsonResponse
    {
        $sync = app(AccurateLossGainSync::class);
        if (! $sync->enabled()) {
            return response()->json(['items' => [], 'total' => 0, 'accurate' => false]);
        }
        try {
            return response()->json($sync->akun(trim((string) $r->query('q', '')), max(1, (int) $r->query('page', 1))) + ['accurate' => true]);
        } catch (AccurateException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'ACCURATE'], 502);
        }
    }

    public function lampiran(int $id, int $gl)
    {
        $g = DB::table('new_pro_inventory_gain_loss')->where('id_po_supplier', $id)->where('id_master', $gl)->first(['file_upload', 'file_upload_ori']);

        return LegacyFile::response($g && $g->file_upload ? 'lampiran/'.$g->file_upload : null, $g->file_upload_ori ?? 'lampiran');
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $po = $this->po($id);
        if (! $po) {
            return response()->json(['message' => 'PO Supplier tidak ditemukan.'], 404);
        }
        $h = $this->hitung($po);
        $this->cekBisa($po, $h);
        $d = $request->validate([
            'jenis' => ['required', 'in:1,2'], 'ket' => ['required', 'string', 'max:2000'], 'ket_loss_gain' => ['required', 'string', 'max:2000'],
            'akun_penyesuaian' => ['required_if:jenis,2', 'nullable', 'string', 'max:50'], 'nomor_penyesuaian' => ['required_if:jenis,2', 'nullable', 'string', 'max:100'],
            'file_template' => ['nullable', 'file', 'max:5120', 'mimes:pdf'],
        ]);
        $jenis = (int) $d['jenis'];
        $user = $request->user();
        $ip = $request->ip();
        $sync = app(AccurateLossGainSync::class);
        $pakaiAccurate = $sync->enabled() && ! empty($po->id_accurate);
        $receives = DB::table('new_pro_inventory_vendor_po_receive')->where('id_po_supplier', $id)->orderBy('id_po_receive')->get();
        if ($jenis === 1 && $pakaiAccurate && $receives->count() > 1) {
            self::fail('Gain pada PO dengan lebih dari satu penerimaan belum didukung untuk sinkron Accurate.', 'MULTI_RI', 409);
        }

        $berkas = $request->file('file_template');
        $fileName = $fileAsli = '';
        if ($berkas) {
            $ext = strtolower($berkas->getClientOriginalExtension() ?: 'pdf');
            $fileName = date('Ym').'/BOL_'.$id.'_'.md5(basename($berkas->getClientOriginalName(), $ext)).'.'.$ext;
            $fileAsli = preg_replace('/[^\w.\- ]+/u', '_', basename($berkas->getClientOriginalName())) ?: 'lampiran.pdf';
            if (! LegacyFile::store($berkas, 'lampiran/'.$fileName)) {
                self::fail('Lampiran tidak dapat disimpan di server (folder unggahan tidak bisa ditulis).', 'UPLOAD_GAGAL', 500);
            }
        }

        $cabangUser = DB::table('pro_master_cabang')->where('id_master', $user->id_wilayah)->value('nama_cabang');
        $cabangTerminal = DB::table('pro_master_cabang')->where('id_master', $po->id_cabang)->first(['inisial_cabang', 'nama_cabang']);
        $accurateManual = false;

        try {
            DB::transaction(function () use ($po, $h, $d, $jenis, $id, $user, $ip, $sync, $pakaiAccurate, $receives, $cabangUser, $cabangTerminal, $fileName, $fileAsli, &$accurateManual) {
                $now = now();
                if ($jenis === 1) {
                    $set = [
                        'harga_tebus' => $h['harga_ri'], 'harga_po' => $h['harga_dasar'], 'volume_ri' => $h['volume_ri'], 'disposisi_po' => 1,
                        'cfo_result' => 0, 'cfo_pic' => '', 'cfo_summary' => '', 'ceo_result' => 0, 'ceo_pic' => '', 'ceo_summary' => '',
                        'volume_po' => $h['volume_po'], 'subtotal' => $h['subtotal'], 'ppn_11' => $h['ppn_11'], 'pph_22' => $h['pph_22'], 'pbbkb' => $h['pbbkb'],
                        'pbbkb_po' => $h['pbbkb_po'], 'total_order' => $h['total_order'], 'keterangan' => $d['ket'],
                        'lastupdate_time' => $now, 'lastupdate_ip' => $ip, 'lastupdate_by' => $user->fullname,
                    ];
                    DB::table(self::PO)->where('id_master', $id)->update($set);
                }
                DB::table('new_pro_inventory_gain_loss')->insert([
                    'id_po_supplier' => $id, 'volume_po' => $h['volume_po'], 'volume_terima' => $h['volume_ri'], 'jenis' => $jenis, 'volume' => $h['volume_loss_gain'],
                    'file_upload' => $fileName, 'file_upload_ori' => $fileAsli, 'ket' => $d['ket_loss_gain'], 'disposisi_gain_loss' => 1,
                    'created_time' => $now, 'created_ip' => $ip, 'created_by' => $user->fullname,
                ]);

                if (empty($po->id_accurate)) {
                    return; // web: tanpa id Accurate hanya baris gain/loss (dan pembaruan PO untuk gain)
                }
                $vendorNo = (string) $po->kode_vendor;
                $receive = $receives->first();
                if ($jenis === 1) {
                    if ($pakaiAccurate) {
                        $acc = $sync->gain(['po' => $po, 'vendorNo' => $vendorNo, 'receive' => $receive, 'hargaRi' => $h['harga_ri'], 'volume' => $h['volume_loss_gain'],
                            'pbbkb' => $h['pbbkb'], 'pph22' => $h['pph_22'], 'ket' => $d['ket'], 'cabangUser' => $cabangUser, 'namaCabangTerminal' => $cabangTerminal->nama_cabang ?? null]);
                        DB::table(self::PO)->where('id_master', $id)->update(['id_accurate' => $acc['id_accurate_po']]);
                        DB::table('new_pro_inventory_vendor_po_receive')->where('id_po_supplier', $id)->update(['id_accurate' => $acc['id_accurate_ri']]);
                    } else {
                        $accurateManual = true;
                    }

                    return;
                }
                // Loss: penyesuaian stok (baris depot jenis 3) selalu dicatat bila PO tercatat di Accurate; Accurate hanya bila aktif
                $kodeItem = $pakaiAccurate ? $sync->lossTutup($po, $vendorNo, $cabangUser, $d['ket']) : null;
                if ($pakaiAccurate && ! $kodeItem) {
                    throw new AccurateException('Kode item inventory tidak ditemukan di PO Accurate');
                }
                $vol = abs($h['volume_loss_gain']);
                $ketAdj = 'Loss PO '.$po->nomor_po.($d['ket_loss_gain'] ? ' - '.$d['ket_loss_gain'] : '');
                $idDepot = DB::table('new_pro_inventory_depot')->insertGetId([
                    'id_datanya' => md5(uniqid('1089', true).'-'.time().'-'.date('YmdHis')), 'id_jenis' => 3, 'id_produk' => $po->id_produk, 'id_terminal' => $po->id_terminal,
                    'id_po_supplier' => $id, 'id_po_receive' => $receives->last()->id_po_receive ?? null, 'tanggal_inven' => date('Y-m-d'), 'adj_inven' => -$vol, 'keterangan' => $ketAdj,
                    'created_time' => $now, 'created_ip' => $ip, 'created_by' => $user->fullname, 'lastupdate_time' => $now, 'lastupdate_ip' => $ip, 'lastupdate_by' => $user->fullname,
                ]);
                if ($pakaiAccurate) {
                    $idAdj = $sync->penyesuaian($d['nomor_penyesuaian'], $d['akun_penyesuaian'], $ketAdj, $cabangUser, $kodeItem, $vol, $cabangTerminal->inisial_cabang ?? null, $cabangTerminal->nama_cabang ?? null);
                    DB::table('new_pro_inventory_depot')->where('id_master', $idDepot)->update(['id_accurate' => $idAdj]);
                } else {
                    $accurateManual = true;
                }
            });
        } catch (\Throwable $e) {
            LegacyFile::delete($fileName ? 'lampiran/'.$fileName : null);
            if ($e instanceof AccurateException) {
                return response()->json(['message' => $e->getMessage(), 'code' => 'ACCURATE'], 502);
            }
            throw $e;
        }

        return response()->json([
            'message' => $jenis === 1 ? 'Gain tersimpan. PO kembali ke verifikasi.' : 'Loss tersimpan.',
            'accurate_manual' => $accurateManual,
        ], 201);
    }
}
