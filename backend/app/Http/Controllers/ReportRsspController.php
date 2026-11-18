<?php

namespace App\Http\Controllers;

use App\Services\Accurate\AccurateClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Report RSSP Fuel untuk OM (peran 6) dan CEO (peran 21). Hanya baca.
 * Sumber: rssp-fuel.php (filter), rssp-fuel-loaded.php (rumus matriks harian), __get_rssp_detail.php (rincian sel).
 * Input forecast supply / forecast sales planning milik Procurement (peran 5), tidak ada di sini.
 *
 * Matriks per hari: STOCK AWAL, DAILY LOSSES, SUPPLY (actual, atau forecast bila actual belum ada dan tanggal belum lewat),
 * TRANSFER STOCK (keluar), SALES (actual per terminal) + FORECAST (total, ×1000 dari pro_forecast) dan planning per terminal,
 * STOCK AKHIR per terminal dan total. Mode "nasional": satu baris = satu cabang (gabungan semua terminalnya).
 */
class ReportRsspController extends Controller
{
    private const TTL = 120; // detik
    private const KECUALI = [92, 101]; // id_master terminal/cabang yang dikecualikan web

    public function cabangList(): JsonResponse
    {
        return response()->json(['data' => DB::table('pro_master_cabang')->where('is_active', 1)->where('id_master', '<>', 1)->orderBy('nama_cabang')->get(['id_master as id', 'nama_cabang as nama'])]);
    }

    /** @return array{0:string,1:int,2:int} [cabang ('nasional'|id), bulan, tahun] */
    private function parameter(Request $r): array
    {
        $c = (string) $r->query('cabang', 'nasional');
        $cabang = $c === 'nasional' ? 'nasional' : (string) max(1, (int) $c);
        $bulan = max(1, min(12, (int) $r->query('bulan', date('n'))));
        $tahun = max(2022, min((int) date('Y') + 1, (int) $r->query('tahun', date('Y'))));

        return [$cabang, $bulan, $tahun];
    }

    /** Libur nasional + cuti bersama (tanggalmerah.upset.dev), seperti web: di-cache sehari, gagal -> hanya akhir pekan. */
    private function libur(int $tahun): array
    {
        return Cache::remember("rssp-libur:$tahun", 86400, function () use ($tahun) {
            try {
                $json = Http::timeout(3)->get('https://tanggalmerah.upset.dev/api/holidays', ['year' => $tahun])->json();
            } catch (\Throwable) {
                $json = null;
            }
            $map = [];
            if (is_array($json) && ! empty($json['success']) && isset($json['data'])) {
                foreach ($json['data'] as $h) {
                    $map[$h['date']] = ['name' => $h['name'], 'type' => $h['type']];
                }
            }

            return $map;
        });
    }

    private static function filterTerminal(bool $nasional): string
    {
        return $nasional
            ? 'd.id_terminal IN (SELECT id_master FROM pro_master_terminal WHERE id_cabang = ? AND is_active = 1 AND kategori_terminal IN (1,3))'
            : 'd.id_terminal = ?';
    }

    public function index(Request $r): JsonResponse
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        [$cabang, $bulan, $tahun] = $this->parameter($r);
        $data = Cache::remember("rssp:$cabang:$bulan:$tahun:".date('Ymd'), self::TTL, fn () => $this->hitung($cabang, $bulan, $tahun));

        return response()->json($data);
    }

    private function hitung(string $cabang, int $bulan, int $tahun): array
    {
        $nasional = $cabang === 'nasional';
        $jumlahHari = (int) date('t', mktime(0, 0, 0, $bulan, 1, $tahun)); // tanpa ekstensi calendar (tidak ada di image Docker)
        $first = sprintf('%04d-%02d-01', $tahun, $bulan);
        $last = sprintf('%04d-%02d-%02d', $tahun, $bulan, $jumlahHari);
        $today = date('Y-m-d');

        // ---- hari, akhir pekan/libur ----
        $libur = $this->libur($tahun);
        $hari = [];
        for ($d = 1; $d <= $jumlahHari; $d++) {
            $ymd = sprintf('%04d-%02d-%02d', $tahun, $bulan, $d);
            $weekend = (int) date('N', strtotime($ymd)) >= 6;
            $l = $libur[$ymd] ?? null;
            $tip = $l ? $l['name'].($l['type'] === 'leave' ? ' (Cuti Bersama)' : '') : ($weekend ? 'Akhir Pekan' : '');
            $hari[] = ['d' => $d, 'ymd' => $ymd, 'weekend' => $weekend || $l !== null, 'tip' => $tip];
        }

        // hari "actual" untuk sorotan: bulan berjalan H-1, bulan lampau seluruhnya, bulan depan 0
        $curY = (int) date('Y');
        $curM = (int) date('n');
        $tglActual = ($tahun === $curY && $bulan === $curM) ? (int) date('j') - 1
            : (($tahun < $curY || ($tahun === $curY && $bulan < $curM)) ? $jumlahHari : 0);

        // ---- daftar depot: terminal (kategori 1,3) di cabang itu, atau cabang bila nasional ----
        if ($nasional) {
            $depots = DB::table('pro_master_cabang')->where('is_active', 1)->where('id_master', '<>', 1)->whereNotIn('id_master', self::KECUALI)
                ->orderBy('nama_cabang')->get(['id_master', 'nama_cabang as nama']);
            $namaCabang = 'Semua Cabang';
        } else {
            $depots = DB::table('pro_master_terminal')->where('is_active', 1)->whereIn('kategori_terminal', [1, 3])->whereNotIn('id_master', self::KECUALI)->where('id_cabang', $cabang)
                ->orderBy('nama_terminal')->selectRaw("id_master, TRIM(CONCAT(nama_terminal, ' (', COALESCE(tanki_terminal, ''), ')')) AS nama")->get();
            $namaCabang = (string) DB::table('pro_master_cabang')->where('id_master', $cabang)->value('nama_cabang');
        }
        $ids = $depots->pluck('id_master')->all();

        // ---- forecast supply & planning per terminal (atau per cabang bila nasional) ----
        $fcSupply = $this->forecastPerHari('pro_forecast_supply', $nasional, $ids, $first, $last);
        $fcPlan = $this->forecastPerHari('pro_forecast_sales', $nasional, $ids, $first, $last);

        // ---- total actual sales per cabang per hari (penentu kapan forecast sales berhenti dipakai) ----
        $actualCabang = [];
        $dasar = DB::table('new_pro_inventory_depot as d')->join('pro_master_terminal as t', 't.id_master', '=', 'd.id_terminal')
            ->where('t.is_active', 1)->whereIn('t.kategori_terminal', [1, 3])->whereNotIn('t.id_master', self::KECUALI)->whereBetween('d.tanggal_inven', [$first, $last]);
        if (! $nasional) {
            $dasar->where('t.id_cabang', $cabang);
        }
        foreach ($dasar->groupBy('t.id_cabang', DB::raw('DAY(d.tanggal_inven)'))->get(['t.id_cabang as entity_id', DB::raw('DAY(d.tanggal_inven) as d'), DB::raw('SUM(d.out_inven) as total')]) as $x) {
            $actualCabang[$x->entity_id][(int) $x->d] = (float) $x->total;
        }

        $out = [];
        $totalSales = array_fill(0, $jumlahHari, 0.0);
        $stokTotal = array_fill(0, $jumlahHari, 0.0);

        foreach ($depots as $dp) {
            $tid = $dp->id_master;
            $filter = self::filterTerminal($nasional);
            $cutoff = $first > $today ? $today : $first;

            $saldo = (float) (DB::selectOne("SELECT COALESCE(SUM(CASE WHEN t.kategori_terminal = 3 THEN d.adj_inven ELSE d.in_inven + d.adj_inven END - d.out_inven), 0) AS s
                FROM new_pro_inventory_depot d JOIN pro_master_terminal t ON t.id_master = d.id_terminal WHERE $filter AND d.tanggal_inven < ?", [$tid, $cutoff])->s ?? 0);

            if ($first > $today) {
                $saldo = $this->kejarSaldo($saldo, $nasional, $tid, $cabang, $today, $first);
            }

            $trx = [];
            foreach (DB::select("SELECT DAY(d.tanggal_inven) AS d,
                    SUM(CASE WHEN t.kategori_terminal = 3 THEN GREATEST(d.adj_inven, 0)
                             ELSE d.in_inven + (CASE WHEN d.id_jenis = 4 AND d.adj_inven > 0 THEN d.adj_inven ELSE 0 END) END) AS in_inven,
                    SUM(d.out_inven) AS out_total,
                    SUM(CASE WHEN t.kategori_terminal = 3 THEN LEAST(CASE WHEN d.id_jenis IN (3, 5) THEN d.adj_inven ELSE 0 END, 0)
                             ELSE (CASE WHEN d.id_jenis IN (3, 5) THEN d.adj_inven ELSE 0 END) END) AS adj_inven,
                    SUM(CASE WHEN t.kategori_terminal = 3 THEN LEAST(CASE WHEN d.id_jenis = 4 THEN d.adj_inven ELSE 0 END, 0)
                             ELSE (CASE WHEN d.id_jenis = 4 AND d.adj_inven < 0 THEN d.adj_inven ELSE 0 END) END) AS tf_out,
                    SUM(CASE WHEN t.kategori_terminal = 3 THEN LEAST(d.adj_inven, 0)
                             ELSE (CASE WHEN d.id_jenis IN (3, 5) THEN d.adj_inven ELSE 0 END) + (CASE WHEN d.id_jenis = 4 AND d.adj_inven < 0 THEN d.adj_inven ELSE 0 END) END) AS adj_saldo
                FROM new_pro_inventory_depot d JOIN pro_master_terminal t ON t.id_master = d.id_terminal
                WHERE $filter AND d.tanggal_inven BETWEEN ? AND ? GROUP BY DAY(d.tanggal_inven)", [$tid, $first, $last]) as $x) {
                $trx[(int) $x->d] = $x;
            }

            $row = ['id' => (int) $tid, 'nama' => (string) $dp->nama, 'stock_awal' => [], 'loss' => [], 'supply' => [], 'supply_fc' => [], 'transfer' => [], 'sales' => [], 'plan' => [], 'stock_akhir' => []];
            $cabangKey = $nasional ? $tid : (int) $cabang;

            for ($d = 1; $d <= $jumlahHari; $d++) {
                $row['stock_awal'][] = $saldo;
                $belumLewat = $hari[$d - 1]['ymd'] >= $today;
                $in = (float) ($trx[$d]->in_inven ?? 0);
                $outv = (float) ($trx[$d]->out_total ?? 0);
                $adj = (float) ($trx[$d]->adj_inven ?? 0);
                $tfOut = (float) ($trx[$d]->tf_out ?? 0);
                $adjSaldo = (float) ($trx[$d]->adj_saldo ?? 0);

                // SUPPLY: actual menang; bila belum ada dan tanggal belum lewat, pakai forecast supply (ikut ke saldo)
                $fc = $fcSupply[$tid][$d] ?? null;
                $supplyPakai = $in;
                $tampil = $in;
                $isFc = false;
                if ($in > 0) {
                    $tampil = $in;
                } elseif ($belumLewat && $fc !== null && $fc != 0) {
                    $tampil = $fc;
                    $isFc = true;
                    $supplyPakai = $fc;
                } else {
                    $tampil = 0.0;
                }

                // SALDO: actual sales menang bila cabang sudah ada actual hari itu; bila belum dan tanggal belum lewat, pakai planning terminal
                $fcSales = $fcPlan[$tid][$d] ?? null;
                $salesPakai = $outv;
                if ($belumLewat && ($actualCabang[$cabangKey][$d] ?? 0) == 0 && $fcSales !== null && $fcSales != 0) {
                    $salesPakai = $fcSales;
                }

                $saldo = $saldo + $supplyPakai + $adjSaldo - $salesPakai;

                $row['loss'][] = $adj;
                $row['supply'][] = $tampil;
                $row['supply_fc'][] = $isFc;
                $row['transfer'][] = $tfOut;
                $row['sales'][] = $outv;
                $row['plan'][] = (float) ($fcPlan[$tid][$d] ?? 0);
                $row['stock_akhir'][] = $saldo;
                $totalSales[$d - 1] += $outv;
                $stokTotal[$d - 1] += $saldo;
            }
            $out[] = $row;
        }

        // ---- FORECAST (total per cabang; hanya periode yang sudah APPROVED CEO). pro_forecast.qty = "angka depan" -> ×1000 ----
        $forecast = array_fill(0, $jumlahHari, 0.0);
        $fcSql = 'SELECT DAY(f.tanggal) AS d, SUM(f.qty) AS total FROM pro_forecast f JOIN pro_customer c ON c.id_customer = f.id_customer
            JOIN (SELECT a.id_wilayah, a.periode FROM pro_forecast_approval a
                  JOIN (SELECT id_wilayah, periode, MAX(id_approval) AS mid FROM pro_forecast_approval WHERE bulan = ? AND tahun = ? GROUP BY id_wilayah, periode) m ON m.mid = a.id_approval
                  WHERE a.status = \'APPROVED\') appr ON appr.id_wilayah = c.id_wilayah AND appr.periode = (CASE WHEN DAY(f.tanggal) <= 14 THEN 1 ELSE 2 END)
            WHERE MONTH(f.tanggal) = ? AND YEAR(f.tanggal) = ?'.($nasional ? '' : ' AND c.id_wilayah = ?').' GROUP BY DAY(f.tanggal)';
        foreach (DB::select($fcSql, array_filter([$bulan, $tahun, $bulan, $tahun, $nasional ? null : $cabang], fn ($v) => $v !== null)) as $x) {
            $forecast[(int) $x->d - 1] = (float) $x->total * 1000;
        }

        return [
            'cabang' => $cabang, 'nama_cabang' => $namaCabang, 'bulan' => $bulan, 'tahun' => $tahun, 'jumlah_hari' => $jumlahHari,
            'tgl_actual' => $tglActual, 'hari_ini' => $tahun === $curY && $bulan === $curM ? (int) date('j') : 0,
            'hari' => $hari, 'depots' => $out, 'forecast' => $forecast, 'total_sales' => $totalSales, 'stock_akhir_total' => $stokTotal,
        ];
    }

    /** @return array<int|string,array<int,float>> entity_id => [hari => qty] (entity = terminal, atau cabang bila nasional) */
    private function forecastPerHari(string $tabel, bool $nasional, array $ids, string $first, string $last): array
    {
        if (! $ids) {
            return [];
        }
        $q = DB::table($tabel.' as f');
        if ($nasional) {
            $q->join('pro_master_terminal as t', 'f.id_terminal', '=', 't.id_master')->groupBy('t.id_cabang', DB::raw('DAY(f.tanggal)'))
                ->select('t.id_cabang as entity_id', DB::raw('DAY(f.tanggal) as d'), DB::raw('SUM(f.qty) as qty'));
        } else {
            $q->whereIn('f.id_terminal', $ids)->select('f.id_terminal as entity_id', DB::raw('DAY(f.tanggal) as d'), 'f.qty');
        }
        $res = [];
        foreach ($q->whereBetween('f.tanggal', [$first, $last])->get() as $x) {
            $res[$x->entity_id][(int) $x->d] = ($res[$x->entity_id][(int) $x->d] ?? 0) + (float) $x->qty;
        }

        return $res;
    }

    /** Bulan depan: saldo sebelum bulan itu diputar ulang harian dari hari ini (actual menang, forecast mengisi bila kosong). */
    private function kejarSaldo(float $saldo, bool $nasional, $tid, string $cabang, string $today, string $first): float
    {
        $filter = self::filterTerminal($nasional);
        $perTgl = [];
        foreach (DB::select("SELECT d.tanggal_inven AS tgl,
                SUM(CASE WHEN t.kategori_terminal = 3 THEN GREATEST(d.adj_inven, 0)
                         ELSE d.in_inven + (CASE WHEN d.id_jenis = 4 AND d.adj_inven > 0 THEN d.adj_inven ELSE 0 END) END) AS in_inven,
                SUM(d.out_inven) AS out_total,
                SUM(CASE WHEN t.kategori_terminal = 3 THEN LEAST(d.adj_inven, 0)
                         ELSE (CASE WHEN d.id_jenis IN (3, 5) THEN d.adj_inven ELSE 0 END) + (CASE WHEN d.id_jenis = 4 AND d.adj_inven < 0 THEN d.adj_inven ELSE 0 END) END) AS adj_saldo
            FROM new_pro_inventory_depot d JOIN pro_master_terminal t ON t.id_master = d.id_terminal
            WHERE $filter AND d.tanggal_inven >= ? AND d.tanggal_inven < ? GROUP BY d.tanggal_inven", [$tid, $today, $first]) as $x) {
            $perTgl[$x->tgl] = $x;
        }
        $fcTerminal = $nasional
            ? 'id_terminal IN (SELECT id_master FROM pro_master_terminal WHERE id_cabang = ? AND is_active = 1 AND kategori_terminal IN (1,3))'
            : 'id_terminal = ?';
        $fcSup = DB::table('pro_forecast_supply')->whereRaw($fcTerminal, [$tid])->where('tanggal', '>=', $today)->where('tanggal', '<', $first)->groupBy('tanggal')->pluck(DB::raw('SUM(qty)'), 'tanggal')->all();
        $fcSal = DB::table('pro_forecast_sales')->whereRaw($fcTerminal, [$tid])->where('tanggal', '>=', $today)->where('tanggal', '<', $first)->groupBy('tanggal')->pluck(DB::raw('SUM(qty)'), 'tanggal')->all();
        $cabangId = $nasional ? $tid : $cabang;
        $actual = DB::table('new_pro_inventory_depot as d')->join('pro_master_terminal as t', 't.id_master', '=', 'd.id_terminal')->where('t.id_cabang', $cabangId)
            ->where('t.is_active', 1)->whereIn('t.kategori_terminal', [1, 3])->whereNotIn('t.id_master', self::KECUALI)->where('d.tanggal_inven', '>=', $today)->where('d.tanggal_inven', '<', $first)
            ->groupBy('d.tanggal_inven')->pluck(DB::raw('SUM(d.out_inven)'), 'd.tanggal_inven')->all();

        for ($c = new \DateTime($today), $b = new \DateTime($first); $c < $b; $c->modify('+1 day')) {
            $t = $c->format('Y-m-d');
            $in = (float) ($perTgl[$t]->in_inven ?? 0);
            $outv = (float) ($perTgl[$t]->out_total ?? 0);
            $supply = $in <= 0 && ! empty($fcSup[$t]) ? (float) $fcSup[$t] : $in;
            $sales = ((float) ($actual[$t] ?? 0)) == 0 && ! empty($fcSal[$t]) ? (float) $fcSal[$t] : $outv;
            $saldo = $saldo + $supply + (float) ($perTgl[$t]->adj_saldo ?? 0) - $sales;
        }

        return $saldo;
    }

    /* ------------------------------------------------------------------ */
    /* Rincian satu sel (klik di tabel RSSP) */
    /* ------------------------------------------------------------------ */

    public function detail(Request $r): JsonResponse
    {
        $type = (string) $r->query('type', '');
        $tid = (string) $r->query('tid', '');
        $tanggal = (string) $r->query('tanggal', '');
        $scope = $r->query('scope') === 'cabang' ? 'cabang' : 'terminal';

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) || ($tid === '' && $type !== 'forecast') || ! in_array($type, ['supply', 'sales', 'forecast', 'loss', 'transfer'], true)) {
            return response()->json(['message' => 'Data tidak lengkap.'], 422);
        }
        $tidInt = (int) $tid;
        $filter = $scope === 'cabang' ? 'd.id_terminal IN (SELECT id_master FROM pro_master_terminal WHERE id_cabang = ?)' : 'd.id_terminal = ?';

        return response()->json(match ($type) {
            'supply' => $this->detailSupply($filter, $tidInt, $tanggal),
            'sales' => $this->detailSales($filter, $tidInt, $tanggal),
            'forecast' => $this->detailForecast($tid === '' ? null : $tidInt, $tanggal),
            'loss' => $this->detailAdjustment($filter, $tidInt, $tanggal, 'loss'),
            'transfer' => $this->detailAdjustment($filter, $tidInt, $tanggal, 'transfer'),
        });
    }

    /** Nomor dokumen asli + kode item dari Accurate (gagal/nonaktif -> hanya id internal, seperti web). */
    private function dariAccurate(array $rows, callable $endpoint): array
    {
        $api = app(AccurateClient::class);
        $req = [];
        foreach ($rows as $x) {
            if (! empty($x->id_accurate)) {
                $req[$x->id_master] = ['method' => 'GET', 'path' => $endpoint($x).'/detail.do', 'query' => ['id' => $x->id_accurate]];
            }
        }
        $hasil = [];
        if ($req && $api->enabled()) {
            try {
                $res = $api->batch($req);
            } catch (\Throwable) {
                $res = [];
            }
            foreach ($res as $k => $v) {
                if (! empty($v['s'])) {
                    $det = $v['d']['detailItem'][0] ?? [];
                    $kode = $det['item']['no'] ?? $det['itemNo'] ?? null;
                    $nama = $det['item']['name'] ?? null;
                    $hasil[$k] = ['nomor' => $v['d']['number'] ?? null, 'kode_item' => $kode ? ($nama ? "$kode ($nama)" : $kode) : null];
                }
            }
        }

        return $hasil;
    }

    private function detailSupply(string $filter, int $tid, string $tanggal): array
    {
        $all = DB::select("SELECT d.id_master, t.kategori_terminal, CASE WHEN t.kategori_terminal = 3 THEN d.adj_inven ELSE d.in_inven END AS in_inven,
                d.keterangan, d.id_accurate, d.created_by, vp.nomor_po, vpr.tgl_terima, vpr.no_terima
            FROM new_pro_inventory_depot d JOIN pro_master_terminal t ON t.id_master = d.id_terminal
            LEFT JOIN new_pro_inventory_vendor_po vp ON d.id_po_supplier = vp.id_master
            LEFT JOIN new_pro_inventory_vendor_po_receive vpr ON d.id_po_supplier = vpr.id_po_supplier AND d.id_po_receive = vpr.id_po_receive
            WHERE $filter AND d.tanggal_inven = ? AND (CASE WHEN t.kategori_terminal = 3 THEN d.adj_inven ELSE d.in_inven END) > 0 ORDER BY d.id_master", [$tid, $tanggal]);

        $po = [];
        $truck = [];
        foreach ($all as $x) {
            if ((int) $x->kategori_terminal === 3) {
                $truck[] = $x;
            } else {
                $po[] = ['nomor_po' => $x->nomor_po, 'nomor_receive' => $x->no_terima, 'tgl_terima' => $x->tgl_terima, 'volume' => (float) $x->in_inven];
            }
        }
        $acc = $this->dariAccurate($truck, fn () => 'item-adjustment');

        return [
            'type' => 'supply', 'po' => $po,
            'truck' => array_map(fn ($x) => ['volume' => (float) $x->in_inven, 'nomor' => $acc[$x->id_master]['nomor'] ?? null, 'id_accurate' => $x->id_accurate, 'kode_item' => $acc[$x->id_master]['kode_item'] ?? null, 'keterangan' => $x->keterangan, 'pic' => $x->created_by], $truck),
            'total' => array_sum(array_column($po, 'volume')) + array_sum(array_map(fn ($x) => (float) $x->in_inven, $truck)),
        ];
    }

    private function detailSales(string $filter, int $tid, string $tanggal): array
    {
        $rows = DB::select("SELECT d.id_master, d.out_inven,
                COALESCE(prd.no_do_syop, prdk.no_do_syop) AS no_do_syop, COALESCE(prd.volume, prdk.volume) AS volume,
                COALESCE(prd.nomor_lo_pr, prdk.nomor_lo_pr) AS nomor_lo_pr, COALESCE(pr.nomor_pr, dsk.nomor_dn_kapal) AS nomor_pr,
                COALESCE(CONCAT(cust.kode_pelanggan, ' ', cust.nama_customer), CONCAT(custk.kode_pelanggan, ' ', custk.nama_customer)) AS nama_customer
            FROM new_pro_inventory_depot d
            LEFT JOIN pro_po_ds_detail dsd ON d.id_dsd = dsd.id_dsd LEFT JOIN pro_pr_detail prd ON dsd.id_prd = prd.id_prd
            LEFT JOIN pro_pr pr ON prd.id_pr = pr.id_pr LEFT JOIN pro_po_customer poc ON dsd.id_poc = poc.id_poc LEFT JOIN pro_customer cust ON poc.id_customer = cust.id_customer
            LEFT JOIN pro_po_ds_kapal dsk ON d.id_dsk = dsk.id_dsk LEFT JOIN pro_pr_detail prdk ON dsk.id_prd = prdk.id_prd
            LEFT JOIN pro_po_customer pock ON dsk.id_poc = pock.id_poc LEFT JOIN pro_customer custk ON pock.id_customer = custk.id_customer
            WHERE $filter AND d.tanggal_inven = ? AND d.out_inven <> 0 ORDER BY d.id_master", [$tid, $tanggal]);

        $data = array_map(fn ($x) => ['customer' => $x->nama_customer, 'nomor_dr' => $x->nomor_pr, 'nomor_do' => $x->no_do_syop, 'nomor_lo' => $x->nomor_lo_pr, 'volume' => (float) $x->volume], $rows);

        return ['type' => 'sales', 'data' => $data, 'total' => array_sum(array_column($data, 'volume'))];
    }

    private function detailForecast(?int $cabang, string $tanggal): array
    {
        $rows = DB::select('SELECT c.nama_customer, f.qty FROM pro_forecast f JOIN pro_customer c ON c.id_customer = f.id_customer WHERE f.tanggal = ?'
            .($cabang !== null ? ' AND c.id_wilayah = ?' : '').' AND f.qty <> 0 ORDER BY c.nama_customer', array_filter([$tanggal, $cabang], fn ($v) => $v !== null));
        // pro_forecast.qty "angka depan": RSSP menampilkan ×1000 (tabel rincian web menampilkan angka mentah; di sini disamakan dengan sel)
        $data = array_map(fn ($x) => ['customer' => $x->nama_customer, 'volume' => (float) $x->qty * 1000], $rows);

        return ['type' => 'forecast', 'data' => $data, 'total' => array_sum(array_column($data, 'volume'))];
    }

    private function detailAdjustment(string $filter, int $tid, string $tanggal, string $type): array
    {
        $cond = $type === 'loss' ? 'd.id_jenis IN (3, 5) AND d.adj_inven <> 0' : 'd.id_jenis = 4 AND d.adj_inven < 0';
        $rows = DB::select("SELECT d.id_master, d.id_jenis, d.adj_inven, d.keterangan, d.id_accurate, d.created_by FROM new_pro_inventory_depot d
            WHERE $filter AND d.tanggal_inven = ? AND $cond ORDER BY d.id_master", [$tid, $tanggal]);
        $acc = $this->dariAccurate($rows, fn ($x) => (int) $x->id_jenis === 5 ? 'purchase-return' : 'item-adjustment');

        return [
            'type' => $type,
            'data' => array_map(fn ($x) => [
                'jenis' => $type === 'transfer' ? 'Transfer' : ((int) $x->id_jenis === 5 ? 'Purchase Return' : 'Adjustment'),
                'volume' => (float) $x->adj_inven, 'nomor' => $acc[$x->id_master]['nomor'] ?? null, 'id_accurate' => $x->id_accurate,
                'kode_item' => $acc[$x->id_master]['kode_item'] ?? null, 'keterangan' => $x->keterangan, 'pic' => $x->created_by,
            ], $rows),
            'total' => array_sum(array_map(fn ($x) => (float) $x->adj_inven, $rows)),
        ];
    }
}
