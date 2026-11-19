<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Aturan "default forecast = cermin realisasi loading bulan lalu" dari forecast-default.php. OM hanya melihat data yang sudah tersimpan
 * (penyimpanan default dilakukan cron web, cekforecastdefault.php, mulai H-3); yang dipakai OM di sini hanyalah penanda hapus:
 * bila OM mengosongkan sel terakhir seorang customer yang punya default, disimpan baris qty 0 supaya customer itu dianggap sudah diisi
 * dan default tidak mengisinya lagi.
 * pro_forecast.qty disimpan sebagai "angka depan" (8 = 8000 liter), jadi volume dibagi 1000.
 */
class ForecastDefault
{
    /** @return array{0:int,1:int} hari awal dan akhir periode (1 = tanggal 1-14, 2 = 15 sampai akhir bulan) */
    public static function range(int $bulan, int $tahun, int $periode): array
    {
        $hari = (int) date('t', mktime(0, 0, 0, $bulan, 1, $tahun)); // tanpa ekstensi calendar
        return [$periode === 1 ? 1 : 15, $periode === 1 ? min(14, $hari) : $hari];
    }

    /** [id_customer => [hari => qty]] hasil cermin bulan lalu untuk satu periode (dan satu customer bila diberikan). */
    public static function map(int $cabang, int $bulan, int $tahun, int $periode, ?int $customerId = null): array
    {
        [$lo, $hi] = self::range($bulan, $tahun, $periode);
        $prevTs = mktime(0, 0, 0, $bulan - 1, 1, $tahun);
        $prevAwal = date('Y-m-01', $prevTs);
        $prevAkhir = date('Y-m-t', $prevTs);
        $hiPrev = (int) date('t', $prevTs);
        if ($lo > $hiPrev) {
            return [];
        }
        $hi = min($hi, $hiPrev);
        $tgl = 'COALESCE(dsd.tanggal_loaded, dsk.tanggal_loaded)';
        $rows = DB::select("
            select c.id_customer, DAY($tgl) as hari, SUM(COALESCE(prd.volume, prdk.volume)) as vol
            from new_pro_inventory_depot d
                join pro_master_terminal t on t.id_master = d.id_terminal
                left join pro_po_ds_detail dsd on d.id_dsd = dsd.id_dsd
                left join pro_pr_detail prd on dsd.id_prd = prd.id_prd
                left join pro_po_customer poc on dsd.id_poc = poc.id_poc
                left join pro_po_ds_kapal dsk on d.id_dsk = dsk.id_dsk
                left join pro_pr_detail prdk on dsk.id_prd = prdk.id_prd
                left join pro_po_customer pock on dsk.id_poc = pock.id_poc
                join pro_customer c on c.id_customer = COALESCE(poc.id_customer, pock.id_customer)
            where c.id_wilayah = ?
              ".($customerId ? 'and c.id_customer = '.(int) $customerId : '')."
              and $tgl between ? and ?
              and DAY($tgl) between $lo and $hi
              and d.id_datanya = 'generate Loading'
              and d.out_inven <> 0
              and t.is_active = 1 and t.kategori_terminal in (1,3) and t.id_master not in (92,101)
              and (dsd.id_dsd is null or dsd.is_cancel = 0)
              and (dsk.id_dsk is null or dsk.is_cancel = 0)
            group by c.id_customer, DAY($tgl)", [$cabang, $prevAwal, $prevAkhir]);
        $map = [];
        foreach ($rows as $r) {
            $qty = round(((float) $r->vol) / 1000, 3);
            if ($qty > 0) {
                $map[(int) $r->id_customer][(int) $r->hari] = $qty;
            }
        }

        return $map;
    }

    /** Dipanggil setelah sebuah sel customer dikosongkan: bila periodenya jadi kosong padahal punya default, simpan penanda (qty 0). */
    public static function tandaiDihapus(int $idCustomer, string $tanggal): void
    {
        $ts = strtotime($tanggal);
        if (! $ts) {
            return;
        }
        $bulan = (int) date('n', $ts);
        $tahun = (int) date('Y', $ts);
        $periode = (int) date('j', $ts) <= 14 ? 1 : 2;
        [$lo, $hi] = self::range($bulan, $tahun, $periode);
        $awal = sprintf('%04d-%02d-%02d', $tahun, $bulan, $lo);
        $akhir = sprintf('%04d-%02d-%02d', $tahun, $bulan, $hi);
        if (DB::table('pro_forecast')->where('id_customer', $idCustomer)->whereBetween('tanggal', [$awal, $akhir])->exists()) {
            return;
        }
        $cabang = DB::table('pro_customer')->where('id_customer', $idCustomer)->value('id_wilayah');
        if (! $cabang || ! self::map((int) $cabang, $bulan, $tahun, $periode, $idCustomer)) {
            return;
        }
        DB::statement('insert ignore into pro_forecast (id_customer, tanggal, qty) values (?, ?, 0)', [$idCustomer, $tanggal]);
    }
}
