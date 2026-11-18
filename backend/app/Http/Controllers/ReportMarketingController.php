<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Services\LegacyFile;
use Illuminate\Support\Facades\DB;

/**
 * Report Marketing untuk OM (peran 6). Hanya baca.
 * Sumber: marketing-volume-report.php + __get_volume_marketing.php + data-detail-volume-marketing.php (volume kirim),
 *         marketing-active-customer.php + __get_active_customer.php + data-detail-active-customer.php (customer aktif),
 *         __get_new_customer.php + __get_marketing_new_customer.php (customer baru).
 * Marketing = peran 11/17; atribusi marketing: PO customer > penawaran > customer (COALESCE), sama seperti web.
 * Seluruh baris marketing dikirim sekaligus (tanpa filter marketing) supaya grafik, peringkat dan filter dihitung di aplikasi.
 */
class ReportMarketingController extends Controller
{
    private const TTL = 300; // detik; query riwayat PO besar, hasilnya cukup di-cache sebentar

    private function tahun(Request $r): int
    {
        return max(2015, min((int) date('Y') + 1, (int) $r->query('tahun', date('Y'))));
    }

    private function cabang(Request $r): ?int
    {
        $c = (int) $r->query('cabang', 0);

        return $c > 0 ? $c : null;
    }

    private function bulan(Request $r): int
    {
        return max(1, min(12, (int) $r->query('bulan', 1)));
    }

    /** @return array{0:string,1:string} awal (termasuk) dan akhir (tidak termasuk) tahun */
    private static function rentangTahun(int $tahun): array
    {
        return [sprintf('%04d-01-01', $tahun), sprintf('%04d-01-01', $tahun + 1)];
    }

    /** @return array{0:string,1:string} */
    private static function rentangBulan(int $tahun, int $bulan): array
    {
        $n = $bulan === 12 ? [$tahun + 1, 1] : [$tahun, $bulan + 1];

        return [sprintf('%04d-%02d-01', $tahun, $bulan), sprintf('%04d-%02d-01', $n[0], $n[1])];
    }

    /** SELECT 12 kolom bulanan m1..m12 dari ekspresi per bulan. */
    private static function kolomBulan(callable $ekspresi): string
    {
        return implode(', ', array_map(fn ($m) => $ekspresi($m).' AS m'.$m, range(1, 12)));
    }

    /**
     * @param  array<int,object>  $hasil
     * @return array<int,array{id:int,nama:string,bulan:array<int,float>,total:float}>
     */
    private static function baris(array $hasil): array
    {
        return array_map(function ($x) {
            $b = [];
            for ($m = 1; $m <= 12; $m++) {
                $b[] = (float) $x->{'m'.$m};
            }

            return ['id' => (int) $x->id_user, 'nama' => (string) $x->fullname, 'bulan' => $b, 'total' => array_sum($b)];
        }, $hasil);
    }

    /** @param array<int,array{bulan:array<int,float>}> $baris @return array<int,float> */
    private static function jumlahBulanan(array $baris): array
    {
        $t = array_fill(0, 12, 0.0);
        foreach ($baris as $b) {
            foreach ($b['bulan'] as $i => $v) {
                $t[$i] += $v;
            }
        }

        return $t;
    }

    /** Volume kirim (liter) per marketing per bulan; status_plan=1 dan PO disetujui. */
    private function volumeBaris(int $tahun, ?int $cabang): array
    {
        [$a, $b] = self::rentangTahun($tahun);
        $sql = 'SELECT a.id_user, a.fullname, '.self::kolomBulan(fn ($m) => "COALESCE(SUM(CASE WHEN MONTH(b.tanggal_kirim) = $m THEN b.volume_kirim ELSE 0 END), 0)").'
            FROM pro_po_customer ppc
            JOIN pro_customer pc ON pc.id_customer = ppc.id_customer
            JOIN pro_penawaran pp ON pp.id_penawaran = ppc.id_penawaran
            JOIN pro_po_customer_plan b ON b.id_poc = ppc.id_poc
            LEFT JOIN acl_user mkt_po ON mkt_po.id_user = ppc.id_marketing AND mkt_po.id_role IN (11, 17)
            LEFT JOIN acl_user mkt_pp ON mkt_pp.id_user = pp.id_marketing AND mkt_pp.id_role IN (11, 17)
            JOIN acl_user a ON a.id_user = COALESCE(mkt_po.id_user, mkt_pp.id_user, pc.id_marketing)
            WHERE a.id_role IN (11, 17) AND ppc.poc_approved = 1 AND b.status_plan = 1
              AND b.tanggal_kirim >= ? AND b.tanggal_kirim < ?'.($cabang ? ' AND pp.id_cabang = ?' : '').'
            GROUP BY a.id_user, a.fullname ORDER BY a.fullname ASC';

        return self::baris(DB::select($sql, array_filter([$a, $b, $cabang], fn ($v) => $v !== null)));
    }

    /** Jumlah customer aktif (customer berbeda yang PO-nya sudah ada realisasi kirim) per marketing per bulan PO. */
    private function aktifBaris(int $tahun, ?int $cabang): array
    {
        [$a, $b] = self::rentangTahun($tahun);
        $sql = 'SELECT a.id_user, a.fullname, '.self::kolomBulan(fn ($m) => "COUNT(DISTINCT CASE WHEN MONTH(ppc.tanggal_poc) = $m THEN ppc.id_customer END)").'
            FROM pro_po_customer ppc
            JOIN pro_customer pc ON pc.id_customer = ppc.id_customer
            JOIN pro_penawaran pp ON pp.id_penawaran = ppc.id_penawaran
            JOIN pro_po_customer_plan b ON b.id_poc = ppc.id_poc
            LEFT JOIN acl_user mkt_po ON mkt_po.id_user = ppc.id_marketing AND mkt_po.id_role IN (11, 17)
            LEFT JOIN acl_user mkt_pp ON mkt_pp.id_user = pp.id_marketing AND mkt_pp.id_role IN (11, 17)
            JOIN acl_user a ON a.id_user = COALESCE(mkt_po.id_user, mkt_pp.id_user, pc.id_marketing)
            WHERE a.id_role IN (11, 17) AND ppc.poc_approved = 1 AND b.realisasi_kirim != 0
              AND ppc.tanggal_poc >= ? AND ppc.tanggal_poc < ?'.($cabang ? ' AND pp.id_cabang = ?' : '').'
            GROUP BY a.id_user, a.fullname ORDER BY a.fullname ASC';

        return self::baris(DB::select($sql, array_filter([$a, $b, $cabang], fn ($v) => $v !== null)));
    }

    /**
     * Riwayat order per customer lengkap dengan tanggal order sebelumnya (lintas marketing).
     * "Customer baru" = order pertama sepanjang riwayat, atau jeda >= 6 bulan sejak order valid terakhir.
     */
    private static function dasarBaru(): string
    {
        return "SELECT id_customer, tanggal_poc, id_marketing_efektif, id_cabang, prev_date
            FROM (
                SELECT id_customer, tanggal_poc, id_marketing_efektif, id_cabang,
                    LAG(tanggal_poc) OVER (PARTITION BY id_customer ORDER BY tanggal_poc) AS prev_date
                FROM (
                    SELECT DISTINCT ppc.id_customer, ppc.tanggal_poc,
                        COALESCE(mkt_po.id_user, mkt_pp.id_user, pc.id_marketing) AS id_marketing_efektif, pp.id_cabang
                    FROM pro_po_customer ppc
                    JOIN pro_customer pc ON pc.id_customer = ppc.id_customer
                    JOIN pro_penawaran pp ON pp.id_penawaran = ppc.id_penawaran
                    LEFT JOIN acl_user mkt_po ON mkt_po.id_user = ppc.id_marketing AND mkt_po.id_role IN (11, 17)
                    LEFT JOIN acl_user mkt_pp ON mkt_pp.id_user = pp.id_marketing AND mkt_pp.id_role IN (11, 17)
                    WHERE ppc.poc_approved = 1 AND pc.is_verified = 1 AND pc.status_customer IN (2, 3)
                      AND EXISTS (SELECT 1 FROM pro_po_customer_plan b WHERE b.id_poc = ppc.id_poc AND b.realisasi_kirim != 0)
                ) base
            ) withgap
            WHERE (prev_date IS NULL OR TIMESTAMPDIFF(MONTH, prev_date, tanggal_poc) >= 6)";
    }

    private function baruBaris(int $tahun, ?int $cabang): array
    {
        [$a, $b] = self::rentangTahun($tahun);
        $sql = 'SELECT a.id_user, a.fullname, '.self::kolomBulan(fn ($m) => "COUNT(DISTINCT CASE WHEN MONTH(nc.tanggal_poc) = $m THEN nc.id_customer END)").'
            FROM ('.self::dasarBaru().' AND tanggal_poc >= ? AND tanggal_poc < ?) nc
            JOIN acl_user a ON a.id_user = nc.id_marketing_efektif
            WHERE a.id_role IN (11, 17)'.($cabang ? ' AND nc.id_cabang = ?' : '').'
            GROUP BY a.id_user, a.fullname ORDER BY a.fullname ASC';

        return self::baris(DB::select($sql, array_filter([$a, $b, $cabang], fn ($v) => $v !== null)));
    }

    private function laporan(Request $r, string $jenis): JsonResponse
    {
        $tahun = $this->tahun($r);
        $cabang = $this->cabang($r);
        $fn = ['volume' => 'volumeBaris', 'aktif' => 'aktifBaris', 'baru' => 'baruBaris', 'kegiatan' => 'kegiatanBaris'][$jenis];

        $data = Cache::remember("rpt-mkt:$jenis:$tahun:".($cabang ?? 'all'), self::TTL, function () use ($fn, $jenis, $tahun, $cabang) {
            $baris = $this->$fn($tahun, $cabang);
            $prev = self::jumlahBulanan($this->$fn($tahun - 1, $cabang));

            return [
                'rows' => $baris,
                'totals' => self::jumlahBulanan($baris),
                'prev_totals' => $prev,
                'extra' => $jenis === 'kegiatan' ? $this->kegiatanExtra($tahun, $cabang) : null,
            ];
        });

        $thisYear = (int) date('Y');

        return response()->json([
            'jenis' => $jenis,
            'tahun' => $tahun,
            'cabang' => $cabang,
            'bulan_berjalan' => $tahun === $thisYear ? (int) date('n') : ($tahun < $thisYear ? 12 : 0),
            'rows' => $data['rows'],
            'totals' => $data['totals'],
            'prev_totals' => $data['prev_totals'],
            'total' => array_sum($data['totals']),
            'prev_total' => array_sum($data['prev_totals']),
            'extra' => $data['extra'],
        ]);
    }

    public function cabangList(): JsonResponse
    {
        return response()->json(['data' => DB::table('pro_master_cabang')->orderBy('nama_cabang')->get(['id_master as id', 'nama_cabang as nama'])]);
    }

    public function volume(Request $r): JsonResponse
    {
        return $this->laporan($r, 'volume');
    }

    public function aktif(Request $r): JsonResponse
    {
        return $this->laporan($r, 'aktif');
    }

    public function baru(Request $r): JsonResponse
    {
        return $this->laporan($r, 'baru');
    }

    private function namaMarketing(int $id): string
    {
        return (string) DB::table('acl_user')->where('id_user', $id)->value('fullname');
    }

    /** Rincian pengiriman (plan) seorang marketing pada satu bulan. */
    public function volumeDetail(Request $r): JsonResponse
    {
        $tahun = $this->tahun($r);
        $bulan = $this->bulan($r);
        $mkt = (int) $r->query('marketing');
        $cabang = $this->cabang($r);
        [$a, $b] = self::rentangBulan($tahun, $bulan);

        $sql = 'SELECT a.id_poc, a.id_customer, b.nama_customer, a.nomor_poc, a.tanggal_poc, c.tanggal_kirim, c.volume_kirim, c.realisasi_kirim, d.pr_mobil
            FROM pro_po_customer a
            JOIN pro_customer b ON a.id_customer = b.id_customer
            JOIN pro_po_customer_plan c ON a.id_poc = c.id_poc
            JOIN (SELECT id_plan, MIN(pr_mobil) AS pr_mobil FROM pro_pr_detail GROUP BY id_plan) d ON c.id_plan = d.id_plan
            JOIN pro_penawaran e ON e.id_penawaran = a.id_penawaran
            LEFT JOIN acl_user mkt_po ON mkt_po.id_user = a.id_marketing AND mkt_po.id_role IN (11, 17)
            LEFT JOIN acl_user mkt_pp ON mkt_pp.id_user = e.id_marketing AND mkt_pp.id_role IN (11, 17)
            WHERE a.poc_approved = 1 AND c.status_plan = 1
              AND COALESCE(mkt_po.id_user, mkt_pp.id_user, b.id_marketing) = ?
              AND c.tanggal_kirim >= ? AND c.tanggal_kirim < ?'.($cabang ? ' AND e.id_cabang = ?' : '').'
            ORDER BY c.tanggal_kirim DESC, a.id_poc DESC LIMIT 500';
        $rows = DB::select($sql, array_filter([$mkt, $a, $b, $cabang], fn ($v) => $v !== null));

        $perCustomer = [];
        foreach ($rows as $x) {
            $perCustomer[$x->id_customer] ??= ['id' => (int) $x->id_customer, 'nama' => $x->nama_customer, 'volume' => 0.0, 'realisasi' => 0.0, 'kirim' => 0];
            $perCustomer[$x->id_customer]['volume'] += (float) $x->volume_kirim;
            $perCustomer[$x->id_customer]['realisasi'] += (float) $x->realisasi_kirim;
            $perCustomer[$x->id_customer]['kirim']++;
        }
        $customer = array_values($perCustomer);
        usort($customer, fn ($p, $q) => $q['volume'] <=> $p['volume']);

        return response()->json([
            'marketing' => $this->namaMarketing($mkt), 'tahun' => $tahun, 'bulan' => $bulan,
            'total' => array_sum(array_map(fn ($x) => (float) $x->volume_kirim, $rows)),
            'customer' => $customer,
            'data' => array_map(fn ($x) => [
                'nomor_poc' => $x->nomor_poc, 'customer' => $x->nama_customer, 'tanggal_poc' => $x->tanggal_poc,
                'tanggal_kirim' => $x->tanggal_kirim, 'volume' => (float) $x->volume_kirim, 'realisasi' => (float) $x->realisasi_kirim,
            ], $rows),
        ]);
    }

    /** Customer aktif seorang marketing pada satu bulan PO. */
    public function aktifDetail(Request $r): JsonResponse
    {
        $tahun = $this->tahun($r);
        $bulan = $this->bulan($r);
        $mkt = (int) $r->query('marketing');
        $cabang = $this->cabang($r);
        [$a, $b] = self::rentangBulan($tahun, $bulan);

        $sql = 'SELECT a.id_customer, b.nama_customer, e.nama_prov, g.nama_cabang, SUM(c.realisasi_kirim) AS total
            FROM pro_po_customer a
            JOIN pro_customer b ON a.id_customer = b.id_customer
            JOIN pro_po_customer_plan c ON a.id_poc = c.id_poc
            JOIN (SELECT DISTINCT id_plan FROM pro_pr_detail) d ON c.id_plan = d.id_plan
            JOIN pro_master_provinsi e ON e.id_prov = b.prov_customer
            JOIN pro_penawaran f ON f.id_penawaran = a.id_penawaran
            JOIN pro_master_cabang g ON f.id_cabang = g.id_master
            LEFT JOIN acl_user mkt_po ON mkt_po.id_user = a.id_marketing AND mkt_po.id_role IN (11, 17)
            LEFT JOIN acl_user mkt_pp ON mkt_pp.id_user = f.id_marketing AND mkt_pp.id_role IN (11, 17)
            WHERE a.poc_approved = 1 AND c.realisasi_kirim != 0
              AND COALESCE(mkt_po.id_user, mkt_pp.id_user, b.id_marketing) = ?
              AND a.tanggal_poc >= ? AND a.tanggal_poc < ?'.($cabang ? ' AND f.id_cabang = ?' : '').'
            GROUP BY a.id_customer, b.nama_customer, e.nama_prov, g.nama_cabang
            ORDER BY b.nama_customer ASC LIMIT 500';
        $rows = DB::select($sql, array_filter([$mkt, $a, $b, $cabang], fn ($v) => $v !== null));

        return response()->json([
            'marketing' => $this->namaMarketing($mkt), 'tahun' => $tahun, 'bulan' => $bulan,
            'total' => array_sum(array_map(fn ($x) => (float) $x->total, $rows)),
            'data' => array_map(fn ($x) => ['id' => (int) $x->id_customer, 'customer' => $x->nama_customer, 'provinsi' => $x->nama_prov, 'cabang' => $x->nama_cabang, 'realisasi' => (float) $x->total], $rows),
        ]);
    }

    /** Customer baru seorang marketing pada satu bulan; tipe 'baru' (order pertama) atau 'kembali' (jeda >= 6 bulan). */
    public function baruDetail(Request $r): JsonResponse
    {
        $tahun = $this->tahun($r);
        $bulan = $this->bulan($r);
        $mkt = (int) $r->query('marketing');
        $cabang = $this->cabang($r);
        [$a, $b] = self::rentangBulan($tahun, $bulan);

        $sql = 'SELECT nc.id_customer, pc.nama_customer, nc.tanggal_poc, nc.prev_date, mc.nama_cabang
            FROM ('.self::dasarBaru().' AND tanggal_poc >= ? AND tanggal_poc < ?) nc
            JOIN pro_customer pc ON pc.id_customer = nc.id_customer
            LEFT JOIN pro_master_cabang mc ON mc.id_master = nc.id_cabang
            WHERE nc.id_marketing_efektif = ?'.($cabang ? ' AND nc.id_cabang = ?' : '').'
            ORDER BY nc.tanggal_poc ASC, pc.nama_customer ASC';
        $rows = DB::select($sql, array_filter([$a, $b, $mkt, $cabang], fn ($v) => $v !== null));

        $uniq = [];
        foreach ($rows as $x) {
            $uniq[$x->id_customer] ??= [
                'id' => (int) $x->id_customer, 'customer' => $x->nama_customer, 'tanggal' => $x->tanggal_poc, 'cabang' => $x->nama_cabang,
                'tipe' => $x->prev_date === null ? 'baru' : 'kembali', 'jeda_bulan' => $x->prev_date === null ? null : (int) round((strtotime($x->tanggal_poc) - strtotime($x->prev_date)) / 2629800),
            ];
        }

        return response()->json(['marketing' => $this->namaMarketing($mkt), 'tahun' => $tahun, 'bulan' => $bulan, 'total' => count($uniq), 'data' => array_values($uniq)]);
    }

    /* ------------------------------------------------------------------ */
    /* Kegiatan marketing (pro_marketing_report_master) — marketing-report.php + datatable/marketing-report.php */
    /* Untuk OM hanya lihat (tanpa tambah/ubah/hapus/ajukan, itu milik marketing 11/17). */
    /* ------------------------------------------------------------------ */

    private const TAHAP = [1 => 'SPV', 2 => 'BM', 3 => 'BM Cabang'];

    /** Hasil disposisi terakhir; ditulis persis seperti query web (id_disposisi dibandingkan dengan MAX(disposisi)). */
    private const HASIL_TERAKHIR = '(SELECT result FROM pro_marketing_report_master_disposisi WHERE id_disposisi = (SELECT MAX(disposisi) FROM pro_marketing_report_master_disposisi WHERE id_mkt_report = a.id_mkt_report) AND id_mkt_report = a.id_mkt_report)';

    private const DARI_KEGIATAN = 'FROM pro_marketing_report_master a
        JOIN acl_user b ON b.id_user = a.create_by
        JOIN pro_master_area c ON c.id_master = b.id_wilayah
        JOIN pro_customer d ON a.id_customer = d.id_customer
        WHERE a.deleted_time IS NULL';

    private static function labelStatus($status, $hasil): string
    {
        $tahap = self::TAHAP[(int) $status] ?? null;
        if ((string) $status === '' || ! $tahap) {
            return 'Draf';
        }

        return (($hasil === null || $hasil === '') ? 'Verifikasi' : 'Approve').' '.$tahap;
    }

    private function kegiatanBaris(int $tahun, ?int $cabang): array
    {
        [$a, $b] = self::rentangTahun($tahun);
        $sql = 'SELECT b.id_user, b.fullname, '.self::kolomBulan(fn ($m) => "COUNT(CASE WHEN MONTH(a.tanggal) = $m THEN 1 END)").' '.self::DARI_KEGIATAN.'
            AND a.tanggal >= ? AND a.tanggal < ?'.($cabang ? ' AND b.id_wilayah = ?' : '').'
            GROUP BY b.id_user, b.fullname ORDER BY b.fullname ASC';

        return self::baris(DB::select($sql, array_filter([$a, $b, $cabang], fn ($v) => $v !== null)));
    }

    /** Sebaran status tahap persetujuan dan jumlah customer yang dikunjungi pada tahun itu. */
    private function kegiatanExtra(int $tahun, ?int $cabang): array
    {
        [$a, $b] = self::rentangTahun($tahun);
        $where = ' AND a.tanggal >= ? AND a.tanggal < ?'.($cabang ? ' AND b.id_wilayah = ?' : '');
        $par = array_filter([$a, $b, $cabang], fn ($v) => $v !== null);

        $per = [];
        foreach (DB::select('SELECT a.status AS st, '.self::HASIL_TERAKHIR.' AS hasil, COUNT(*) AS n '.self::DARI_KEGIATAN.$where.' GROUP BY a.status, hasil', $par) as $x) {
            $l = self::labelStatus($x->st, $x->hasil);
            $per[$l] = ($per[$l] ?? 0) + (int) $x->n;
        }
        arsort($per);
        $cust = (int) (DB::selectOne('SELECT COUNT(DISTINCT a.id_customer) AS n '.self::DARI_KEGIATAN.$where, $par)->n ?? 0);

        return ['status' => array_map(fn ($l, $n) => ['label' => $l, 'jumlah' => $n], array_keys($per), array_values($per)), 'customer' => $cust];
    }

    public function kegiatan(Request $r): JsonResponse
    {
        return $this->laporan($r, 'kegiatan');
    }

    /** Daftar kegiatan (urutan terbaru dibuat dulu, seperti web). Filter: tahun, bulan (opsional), marketing, cabang, kata kunci. */
    public function kegiatanList(Request $r): JsonResponse
    {
        $tahun = $this->tahun($r);
        $bulan = (int) $r->query('bulan', 0);
        $mkt = (int) $r->query('marketing', 0);
        $cabang = $this->cabang($r);
        $q = trim((string) $r->query('q', ''));
        $page = max(1, (int) $r->query('page', 1));
        $per = max(5, min(50, (int) $r->query('per_page', 15)));
        [$a, $b] = $bulan >= 1 && $bulan <= 12 ? self::rentangBulan($tahun, $bulan) : self::rentangTahun($tahun);

        $where = ' AND a.tanggal >= ? AND a.tanggal < ?';
        $par = [$a, $b];
        if ($cabang) {
            $where .= ' AND b.id_wilayah = ?';
            $par[] = $cabang;
        }
        if ($mkt) {
            $where .= ' AND b.id_user = ?';
            $par[] = $mkt;
        }
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $where .= ' AND (a.tanggal LIKE ? OR d.nama_customer LIKE ? OR a.kegiatan LIKE ? OR a.hasil_kegiatan LIKE ? OR a.pic_customer LIKE ? OR d.email_customer LIKE ? OR d.telp_customer LIKE ?)';
            array_push($par, $like, $like, $like, $like, $like, $like, $like);
        }

        $total = (int) DB::selectOne('SELECT COUNT(*) AS n '.self::DARI_KEGIATAN.$where, $par)->n;
        $rows = DB::select('SELECT a.id_mkt_report, a.tanggal, a.kegiatan, a.status, d.nama_customer, b.fullname, '.self::HASIL_TERAKHIR.' AS hasil '.self::DARI_KEGIATAN.$where
            .' ORDER BY a.create_date DESC, a.id_mkt_report DESC LIMIT '.$per.' OFFSET '.(($page - 1) * $per), $par);

        return response()->json([
            'data' => array_map(fn ($x) => [
                'id' => (int) $x->id_mkt_report, 'customer' => $x->nama_customer, 'tanggal' => $x->tanggal, 'kegiatan' => $x->kegiatan,
                'marketing' => $x->fullname, 'status' => self::labelStatus($x->status, $x->hasil),
            ], $rows),
            'meta' => ['page' => $page, 'last_page' => max(1, (int) ceil($total / $per)), 'total' => $total],
        ]);
    }

    private function kegiatanBaris1(int $id): ?object
    {
        return DB::selectOne('SELECT a.*, d.nama_customer, d.alamat_customer, d.email_customer, d.telp_customer, b.fullname AS user_name, '.self::HASIL_TERAKHIR.' AS hasil '.self::DARI_KEGIATAN.' AND a.id_mkt_report = ?', [$id]);
    }

    public function kegiatanShow(int $id): JsonResponse
    {
        $x = $this->kegiatanBaris1($id);
        if (! $x) {
            return response()->json(['message' => 'Data kegiatan tidak ditemukan.'], 404);
        }
        $tahap = DB::select('SELECT disposisi, catatan, tanggal, result, pic FROM pro_marketing_report_master_disposisi WHERE id_mkt_report = ? ORDER BY id_disposisi ASC', [$id]);
        $files = [];
        if ($x->file_upload) {
            $files[] = ['id' => 0, 'nama' => $x->file_ori ?: $x->file_upload, 'keterangan' => null];
        }
        foreach (DB::select('SELECT id_file_upload, keterangan, file_upload, file_ori FROM pro_marketing_report_master_file WHERE id_mkt_report = ? ORDER BY id_file_upload', [$id]) as $f) {
            if ($f->file_upload) {
                $files[] = ['id' => (int) $f->id_file_upload, 'nama' => $f->file_ori ?: $f->file_upload, 'keterangan' => $f->keterangan];
            }
        }

        return response()->json([
            'id' => (int) $x->id_mkt_report, 'customer' => $x->nama_customer, 'alamat' => $x->alamat_customer, 'email' => $x->email_customer, 'telp' => $x->telp_customer,
            'pic' => $x->pic_customer, 'tanggal' => $x->tanggal, 'kegiatan' => $x->kegiatan, 'hasil' => $x->hasil_kegiatan, 'marketing' => $x->user_name,
            'status' => self::labelStatus($x->status, $x->hasil),
            'tahap' => array_map(fn ($t) => ['tahap' => self::TAHAP[(int) $t->disposisi] ?? (string) $t->disposisi, 'catatan' => $t->catatan, 'pic' => $t->pic, 'tanggal' => $t->tanggal, 'hasil' => $t->result], $tahap),
            'files' => $files,
        ]);
    }

    /** Lampiran kegiatan: fid 0 = berkas utama (file_upload), selain itu id baris di pro_marketing_report_master_file. */
    public function kegiatanBerkas(int $id, int $fid)
    {
        if (! $this->kegiatanBaris1($id)) {
            return response()->json(['message' => 'Data kegiatan tidak ditemukan.'], 404);
        }
        if ($fid === 0) {
            $row = DB::table('pro_marketing_report_master')->where('id_mkt_report', $id)->first(['file_upload', 'file_ori']);
        } else {
            $row = DB::table('pro_marketing_report_master_file')->where('id_mkt_report', $id)->where('id_file_upload', $fid)->first(['file_upload', 'file_ori']);
        }
        if (! $row || ! $row->file_upload) {
            return response()->json(['message' => 'Berkas tidak ditemukan.', 'code' => 'FILE_MISSING'], 404);
        }

        return LegacyFile::response('lampiran/'.basename($row->file_upload), $row->file_ori ?: $row->file_upload);
    }
}
