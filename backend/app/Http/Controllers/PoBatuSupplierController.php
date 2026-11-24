<?php

namespace App\Http\Controllers;

use App\Services\ApprovalMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PO Supplier Crushed Stone (PO Batu) untuk Procurement (peran 5): daftar, detail, tambah, ubah, ajukan persetujuan, cetak.
 * Sumber: vendor-po-new-crushed-stone.php (daftar + datatable "(2)"), vendor-po-new-crushed-stone-detail.php, vendor-po-new-crushed-stone-add.php,
 * web/action/vendor-po-new-crushed-stone.php (aksi add/update). Terima barang, cancel, dan close menyusul (halamannya belum ada).
 * disposisi_po: 0 = Terdaftar, 1 = Verifikasi CFO, 2 = Verifikasi CEO, 3 = Ditolak CFO, 4 = Terverifikasi, 5 = Ditolak CEO.
 */
class PoBatuSupplierController extends Controller
{
    private const PO = 'new_pro_inventory_vendor_po_crushed_stone';
    private const RI = 'new_pro_inventory_vendor_po_crushed_stone_receive';

    private function base()
    {
        return DB::table(self::PO.' as a')
            ->join('pro_master_produk as c', 'a.id_produk', '=', 'c.id_master')
            ->join('pro_master_vendor as d', 'a.id_vendor', '=', 'd.id_master')
            ->join('pro_master_terminal as e', 'a.id_terminal', '=', 'e.id_master');
    }

    private static function terminal($r): string
    {
        return trim($r->nama_terminal.($r->tanki_terminal ? ' - '.$r->tanki_terminal : '').($r->lokasi_terminal ? ', '.$r->lokasi_terminal : ''));
    }

    private static function fail(string $m, string $c = 'INVALID', int $s = 422)
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['message' => $m, 'code' => $c], $s));
    }

    /** Pilihan filter dan form: produk, terminal, dan vendor aktif (sama dengan kotak pilih di web). */
    public function opsi(): JsonResponse
    {
        return response()->json([
            'produk' => DB::table('pro_master_produk')->where('is_active', 1)->orderBy('no_urut')->get(['id_master', 'jenis_produk', 'merk_dagang'])
                ->map(fn ($p) => ['id' => (int) $p->id_master, 'label' => $p->jenis_produk.' - '.$p->merk_dagang])->values(),
            'terminal' => DB::table('pro_master_terminal as a')->join('pro_master_cabang as b', 'a.id_cabang', '=', 'b.id_master')->where('a.is_active', 1)->orderBy('a.id_master')
                ->get(['a.id_master', 'a.nama_terminal', 'a.tanki_terminal', 'a.lokasi_terminal'])
                ->map(fn ($t) => ['id' => (int) $t->id_master, 'label' => $t->nama_terminal.' - '.$t->tanki_terminal.' - '.$t->lokasi_terminal])->values(),
            'vendor' => DB::table('pro_master_vendor')->where('is_active', 1)->orderBy('id_master')
                ->get(['id_master', 'nama_vendor'])->map(fn ($v) => ['id' => (int) $v->id_master, 'label' => $v->nama_vendor])->values(),
        ]);
    }

    /** Teks disposisi persis seperti kolom Disposisi di web: "Teks|tanggal" bila ada tanggal. */
    private static function status($p): string
    {
        $tgl = fn ($t) => $t ? date('d/m/Y H:i:s', strtotime($t)).' WIB' : '';

        return match ((int) $p->disposisi_po) {
            2 => 'Verifikasi CEO',
            1 => 'Verifikasi CFO',
            0 => 'Terdaftar',
            3 => 'Ditolak CFO|'.$tgl($p->cfo_tanggal),
            5 => 'Ditolak CEO|'.$tgl($p->ceo_tanggal),
            4 => 'Terverifikasi|'.$tgl($p->ceo_tanggal),
            default => '',
        };
    }

    /** Tombol per baris: detail dan cetak (terima, cancel, dan close menyusul). */
    private static function aksi($p): array
    {
        $out = [];
        $disetujui = (int) $p->ceo_result === 1 && (int) ($p->revert_ceo ?? 0) === 0;
        if ((int) $p->is_cancel !== 1 && (int) $p->is_close !== 1) {
            $out[] = 'detail';
        }
        if ($disetujui || (int) $p->is_cancel === 1 || (int) $p->is_close === 1) {
            $out[] = 'cetak';
        }

        return $out;
    }

    public function index(Request $r): JsonResponse
    {
        $q = $this->base()->where('a.harga_tebus', '>', 0);
        if ($r->filled('bulan') && $r->filled('tahun')) {
            $q->whereMonth('a.tanggal_inven', (int) $r->query('bulan'))->whereYear('a.tanggal_inven', (int) $r->query('tahun'));
        }
        if ($r->filled('produk')) {
            $q->where('a.id_produk', (int) $r->query('produk'));
        }
        if ($r->filled('vendor')) {
            $q->where('a.id_vendor', (int) $r->query('vendor'));
        }
        if ($r->filled('terminal')) {
            $q->where('a.id_terminal', (int) $r->query('terminal'));
        }
        if (($t = trim((string) $r->query('q', ''))) !== '') {
            $q->whereRaw('upper(a.nomor_po) like ?', ['%'.strtoupper($t).'%']);
        }
        $per = min(max((int) $r->query('per_page', 20), 1), (int) config('orca.per_page_max'));
        $page = $q->orderByDesc('a.tanggal_inven')->orderByDesc('a.id_master')->select([
            'a.*', 'c.jenis_produk', 'c.merk_dagang', 'd.nama_vendor', 'e.nama_terminal', 'e.tanki_terminal', 'e.lokasi_terminal',
            DB::raw('(select sum(r.volume_bol) from '.self::RI.' r where r.id_po_supplier = a.id_master) as vol_bl'),
            DB::raw('(select sum(r.volume_terima) from '.self::RI.' r where r.id_po_supplier = a.id_master) as vol_terima'),
        ])->paginate($per);

        $data = collect($page->items())->map(fn ($p) => [
            'id' => (int) $p->id_master, 'nomor_po' => $p->nomor_po, 'tanggal' => $p->tanggal_inven, 'vendor' => $p->nama_vendor, 'terminal' => self::terminal($p),
            'produk' => $p->jenis_produk.' - '.$p->merk_dagang, 'volume_po' => (float) $p->volume_po, 'vol_bl' => (float) $p->vol_bl, 'vol_terima' => (float) $p->vol_terima,
            'harga_tebus' => (float) $p->harga_tebus, 'status' => explode('|', self::status($p))[0], 'status_tgl' => explode('|', self::status($p))[1] ?? null, 'is_cancel' => (int) $p->is_cancel === 1, 'keterangan_cancel' => $p->keterangan_cancel,
            'is_close' => (int) $p->is_close === 1, 'volume_close' => (float) $p->volume_close, 'aksi' => self::aksi($p),
        ])->values();

        return response()->json(['data' => $data, 'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage()]]);
    }

    public function show(int $id): JsonResponse
    {
        $p = $this->base()->where('a.id_master', $id)->first(['a.*', 'c.jenis_produk', 'c.merk_dagang', 'd.nama_vendor', 'e.nama_terminal', 'e.tanki_terminal', 'e.lokasi_terminal']);
        if (! $p) {
            return response()->json(['message' => 'PO tidak ditemukan.'], 404);
        }
        $ri = DB::table(self::RI)->where('id_po_supplier', $id)->first(['volume_bol', 'volume_terima']);
        $out = (array) $p;
        $out['terminal'] = self::terminal($p);
        $out['status'] = explode('|', self::status($p))[0];
        $out['status_tgl'] = explode('|', self::status($p))[1] ?? null;
        $out['volume_bol'] = $ri ? (float) $ri->volume_bol : 0;
        $out['volume_terima'] = $ri ? (float) $ri->volume_terima : 0;
        $out['gain_loss'] = (float) $p->volume_ri > 0 ? (float) $p->volume_ri - (float) ($ri->volume_bol ?? 0) : null;
        $out['bisa_ubah'] = (int) $p->is_cancel !== 1 && (int) $p->is_close !== 1;
        $out['bisa_ajukan'] = (int) $p->disposisi_po === 0 && $out['bisa_ubah'];
        $out['aksi'] = self::aksi($p);

        return response()->json($out);
    }

    // ------------------------------------------------------------------ Tambah dan ubah

    private static function angka(float $volume, float $harga, string $tax): array
    {
        $sub = $volume * $harga;
        $ppn = $tax === 'EC' ? 0.0 : round($sub * 11 / 100); // form web: PPN 11% (toFixed(0)); kode tax EC tanpa PPN
        $totalOrder = $sub + $ppn;

        return ['subtotal' => round($sub, 4), 'ppn_11' => $ppn, 'total_order' => round($totalOrder, 4)];
    }

    private static function catatan(?string $s): string
    {
        return str_replace(["\r\n", "\r", "\n"], '<br />', htmlspecialchars((string) $s, ENT_QUOTES));
    }

    private function aturan(bool $baru): array
    {
        return [
            'tanggal' => ['required', 'date_format:Y-m-d'],
            'id_produk' => [$baru ? 'required' : 'nullable', 'integer'],
            'id_terminal' => [$baru ? 'required' : 'nullable', 'integer'],
            'id_vendor' => [$baru ? 'required' : 'nullable', 'integer'],
            'terms' => ['required', 'in:COD,NET,CBD'],
            'terms_day' => ['nullable', 'required_if:terms,NET', 'integer', 'min:0', 'max:999'],
            'kd_tax' => ['required', 'in:E,EC'],
            'volume_po' => ['required', 'numeric', 'gt:0'],
            'harga_tebus' => ['required', 'numeric', 'gt:0'],
            'deskripsi' => ['required', 'string', 'max:2000'],
            'ket' => ['required', 'string', 'max:4000'],
            'terms_condition' => ['nullable', 'string', 'max:8000'],
        ];
    }

    private function nextId(): string
    {
        $prefix = date('Ym');
        try {
            $n = DB::selectOne('select LPAD(cast((select nextval(new_pro_inventory_vendor_po_crushed_stone1_seq)) as char(10)), 9, \'0\') as n')->n;
        } catch (\Throwable) {
            $max = DB::table(self::PO)->where('id_master', 'like', $prefix.'%')->max('id_master');
            $n = str_pad((string) (((int) substr((string) $max, 6)) + 1), 9, '0', STR_PAD_LEFT);
        }

        return $prefix.$n;
    }

    private static function romawi(int $b): string
    {
        return [1 => 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][$b];
    }

    /** Nomor PO: NNN/VENDOR/CABANG/BULAN-ROMAWI/YY, urut per vendor, cabang terminal, dan tahun (aksi add di web). */
    private function nomorBaru(int $vendor, int $terminal, string $tanggal): string
    {
        $cab = DB::table('pro_master_terminal as t')->join('pro_master_cabang as c', 't.id_cabang', '=', 'c.id_master')->where('t.id_master', $terminal)->first(['c.id_master', 'c.inisial_cabang']);
        $ini = DB::table('pro_master_vendor')->where('id_master', $vendor)->value('inisial_vendor');
        $maks = (int) DB::table(self::PO.' as a')->join('pro_master_terminal as b', 'a.id_terminal', '=', 'b.id_master')
            ->where('a.id_vendor', $vendor)->where('b.id_cabang', $cab->id_master)->whereYear('a.tanggal_inven', substr($tanggal, 0, 4))
            ->selectRaw('coalesce(max(cast(substr(a.nomor_po, 1, 3) as unsigned)), 0) as n')->value('n');

        return str_pad((string) ($maks + 1), 3, '0', STR_PAD_LEFT).'/'.strtoupper((string) $ini).'/'.strtoupper((string) $cab->inisial_cabang).'/'.self::romawi((int) substr($tanggal, 5, 2)).'/'.substr($tanggal, 2, 2);
    }

    /** Kolom yang sama untuk tambah dan ubah. */
    private function isiKolom(array $d): array
    {
        $a = self::angka((float) $d['volume_po'], (float) $d['harga_tebus'], $d['kd_tax']);

        return [
            'tanggal_inven' => $d['tanggal'], 'volume_po' => $d['volume_po'], 'harga_tebus' => $d['harga_tebus'], 'kd_tax' => $d['kd_tax'],
            'subtotal' => $a['subtotal'], 'ppn_11' => $a['ppn_11'], 'total_order' => $a['total_order'],
            'terms' => $d['terms'], 'terms_day' => $d['terms'] === 'NET' ? (string) $d['terms_day'] : null,
            // Seperti aksi web: catatan dan deskripsi disimpan ter-escape dengan baris baru asli; hanya syarat & ketentuan yang diubah ke <br />.
            'keterangan' => htmlspecialchars((string) $d['ket'], ENT_QUOTES), 'description' => htmlspecialchars((string) $d['deskripsi'], ENT_QUOTES),
            'terms_condition' => self::catatan($d['terms_condition'] ?? ''),
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate($this->aturan(true));
        foreach ([['pro_master_produk', 'id_produk', 'Produk'], ['pro_master_terminal', 'id_terminal', 'Terminal'], ['pro_master_vendor', 'id_vendor', 'Vendor']] as [$tbl, $f, $nama]) {
            if (! DB::table($tbl)->where('id_master', $d[$f])->where('is_active', 1)->exists()) {
                self::fail($nama.' tidak valid.');
            }
        }
        $id = $this->nextId();
        $nomor = $this->nomorBaru((int) $d['id_vendor'], (int) $d['id_terminal'], $d['tanggal']);
        $user = $request->user();
        DB::table(self::PO)->insert($this->isiKolom($d) + [
            'id_master' => $id, 'id_vendor' => $d['id_vendor'], 'id_produk' => $d['id_produk'], 'id_terminal' => $d['id_terminal'], 'nomor_po' => $nomor, 'disposisi_po' => 0,
            'created_time' => now(), 'created_ip' => (string) $request->ip(), 'created_by' => $user->fullname,
        ]);

        return response()->json(['id' => (int) $id, 'nomor_po' => $nomor, 'message' => 'PO Batu tersimpan.'], 201);
    }

    /** Data awal form ubah. */
    public function ubah(int $id): JsonResponse
    {
        $p = $this->base()->where('a.id_master', $id)->first(['a.*', 'c.jenis_produk', 'c.merk_dagang', 'd.nama_vendor', 'e.nama_terminal', 'e.tanki_terminal', 'e.lokasi_terminal']);
        if (! $p) {
            return response()->json(['message' => 'PO tidak ditemukan.'], 404);
        }
        $out = (array) $p;
        $out['terminal'] = self::terminal($p);
        $out['terms_condition'] = str_replace('<br />', "\n", (string) $p->terms_condition);
        foreach (['keterangan', 'description'] as $k) {
            $out[$k] = html_entity_decode(str_replace('<br />', "\n", (string) $p->$k), ENT_QUOTES);
        }
        $out['terms_condition'] = html_entity_decode($out['terms_condition'], ENT_QUOTES);
        $out['direset'] = isset($p->disposisi_po); // web: "Merubah data ini akan mengulang proses persetujuan"

        return response()->json($out);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $d = $request->validate($this->aturan(false));
        $user = $request->user();

        return DB::transaction(function () use ($id, $d, $user, $request) {
            $po = DB::table(self::PO)->where('id_master', $id)->lockForUpdate()->first();
            if (! $po) {
                return response()->json(['message' => 'PO tidak ditemukan.'], 404);
            }
            if ((int) $po->is_cancel === 1 || (int) $po->is_close === 1) {
                return response()->json(['message' => 'PO yang sudah dibatalkan atau ditutup tidak bisa diubah.', 'code' => 'LOCKED'], 409);
            }
            // Produk, terminal, dan vendor terkunci saat ubah; persetujuan CFO dan CEO diulang dari awal.
            DB::table(self::PO)->where('id_master', $id)->update($this->isiKolom($d) + [
                'disposisi_po' => 0, 'cfo_result' => 0, 'ceo_result' => 0, 'revert_cfo' => 0, 'revert_ceo' => 0,
                'lastupdate_time' => now(), 'lastupdate_ip' => (string) $request->ip(), 'lastupdate_by' => $user->fullname,
            ]);

            return response()->json(['id' => $id, 'nomor_po' => $po->nomor_po, 'message' => 'PO Batu diperbarui. Persetujuan diulang dari awal.']);
        });
    }

    /** Tombol "Persetujuan" di detail (po-izin.php): PO Terdaftar diajukan ke CFO. */
    public function ajukan(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        return DB::transaction(function () use ($id, $user) {
            $po = DB::table(self::PO)->where('id_master', $id)->lockForUpdate()->first();
            if (! $po) {
                return response()->json(['message' => 'PO tidak ditemukan.'], 404);
            }
            if ((int) $po->disposisi_po !== 0 || (int) $po->is_cancel === 1 || (int) $po->is_close === 1) {
                return response()->json(['message' => 'PO ini tidak bisa diajukan untuk persetujuan.', 'code' => 'NOT_REGISTERED'], 409);
            }
            DB::table(self::PO)->where('id_master', $id)->update(['disposisi_po' => 1]);
            // po-izin.php: disposisi_po = 1, email ke semua CFO (peran 4).
            ApprovalMail::toRoles([4], null, 'Persetujuan PO crushed stone ['.ApprovalMail::stamp().']', e($user->fullname).' Meminta Persetujuan Po Supplier Crushed Stone');

            return response()->json(['message' => 'Persetujuan untuk PO crushed stone '.$po->nomor_po.' sudah diajukan']);
        });
    }
}
