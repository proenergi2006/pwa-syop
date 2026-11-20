<?php

namespace App\Support;

/**
 * Port apa adanya dari terbilang_inggris() di tgl_indo.php web lama (termasuk kebiasaannya: tanpa kata "Rupiah",
 * 14-19 ditulis "Four Teen", nol menjadi kosong, dan di atas 1 triliun kosong), supaya kalimat "Say" di cetakan PO sama persis.
 */
class Terbilang
{
    private const UNITS = [1 => 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve'];

    private const TENS = [2 => 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    private static function hitung(float|int $x): string
    {
        if ($x < 0) {
            return 'Negative '.self::hitung(abs($x));
        }
        if ($x < 13) {
            return self::UNITS[(int) $x] ?? '';
        }
        if ($x < 20) {
            return self::hitung($x - 10).' Teen';
        }
        if ($x < 100) {
            return (self::TENS[(int) ($x / 10)] ?? '').' '.self::hitung((int) $x % 10);
        }
        if ($x < 1000) {
            return self::hitung($x / 100).' Hundred '.self::hitung((int) $x % 100);
        }
        if ($x < 1000000) {
            return self::hitung($x / 1000).' Thousand '.self::hitung((int) $x % 1000);
        }
        if ($x < 1000000000) {
            return self::hitung($x / 1000000).' Million '.self::hitung((int) $x % 1000000);
        }
        if ($x < 1000000000000) {
            return self::hitung($x / 1000000000).' Billion '.self::hitung((int) $x % 1000000000);
        }

        return '';
    }

    public static function inggris(float $angka): string
    {
        return trim(preg_replace('/\s+/', ' ', self::hitung($angka)));
    }
}
