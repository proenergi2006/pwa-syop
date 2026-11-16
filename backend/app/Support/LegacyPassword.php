<?php

namespace App\Support;

/**
 * Pencocokan password yang SAMA PERSIS dengan web lama (acl_login.php + passwordHash.php / phpass):
 *   1. password dari form lewat htmlspecialchars($x, ENT_QUOTES)
 *   2. ditambah garam aplikasi (ORCA_PASSWORD_SALT; sama dengan $salt di acl_login.php)
 *   3. hash blowfish lama ($2a$08$...) dicocokkan dengan crypt()
 *   4. panjang (password + garam) > 72 byte ditolak
 * Dengan begitu satu username dan password berlaku di web lama dan PWA tanpa mengubah database.
 */
class LegacyPassword
{
    /** Hash bohongan agar waktu respons sama baik pengguna ada maupun tidak. */
    private const DUMMY_HASH = '$2a$08$usesomesillystringfeOXIQ4U0O8cbxiF5y3GcD3wkoEPuaaaaaaaaaaa';

    public static function check(string $plain, ?string $hash): bool
    {
        $prepared = self::prepare($plain);
        $valid    = $prepared !== null && $hash !== null && $hash !== '';

        $calc = crypt($prepared ?? '', $valid ? $hash : self::DUMMY_HASH);

        return $valid && is_string($calc) && strlen($calc) > 13 && hash_equals($hash, $calc);
    }

    /** Membuat hash format web lama (untuk tes dan alat bantu; aplikasi tidak mengubah password). */
    public static function make(string $plain): string
    {
        $prepared = self::prepare($plain) ?? throw new \InvalidArgumentException('Password terlalu panjang.');

        return '$2a$' . substr(password_hash($prepared, PASSWORD_BCRYPT, ['cost' => 8]), 4);
    }

    private static function prepare(string $plain): ?string
    {
        $salt = (string) config('orca.password_salt');
        if ($salt === '') {
            return null; // tanpa garam, tidak ada password yang bisa cocok dengan web lama
        }

        $prepared = htmlspecialchars($plain, ENT_QUOTES) . $salt;

        return strlen($prepared) > 72 ? null : $prepared;
    }
}
