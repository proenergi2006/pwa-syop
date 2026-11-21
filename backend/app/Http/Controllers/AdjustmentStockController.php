<?php

namespace App\Http\Controllers;

use App\Services\Accurate\AccurateClient;
use App\Services\Accurate\AccurateException;
use App\Services\Accurate\AccurateTiSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Adjustment Stock (Procurement, peran 5). Sumber: adjustment-stock.php (halaman), datatable/adjustment-stock.php (daftar),
 * adjustment-stock-detail.php (detail) dan web/action/adjustment-stock.php (aksi hapus).
 * Tabel new_pro_inventory_depot: id_jenis 3 = Adjustment, 4 = Transfer Stock (dua sisi: OUT adj_inven < 0 dan IN adj_inven > 0 dengan id_datanya sama),
 * 5 = Purchase Return. Dokumen di Accurate: item-adjustment (3 dan 4) dan purchase-return (5), terkait lewat kolom id_accurate.
 */
class AdjustmentStockController extends Controller
{
    private const T = 'new_pro_inventory_depot';

    private const JENIS = [3 => 'Adjustment', 4 => 'Transfer Stock', 5 => 'Purchase Return'];

    private static function fail(string $m, string $c = 'INVALID', int $s = 422)
    {
        abort(response()->json(['message' => $m, 'code' => $c], $s));
    }

    public function opsi(): JsonResponse
    {
        return response()->json(['jenis' => collect(self::JENIS)->map(fn ($l, $k) => ['id' => $k, 'label' => $l])->values()]);
    }

    /** Subkueri gabungan seperti datatable web: Adjustment/Purchase Return per baris, Transfer Stock per pasangan OUT-IN (nilai = total OUT). */
    private const GABUNG = "
        WITH tf_dari AS (
            SELECT MIN(a.id_master) AS id_master, a.id_datanya, a.id_produk, CONCAT(c.jenis_produk, ' - ', c.merk_dagang) AS ket_produk,
                CONCAT(b.nama_terminal, ' ', b.tanki_terminal) AS ket_terminal, a.tanggal_inven, SUM(a.adj_inven) AS nilai,
                MAX(a.keterangan) AS keterangan, MAX(a.lastupdate_time) AS lastupdate_time
            FROM new_pro_inventory_depot a
            JOIN pro_master_terminal b ON a.id_terminal = b.id_master
            JOIN pro_master_produk c ON a.id_produk = c.id_master
            WHERE a.id_jenis = 4 AND a.adj_inven < 0
            GROUP BY a.id_datanya, a.id_produk, a.id_terminal, a.tanggal_inven, c.jenis_produk, c.merk_dagang, b.nama_terminal, b.tanki_terminal
        ),
        tf_ke AS (
            SELECT a.id_datanya, CONCAT(b.nama_terminal, ' ', b.tanki_terminal) AS ket_terminal
            FROM new_pro_inventory_depot a
            JOIN pro_master_terminal b ON a.id_terminal = b.id_master
            WHERE a.id_jenis = 4 AND a.adj_inven > 0
            GROUP BY a.id_datanya, a.id_terminal, b.nama_terminal, b.tanki_terminal
        )
        SELECT * FROM (
            SELECT a.id_master, a.id_datanya, a.id_jenis, CASE WHEN a.id_jenis = 3 THEN 'Adjustment' ELSE 'Purchase Return' END AS jenis,
                CONCAT(c.jenis_produk, ' - ', c.merk_dagang) AS ket_produk, CONCAT(b.nama_terminal, ' ', b.tanki_terminal) AS ket_terminal,
                CAST(NULL AS CHAR) AS terminal_ke, a.tanggal_inven, a.adj_inven AS nilai, a.keterangan, a.lastupdate_time
            FROM new_pro_inventory_depot a
            JOIN pro_master_terminal b ON a.id_terminal = b.id_master
            JOIN pro_master_produk c ON a.id_produk = c.id_master
            WHERE a.id_jenis IN (3, 5)
            UNION ALL
            SELECT d.id_master, d.id_datanya, 4, 'Transfer Stock', d.ket_produk, d.ket_terminal, k.ket_terminal,
                d.tanggal_inven, d.nilai * -1, d.keterangan, d.lastupdate_time
            FROM tf_dari d JOIN tf_ke k ON d.id_datanya = k.id_datanya
        ) x WHERE 1 = 1";

    public function index(Request $r): JsonResponse
    {
        $sql = self::GABUNG;
        $bind = [];
        if (($q = trim((string) $r->query('q', ''))) !== '') {
            $sql .= " AND (CONCAT(x.ket_terminal, ' ', IFNULL(x.terminal_ke, '')) LIKE ? OR x.keterangan LIKE ? OR x.nilai LIKE ?)";
            array_push($bind, "%{$q}%", "%{$q}%", "%{$q}%");
        }
        if (($t = trim((string) $r->query('tanggal', ''))) !== '') {
            $sql .= ' AND x.tanggal_inven = ?';
            $bind[] = $t;
        }
        if (($j = (int) $r->query('jenis', 0)) > 0) {
            $sql .= ' AND x.id_jenis = ?';
            $bind[] = $j;
        }

        $per = min(max((int) $r->query('per_page', 25), 1), (int) config('orca.per_page_max', 100));
        $page = max((int) $r->query('page', 1), 1);
        $total = (int) DB::selectOne(str_replace('SELECT * FROM (', 'SELECT COUNT(*) AS n FROM (', $sql), $bind)->n;
        $rows = DB::select($sql.' ORDER BY x.tanggal_inven DESC, x.id_master DESC LIMIT '.$per.' OFFSET '.(($page - 1) * $per), $bind);

        // Sudah terkirim ke Accurate? (satu kueri untuk seluruh halaman)
        $acc = $rows ? DB::table(self::T)->whereIn('id_datanya', array_column($rows, 'id_datanya'))->whereNotNull('id_accurate')->distinct()->pluck('id_datanya')->flip()->all() : [];

        $data = array_map(fn ($x) => [
            'id' => (int) $x->id_master, 'id_jenis' => (int) $x->id_jenis, 'jenis' => $x->jenis, 'tanggal' => $x->tanggal_inven, 'produk' => $x->ket_produk,
            'terminal' => $x->ket_terminal, 'terminal_ke' => $x->terminal_ke, 'nilai' => round((float) $x->nilai, 2), 'keterangan' => $x->keterangan,
            'di_accurate' => isset($acc[$x->id_datanya]),
        ], $rows);

        return response()->json(['data' => $data, 'meta' => ['page' => $page, 'per_page' => $per, 'total' => $total, 'last' => max((int) ceil($total / $per), 1)]]);
    }

    /** Detail satu data: Adjustment/Purchase Return = satu baris; Transfer Stock = baris OUT (dari) dan IN (ke) dengan id_datanya sama. */
    public function show(int $id): JsonResponse
    {
        $a = DB::table(self::T.' as a')->leftJoin('new_pro_inventory_vendor_po as p', 'a.id_po_supplier', '=', 'p.id_master')
            ->leftJoin('pro_master_produk as pr', 'a.id_produk', '=', 'pr.id_master')
            ->whereIn('a.id_jenis', array_keys(self::JENIS))->where('a.id_master', $id)
            ->first(['a.*', 'p.nomor_po', 'p.tanggal_inven as tgl_po', DB::raw("concat(pr.jenis_produk,' - ',pr.merk_dagang) as produk")]);
        if (! $a) {
            self::fail('Data tidak ditemukan.', 'NOT_FOUND', 404);
        }
        $terminal = fn ($tid) => ($t = DB::table('pro_master_terminal')->where('id_master', $tid)->first(['nama_terminal', 'tanki_terminal'])) ? $t->nama_terminal.' - '.$t->tanki_terminal : null;
        $out = [
            'id' => (int) $a->id_master, 'id_jenis' => (int) $a->id_jenis, 'jenis' => self::JENIS[(int) $a->id_jenis], 'produk' => $a->produk, 'tanggal' => $a->tanggal_inven,
            'keterangan' => $a->keterangan, 'di_accurate' => DB::table(self::T)->where('id_datanya', $a->id_datanya)->whereNotNull('id_accurate')->exists(),
        ];

        if ((int) $a->id_jenis === 4) {
            $dari = DB::table(self::T.' as a')->leftJoin('new_pro_inventory_vendor_po as p', 'a.id_po_supplier', '=', 'p.id_master')
                ->where('a.id_datanya', $a->id_datanya)->where('a.adj_inven', '<', 0)->orderBy('a.id_master')
                ->get(['a.id_terminal', 'a.adj_inven', 'a.tanggal_inven', 'p.nomor_po', 'p.tanggal_inven as tgl_po']);
            $ke = DB::table(self::T)->where('id_datanya', $a->id_datanya)->where('adj_inven', '>', 0)->orderBy('id_master')->first();
            $out += [
                'terminal_dari' => $terminal($dari->first()->id_terminal ?? $a->id_terminal),
                'terminal_ke' => $ke ? $terminal($ke->id_terminal) : null,
                'tanggal_terima' => $ke->tanggal_inven ?? null,
                'harga_transfer' => $ke ? (float) $ke->harga : null,
                'rincian' => $dari->map(fn ($d) => ['nomor_po' => $d->nomor_po, 'tgl_po' => $d->tgl_po, 'tgl_transfer' => $d->tanggal_inven, 'volume' => abs((float) $d->adj_inven)])->values(),
                'total' => (float) $dari->sum(fn ($d) => abs((float) $d->adj_inven)),
            ];
        } else {
            $out += ['terminal' => $terminal($a->id_terminal), 'nomor_po' => $a->nomor_po, 'tgl_po' => $a->tgl_po, 'volume' => (float) $a->adj_inven];
            if ((int) $a->id_jenis === 5) {
                $out['harga'] = (float) $a->harga;
            }
        }

        return response()->json($out);
    }

    // ---- form tambah (getoptdepotinventory.php, getoptdepotinventory_listpo.php, action add jenis 4) ----

    /** Pilihan form: produk aktif dan status koneksi Accurate. */
    public function formOpsi(AccurateTiSync $sync): JsonResponse
    {
        return response()->json([
            'produk' => DB::table('pro_master_produk')->where('is_active', 1)->orderBy('no_urut')->get(['id_master', 'jenis_produk', 'merk_dagang'])
                ->map(fn ($p) => ['id' => (int) $p->id_master, 'label' => $p->jenis_produk.' - '.$p->merk_dagang])->values(),
            'accurate' => $sync->enabled(),
        ]);
    }

    /** Terminal aktif yang sudah punya Data Awal (id_jenis 1) untuk produk tsb; dipakai untuk terminal asal dan tujuan (getoptdepotinventory.php). */
    private function terminalProduk(int $produk): array
    {
        return DB::table('pro_master_terminal as a')->join('pro_master_cabang as c', 'a.id_cabang', '=', 'c.id_master')
            ->whereExists(fn ($e) => $e->select(DB::raw(1))->from(self::T.' as b')->whereColumn('b.id_terminal', 'a.id_master')->where('b.id_jenis', 1)->where('b.id_produk', $produk))
            ->where('a.is_active', 1)->orderBy('a.id_master')->get(['a.id_master', 'a.nama_terminal', 'a.tanki_terminal', 'a.lokasi_terminal'])
            ->map(fn ($t) => ['id' => (int) $t->id_master, 'label' => $t->nama_terminal.' '.$t->tanki_terminal.', '.$t->lokasi_terminal])->all();
    }

    public function terminal(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->terminalProduk((int) $r->query('produk', 0))]);
    }

    /** PO penerimaan di terminal+produk dengan sisa volume > 0, termasuk PO blending (getoptdepotinventory_listpo.php jenis 1). */
    private function poSisa(int $terminal, int $produk): array
    {
        $rows = DB::select("
            with tbl_realisasi as (
                select a.id_po_supplier, a.id_po_receive, c.nomor_po, c.tanggal_inven as tgl_po, c.id_vendor, a.tgl_terima, a.volume_terima,
                    sum(in_inven) as in_inven, sum(out_inven) as out_inven, sum(adj_inven) as adj_inven, sum(out_inven_virtual) as out_inven_virtual
                from new_pro_inventory_depot b
                join new_pro_inventory_vendor_po_receive a on a.id_po_supplier = b.id_po_supplier and a.id_po_receive = b.id_po_receive
                join new_pro_inventory_vendor_po c on a.id_po_supplier = c.id_master
                where b.id_produk = ? and b.id_terminal = ? and a.is_aktif = 1 and b.id_po_supplier is not null
                group by b.id_po_supplier, b.id_po_receive, a.id_po_supplier, a.id_po_receive, c.nomor_po, c.tanggal_inven, c.id_vendor, a.tgl_terima, a.volume_terima
                union all
                select a.id_po_supplier, a.id_po_receive, a.nomor_blending_po as nomor_po, a.tanggal_blending as tgl_po, a.id_vendor_blending as id_vendor,
                    a.tanggal_blending as tgl_terima, a.volume_total as volume_terima,
                    sum(in_inven) as in_inven, sum(out_inven) as out_inven, sum(adj_inven) as adj_inven, sum(out_inven_virtual) as out_inven_virtual
                from new_pro_inventory_depot b
                join pro_blending_po a on a.id_po_supplier = b.id_po_supplier and a.id_po_receive = b.id_po_receive
                where b.id_produk = ? and b.id_terminal = ? and b.id_po_supplier is not null
                group by b.id_po_supplier, b.id_po_receive, a.id_po_supplier, a.id_po_receive, a.nomor_blending_po, a.tanggal_blending, a.id_vendor_blending, a.volume_total
            )
            select a.nomor_po, a.tgl_po, b.nama_vendor, a.id_po_supplier, a.id_po_receive, a.tgl_terima, a.volume_terima,
                ((a.in_inven + a.adj_inven) - (a.out_inven + a.out_inven_virtual)) as sisa_inven
            from tbl_realisasi a join pro_master_vendor b on a.id_vendor = b.id_master
            order by a.tgl_terima desc, a.id_po_receive desc", [$produk, $terminal, $produk, $terminal]);

        return array_values(array_map(fn ($x) => [
            'id_po_supplier' => (int) $x->id_po_supplier, 'id_po_receive' => (int) $x->id_po_receive, 'nomor_po' => $x->nomor_po, 'tgl_po' => $x->tgl_po, 'vendor' => $x->nama_vendor,
            'tgl_terima' => $x->tgl_terima, 'volume_terima' => (float) $x->volume_terima, 'sisa' => (float) $x->sisa_inven,
        ], array_filter($rows, fn ($x) => (float) $x->sisa_inven > 0)));
    }

    public function poTransfer(Request $r): JsonResponse
    {
        $terminal = (int) $r->query('terminal', 0);
        $produk = (int) $r->query('produk', 0);
        if (! in_array($terminal, array_column($this->terminalProduk($produk), 'id'), true)) {
            return response()->json(['data' => []]);
        }

        return response()->json(['data' => $this->poSisa($terminal, $produk)]);
    }

    private static function tglDb(string $d): string
    {
        return date('Y-m-d', strtotime($d));
    }

    /** Tambah data: Adjustment (id_jenis 3) atau Transfer Stock (id_jenis 4). Purchase Return dinonaktifkan di form web, jadi tidak dibuat di sini. */
    public function store(Request $request, AccurateTiSync $sync): JsonResponse
    {
        $request->validate(['id_jenis' => 'required|in:3,4']);

        return (int) $request->input('id_jenis') === 3 ? $this->simpanAdjustment($request, $sync) : $this->simpanTransfer($request, $sync);
    }

    /**
     * Adjustment: satu baris di new_pro_inventory_depot (adj_inven bertanda sesuai Gain/Loss, tanpa kolom harga seperti web) dan satu dokumen item-adjustment di Accurate
     * (Gain = ADJUSTMENT_IN dengan unitCost, Loss = ADJUSTMENT_OUT). PO dipilih dari PO terminal yang masih bersisa (pemilih PO web).
     */
    private function simpanAdjustment(Request $request, AccurateTiSync $sync): JsonResponse
    {
        $on = $sync->enabled();
        $d = $request->validate([
            'id_produk' => 'required|integer', 'tanggal' => 'required|date', 'id_terminal' => 'required|integer',
            'id_po_supplier' => 'required|integer', 'id_po_receive' => 'required|integer',
            'tanda' => 'required|in:+,-', 'volume' => 'required|numeric|gt:0', 'harga' => 'required_if:tanda,+|nullable|numeric|min:0', 'keterangan' => 'nullable|string|max:5000',
            'nomor_penyesuaian' => ($on ? 'required' : 'nullable').'|string|max:100', 'kode_item' => ($on ? 'required' : 'nullable').'|string|max:100',
            'akun_penyesuaian' => ($on ? 'required' : 'nullable').'|string|max:100',
        ]);
        $produk = (int) $d['id_produk'];
        if (! in_array((int) $d['id_terminal'], array_column($this->terminalProduk($produk), 'id'), true)) {
            self::fail('Terminal tidak valid untuk produk yang dipilih.');
        }
        $po = collect($this->poSisa((int) $d['id_terminal'], $produk))->first(fn ($x) => $x['id_po_supplier'] === (int) $d['id_po_supplier'] && $x['id_po_receive'] === (int) $d['id_po_receive']);
        if (! $po) {
            self::fail('PO tidak tersedia di terminal yang dipilih.');
        }
        $vol = round((float) $d['volume'], 2);
        if ($vol <= 0) {
            self::fail('Volume harus lebih dari 0.');
        }
        if ($d['tanda'] === '-' && $vol > $po['sisa'] + 0.0001) {
            self::fail('Volume loss melebihi sisa PO ('.number_format($po['sisa'], 2).' L).');
        }

        $user = $request->user();
        $ip = $request->ip();
        $tgl = self::tglDb($d['tanggal']);
        $cabangUser = DB::table('pro_master_cabang')->where('id_master', $user->id_wilayah)->value('nama_cabang');
        $gudang = DB::table('pro_master_terminal as a')->join('pro_master_cabang as b', 'a.id_cabang', '=', 'b.id_master')->where('a.id_master', $d['id_terminal'])->first(['b.inisial_cabang', 'b.nama_cabang']);
        $ds = md5(uniqid('1089', true).'-'.intval(microtime(true)).'-'.date('YmdHis'));
        $posted = [];

        try {
            DB::transaction(function () use ($d, $produk, $ds, $tgl, $vol, $user, $ip, $on, $sync, $cabangUser, $gudang, &$posted) {
                $id = DB::table(self::T)->insertGetId([
                    'id_datanya' => $ds, 'id_jenis' => 3, 'id_produk' => $produk, 'id_terminal' => $d['id_terminal'], 'id_po_supplier' => $d['id_po_supplier'], 'id_po_receive' => $d['id_po_receive'],
                    'tanggal_inven' => $tgl, 'adj_inven' => $d['tanda'] === '-' ? -$vol : $vol, 'keterangan' => $d['keterangan'] ?? null,
                    'created_time' => now(), 'created_ip' => $ip, 'created_by' => $user->fullname, 'lastupdate_time' => now(), 'lastupdate_ip' => $ip, 'lastupdate_by' => $user->fullname,
                ]);
                if (! $on) {
                    return;
                }
                $tglA = date('d/m/Y', strtotime($tgl));
                $acc = $d['tanda'] === '+'
                    ? $sync->in($d['nomor_penyesuaian'], $d['akun_penyesuaian'], $tglA, $d['keterangan'] ?? null, $cabangUser, $d['kode_item'], $vol, (float) $d['harga'], $gudang->inisial_cabang ?? null, $gudang->nama_cabang ?? null)
                    : $sync->out($d['nomor_penyesuaian'], $d['akun_penyesuaian'], $tglA, $d['keterangan'] ?? null, $cabangUser, $d['kode_item'], $vol, $gudang->inisial_cabang ?? null, $gudang->nama_cabang ?? null);
                $posted[] = $acc;
                DB::table(self::T)->where('id_master', $id)->update(['id_accurate' => $acc]);
            });
        } catch (AccurateException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'ACCURATE'], 502);
        }

        return response()->json(['message' => 'Data berhasil disimpan', 'accurate_manual' => ! $on], 201);
    }

    private function simpanTransfer(Request $request, AccurateTiSync $sync): JsonResponse
    {
        $on = $sync->enabled();
        $d = $request->validate([
            'id_produk' => 'required|integer', 'tanggal' => 'required|date', 'tanggal_terima' => 'required|date',
            'terminal_dari' => 'required|integer', 'terminal_ke' => 'required|integer|different:terminal_dari',
            'rincian' => 'required|array|min:1', 'rincian.*.id_po_supplier' => 'required|integer', 'rincian.*.id_po_receive' => 'required|integer', 'rincian.*.volume' => 'nullable|numeric|min:0',
            'harga_transfer' => 'required|numeric|min:0', 'keterangan' => 'nullable|string|max:5000',
            'nomor_penyesuaian' => ($on ? 'required' : 'nullable').'|string|max:100', 'nomor_penyesuaian_terima' => ($on ? 'required' : 'nullable').'|string|max:100',
            'kode_item' => ($on ? 'required' : 'nullable').'|string|max:100', 'kode_item_terima' => ($on ? 'required' : 'nullable').'|string|max:100',
            'akun_penyesuaian' => ($on ? 'required' : 'nullable').'|string|max:100',
        ]);
        $produk = (int) $d['id_produk'];
        foreach (['terminal_dari', 'terminal_ke'] as $k) {
            if (! in_array((int) $d[$k], array_column($this->terminalProduk($produk), 'id'), true)) {
                self::fail('Terminal tidak valid untuk produk yang dipilih.');
            }
        }
        // Volume bulat (web membuang pemisah ribuan), hanya baris berisi > 0, tiap PO sekali.
        $isi = [];
        foreach ($d['rincian'] as $b) {
            $v = (int) round((float) ($b['volume'] ?? 0));
            if ($v > 0) {
                $k = $b['id_po_supplier'].'|'.$b['id_po_receive'];
                if (isset($isi[$k])) {
                    self::fail('PO yang sama dipilih lebih dari sekali.');
                }
                $isi[$k] = ['ps' => (int) $b['id_po_supplier'], 'pr' => (int) $b['id_po_receive'], 'v' => $v];
            }
        }
        if (! $isi) {
            self::fail('Isi volume transfer minimal pada satu PO.');
        }
        $sisa = collect($this->poSisa((int) $d['terminal_dari'], $produk))->keyBy(fn ($x) => $x['id_po_supplier'].'|'.$x['id_po_receive']);
        foreach ($isi as $k => $x) {
            if (! isset($sisa[$k])) {
                self::fail('Ada PO yang tidak tersedia di terminal asal.');
            }
            if ($x['v'] > $sisa[$k]['sisa'] + 0.0001) {
                self::fail('Volume transfer PO '.$sisa[$k]['nomor_po'].' melebihi sisa ('.number_format($sisa[$k]['sisa']).' L).');
            }
        }

        $user = $request->user();
        $ip = $request->ip();
        $total = array_sum(array_column($isi, 'v'));
        $tgl = self::tglDb($d['tanggal']);
        $tglTerima = self::tglDb($d['tanggal_terima']);
        $cabangUser = DB::table('pro_master_cabang')->where('id_master', $user->id_wilayah)->value('nama_cabang');
        $gudang = fn ($t) => DB::table('pro_master_terminal as a')->join('pro_master_cabang as b', 'a.id_cabang', '=', 'b.id_master')->where('a.id_master', $t)->first(['b.inisial_cabang', 'b.nama_cabang']);
        $gOut = $gudang($d['terminal_dari']);
        $gIn = $gudang($d['terminal_ke']);
        $ds = md5(uniqid('1089', true).'-'.intval(microtime(true)).'-'.date('YmdHis'));
        $posted = [];

        try {
            DB::transaction(function () use ($d, $isi, $produk, $ds, $tgl, $tglTerima, $user, $ip, $total, $on, $sync, $cabangUser, $gOut, $gIn, &$posted) {
                $dasar = ['id_datanya' => $ds, 'id_jenis' => 4, 'id_produk' => $produk, 'keterangan' => $d['keterangan'] ?? null, 'created_time' => now(), 'created_ip' => $ip, 'created_by' => $user->fullname,
                    'lastupdate_time' => now(), 'lastupdate_ip' => $ip, 'lastupdate_by' => $user->fullname];
                foreach ($isi as $x) {
                    DB::table(self::T)->insert($dasar + ['id_terminal' => $d['terminal_dari'], 'id_po_supplier' => $x['ps'], 'id_po_receive' => $x['pr'], 'tanggal_inven' => $tgl, 'adj_inven' => -$x['v']]);
                    DB::table(self::T)->insert($dasar + ['id_terminal' => $d['terminal_ke'], 'id_po_supplier' => $x['ps'], 'id_po_receive' => $x['pr'], 'tanggal_inven' => $tglTerima, 'adj_inven' => $x['v'], 'harga' => $d['harga_transfer']]);
                }
                if (! $on) {
                    return;
                }
                $idOut = $sync->out($d['nomor_penyesuaian'], $d['akun_penyesuaian'], date('d/m/Y', strtotime($tgl)), $d['keterangan'] ?? null, $cabangUser, $d['kode_item'], (float) $total, $gOut->inisial_cabang ?? null, $gOut->nama_cabang ?? null);
                $posted[] = $idOut;
                DB::table(self::T)->where('id_datanya', $ds)->where('adj_inven', '<', 0)->update(['id_accurate' => $idOut]);
                // departemen transaksi IN mengikuti cabang terminal asal, seperti web
                $idIn = $sync->in($d['nomor_penyesuaian_terima'], $d['akun_penyesuaian'], date('d/m/Y', strtotime($tglTerima)), $d['keterangan'] ?? null, $cabangUser, $d['kode_item_terima'], (float) $total, (float) $d['harga_transfer'], $gIn->inisial_cabang ?? null, $gOut->nama_cabang ?? null);
                $posted[] = $idIn;
                DB::table(self::T)->where('id_datanya', $ds)->where('adj_inven', '>', 0)->update(['id_accurate' => $idIn]);
            });
        } catch (AccurateException $e) {
            // DB sudah dibatalkan; transaksi OUT yang telanjur masuk di Accurate dihapus kembali (web membiarkannya).
            $gagal = $posted ? $sync->batalkan($posted) : [];

            return response()->json(['message' => $e->getMessage().($gagal ? '. Transaksi Accurate berikut gagal dibatalkan otomatis, mohon dihapus manual (id): '.implode(', ', $gagal) : ''), 'code' => 'ACCURATE'], 502);
        }

        return response()->json(['message' => 'Data berhasil disimpan', 'accurate_manual' => ! $on], 201);
    }

    /**
     * Hapus data (aksi "hapus" web). Dokumen yang sudah terkirim dihapus dulu di Accurate (item-adjustment/delete.do atau purchase-return/delete.do);
     * gagal di Accurate = data lokal tidak dihapus (transaksi database dibatalkan), pesan "<pesan Accurate> - Response dari Accurate".
     */
    public function destroy(int $id, AccurateClient $api): JsonResponse
    {
        $a = DB::table(self::T)->whereIn('id_jenis', array_keys(self::JENIS))->where('id_master', $id)->first(['id_master', 'id_jenis', 'id_datanya']);
        if (! $a) {
            self::fail('Data tidak ditemukan.', 'NOT_FOUND', 404);
        }
        $ds = $a->id_datanya;
        $path = (int) $a->id_jenis === 5 ? 'purchase-return/delete.do' : 'item-adjustment/delete.do';
        $ids = DB::table(self::T)->where('id_datanya', $ds)->whereNotNull('id_accurate')->distinct()->pluck('id_accurate')->all();
        if ($ids && ! $api->enabled()) {
            self::fail('Data ini sudah terkirim ke Accurate, tetapi koneksi Accurate belum aktif di server. Hapus tidak dapat dilakukan.', 'ACCURATE_OFF');
        }

        $terhapus = 0;
        $pesan = null;
        DB::beginTransaction();
        try {
            foreach ($ids as $acc) {
                $res = $api->post($path, ['id' => $acc]);
                if (empty($res['s'])) {
                    $pesan ??= ($res['d'][0] ?? 'Gagal terhubung ke Accurate').' - Response dari Accurate';

                    continue;
                }
                $terhapus++;
                DB::table(self::T)->where('id_datanya', $ds)->where('id_accurate', $acc)->delete();
            }
            if ($pesan === null) {
                DB::table(self::T)->where('id_datanya', $ds)->whereNull('id_accurate')->delete();
                DB::commit();

                return response()->json(['message' => 'Data berhasil dihapus']);
            }
            DB::rollBack();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        // Sebagian dokumen Accurate bisa sudah terhapus sebelum yang lain gagal; data lokal tetap utuh (sama dengan web), jadi beri tahu pengguna.
        self::fail($pesan.($terhapus ? " ({$terhapus} dokumen sudah terhapus di Accurate; hubungi admin untuk merapikan)" : ''), 'ACCURATE', 422);
    }
}
