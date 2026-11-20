<?php

namespace App\Http\Controllers;

use App\Services\Accurate\AccurateException;
use App\Services\Accurate\AccurateRiSync;
use App\Services\LegacyFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Terima barang (RI) pada PO Supplier. Sumber: vendor-po-new-terima.php (daftar), vendor-po-new-terima-add.php (form),
 * web/action/vendor-po-new-terima.php (tambah, ubah, hapus), cek-validasi.php, get-detail-stock.php, get-detail-receive.php.
 * Loss/gain ada di PoSupplierLossGainController.
 */
class PoSupplierTerimaController extends Controller
{
    private const PO = 'new_pro_inventory_vendor_po';

    private const RI = 'new_pro_inventory_vendor_po_receive';

    private function po(int $id): ?object
    {
        return DB::table(self::PO.' as a')
            ->join('pro_master_produk as b', 'a.id_produk', '=', 'b.id_master')
            ->join('pro_master_vendor as d', 'a.id_vendor', '=', 'd.id_master')
            ->join('pro_master_terminal as e', 'a.id_terminal', '=', 'e.id_master')
            ->where('a.id_master', $id)
            ->first(['a.*', 'b.jenis_produk', 'b.merk_dagang', 'd.nama_vendor', 'e.nama_terminal', 'e.tanki_terminal', 'e.lokasi_terminal', 'e.id_cabang']);
    }

    private static function terkunci(object $po): bool
    {
        return (int) $po->is_close === 1 || (int) $po->is_cancel === 1;
    }

    /** Hanya PO yang sudah disetujui CEO (tidak dikembalikan) dan belum close/cancel yang boleh diubah penerimaannya. */
    private static function bisaUbahRi(object $po): bool
    {
        return ! self::terkunci($po) && (int) $po->ceo_result === 1 && (int) ($po->revert_ceo ?? 0) === 0;
    }

    private static function fail(string $m, string $c = 'INVALID', int $s = 422)
    {
        abort(response()->json(['message' => $m, 'code' => $c], $s));
    }

    private static function terminalTeks(object $p): string
    {
        return trim($p->nama_terminal.($p->tanki_terminal ? ' - '.$p->tanki_terminal : '').($p->lokasi_terminal ? ', '.$p->lokasi_terminal : ''));
    }

    private function totalTerima(int $id): float
    {
        return (float) DB::table(self::RI)->where('id_po_supplier', $id)->sum('volume_terima');
    }

    private function headerPo(object $po): array
    {
        $kode = null;
        $sync = app(AccurateRiSync::class);
        if ($sync->enabled() && ! empty($po->id_accurate)) {
            try {
                $kode = $sync->itemPo($po->id_accurate)['item'];
            } catch (AccurateException) {
                $kode = null;
            }
        }

        return [
            'id' => (int) $po->id_master, 'nomor_po' => $po->nomor_po, 'tanggal' => $po->tanggal_inven, 'produk' => $po->jenis_produk.' - '.$po->merk_dagang,
            'terminal' => self::terminalTeks($po), 'vendor' => $po->nama_vendor, 'volume_po' => (float) $po->volume_po,
            'harga_tebus' => (float) $po->harga_tebus, 'kode_item_accurate' => $kode, 'terkunci' => self::terkunci($po),
        ];
    }

    /** Daftar RI + tabel gain/loss (hanya lihat) + tombol yang boleh tampil. */
    public function index(int $id): JsonResponse
    {
        $po = $this->po($id);
        if (! $po) {
            return response()->json(['message' => 'PO Supplier tidak ditemukan.'], 404);
        }
        $bisaUbah = self::bisaUbahRi($po);
        $hasUpd = Schema::hasColumn(self::RI, 'updated_count');
        $ri = DB::table(self::RI.' as a')
            ->leftJoin('new_pro_inventory_depot as d1', fn ($j) => $j->on('a.id_po_supplier', '=', 'd1.id_po_supplier')->on('a.id_po_receive', '=', 'd1.id_po_receive')->where('d1.id_jenis', 1))
            ->where('a.id_po_supplier', $id)->orderBy('a.id_po_receive')
            ->get(['a.*', 'd1.id_master as stok_awal'])
            ->map(function ($x) use ($bisaUbah, $hasUpd) {
                $upd = $hasUpd ? (int) ($x->updated_count ?? 0) : 0;
                $ubah = $bisaUbah && ! $x->stok_awal && $upd < 2;
                $hapus = $bisaUbah && ! $x->stok_awal;

                return [
                    'id' => (int) $x->id_po_receive, 'no_terima' => $x->no_terima, 'tgl_terima' => $x->tgl_terima, 'nama_pic' => $x->nama_pic,
                    'harga_tebus' => (float) $x->harga_tebus, 'volume_bol' => (float) $x->volume_bol, 'volume_terima' => (float) $x->volume_terima,
                    'lampiran' => $x->file_upload_ori && LegacyFile::resolve('lampiran/'.$x->file_upload) ? $x->file_upload_ori : null,
                    'diubah' => $upd > 0 && ! $x->stok_awal ? 'Last updated by '.$x->lastupdate_by.' - '.$x->lastupdate_time : null,
                    'ada_log' => $upd > 0 && ! $x->stok_awal, 'bisa_ubah' => $ubah, 'bisa_hapus' => $hapus,
                ];
            })->values();
        $total = (float) $ri->sum('volume_terima');
        $vol = (float) $po->volume_po;
        $gl = DB::table('new_pro_inventory_gain_loss')->where('id_po_supplier', $id)->orderBy('id_master')->get()->map(fn ($g) => [
            'id' => (int) $g->id_master, 'lampiran' => $g->file_upload_ori && LegacyFile::resolve('lampiran/'.$g->file_upload) ? $g->file_upload_ori : null,
            'jenis' => (int) $g->jenis === 1 ? 'Gain' : ((int) $g->jenis === 2 ? 'Loss' : ''), 'volume' => (float) $g->volume, 'ket' => $g->ket,
            'status' => match ((int) $g->disposisi_gain_loss) {
                1 => 'Verifikasi CEO', 3 => 'Dikembalikan', 2 => 'Terverifikasi '.date('d/m/Y H:i:s', strtotime((string) $g->ceo_tanggal)).' WIB', default => '',
            },
        ])->values();

        return response()->json([
            'po' => $this->headerPo($po), 'ri' => $ri, 'total_terima' => $total, 'gain_loss' => $gl,
            'bisa_tambah' => $bisaUbah && $total < $vol,
            'bisa_loss_gain' => $bisaUbah && $total > 0 && $total != $vol,
        ]);
    }

    /** Volume terpakai (ringkasan + rincian DO), setara vendor-po-new-terima-add.php dan get-detail-stock.php. */
    private function terpakai(int $idPo, float $volumePo): array
    {
        $s = DB::selectOne("
            SELECT SUM(total_keluar) AS keluar, SUM(total_cancel) AS cancel, SUM(total_volume) AS total FROM (
                SELECT SUM(CASE WHEN dsd.is_cancel = 0 AND dsd.realisasi_volume != 0 THEN a.vol_potongan ELSE 0 END) AS total_keluar,
                       SUM(CASE WHEN dsd.is_cancel = 1 THEN a.vol_potongan ELSE 0 END) AS total_cancel, SUM(a.vol_potongan) AS total_volume
                FROM pro_pr_detail a JOIN pro_po_ds_detail dsd ON a.id_prd = dsd.id_prd
                WHERE a.id_po_supplier = ? AND NOT EXISTS (SELECT 1 FROM new_pro_inventory_potongan_stock b WHERE b.id_prd = a.id_prd)
                UNION ALL
                SELECT SUM(b.volume), 0, SUM(b.volume) FROM new_pro_inventory_potongan_stock b WHERE b.id_po_supplier = ?
            ) x", [$idPo, $idPo]);
        $detail = DB::select("
            SELECT a.no_do_syop, a.vol_potongan AS volume, IF(dsd.is_cancel = 1,'cancel','-') AS is_cancel, IF(a.is_split = 1,'split','-') AS is_split
            FROM pro_pr_detail a JOIN pro_po_ds_detail dsd ON a.id_prd = dsd.id_prd WHERE a.id_po_supplier = ?
            UNION ALL
            SELECT c.no_do_syop, b.volume, IF(d.is_cancel = 1,'cancel','-'), IF(c.is_split = 1,'split','-')
            FROM new_pro_inventory_potongan_stock b JOIN pro_pr_detail c ON b.id_prd = c.id_prd JOIN pro_po_ds_detail d ON c.id_prd = d.id_prd WHERE b.id_po_supplier = ?", [$idPo, $idPo]);

        return [
            'total' => (float) ($s->total ?? 0), 'keluar' => (float) ($s->keluar ?? 0), 'cancel' => (float) ($s->cancel ?? 0),
            'sisa' => $volumePo - (float) ($s->keluar ?? 0),
            'detail' => array_map(fn ($r) => ['no_do' => $r->no_do_syop, 'volume' => (float) $r->volume, 'cancel' => $r->is_cancel === 'cancel', 'split' => $r->is_split === 'split'], $detail),
        ];
    }

    /** Nilai awal form: tambah (ri kosong) mengikuti PO, ubah mengikuti RI. */
    public function form(int $id, ?int $ri = null): JsonResponse
    {
        $po = $this->po($id);
        if (! $po) {
            return response()->json(['message' => 'PO Supplier tidak ditemukan.'], 404);
        }
        $out = ['po' => $this->headerPo($po)];
        if ($ri === null) {
            $out += ['mode' => 'tambah', 'tgl_terima' => '', 'no_terima' => '', 'harga_tebus' => (float) $po->harga_tebus,
                'volume_bol' => (float) $po->volume_po, 'volume_terima' => (float) $po->volume_po, 'nama_pic' => ''];

            return response()->json($out);
        }
        $r = DB::table(self::RI)->where('id_po_supplier', $id)->where('id_po_receive', $ri)->first();
        if (! $r) {
            return response()->json(['message' => 'Penerimaan tidak ditemukan.'], 404);
        }
        $out += ['mode' => 'ubah', 'tgl_terima' => $r->tgl_terima, 'no_terima' => $r->no_terima, 'harga_tebus' => (float) $r->harga_tebus,
            'volume_bol' => (float) $r->volume_bol, 'volume_terima' => (float) $r->volume_terima, 'nama_pic' => $r->nama_pic,
            'lampiran' => $r->file_upload_ori && LegacyFile::resolve('lampiran/'.$r->file_upload) ? $r->file_upload_ori : null,
            'terpakai' => $this->terpakai($id, (float) $po->volume_po)];

        return response()->json($out);
    }

    public function riwayat(int $id, int $ri): JsonResponse
    {
        if (! Schema::hasTable('new_pro_inventory_vendor_po_receive_history')) {
            return response()->json(['data' => []]);
        }
        $h = DB::table('new_pro_inventory_vendor_po_receive_history')->where('id_po_supplier', $id)->where('id_po_receive', $ri)->orderBy('created_time')->get()
            ->map(fn ($x) => [
                'dibuat' => $x->created_time.' / '.$x->created_by, 'tgl_terima' => $x->tgl_terima, 'harga_tebus' => (float) $x->harga_tebus,
                'volume_bol' => (float) $x->volume_bol, 'volume_terima' => (float) $x->volume_terima, 'nama_pic' => $x->nama_pic,
                'keterangan' => $x->keterangan_updated ?? null,
            ])->values();

        return response()->json(['data' => $h]);
    }

    public function lampiran(int $id, int $ri)
    {
        $r = DB::table(self::RI)->where('id_po_supplier', $id)->where('id_po_receive', $ri)->first(['file_upload', 'file_upload_ori']);

        return LegacyFile::response($r && $r->file_upload ? 'lampiran/'.$r->file_upload : null, $r->file_upload_ori ?? 'lampiran');
    }

    /** Nama berkas seperti web: YYYYMM/terimaposupplier_<id RI>_<md5 nama>.<ekstensi>; nama asli dibersihkan dari jalur dan karakter aneh. */
    private static function namaBerkas(UploadedFile $f, string $idRi): array
    {
        $asli = $f->getClientOriginalName();
        $ext = strtolower(pathinfo($asli, PATHINFO_EXTENSION));

        return [date('Ym').'/terimaposupplier_'.$idRi.'_'.md5(basename($asli, $ext)).'.'.$ext, preg_replace('/[^\w.\- ]+/u', '_', basename($asli)) ?: 'lampiran'];
    }

    private function nextId(): string
    {
        $prefix = date('Ym');
        try {
            $n = DB::selectOne('select LPAD(cast((select nextval(new_pro_inventory_vendor_po_receive_seq)) as char(10)), 9, \'0\') as n')->n;
        } catch (\Throwable) {
            $max = DB::table(self::RI)->where('id_po_receive', 'like', $prefix.'%')->max('id_po_receive');
            $n = str_pad((string) (((int) substr((string) $max, 6)) + 1), 9, '0', STR_PAD_LEFT);
        }

        return $prefix.$n;
    }

    private function validasi(Request $r, bool $ubah): array
    {
        $rules = [
            'tgl_terima' => ['required', 'date_format:Y-m-d'], 'no_terima' => ['required', 'string', 'max:255'],
            'volume_bol' => ['required', 'integer', 'min:0'], 'volume_terima' => ['required', 'integer', 'min:0'], 'nama_pic' => ['required', 'string', 'max:300'],
        ];
        $rules['file_template'] = ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx'];
        if ($ubah) {
            $rules['ket_updated'] = ['required', 'string', 'max:2000'];
        }

        return $r->validate($rules);
    }

    /** cek-validasi.php: tanggal terima tidak boleh lebih kecil dari stok awal terminal. */
    private function cekTanggal(object $po, string $tgl): void
    {
        $awal = DB::table('new_pro_inventory_depot')->where('id_terminal', $po->id_terminal)->where('id_jenis', 1)->value('tanggal_inven');
        if ($awal && strtotime($tgl) < strtotime($awal)) {
            self::fail('Tanggal terima lebih kecil dari data awal ( '.date('d/m/Y', strtotime($awal)).' ) untuk terminal ini', 'TANGGAL');
        }
    }

    private function cabangUser(Request $r): ?string
    {
        return DB::table('pro_master_cabang')->where('id_master', $r->user()->id_wilayah)->value('nama_cabang');
    }

    private function cabangTerminal(object $po): ?object
    {
        return DB::table('pro_master_cabang')->where('id_master', $po->id_cabang)->first(['inisial_cabang', 'nama_cabang']);
    }

    private function poSiapUbah(int $id): object
    {
        $po = $this->po($id);
        if (! $po) {
            self::fail('PO Supplier tidak ditemukan.', 'NOT_FOUND', 404);
        }
        if (! self::bisaUbahRi($po)) {
            self::fail('PO ini belum disetujui atau sudah close/cancel, penerimaan tidak dapat diubah.', 'PO_TERKUNCI', 409);
        }

        return $po;
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $po = $this->poSiapUbah($id);
        $d = $this->validasi($request, false);
        $this->cekTanggal($po, $d['tgl_terima']);
        if ($this->totalTerima($id) >= (float) $po->volume_po) {
            self::fail('Volume terima sudah memenuhi volume PO.', 'PENUH', 409);
        }
        $user = $request->user();
        $idRi = $this->nextId();
        $berkas = $request->file('file_template');
        [$fileName, $fileAsli] = $berkas ? self::namaBerkas($berkas, $idRi) : ['', ''];
        if ($berkas && ! LegacyFile::store($berkas, 'lampiran/'.$fileName)) {
            self::fail('Lampiran tidak dapat disimpan di server (folder unggahan tidak bisa ditulis).', 'UPLOAD_GAGAL', 500);
        }
        try {
            DB::transaction(function () use ($po, $d, $user, $request, $id, $idRi, $fileName, $fileAsli) {
                $now = now();
                $ip = $request->ip();
                DB::table(self::RI)->insert([
                    'id_po_receive' => $idRi, 'id_po_supplier' => $id, 'no_terima' => $d['no_terima'], 'nama_pic' => $d['nama_pic'], 'tgl_terima' => $d['tgl_terima'],
                    'volume_bol' => $d['volume_bol'], 'volume_terima' => $d['volume_terima'], 'harga_tebus' => $po->harga_tebus, 'file_upload' => $fileName, 'file_upload_ori' => $fileAsli,
                    'created_time' => $now, 'created_ip' => $ip, 'created_by' => $user->fullname,
                ]);
                DB::table('new_pro_inventory_depot')->insert([
                    'id_datanya' => 'generated_po', 'id_jenis' => 21, 'id_produk' => $po->id_produk, 'id_terminal' => $po->id_terminal, 'id_vendor' => $po->id_vendor,
                    'id_po_supplier' => $id, 'id_po_receive' => $idRi, 'tanggal_inven' => $d['tgl_terima'], 'in_inven' => $d['volume_terima'],
                    'keterangan' => 'Penerimaan stock dari PO supplier', 'created_time' => $now, 'created_ip' => $ip, 'created_by' => $user->fullname,
                    'lastupdate_time' => $now, 'lastupdate_ip' => $ip, 'lastupdate_by' => $user->fullname,
                ]);
                DB::table('new_pro_inventory_harga_tebus')->insert([
                    'id_po_supplier' => $id, 'id_po_receive' => $idRi, 'id_produk' => $po->id_produk, 'id_terminal' => $po->id_terminal,
                    'tgl_terima' => $d['tgl_terima'], 'harga_tebus' => $po->harga_tebus,
                ]);
                $sync = app(AccurateRiSync::class);
                if ($sync->enabled()) {
                    $acc = $sync->buat($po, $d['no_terima'], date('d/m/Y', strtotime($d['tgl_terima'])), (float) $d['volume_bol'], $this->cabangUser($request), $this->cabangTerminal($po));
                    DB::table(self::RI)->where('id_po_receive', $idRi)->update(['id_accurate' => $acc, 'no_terima' => $d['no_terima']]);
                }
            });
        } catch (\Throwable $e) {
            LegacyFile::delete($fileName ? 'lampiran/'.$fileName : null);
            if ($e instanceof AccurateException) {
                return response()->json(['message' => $e->getMessage(), 'code' => 'ACCURATE'], 502);
            }
            throw $e;
        }

        return response()->json(['id' => (int) $idRi, 'message' => 'Penerimaan barang tersimpan.'], 201);
    }

    public function update(Request $request, int $id, int $ri): JsonResponse
    {
        $po = $this->poSiapUbah($id);
        $d = $this->validasi($request, true);
        $row = DB::table(self::RI)->where('id_po_supplier', $id)->where('id_po_receive', $ri)->first();
        if (! $row) {
            self::fail('Penerimaan tidak ditemukan.', 'NOT_FOUND', 404);
        }
        if (DB::table('new_pro_inventory_depot')->where('id_po_supplier', $id)->where('id_po_receive', $ri)->where('id_jenis', 1)->exists()) {
            self::fail('Penerimaan ini tidak dapat diubah.', 'TERKUNCI', 409);
        }
        $hasUpd = Schema::hasColumn(self::RI, 'updated_count');
        if ($hasUpd && (int) ($row->updated_count ?? 0) >= 2) {
            self::fail('Penerimaan ini sudah diubah 2 kali, tidak dapat diubah lagi.', 'BATAS_UBAH', 409);
        }
        $this->cekTanggal($po, $d['tgl_terima']);
        $user = $request->user();
        $berkas = $request->file('file_template');
        [$fileName, $fileAsli] = $berkas ? self::namaBerkas($berkas, (string) $ri) : [null, null];
        if ($berkas && ! LegacyFile::store($berkas, 'lampiran/'.$fileName)) {
            self::fail('Lampiran tidak dapat disimpan di server (folder unggahan tidak bisa ditulis).', 'UPLOAD_GAGAL', 500);
        }
        try {
            DB::transaction(function () use ($po, $d, $user, $request, $id, $ri, $row, $hasUpd, $fileName, $fileAsli) {
                $now = now();
                $ip = $request->ip();
                $set = [
                    'nama_pic' => $d['nama_pic'], 'tgl_terima' => $d['tgl_terima'], 'volume_bol' => $d['volume_bol'], 'volume_terima' => $d['volume_terima'],
                    'lastupdate_time' => $now, 'lastupdate_ip' => $ip, 'lastupdate_by' => $user->fullname,
                ];
                if ($fileName) {
                    $set += ['file_upload' => $fileName, 'file_upload_ori' => $fileAsli];
                }
                $count = (int) ($row->updated_count ?? 0);
                if ($hasUpd && $count < 3) {
                    $count++;
                    $set += ['is_updated' => 1, 'updated_count' => $count];
                    if (Schema::hasTable('new_pro_inventory_vendor_po_receive_history')) {
                        DB::table('new_pro_inventory_vendor_po_receive_history')->insert([
                            'id_po_receive' => $row->id_po_receive, 'id_po_supplier' => $row->id_po_supplier, 'id_accurate' => $row->id_accurate, 'nama_pic' => $row->nama_pic,
                            'tgl_terima' => $row->tgl_terima, 'no_terima' => $row->no_terima, 'volume_bol' => $row->volume_bol, 'volume_terima' => $row->volume_terima,
                            'harga_tebus' => $row->harga_tebus, 'file_upload' => $row->file_upload, 'file_upload_ori' => $row->file_upload_ori,
                            'created_time' => $row->created_time, 'created_ip' => $row->created_ip, 'created_by' => $row->created_by,
                            'is_updated' => $row->is_updated ?? 0, 'updated_count' => $row->updated_count ?? 0, 'keterangan_updated' => $d['ket_updated'],
                        ]);
                    }
                }
                DB::table(self::RI)->where('id_po_supplier', $id)->where('id_po_receive', $ri)->update($set);
                DB::table('new_pro_inventory_depot')->where('id_po_supplier', $id)->where('id_po_receive', $ri)->where('id_jenis', 21)->update([
                    'in_inven' => $d['volume_terima'], 'tanggal_inven' => $d['tgl_terima'], 'lastupdate_time' => $now, 'lastupdate_ip' => $ip, 'lastupdate_by' => $user->fullname,
                ]);
                // web: harga tebus RI (readonly di form) dibandingkan dengan harga tebus PO; beda = catat baris harga tebus baru
                if ((float) $po->harga_tebus !== (float) $row->harga_tebus) {
                    DB::table('new_pro_inventory_harga_tebus')->insert([
                        'id_po_supplier' => $id, 'id_po_receive' => $ri, 'id_produk' => $po->id_produk, 'id_terminal' => $po->id_terminal,
                        'tgl_terima' => $d['tgl_terima'], 'harga_tebus' => $row->harga_tebus,
                    ]);
                }
                $sync = app(AccurateRiSync::class);
                if ($sync->enabled()) {
                    $acc = $sync->ubah($po, $row, $d['no_terima'], date('d/m/Y', strtotime($d['tgl_terima'])), (float) $d['volume_bol'], (float) $d['volume_terima'], $this->cabangUser($request), $this->cabangTerminal($po));
                    DB::table(self::RI)->where('id_po_receive', $ri)->update(['id_accurate' => $acc, 'no_terima' => $d['no_terima']]);
                }
            });
        } catch (\Throwable $e) {
            if ($fileName && $fileName !== $row->file_upload) {
                LegacyFile::delete('lampiran/'.$fileName);
            }
            if ($e instanceof AccurateException) {
                return response()->json(['message' => $e->getMessage(), 'code' => 'ACCURATE'], 502);
            }
            throw $e;
        }
        if ($fileName && $row->file_upload && $row->file_upload !== $fileName) {
            LegacyFile::delete('lampiran/'.$row->file_upload); // berkas lama dihapus setelah berhasil, seperti web
        }

        return response()->json(['message' => 'Penerimaan barang diperbarui.']);
    }

    public function destroy(int $id, int $ri): JsonResponse
    {
        $this->poSiapUbah($id);
        $row = DB::table(self::RI)->where('id_po_supplier', $id)->where('id_po_receive', $ri)->first();
        if (! $row) {
            self::fail('Penerimaan tidak ditemukan.', 'NOT_FOUND', 404);
        }
        if (DB::table('new_pro_inventory_depot')->where('id_po_supplier', $id)->where('id_po_receive', $ri)->where('id_jenis', 1)->exists()) {
            self::fail('Penerimaan ini tidak dapat dihapus.', 'TERKUNCI', 409);
        }
        if (DB::table('new_pro_inventory_depot')->where('id_po_supplier', $id)->where('id_po_receive', $ri)->where('id_datanya', '!=', 'generated_po')->exists()) {
            self::fail('Maaf, data tidak dapat dihapus karena stok sudah terpakai', 'STOK_TERPAKAI', 409);
        }
        try {
            DB::transaction(function () use ($id, $ri, $row) {
                DB::table(self::RI)->where('id_po_supplier', $id)->where('id_po_receive', $ri)->delete();
                DB::table('new_pro_inventory_depot')->where('id_po_supplier', $id)->where('id_po_receive', $ri)->delete();
                $sync = app(AccurateRiSync::class);
                if ($sync->enabled()) {
                    $sync->hapus($row->id_accurate);
                }
            });
        } catch (AccurateException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'ACCURATE'], 502);
        }
        LegacyFile::delete($row->file_upload ? 'lampiran/'.$row->file_upload : null);

        return response()->json(['message' => 'Penerimaan barang dihapus.']);
    }
}
