<?php

namespace App\Http\Controllers;

use App\Services\Accurate\AccurateException;
use App\Services\Accurate\AccuratePoSupplierSync;
use App\Services\ApprovalMail;
use App\Support\PoHitung;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah dan ubah PO Supplier. Sumber: vendor-po-new-add.php (form dan rumus) dan web/action/vendor-po-new.php (aksi cek, add, update).
 * Semua angka uang dihitung ulang di server (PoHitung). Perubahan harga setelah ada penerimaan (vendor-po-update.php) belum dibawa.
 */
class PoSupplierFormController extends Controller
{
    private const PO = 'new_pro_inventory_vendor_po';

    private static function fail(string $m, string $c = 'INVALID', int $s = 422)
    {
        abort(response()->json(['message' => $m, 'code' => $c], $s));
    }

    private static function has(string $col, string $table = self::PO): bool
    {
        return Schema::hasColumn($table, $col);
    }

    public function opsi(): JsonResponse
    {
        return response()->json([
            'produk' => DB::table('pro_master_produk')->where('is_active', 1)->orderBy('no_urut')->get(['id_master', 'jenis_produk', 'merk_dagang'])
                ->map(fn ($x) => ['id' => (int) $x->id_master, 'label' => $x->jenis_produk.' - '.$x->merk_dagang])->values(),
            'terminal' => DB::table('pro_master_terminal as a')->join('pro_master_cabang as b', 'a.id_cabang', '=', 'b.id_master')->where('a.is_active', 1)->orderBy('a.id_master')
                ->get(['a.id_master', 'a.nama_terminal', 'a.tanki_terminal', 'a.lokasi_terminal'])
                ->map(fn ($t) => ['id' => (int) $t->id_master, 'label' => $t->nama_terminal.' - '.$t->tanki_terminal.' - '.$t->lokasi_terminal])->values(),
            'vendor' => DB::table('pro_master_vendor')->where('is_active', 1)->orderBy('id_master')->get(['id_master', 'nama_vendor'])
                ->map(fn ($v) => ['id' => (int) $v->id_master, 'label' => $v->nama_vendor])->values(),
            'pbbkb' => DB::table('pro_master_pbbkb')->orderBy('id_master')->get(['nilai_pbbkb'])->map(fn ($p) => (float) $p->nilai_pbbkb)->unique()->values(),
            'accurate' => app(AccuratePoSupplierSync::class)->enabled(),
        ]);
    }

    public function barang(Request $r): JsonResponse
    {
        $sync = app(AccuratePoSupplierSync::class);
        if (! $sync->enabled()) {
            return response()->json(['items' => [], 'total' => 0, 'accurate' => false]);
        }
        try {
            return response()->json($sync->barang(trim((string) $r->query('q', '')), max(1, (int) $r->query('page', 1))) + ['accurate' => true]);
        } catch (AccurateException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'ACCURATE'], 502);
        }
    }

    /** Pratinjau angka (sumber kebenaran tetap di server). */
    public function hitung(Request $r): JsonResponse
    {
        $d = $r->validate([
            'volume_po' => ['required', 'numeric', 'min:0'], 'harga_dasar' => ['required', 'numeric', 'min:0'], 'kategori_oa' => ['required', 'in:1,2'],
            'ongkos_angkut' => ['nullable', 'numeric', 'min:0'], 'kategori_plat' => ['nullable', 'in:Hitam,Kuning'], 'kd_tax' => ['nullable', 'in:E,EC'],
            'pbbkb_tawar' => ['nullable', 'numeric', 'min:0'], 'iuran_migas' => ['nullable', 'boolean'], 'nominal_iuran' => ['nullable', 'numeric', 'min:0'],
        ]);

        return response()->json($this->angka($d));
    }

    private function angka(array $d): array
    {
        $iuran = ! empty($d['iuran_migas']) ? (float) ($d['nominal_iuran'] ?? PoHitung::iuranBawaan((float) $d['volume_po'], (float) $d['harga_dasar'])) : 0.0;
        $oa2 = (int) $d['kategori_oa'] === 2;

        return PoHitung::hitung([
            'volume' => (float) $d['volume_po'], 'harga' => (float) $d['harga_dasar'], 'kategori_oa' => (int) $d['kategori_oa'], 'ongkos' => $oa2 ? (float) ($d['ongkos_angkut'] ?? 0) : 0,
            'plat' => $oa2 ? ($d['kategori_plat'] ?? '') : null, 'kd_tax' => $d['kd_tax'] ?? null, 'pbbkb_persen' => $d['pbbkb_tawar'] ?? null, 'iuran' => $iuran,
        ]) + ['nominal_iuran' => $iuran];
    }

    private function aturan(bool $baru, bool $accurate, bool $perluResubmit): array
    {
        $acc = $accurate ? 'required' : 'nullable';
        $r = [
            'tanggal' => ['required', 'date_format:Y-m-d'], 'id_terminal' => ['required', 'integer', 'exists:pro_master_terminal,id_master'],
            'jenis_kirim' => ['required', 'in:1,2'], 'terms' => ['required', 'in:COD,NET,CBD'], 'terms_day' => ['required_if:terms,NET', 'nullable', 'integer', 'min:0', 'max:999'],
            'kd_tax' => ['required', 'in:E,EC'], 'volume_po' => ['required', 'integer', 'min:1'], 'harga_dasar' => ['required', 'numeric', 'min:0.0001'],
            'jenis_harga' => ['nullable', 'in:1,2'], 'kategori_oa' => ['required', 'in:1,2'],
            'jenis_oa' => ['required_if:kategori_oa,2', 'nullable', 'in:0,1'], 'ongkos_angkut' => ['required_if:kategori_oa,2', 'nullable', 'numeric', 'min:0'],
            'kategori_plat' => ['required_if:kategori_oa,2', 'nullable', 'in:Hitam,Kuning'], 'pbbkb_tawar' => ['required', 'numeric', 'min:0'],
            'iuran_migas' => ['nullable', 'boolean'], 'nominal_iuran' => ['nullable', 'numeric', 'min:0'], 'ket' => ['required', 'string', 'max:5000'], 'internal_notes' => ['nullable', 'string', 'max:5000'],
            'kode_item' => [$acc, 'nullable', 'string', 'max:100'], 'keterangan_item1' => ['nullable', 'string', 'max:500'],
            'kode_item2' => ['nullable', 'string', 'max:100'], 'keterangan_item2' => ['nullable', 'string', 'max:500'],
            'biaya_oa' => ['nullable', 'string', 'max:100'], 'keterangan_biaya1' => ['nullable', 'string', 'max:500'], 'alokasi_barang1' => ['nullable', 'boolean'],
            'biaya_lain' => ['nullable', 'string', 'max:100'], 'jumlah_biaya' => ['nullable', 'numeric', 'min:0'], 'keterangan_biaya2' => ['nullable', 'string', 'max:500'], 'alokasi_barang2' => ['nullable', 'boolean'],
            'kode_item3' => ['nullable', 'string', 'max:100'], 'keterangan_biaya3' => ['nullable', 'string', 'max:500'], 'alokasi_barang3' => ['nullable', 'boolean'],
            'kode_biaya1' => ['nullable', 'string', 'max:100'], 'keterangan_biaya4' => ['nullable', 'string', 'max:500'], 'alokasi_barang4' => ['nullable', 'boolean'],
            'kode_biaya2' => ['nullable', 'string', 'max:100'], 'keterangan_biaya5' => ['nullable', 'string', 'max:500'], 'alokasi_barang5' => ['nullable', 'boolean'],
        ];
        if ($baru) {
            $r += ['id_produk' => ['required', 'integer', 'exists:pro_master_produk,id_master'], 'id_vendor' => ['required', 'integer', 'exists:pro_master_vendor,id_master']];
        }
        if ($perluResubmit) {
            $r['ket_resubmission'] = ['required', 'string', 'max:2000'];
        }

        return $r;
    }

    /** Akun/ item Accurate yang wajib menurut pilihan form (kolom required dinamis di web). */
    private function cekAccurate(array $d): void
    {
        $pesan = [];
        $oa2 = (int) $d['kategori_oa'] === 2;
        if ($oa2 && (int) $d['jenis_oa'] === 0 && empty($d['kode_item2'])) {
            $pesan['kode_item2'] = 'Kode OA Accurate wajib diisi.';
        }
        if ($oa2 && (int) $d['jenis_oa'] === 1) {
            foreach (['biaya_oa' => 'Akun OA Accurate', 'biaya_lain' => 'Akun biaya lain'] as $k => $l) {
                if (empty($d[$k])) {
                    $pesan[$k] = "$l wajib diisi.";
                }
            }
            if (! isset($d['jumlah_biaya'])) {
                $pesan['jumlah_biaya'] = 'Jumlah biaya lain wajib diisi.';
            }
        }
        if ($d['kd_tax'] === 'EC' && empty($d['kode_item3'])) {
            $pesan['kode_item3'] = 'Akun PPH22 Accurate wajib diisi.';
        }
        if ((float) $d['pbbkb_tawar'] > 0 && empty($d['kode_biaya1'])) {
            $pesan['kode_biaya1'] = 'Akun PBBKB Accurate wajib diisi.';
        }
        if (! empty($d['iuran_migas']) && empty($d['kode_biaya2'])) {
            $pesan['kode_biaya2'] = 'Akun Migas Accurate wajib diisi.';
        }
        if ($pesan) {
            abort(response()->json(['message' => reset($pesan), 'errors' => array_map(fn ($m) => [$m], $pesan)], 422));
        }
    }

    private function isiKolom(array $d, array $a): array
    {
        $oa2 = (int) $d['kategori_oa'] === 2;
        $col = [
            'volume_po' => $d['volume_po'], 'harga_tebus' => $d['harga_dasar'], 'kategori_oa' => $d['kategori_oa'], 'is_biaya' => $oa2 ? $d['jenis_oa'] : null,
            'ongkos_angkut' => $oa2 ? $d['ongkos_angkut'] : 0, 'kategori_plat' => $oa2 ? $d['kategori_plat'] : '', 'iuran_migas' => ! empty($d['iuran_migas']) ? '1' : '0',
            'nominal_migas' => $a['nominal_iuran'], 'kd_tax' => $d['kd_tax'], 'subtotal' => $a['subtotal'], 'ppn_11' => $a['ppn_11'], 'dpp_11_12' => $a['dpp_11_12'], 'ppn_12' => $a['ppn_12'],
            'pph_22' => $a['pph_22'], 'nilai_pbbkb' => $d['pbbkb_tawar'], 'pbbkb' => $a['pbbkb'], 'total_order' => $a['total_order'], 'terms' => $d['terms'],
            'terms_day' => $d['terms'] === 'NET' ? $d['terms_day'] : ($d['terms_day'] ?? ''), 'keterangan' => $d['ket'], 'jenis_kirim' => $d['jenis_kirim'], 'tanggal_inven' => $d['tanggal'], 'id_terminal' => $d['id_terminal'],
        ];
        if (self::has('internal_notes')) {
            $col['internal_notes'] = $d['internal_notes'] ?? '';
        }
        if (self::has('jenis_harga')) {
            $col['jenis_harga'] = $d['jenis_harga'] ?? 1;
        }

        return $col;
    }

    /** Parameter Accurate dari data form (item, biaya, alamat). */
    private function paramAccurate(array $d, array $a, object $vendor, string $nomor, Request $r): array
    {
        $oa2 = (int) $d['kategori_oa'] === 2;
        $cabTerminal = DB::table('pro_master_terminal as t')->join('pro_master_cabang as c', 't.id_cabang', '=', 'c.id_master')->where('t.id_master', $d['id_terminal'])->first(['c.inisial_cabang', 'c.nama_cabang']);
        $vol = (float) $d['volume_po'];
        $items = [['no' => $d['kode_item'], 'qty' => $vol, 'harga' => (float) $d['harga_dasar'], 'pajak' => true, 'catatan' => $d['keterangan_item1'] ?? '']];
        if ($oa2 && ! empty($d['kode_item2'])) {
            $items[] = ['no' => $d['kode_item2'], 'qty' => $vol, 'harga' => (float) $d['ongkos_angkut'], 'pajak' => ($d['kategori_plat'] ?? '') === 'Hitam', 'catatan' => $d['keterangan_item2'] ?? ''];
        }
        $biaya = [];
        $tambah = function (?string $akun, $jumlah, $alok, ?string $cat) use (&$biaya) {
            if (! empty($akun)) {
                $biaya[] = ['akun' => $akun, 'jumlah' => $jumlah, 'alokasi' => (bool) $alok, 'catatan' => $cat ?? ''];
            }
        };
        $tambah($d['biaya_oa'] ?? null, $vol * (float) ($oa2 ? $d['ongkos_angkut'] : 0), $d['alokasi_barang1'] ?? false, $d['keterangan_biaya1'] ?? '');
        $tambah($d['biaya_lain'] ?? null, (float) ($d['jumlah_biaya'] ?? 0), $d['alokasi_barang2'] ?? false, $d['keterangan_biaya2'] ?? '');
        $tambah($d['kode_item3'] ?? null, $a['pph_22'], $d['alokasi_barang3'] ?? false, $d['keterangan_biaya3'] ?? '');
        $tambah($d['kode_biaya1'] ?? null, $a['pbbkb'], $d['alokasi_barang4'] ?? false, $d['keterangan_biaya4'] ?? '');
        $tambah($d['kode_biaya2'] ?? null, $a['nominal_iuran'], $d['alokasi_barang5'] ?? false, $d['keterangan_biaya5'] ?? '');

        return [
            'tanggal' => $d['tanggal'], 'vendorNo' => $vendor->kode_vendor, 'nomor' => $nomor, 'cabangUser' => DB::table('pro_master_cabang')->where('id_master', $r->user()->id_wilayah)->value('nama_cabang'),
            'terms' => $d['terms'], 'terms_day' => $d['terms_day'] ?? '', 'terminal' => $d['id_terminal'], 'plat' => $oa2 ? $d['kategori_plat'] : '', 'kd_tax' => $d['kd_tax'], 'ket' => $d['ket'],
            'items' => $items, 'biaya' => $biaya, 'departemen' => $cabTerminal->nama_cabang ?? null, 'gudang' => $cabTerminal->inisial_cabang ?? null,
        ];
    }

    private static function romawi(int $b): string
    {
        return [1 => 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][$b];
    }

    /** Nomor PO otomatis: NNN/VENDOR/CABANG/BULAN-ROMAWI/YY, urut per vendor, cabang terminal, dan tahun (web aksi add). */
    private function nomorBaru(int $vendor, int $terminal, string $tanggal): string
    {
        $cab = DB::table('pro_master_terminal as t')->join('pro_master_cabang as c', 't.id_cabang', '=', 'c.id_master')->where('t.id_master', $terminal)->first(['c.id_master', 'c.inisial_cabang']);
        $ini = DB::table('pro_master_vendor')->where('id_master', $vendor)->value('inisial_vendor');
        $maks = (int) DB::table(self::PO.' as a')->join('pro_master_terminal as b', 'a.id_terminal', '=', 'b.id_master')
            ->where('a.id_vendor', $vendor)->where('b.id_cabang', $cab->id_master)->whereYear('a.tanggal_inven', substr($tanggal, 0, 4))
            ->selectRaw('coalesce(max(cast(substr(a.nomor_po, 1, 3) as unsigned)), 0) as n')->value('n');

        return str_pad((string) ($maks + 1), 3, '0', STR_PAD_LEFT).'/'.strtoupper((string) $ini).'/'.strtoupper((string) $cab->inisial_cabang).'/'.self::romawi((int) substr($tanggal, 5, 2)).'/'.substr($tanggal, 2, 2);
    }

    private function nextId(): string
    {
        $prefix = date('Ym');
        try {
            $n = DB::selectOne('select LPAD(cast((select nextval(new_pro_inventory_vendor_po_seq)) as char(10)), 9, \'0\') as n')->n;
        } catch (\Throwable) {
            $max = DB::table(self::PO)->where('id_master', 'like', $prefix.'%')->max('id_master');
            $n = str_pad((string) (((int) substr((string) $max, 6)) + 1), 9, '0', STR_PAD_LEFT);
        }

        return $prefix.$n;
    }

    public function store(Request $request): JsonResponse
    {
        $sync = app(AccuratePoSupplierSync::class);
        $d = $request->validate($this->aturan(true, $sync->enabled(), false));
        if ($sync->enabled()) {
            $this->cekAccurate($d);
        }
        $vendor = DB::table('pro_master_vendor')->where('id_master', $d['id_vendor'])->first(['id_master', 'kode_vendor', 'nama_vendor']);
        if ($sync->enabled() && empty($vendor->kode_vendor)) {
            self::fail('Vendor pada accurate tidak ditemukan', 'VENDOR_ACCURATE');
        }
        $a = $this->angka($d);
        $id = $this->nextId();
        $nomor = $this->nomorBaru((int) $d['id_vendor'], (int) $d['id_terminal'], $d['tanggal']);
        $user = $request->user();
        try {
            DB::transaction(function () use ($d, $a, $id, $nomor, $user, $request, $sync, $vendor) {
                $row = $this->isiKolom($d, $a) + [
                    'id_master' => $id, 'id_vendor' => $d['id_vendor'], 'id_produk' => $d['id_produk'], 'nomor_po' => $nomor, 'disposisi_po' => 1,
                    'created_time' => now(), 'created_ip' => $request->ip(), 'created_by' => $user->fullname,
                ];
                DB::table(self::PO)->insert($row);
                if ($sync->enabled()) {
                    $idAcc = $sync->buat($this->paramAccurate($d, $a, $vendor, $nomor, $request));
                    DB::table(self::PO)->where('id_master', $id)->update(['id_accurate' => $idAcc]);
                }
            });
        } catch (AccurateException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'ACCURATE'], 502);
        }
        ApprovalMail::toRoles([4], null, 'Persetujuan PO Supplier['.ApprovalMail::stamp().']', e($user->fullname).' meminta persetujuan untuk PO supplier <b>'.e($nomor).'</b>');

        return response()->json(['id' => (int) $id, 'nomor_po' => $nomor, 'message' => 'PO Supplier tersimpan.', 'accurate_manual' => ! $sync->enabled()], 201);
    }

    private function poUbah(int $id): object
    {
        $po = DB::table(self::PO)->where('id_master', $id)->first();
        if (! $po) {
            self::fail('PO Supplier tidak ditemukan.', 'NOT_FOUND', 404);
        }
        $vt = (float) DB::table('new_pro_inventory_vendor_po_receive')->where('id_po_supplier', $id)->sum('volume_terima');
        if ($vt != 0) {
            self::fail('PO ini sudah ada penerimaan barang. Perubahan harga PO belum tersedia di PWA.', 'PERUBAHAN_HARGA', 409);
        }
        if (! in_array('edit', PoSupplierController::aksi($po, PoSupplierController::ship($id), $vt), true)) {
            self::fail('PO ini tidak dapat diubah (sudah disetujui dan melewati batas pengajuan ulang, atau sudah close/cancel).', 'TIDAK_BISA_UBAH', 409);
        }

        return $po;
    }

    /** Nilai awal form ubah, ditambah isi dari Accurate (kode item, akun biaya) bila tersedia. */
    public function ubah(int $id): JsonResponse
    {
        return response()->json($this->dataForm($this->poUbah($id)));
    }

    private function dataForm(object $po): array
    {
        $id = (int) $po->id_master;
        $acc = null;
        $peringatan = null;
        $sync = app(AccuratePoSupplierSync::class);
        if ($sync->enabled() && ! empty($po->id_accurate)) {
            try {
                $acc = $sync->prefill($po->id_accurate);
            } catch (AccurateException $e) {
                $peringatan = $e->getMessage();
            }
        }
        $v = DB::table('pro_master_vendor')->where('id_master', $po->id_vendor)->value('nama_vendor');
        $pr = DB::table('pro_master_produk')->where('id_master', $po->id_produk)->first(['jenis_produk', 'merk_dagang']);
        $riw = Schema::hasTable('new_pro_inventory_vendor_po_history') && Schema::hasColumn('new_pro_inventory_vendor_po_history', 'keterangan_resubmission')
            ? DB::table('new_pro_inventory_vendor_po_history')->where('id_po_supplier', $id)->orderByDesc('id_master')->value('keterangan_resubmission') : null;
        $disetujui = (int) $po->ceo_result === 1 && (int) ($po->revert_ceo ?? 0) === 0;

        return [
            'id' => (int) $po->id_master, 'volume_terima' => (float) DB::table('new_pro_inventory_vendor_po_receive')->where('id_po_supplier', $id)->sum('volume_terima'), 'nomor_po' => $po->nomor_po, 'tanggal' => $po->tanggal_inven, 'id_produk' => (int) $po->id_produk, 'produk' => $pr ? $pr->jenis_produk.' - '.$pr->merk_dagang : '',
            'id_vendor' => (int) $po->id_vendor, 'vendor' => $v, 'id_terminal' => (int) $po->id_terminal, 'jenis_kirim' => (int) $po->jenis_kirim, 'terms' => $po->terms, 'terms_day' => $po->terms_day,
            'kd_tax' => $po->kd_tax, 'volume_po' => (float) $po->volume_po, 'harga_dasar' => (float) $po->harga_tebus, 'jenis_harga' => self::has('jenis_harga') ? (int) ($po->jenis_harga ?? 1) : 1,
            'kategori_oa' => (int) ($po->kategori_oa ?: 1), 'jenis_oa' => $po->is_biaya === null ? null : (int) $po->is_biaya, 'ongkos_angkut' => (float) $po->ongkos_angkut, 'kategori_plat' => $po->kategori_plat,
            'pbbkb_tawar' => (float) $po->nilai_pbbkb, 'iuran_migas' => (int) $po->iuran_migas === 1, 'nominal_iuran' => (float) $po->nominal_migas,
            'ket' => $po->keterangan, 'internal_notes' => self::has('internal_notes') ? $po->internal_notes : null,
            'perlu_pengajuan_ulang' => $disetujui, 'pengajuan_ulang_terakhir' => $riw, 'accurate' => $acc, 'peringatan_accurate' => $peringatan,
            'catatan_cfo' => $po->revert_cfo_summary, 'catatan_ceo' => $po->revert_ceo_summary,
        ];
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $po = $this->poUbah($id);
        $sync = app(AccuratePoSupplierSync::class);
        $disetujui = (int) $po->ceo_result === 1 && (int) ($po->revert_ceo ?? 0) === 0;
        $d = $request->validate($this->aturan(false, $sync->enabled() && ! empty($po->id_accurate), $disetujui));
        if ($sync->enabled() && ! empty($po->id_accurate)) {
            $this->cekAccurate($d);
        }
        $dup = DB::table(self::PO)->where('nomor_po', $po->nomor_po)->where('id_master', '!=', $id)->exists();
        if ($dup) {
            self::fail('Nomor PO Sudah Ada...', 'NOMOR_ADA');
        }
        $a = $this->angka($d);
        $user = $request->user();
        try {
            DB::transaction(function () use ($po, $d, $a, $id, $user, $request, $sync, $disetujui) {
                $set = $this->isiKolom($d, $a);
                unset($set['id_vendor']);
                $set += ['disposisi_po' => 1, 'cfo_result' => 0, 'ceo_result' => 0, 'revert_cfo' => 0, 'revert_ceo' => 0,
                    'lastupdate_time' => now(), 'lastupdate_ip' => $request->ip(), 'lastupdate_by' => $user->fullname];
                $hitung = (int) ($po->resubmission_count ?? 0);
                if ($disetujui && $hitung < 3) {
                    $hitung++;
                    $set += ['resubmission_date' => now(), 'is_resubmission' => 1, 'resubmission_count' => $hitung];
                    $this->riwayat($po, $d['ket_resubmission'] ?? '', $d['internal_notes'] ?? '');
                }
                DB::table(self::PO)->where('id_master', $id)->update($set);
                if ($sync->enabled() && ! empty($po->id_accurate)) {
                    $vendor = DB::table('pro_master_vendor')->where('id_master', $po->id_vendor)->first(['kode_vendor']);
                    if (empty($vendor->kode_vendor)) {
                        throw new AccurateException('Vendor pada accurate tidak ditemukan');
                    }
                    $idAcc = $sync->ubah($po->id_accurate, $this->paramAccurate($d, $a, $vendor, $po->nomor_po, $request));
                    DB::table(self::PO)->where('id_master', $id)->update(['id_accurate' => $idAcc]);
                }
            });
        } catch (AccurateException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'ACCURATE'], 502);
        }

        return response()->json(['message' => 'PO Supplier diperbarui.', 'accurate_manual' => ! empty($po->id_accurate) && ! $sync->enabled()]);
    }

    /** Salin kondisi PO sebelum diubah ke riwayat (hanya kolom yang ada di tabel riwayat server). */
    private function riwayat(object $po, string $ketResubmit, string $internal, bool $perubahanHarga = false): void
    {
        $t = 'new_pro_inventory_vendor_po_history';
        if (! Schema::hasTable($t)) {
            return;
        }
        $salin = ['id_vendor', 'id_produk', 'id_terminal', 'nomor_po', 'tanggal_inven', 'volume_po', 'harga_tebus', 'kategori_oa', 'ongkos_angkut', 'kategori_plat', 'iuran_migas', 'nominal_migas', 'kd_tax',
            'subtotal', 'ppn_11', 'dpp_11_12', 'ppn_12', 'pph_22', 'nilai_pbbkb', 'pbbkb', 'total_order', 'terms', 'terms_day', 'is_biaya', 'keterangan', 'created_time', 'created_ip', 'created_by',
            'disposisi_po', 'is_resubmission', 'resubmission_count', 'jenis_kirim'];
        $row = ['id_po_supplier' => $po->id_master];
        foreach ($salin as $c) {
            $row[$c] = $po->$c ?? null;
        }
        $row['keterangan_resubmission'] = $ketResubmit;
        $row['internal_notes'] = $internal;
        $row['jenis_harga'] = $po->jenis_harga ?? null;
        $row['is_price_changed'] = $perubahanHarga ? 1 : null;
        $kolom = array_flip(Schema::getColumnListing($t));
        DB::table($t)->insert(array_intersect_key($row, $kolom));
    }

    // ---- Perubahan harga PO yang sudah ada penerimaan (vendor-po-update.php, aksi perubahan; aksi change_price) ----

    private function poHarga(int $id): object
    {
        $po = DB::table(self::PO)->where('id_master', $id)->first();
        if (! $po) {
            self::fail('PO Supplier tidak ditemukan.', 'NOT_FOUND', 404);
        }
        $vt = (float) DB::table('new_pro_inventory_vendor_po_receive')->where('id_po_supplier', $id)->sum('volume_terima');
        if ($vt == 0 || (int) $po->is_close === 1 || (int) $po->is_cancel === 1) {
            self::fail('Perubahan harga hanya untuk PO yang sudah ada penerimaan barang dan belum close/cancel.', 'TIDAK_BISA_UBAH_HARGA', 409);
        }
        if (! self::has('jenis_harga') || (int) ($po->jenis_harga ?? 0) !== 2) {
            self::fail('Jenis harga PO harus Harga Sementara terlebih dahulu.', 'BUKAN_SEMENTARA', 409);
        }

        return $po;
    }

    /** change_price: jenis harga PO menjadi Harga Sementara (tombol Perubahan Harga di halaman PO). */
    public function hargaSementara(int $id): JsonResponse
    {
        $po = DB::table(self::PO)->where('id_master', $id)->first();
        if (! $po) {
            return response()->json(['message' => 'PO Supplier tidak ditemukan.'], 404);
        }
        $vt = (float) DB::table('new_pro_inventory_vendor_po_receive')->where('id_po_supplier', $id)->sum('volume_terima');
        if (! self::has('jenis_harga') || ! self::has('is_price_changed')) {
            self::fail('Fitur harga sementara belum tersedia di database server ini.', 'KOLOM_TIDAK_ADA', 409);
        }
        if ($vt == 0 || (int) $po->is_close === 1 || (int) $po->is_cancel === 1 || (int) $po->jenis_harga !== 1 || in_array((int) ($po->is_price_changed ?? 0), [1, 2], true)) {
            self::fail('PO ini tidak dapat diubah menjadi Harga Sementara.', 'TIDAK_BISA', 409);
        }
        DB::table(self::PO)->where('id_master', $id)->update(['jenis_harga' => 2]);

        return response()->json(['message' => 'Jenis harga PO diubah menjadi Harga Sementara.']);
    }

    public function ubahHarga(int $id): JsonResponse
    {
        return response()->json($this->dataForm($this->poHarga($id)));
    }

    public function simpanHarga(Request $request, int $id): JsonResponse
    {
        $po = $this->poHarga($id);
        $sync = app(AccuratePoSupplierSync::class);
        $pakaiAccurate = $sync->enabled() && ! empty($po->id_accurate);
        $disetujui = (int) $po->ceo_result === 1 && (int) ($po->revert_ceo ?? 0) === 0;
        $aturan = $this->aturan(false, $pakaiAccurate, $disetujui);
        // Volume, plat, catatan PO, produk, terminal, vendor terkunci di halaman ini: nilai PO dipertahankan.
        foreach (['volume_po', 'kategori_plat', 'ket', 'id_terminal'] as $k) {
            $aturan[$k] = ['nullable'];
        }
        $d = $request->validate($aturan);
        $d['volume_po'] = (float) $po->volume_po;
        $d['kategori_plat'] = $po->kategori_plat ?: null;
        $d['ket'] = (string) $po->keterangan;
        $d['id_terminal'] = (int) $po->id_terminal;
        if ((int) $d['kategori_oa'] === 2 && empty($d['kategori_plat'])) {
            $d['kategori_plat'] = 'Hitam';
        }
        if ($pakaiAccurate) {
            $this->cekAccurate($d);
        }
        $a = $this->angka($d);
        $user = $request->user();
        try {
            DB::transaction(function () use ($po, $d, $a, $id, $user, $request, $sync, $pakaiAccurate, $disetujui) {
                $set = $this->isiKolom($d, $a);
                unset($set['volume_po'], $set['id_terminal'], $set['kategori_plat'], $set['keterangan'], $set['internal_notes']);
                $set['harga_tebus'] = $d['harga_dasar'];
                $set += ['disposisi_po' => 1, 'cfo_result' => 0, 'ceo_result' => 0, 'revert_cfo' => 0, 'revert_ceo' => 0,
                    'lastupdate_time' => now(), 'lastupdate_ip' => $request->ip(), 'lastupdate_by' => $user->fullname];
                if (self::has('is_price_changed')) {
                    $set['is_price_changed'] = 1;
                }
                $hitung = (int) ($po->resubmission_count ?? 0);
                if ($disetujui && $hitung < 3) {
                    $hitung++;
                    $set += ['resubmission_date' => now(), 'is_resubmission' => 1, 'resubmission_count' => $hitung];
                    $this->riwayat($po, $d['ket_resubmission'] ?? '', (string) ($po->internal_notes ?? ''), true);
                }
                DB::table(self::PO)->where('id_master', $id)->update($set);
                if ($pakaiAccurate) {
                    $vendor = DB::table('pro_master_vendor')->where('id_master', $po->id_vendor)->first(['kode_vendor']);
                    if (empty($vendor->kode_vendor)) {
                        throw new AccurateException('Vendor pada accurate tidak ditemukan');
                    }
                    $oa2 = (int) $d['kategori_oa'] === 2;
                    $cab = DB::table('pro_master_terminal as t')->join('pro_master_cabang as c', 't.id_cabang', '=', 'c.id_master')->where('t.id_master', $po->id_terminal)->value('c.nama_cabang');
                    $biaya = [];
                    foreach ([
                        'biaya_oa' => [$d['volume_po'] * (float) ($oa2 ? $d['ongkos_angkut'] : 0), 'keterangan_biaya1'], 'biaya_lain' => [(float) ($d['jumlah_biaya'] ?? 0), 'keterangan_biaya2'],
                        'kode_item3' => [$a['pph_22'], 'keterangan_biaya3'], 'kode_biaya1' => [$a['pbbkb'], 'keterangan_biaya4'], 'kode_biaya2' => [$a['nominal_iuran'], 'keterangan_biaya5'],
                    ] as $akun => [$jumlah, $cat]) {
                        if (! empty($d[$akun])) {
                            $biaya[$d[$akun]] = ['jumlah' => $jumlah, 'catatan' => (string) ($d[$cat] ?? '')];
                        }
                    }
                    $sync->ubahHarga($po->id_accurate, [
                        'tanggal' => $d['tanggal'], 'vendorNo' => $vendor->kode_vendor, 'ket' => $d['ket'], 'harga' => (float) $d['harga_dasar'], 'ongkos' => $oa2 ? (float) $d['ongkos_angkut'] : 0.0,
                        'cabangUser' => DB::table('pro_master_cabang')->where('id_master', $user->id_wilayah)->value('nama_cabang'), 'departemen' => $cab, 'biaya' => $biaya,
                    ]);
                }
            });
        } catch (AccurateException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'ACCURATE'], 502);
        }
        ApprovalMail::toRoles([21], null, 'Persetujuan PO Supplier['.$po->nomor_po.' - '.ApprovalMail::stamp().']',
            e($user->fullname).' melakukan Perubahan Harga PO <b>'.e($po->nomor_po).'</b> dan meminta persetujuan untuk harga baru.<br>Keterangan: '.e($d['ket_resubmission'] ?? '-'));

        return response()->json(['message' => 'Perubahan harga tersimpan. PO kembali ke verifikasi.', 'accurate_manual' => ! empty($po->id_accurate) && ! $sync->enabled()]);
    }
}
