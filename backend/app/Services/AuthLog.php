<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Jejak audit autentikasi. Tidak pernah menyimpan password atau vektor wajah. */
class AuthLog
{
    public static function record(string $event, Request $request, ?int $idUser = null, ?string $username = null, ?float $distance = null): void
    {
        try {
            DB::table('mobile_auth_log')->insert([
                'id_user'    => $idUser,
                'username'   => $username ? mb_substr($username, 0, 50) : null,
                'event'      => $event,
                'distance'   => $distance,
                'ip'         => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            ]);
        } catch (\Throwable $e) {
            // Audit tidak boleh menggagalkan login.
            report($e);
        }
    }
}
