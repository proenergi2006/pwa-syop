<?php

namespace App\Support;

/**
 * paramEncrypt/paramDecrypt web lama (enkripsi.php + AES.class.php): AES-256 per blok 16 karakter, tanpa IV (ECB),
 * sisa blok diisi byte nol, hasil heksadesimal huruf kecil (32 karakter per blok). Kunci dibaca dari ORCA_PARAM_KEY (.env, heksa 64 karakter).
 */
class ParamCrypt
{
    private static function kunci(): ?string
    {
        $hex = (string) config('orca.param_key');

        return strlen($hex) === 64 && ctype_xdigit($hex) ? hex2bin($hex) : null;
    }

    public static function tersedia(): bool
    {
        return self::kunci() !== null;
    }

    public static function encrypt(string $x): string
    {
        $k = self::kunci();
        $out = '';
        foreach (str_split($x, 16) as $blok) {
            $out .= bin2hex(openssl_encrypt(str_pad($blok, 16, "\0"), 'aes-256-ecb', $k, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING));
        }

        return $out;
    }

    public static function decrypt(string $x): string
    {
        $k = self::kunci();
        $out = '';
        foreach (str_split($x, 32) as $blok) {
            $out .= str_replace("\0", '', openssl_decrypt(hex2bin($blok), 'aes-256-ecb', $k, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING));
        }

        return $out;
    }
}
