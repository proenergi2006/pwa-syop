<?php

namespace App\Http\Controllers;

use App\Services\Accurate\AccurateException;
use App\Services\Accurate\AccurateTiSync;
use App\Services\LegacyFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Trucking Instruction Receive (Procurement). Sumber: trucking-instruction-receive.php (halaman) dan web/action/trucking-instruction.php
 * (aksi received dan approve-cancel). Received memindahkan stok dari depot loading ke depot discharging (baris depot jenis 4) dan mencatat
 * penyesuaian OUT/IN di Accurate. Detail Accurate di web (__get_detail_accurate_ti.php) belum ada; PWA menampilkan transaksi dari data depot.
 */
class TruckingTiTerimaController extends Controller
{
    private const T = 'pro_trucking_instruction_req';

    private static function fail(string $m, string $c = 'INVALID', int $s = 422)
    {
        abort(response()->json(['message' => $m, 'code' => $c], $s));
    }

    private function ti(int $id): object
    {
        $a = DB::table(self::T.' as a')
            ->join('pro_master_terminal as b', 'a.discharging_port', '=', 'b.id_master')->join('pro_master_terminal as c', 'a.loading_port', '=', 'c.id_master')
            ->join('pro_master_transportir as d', 'a.id_transportir', '=', 'd.id_master')->join('pro_master_cabang as e', 'a.id_wilayah', '=', 'e.id_master')
            ->where('a.id_master', $id)
            ->first(['a.*', DB::raw("concat(b.nama_terminal,' - ',b.tanki_terminal,' - ',b.lokasi_terminal) as discharge_port"), DB::raw("concat(c.nama_terminal,' - ',c.tanki_terminal,' - ',c.lokasi_terminal) as load_port"),
                DB::raw("concat(d.nama_suplier,', ',d.nama_transportir) as nama_transportir"), 'e.nama_cabang']);
        if (! $a) {
            self::fail('Trucking Instruction tidak ditemukan.', 'NOT_FOUND', 404);
        }

        return $a;
    }

    /** Baris DS/DR trucking pada TI ini (query halaman web, kolom yang dipakai saja). */
    private function baris(int $id): array
    {
        return DB::select("
            select a.id_pod_ti, c.id_prd_ti, c.id_plan, d.nomor_pr_ti, c.nomor_lo_pr_ti, c.nomor_po_supplier, c.vol_potongan, l.nomor_po_supplier as nomor_potong, l.volume as volume_potong,
                   u.realisasi_volume, u.tgl_realisasi, u.id_dsd_ti, u.is_request, u.is_approved, u.disposisi_request, u.request, u.is_cancel, u.file_sj_ti, u.file_sj_ti_ori
            from pro_po_trucking_detail a
            join pro_po_trucking b on a.id_po_ti = b.id_po_ti
            join pro_pr_trucking_detail c on a.id_prd_ti = c.id_prd_ti
            join pro_pr_trucking d on c.id_pr_ti = d.id_pr_ti
            join pro_trucking_plan e on c.id_plan = e.id_plan
            join pro_trucking_instruction_req f on e.id_trucking = f.id_master
            left join pro_po_ds_trucking_detail u on a.id_pod_ti = u.id_pod_ti
            left join new_pro_inventory_potongan_stock l on c.id_prd_ti = l.id_prd_ti
            where f.id_master = ? order by a.pod_approved desc", [$id]);
    }

    private function grupPo(int $id): array
    {
        return DB::select('
            select dep.id_po_supplier, dep.id_po_receive, vp.nomor_po, sum(abs(dep.adj_inven)) as vol_potong
            from new_pro_inventory_depot dep
            join pro_pr_trucking_detail c on dep.id_prd_ti = c.id_prd_ti
            join pro_trucking_plan e on c.id_plan = e.id_plan
            left join new_pro_inventory_vendor_po vp on dep.id_po_supplier = vp.id_master
            where e.id_trucking = ? and dep.adj_inven < 0
            group by dep.id_po_supplier, dep.id_po_receive, vp.nomor_po order by vp.nomor_po', [$id]);
    }

    public function show(int $id): JsonResponse
    {
        $a = $this->ti($id);
        $rows = collect($this->baris($id))->map(function ($r) {
            $req = '';
            if ((int) $r->is_request !== 0) {
                $req = (int) $r->is_request === 2 ? 'Request Reschedule' : ((int) $r->is_approved === 0 ? 'Request Cancel' : 'Approved Cancel');
            }

            return [
                'id_prd' => (int) $r->id_prd_ti, 'id_dsd' => (int) $r->id_dsd_ti, 'nomor_dr' => $r->nomor_pr_ti, 'nomor_po' => $r->nomor_po_supplier, 'nomor_po_potong' => $r->nomor_potong, 'nomor_lo' => $r->nomor_lo_pr_ti,
                'tgl_realisasi' => $r->tgl_realisasi, 'vol_potongan' => (float) $r->vol_potongan, 'vol_potong2' => $r->volume_potong !== null ? (float) $r->volume_potong : null, 'realisasi' => (float) $r->realisasi_volume,
                'request' => $req, 'catatan_cancel' => $r->request ? 'Keterangan cancel: '.$r->request : '', 'bisa_approve_cancel' => (int) $r->is_request !== 0 && (int) $r->disposisi_request === 1 && (int) $r->is_approved === 0,
                'sj' => $r->file_sj_ti_ori && $r->file_sj_ti && LegacyFile::resolve('surat_jalan/trucking/'.$r->file_sj_ti) ? $r->file_sj_ti_ori : null, 'is_cancel' => (int) $r->is_cancel === 1,
            ];
        })->values();
        $grup = collect($this->grupPo($id))->map(fn ($g) => ['id_po_supplier' => (int) $g->id_po_supplier, 'id_po_receive' => (int) $g->id_po_receive, 'nomor_po' => $g->nomor_po, 'vol_potong' => (float) $g->vol_potong])->values();
        $sync = app(AccurateTiSync::class);

        return response()->json([
            'ti' => ['id' => (int) $a->id_master, 'nomor_ti' => $a->nomor_ti, 'tanggal' => $a->tanggal_pengajuan, 'wilayah' => $a->nama_cabang, 'load_port' => $a->load_port, 'discharge_port' => $a->discharge_port,
                'transportir' => $a->nama_transportir, 'ongkos' => (float) $a->ongkos_angkut, 'volume' => (float) $a->volume, 'status' => (int) $a->status, 'is_received' => (int) $a->is_received === 1, 'is_cancel' => (int) $a->is_cancel === 1],
            'baris' => $rows, 'total_realisasi' => (float) $rows->sum('realisasi'), 'grup_po' => $grup,
            'bisa_received' => (int) $a->is_received !== 1 && (int) $a->status !== 6 && $rows->isNotEmpty() && $rows->count() !== $rows->filter(fn ($r) => $r['realisasi'] <= 0 && $r['is_cancel'])->count(),
            'accurate' => $sync->enabled(),
        ]);
    }

    public function lampiran(int $id, int $prd)
    {
        $r = collect($this->baris($id))->firstWhere('id_prd_ti', $prd);

        return LegacyFile::response($r && $r->file_sj_ti ? 'surat_jalan/trucking/'.$r->file_sj_ti : null, $r->file_sj_ti_ori ?? 'surat-jalan');
    }

    private static function acc(?array $a, array $keys, $default = '-')
    {
        foreach ($keys as $k) {
            if (isset($a[$k]) && $a[$k] !== '' && $a[$k] !== null) {
                return is_array($a[$k]) ? ($a[$k]['name'] ?? $a[$k]['no'] ?? $a[$k]['number'] ?? $default) : $a[$k];
            }
        }

        return $default;
    }

    /** Detail Accurate (__get_detail_accurate_ti.php): transaksi OUT/IN milik satu DO, dikelompokkan per id Accurate, lengkap dengan isi transaksi dari Accurate bila aktif. */
    public function detailAccurate(int $id, int $prd): JsonResponse
    {
        $do = DB::table('pro_pr_trucking_detail as c')->join('pro_pr_trucking as d', 'c.id_pr_ti', '=', 'd.id_pr_ti')->leftJoin('pro_trucking_plan as e', 'c.id_plan', '=', 'e.id_plan')
            ->leftJoin(self::T.' as f', 'e.id_trucking', '=', 'f.id_master')->where('c.id_prd_ti', $prd)->first(['c.nomor_lo_pr_ti', 'c.nomor_po_supplier', 'd.nomor_pr_ti', 'f.nomor_ti']);
        if (! $do) {
            return response()->json(['message' => 'Data DO tidak ditemukan.'], 404);
        }
        $trx = DB::table('new_pro_inventory_depot as dep')->leftJoin('new_pro_inventory_vendor_po as vp', 'dep.id_po_supplier', '=', 'vp.id_master')
            ->where('dep.id_prd_ti', $prd)->whereNotNull('dep.id_accurate')->where('dep.id_accurate', '<>', '')->groupBy('dep.id_accurate')
            ->selectRaw("dep.id_accurate, CASE WHEN SUM(dep.adj_inven) < 0 THEN 'OUT' ELSE 'IN' END AS tipe, SUM(dep.adj_inven) AS total_vol, COUNT(*) AS jml_baris, GROUP_CONCAT(DISTINCT vp.nomor_po) AS nomor_po")
            ->orderByDesc('tipe')->orderBy('dep.id_accurate')->get();
        $sync = app(AccurateTiSync::class);
        $out = $trx->map(function ($t) use ($sync) {
            $x = ['id_accurate' => (string) $t->id_accurate, 'tipe' => $t->tipe, 'keterangan' => $t->tipe === 'OUT' ? 'Pengurangan stock (Loading Port)' : 'Penambahan stock (Discharging Port)',
                'nomor_po' => $t->nomor_po ?: '-', 'volume_syop' => abs((float) $t->total_vol), 'jml_baris' => (int) $t->jml_baris, 'accurate' => null, 'pesan' => null, 'items' => []];
            if (! $sync->enabled()) {
                $x['pesan'] = 'Accurate belum aktif di server; isi transaksi tidak dapat diambil.';

                return $x;
            }
            $r = $sync->detail($t->id_accurate);
            if (! $r['ok']) {
                $x['pesan'] = 'Detail dari Accurate tidak bisa diambil : '.$r['pesan'];

                return $x;
            }
            $d = $r['d'];
            $x['accurate'] = ['nomor' => self::acc($d, ['number']), 'tanggal' => self::acc($d, ['transDate']), 'cabang' => self::acc($d, ['branchName', 'branch']),
                'akun' => self::acc($d, ['adjustmentAccountNo', 'adjustmentAccount']), 'keterangan' => self::acc($d, ['description'])];
            foreach ((array) ($d['detailItem'] ?? []) as $it) {
                $obj = isset($it['item']) && is_array($it['item']) ? $it['item'] : [];
                $qty = $it['quantity'] ?? 0;
                $cost = $it['unitCost'] ?? '';
                $x['items'][] = [
                    'kode' => $obj ? self::acc($obj, ['no', 'itemNo']) : self::acc($it, ['itemNo', 'no']), 'nama' => $obj ? self::acc($obj, ['name']) : self::acc($it, ['itemName', 'name']),
                    'tipe' => self::acc($it, ['itemAdjustmentType']), 'qty' => is_numeric($qty) ? (float) $qty : 0, 'unit_cost' => is_numeric($cost) ? (float) $cost : null, 'gudang' => self::acc($it, ['warehouseName', 'warehouse']),
                ];
            }

            return $x;
        })->values();

        return response()->json(['do' => ['nomor_ti' => $do->nomor_ti ?: '-', 'nomor_dr' => $do->nomor_pr_ti ?: '-', 'nomor_lo' => $do->nomor_lo_pr_ti ?: '-', 'nomor_po' => $do->nomor_po_supplier ?: '-'], 'transaksi' => $out]);
    }

    /** Data cetakan "Trucking Instruction Received" (tipe trucking_receive, template trucking-instruction-received.php). */
    public function cetak(Request $request, int $id): JsonResponse
    {
        $a = DB::table(self::T.' as f')->join('pro_master_terminal as h', 'f.loading_port', '=', 'h.id_master')->join('pro_master_terminal as g', 'f.discharging_port', '=', 'g.id_master')
            ->join('pro_master_transportir as o', 'f.id_transportir', '=', 'o.id_master')->where('f.id_master', $id)
            ->first(['f.nomor_ti', 'h.nama_terminal', 'h.tanki_terminal', 'h.lokasi_terminal', DB::raw("concat(g.nama_terminal,' - ',g.tanki_terminal,' - ',g.lokasi_terminal) as discharge_port"), 'o.nama_transportir', 'o.nama_suplier']);
        if (! $a) {
            return response()->json(['message' => 'Trucking Instruction tidak ditemukan.'], 404);
        }
        $rows = DB::select("
            select a.tgl_realisasi, a.realisasi_volume, a.is_cancel, i.nomor_pr_ti, d.nomor_po_supplier, d.nomor_lo_pr_ti, b.no_spj_ti, m.nomor_plat, n.nama_sopir, b.volume_po
            from pro_po_ds_trucking_detail a
            join pro_po_trucking_detail b on a.id_pod_ti = b.id_pod_ti
            join pro_po_trucking c on a.id_po_ti = c.id_po_ti
            join pro_pr_trucking_detail d on a.id_prd_ti = d.id_prd_ti
            join pro_trucking_plan e on a.id_plan = e.id_plan
            join pro_pr_trucking i on d.id_pr_ti = i.id_pr_ti
            left join pro_master_transportir_mobil m on b.mobil_po_ti = m.id_master
            left join pro_master_transportir_sopir n on b.sopir_po_ti = n.id_master
            where e.id_trucking = ?", [$id]);
        $data = collect($rows)->map(fn ($r) => [
            'tgl_realisasi' => $r->tgl_realisasi ?: '-', 'nomor_dr' => $r->nomor_pr_ti, 'nomor_po' => $r->nomor_po_supplier, 'nomor_lo' => $r->nomor_lo_pr_ti, 'spj' => $r->no_spj_ti, 'plat' => $r->nomor_plat, 'sopir' => $r->nama_sopir,
            'volume' => number_format((float) $r->volume_po), 'realisasi' => number_format((float) $r->realisasi_volume), 'cancel' => (int) $r->is_cancel === 1,
        ])->values();

        return response()->json([
            'nomor' => $a->nomor_ti, 'load_port' => $a->nama_terminal.' '.$a->tanki_terminal.', '.$a->lokasi_terminal, 'discharge_port' => $a->discharge_port, 'transportir' => $a->nama_transportir.' - '.$a->nama_suplier,
            'baris' => $data, 'total' => number_format((float) collect($rows)->sum('realisasi_volume')),
        ]);
    }

    /** approve-cancel: DS dibatalkan, TI berstatus cancel, baris depot DO dihapus. */
    public function approveCancel(int $id, int $dsd): JsonResponse
    {
        $this->ti($id);
        $r = collect($this->baris($id))->firstWhere('id_dsd_ti', $dsd);
        if (! $r || (int) $r->is_request === 0 || (int) $r->disposisi_request !== 1 || (int) $r->is_approved !== 0) {
            self::fail('Request cancel tidak dapat di-approve.', 'TIDAK_BISA', 409);
        }
        DB::transaction(function () use ($id, $dsd, $r) {
            DB::table('pro_po_ds_trucking_detail')->where('id_dsd_ti', $dsd)->update(['is_approved' => 1, 'tanggal_approved' => now(), 'disposisi_request' => 2, 'is_cancel' => 1, 'tanggal_cancel' => now()]);
            DB::table(self::T)->where('id_master', $id)->update(['status' => 6]);
            DB::table('new_pro_inventory_depot')->where('id_prd_ti', $r->id_prd_ti)->delete();
        });

        return response()->json(['message' => 'Request Cancel berhasil di-approve.']);
    }

    private function aturan(bool $accurate, bool $pisah): array
    {
        $acc = $accurate ? 'required' : 'nullable';
        $a = [
            'harga_transfer' => ['required', 'numeric', 'min:0'], 'keterangan' => ['nullable', 'string', 'max:2000'], 'pisah_accurate' => ['nullable', 'boolean'],
            'nomor_penyesuaian' => [$pisah ? 'nullable' : $acc, 'string', 'max:100'], 'kode_item' => [$pisah ? 'nullable' : $acc, 'string', 'max:100'], 'akun_penyesuaian' => [$acc, 'string', 'max:100'],
            'nomor_penyesuaian_terima' => [$pisah ? 'nullable' : $acc, 'string', 'max:100'], 'kode_item_terima' => [$pisah ? 'nullable' : $acc, 'string', 'max:100'],
            'grup' => ['nullable', 'array'], 'grup.*.id_po_supplier' => ['required_with:grup', 'integer'], 'grup.*.id_po_receive' => ['required_with:grup', 'integer'],
            'grup.*.nomor_penyesuaian' => ['nullable', 'string', 'max:100'], 'grup.*.kode_item' => ['nullable', 'string', 'max:100'], 'grup.*.nomor_penyesuaian_terima' => ['nullable', 'string', 'max:100'],
            'grup.*.kode_item_terima' => ['nullable', 'string', 'max:100'], 'grup.*.harga' => ['nullable', 'numeric', 'min:0'],
        ];

        return $a;
    }

    public function received(Request $request, int $id): JsonResponse
    {
        $ti = $this->ti($id);
        $sync = app(AccurateTiSync::class);
        $pisah = (bool) $request->boolean('pisah_accurate');
        $d = $request->validate($this->aturan($sync->enabled(), $pisah));
        $baris = $this->baris($id);
        if ((int) $ti->is_received === 1 || (int) $ti->status === 6 || ! $baris) {
            self::fail('Trucking Instruction ini tidak dapat di-received.', 'TIDAK_BISA', 409);
        }
        $user = $request->user();
        $ip = $request->ip();
        $setting = [];
        foreach ($d['grup'] ?? [] as $g) {
            $setting[$g['id_po_supplier'].'|'.$g['id_po_receive']] = $g + ['harga' => $g['harga'] ?? 0];
        }
        $rowsAktif = collect($baris)->filter(fn ($r) => (int) ($r->is_cancel ?? 0) === 0);
        $tglReal = $rowsAktif->pluck('tgl_realisasi')->filter()->sort()->last();
        $tgl = $tglReal ? date('d/m/Y', strtotime($tglReal)) : date('d/m/Y');
        $tglDb = $tglReal ? date('Y-m-d', strtotime($tglReal)) : date('Y-m-d');
        $totalReal = (float) collect($baris)->sum('realisasi_volume');
        $cabangUser = DB::table('pro_master_cabang')->where('id_master', $user->id_wilayah)->value('nama_cabang');
        $gudangOut = DB::table('pro_master_terminal as a')->join('pro_master_cabang as b', 'a.id_cabang', '=', 'b.id_master')->where('a.id_master', $ti->loading_port)->first(['b.inisial_cabang', 'b.nama_cabang']);
        $gudangIn = DB::table('pro_master_terminal as a')->join('pro_master_cabang as b', 'a.id_cabang', '=', 'b.id_master')->where('a.id_master', $ti->discharging_port)->first(['b.inisial_cabang', 'b.nama_cabang']);
        $harga = (float) $d['harga_transfer'];
        $posted = [];

        try {
            DB::transaction(function () use ($ti, $id, $rowsAktif, $d, $pisah, $setting, $sync, $tglDb, $tgl, $totalReal, $user, $ip, $cabangUser, $gudangOut, $gudangIn, $harga, &$posted) {
                DB::table(self::T)->where('id_master', $id)->update(['realisasi_volume' => $totalReal, 'is_received' => 1, 'status' => 7, 'received_tanggal' => now(), 'received_pic' => $user->fullname]);
                $volGrup = [];
                foreach ($rowsAktif as $r) {
                    $real = (float) $r->realisasi_volume;
                    if ($real <= 0) {
                        continue;
                    }
                    $outs = DB::table('new_pro_inventory_depot')->where('id_prd_ti', $r->id_prd_ti)->where('adj_inven', '<', 0)->orderBy('id_master')->get();
                    if ($outs->isEmpty()) {
                        continue;
                    }
                    DB::table('new_pro_inventory_depot')->where('id_prd_ti', $r->id_prd_ti)->where('adj_inven', '<', 0)->update(['tanggal_inven' => $tglDb]);
                    $total = $outs->sum(fn ($o) => abs((float) $o->adj_inven));
                    $volSplit = $total - abs((float) $outs[0]->adj_inven);
                    $porsi = [];
                    if ($real >= $volSplit) {
                        foreach ($outs as $ix => $o) {
                            $porsi[$ix] = $ix === 0 ? $real - $volSplit : abs((float) $o->adj_inven);
                        }
                    } else {
                        $sisa = $real;
                        foreach ($outs as $ix => $o) {
                            if ($ix === $outs->count() - 1) {
                                $porsi[$ix] = $sisa;
                            } else {
                                $porsi[$ix] = round($real * abs((float) $o->adj_inven) / $total);
                                $sisa -= $porsi[$ix];
                            }
                        }
                    }
                    foreach ($outs as $ix => $o) {
                        if ($porsi[$ix] <= 0) {
                            continue;
                        }
                        $kunci = $o->id_po_supplier.'|'.$o->id_po_receive;
                        $hargaPorsi = $pisah && isset($setting[$kunci]) ? (float) $setting[$kunci]['harga'] : $harga;
                        $idIn = DB::table('new_pro_inventory_depot')->insertGetId([
                            'id_datanya' => $o->id_datanya, 'id_jenis' => 4, 'id_produk' => (int) config('orca.trucking_dr_produk', 15), 'id_terminal' => $ti->discharging_port,
                            'id_po_supplier' => $o->id_po_supplier, 'id_po_receive' => $o->id_po_receive, 'tanggal_inven' => $tglDb, 'adj_inven' => $porsi[$ix], 'harga' => $hargaPorsi ?: 0,
                            'keterangan' => 'Received TI '.$ti->nomor_ti.($ix > 0 ? ' (SPLIT)' : ''), 'created_time' => now(), 'created_ip' => $ip, 'created_by' => $user->fullname,
                            'lastupdate_time' => now(), 'lastupdate_ip' => $ip, 'lastupdate_by' => $user->fullname, 'id_pr_ti' => $o->id_pr_ti, 'id_prd_ti' => $o->id_prd_ti,
                        ]);
                        $g = &$volGrup[$kunci];
                        $g ??= ['vol' => 0, 'out' => [], 'in' => [], 'ips' => $o->id_po_supplier, 'ipr' => $o->id_po_receive];
                        $g['vol'] += $porsi[$ix];
                        $g['out'][] = $o->id_master;
                        $g['in'][] = $idIn;
                        unset($g);
                    }
                }
                if (! $sync->enabled()) {
                    return;
                }
                // transaksi Accurate: satu transaksi gabungan, atau satu pasang per PO bila "pisah"
                $trx = [];
                if ($pisah) {
                    foreach ($volGrup as $kunci => $g) {
                        $s = $setting[$kunci] ?? null;
                        if (! $s || empty($s['nomor_penyesuaian']) || empty($s['kode_item']) || empty($s['nomor_penyesuaian_terima']) || empty($s['kode_item_terima'])) {
                            throw new AccurateException('Nomor penyesuaian dan kode item accurate belum lengkap untuk semua PO');
                        }
                        $trx[] = ['no_out' => $s['nomor_penyesuaian'], 'item_out' => $s['kode_item'], 'no_in' => $s['nomor_penyesuaian_terima'], 'item_in' => $s['kode_item_terima'], 'harga' => (float) ($s['harga'] ?? 0), 'vol' => $g['vol'], 'out' => $g['out'], 'in' => $g['in']];
                    }
                } else {
                    $trx[] = ['no_out' => $d['nomor_penyesuaian'], 'item_out' => $d['kode_item'], 'no_in' => $d['nomor_penyesuaian_terima'], 'item_in' => $d['kode_item_terima'], 'harga' => $harga, 'vol' => $totalReal,
                        'out' => collect($volGrup)->pluck('out')->flatten()->all(), 'in' => collect($volGrup)->pluck('in')->flatten()->all()];
                }
                foreach ($trx as $t) {
                    if ($t['vol'] <= 0 && ! $pisah) {
                        continue;
                    }
                    $idOut = $sync->out($t['no_out'], $d['akun_penyesuaian'], $tgl, $d['keterangan'] ?? null, $cabangUser, $t['item_out'], (float) $t['vol'], $gudangOut->inisial_cabang ?? null, $gudangOut->nama_cabang ?? null);
                    $posted[] = $idOut;
                    if ($t['out']) {
                        DB::table('new_pro_inventory_depot')->whereIn('id_master', $t['out'])->where('adj_inven', '<', 0)->update(['id_accurate' => $idOut]);
                    }
                    $idIn = $sync->in($t['no_in'], $d['akun_penyesuaian'], $tgl, $d['keterangan'] ?? null, $cabangUser, $t['item_in'], (float) $t['vol'], $t['harga'], $gudangIn->inisial_cabang ?? null, $gudangOut->nama_cabang ?? null);
                    $posted[] = $idIn;
                    if ($t['in']) {
                        DB::table('new_pro_inventory_depot')->whereIn('id_master', $t['in'])->where('adj_inven', '>', 0)->update(['id_accurate' => $idIn]);
                    }
                }
            });
        } catch (AccurateException $e) {
            $gagal = $posted ? $sync->batalkan($posted) : [];
            $pesan = $e->getMessage().($gagal ? '. Transaksi Accurate berikut gagal dibatalkan otomatis, mohon dihapus manual (id): '.implode(', ', $gagal) : '');

            return response()->json(['message' => $pesan, 'code' => 'ACCURATE'], 502);
        }

        return response()->json(['message' => 'Data berhasil disimpan', 'accurate_manual' => ! $sync->enabled()]);
    }
}
