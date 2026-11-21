<?php

namespace App\Support;

/**
 * Rumus harga PO Supplier dari form web (vendor-po-new-add.php): handler yang berjalan pada setiap perubahan kolom angka.
 * Hasil dibulatkan seperti toFixed() di form: subtotal, DPP, PPN 12% dan total 4 desimal; PPN 11%, PPh 22 dan PBBKB 0 desimal.
 * Catatan perilaku web yang ikut terbawa: bila "Tanpa OA", PPh 22 dihitung dari subtotal (termasuk iuran migas); bila "Dengan OA", dari volume x harga dasar.
 */
class PoHitung
{
    /**
     * @param  array{volume:float, harga:float, kategori_oa:int, ongkos:float, plat:?string, kd_tax:?string, pbbkb_persen:?float, iuran:float}  $in
     */
    public static function hitung(array $in): array
    {
        $vol = (float) $in['volume'];
        $harga = (float) $in['harga'];
        $oa = (float) ($in['ongkos'] ?? 0);
        $iuran = (float) ($in['iuran'] ?? 0);
        $ec = ($in['kd_tax'] ?? null) === 'EC';
        $dasar = $vol * $harga;

        if ((int) $in['kategori_oa'] === 1) {
            $sub = $dasar + $iuran;
            $pph = $ec ? $sub * 0.3 / 100 : 0.0;
            $ppn11 = 11 * $dasar / 100;
            $dpp = $dasar * 11 / 12;
        } else {
            $sub = $vol * ($harga + $oa) + $iuran;
            $pph = $ec ? $dasar * 0.3 / 100 : 0.0;
            if (in_array($in['plat'] ?? '', ['Hitam', ''], true) || ($in['plat'] ?? null) === null) {
                $ppn11 = 11 * ($vol * ($harga + $oa)) / 100;
                $dpp = $vol * ($harga + $oa) * 11 / 12;
            } else {
                $ppn11 = 11 * $dasar / 100;
                $dpp = $dasar * 11 / 12;
            }
        }
        $ppn12 = 12 * $dpp / 100;
        $pbbkb = isset($in['pbbkb_persen']) && $in['pbbkb_persen'] !== '' ? $dasar * (float) $in['pbbkb_persen'] / 100 : 0.0;
        $total = $sub + $ppn11 + $pph + $pbbkb;

        return [
            'subtotal' => round($sub, 4), 'ppn_11' => round($ppn11, 0), 'pph_22' => round($pph, 0), 'pbbkb' => round($pbbkb, 0),
            'dpp_11_12' => round($dpp, 4), 'ppn_12' => round($ppn12, 4), 'total_order' => round($total, 4),
        ];
    }

    /** Iuran migas bawaan saat dicentang: 0,25% dari harga dasar x volume. */
    public static function iuranBawaan(float $volume, float $harga): float
    {
        return round($harga * 0.25 / 100 * $volume, 0);
    }
}
