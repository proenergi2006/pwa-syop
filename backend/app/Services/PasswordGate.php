<?php

namespace App\Services;

use App\Models\AclUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Konfirmasi password untuk aksi sensitif (daftar/hapus wajah, setujui penawaran
 * dari sesi face login). Dibatasi 5 kali gagal per 15 menit per pengguna.
 */
class PasswordGate
{
    public static function key(AclUser $user): string
    {
        return 'pwgate:' . $user->id_user;
    }

    public static function locked(AclUser $user): bool
    {
        return RateLimiter::tooManyAttempts(self::key($user), 5);
    }

    public static function check(AclUser $user, string $password, Request $request): bool
    {
        if (self::locked($user)) {
            return false;
        }

        // password_verify: hash lama berawalan $2a$ ditolak oleh Hash::check.
        if (password_verify($password, (string) $user->password)) {
            RateLimiter::clear(self::key($user));

            return true;
        }

        RateLimiter::hit(self::key($user), 15 * 60);
        AuthLog::record('step_up_fail', $request, (int) $user->id_user, $user->username);

        return false;
    }
}
