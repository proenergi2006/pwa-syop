<?php

namespace Tests\Feature;

use App\Support\LegacyPassword;
use Tests\TestCase;

class LegacyPasswordTest extends TestCase
{
    /** Meniru persis acl_login.php: CheckPassword(htmlspecialchars($pw) . $salt, $hash) dengan crypt(). */
    private function hashWeb(string $postedPassword): string
    {
        $salt = config('orca.password_salt');

        return crypt(htmlspecialchars($postedPassword, ENT_QUOTES) . $salt, '$2a$08$' . 'abcdefghijklmnopqrstuu');
    }

    public function test_cocok_dengan_hash_yang_dibuat_cara_web_lama(): void
    {
        $hash = $this->hashWeb('123456');

        $this->assertTrue(LegacyPassword::check('123456', $hash));
        $this->assertFalse(LegacyPassword::check('1234567', $hash));
        $this->assertFalse(LegacyPassword::check('', $hash));
    }

    public function test_karakter_khusus_diperlakukan_seperti_web_htmlspecialchars(): void
    {
        $hash = $this->hashWeb("a&b'c\"<d>");

        $this->assertTrue(LegacyPassword::check("a&b'c\"<d>", $hash));
        $this->assertFalse(LegacyPassword::check('a&amp;b', $hash . 'x'));
    }

    public function test_hash_tanpa_garam_tidak_diterima_dan_make_bolak_balik(): void
    {
        $tanpaGaram = '$2a$' . substr(password_hash('123456', PASSWORD_BCRYPT, ['cost' => 8]), 4);

        $this->assertFalse(LegacyPassword::check('123456', $tanpaGaram));
        $this->assertTrue(LegacyPassword::check('Rahasia123', LegacyPassword::make('Rahasia123')));
    }

    public function test_password_terlalu_panjang_dan_hash_kosong_ditolak(): void
    {
        $this->assertFalse(LegacyPassword::check(str_repeat('a', 80), $this->hashWeb('aaa')));
        $this->assertFalse(LegacyPassword::check('123456', null));
        $this->assertFalse(LegacyPassword::check('123456', ''));
    }

    public function test_tanpa_garam_terkonfigurasi_tidak_ada_yang_cocok(): void
    {
        $hash = $this->hashWeb('123456');
        config(['orca.password_salt' => '']);

        $this->assertFalse(LegacyPassword::check('123456', $hash));
    }
}
