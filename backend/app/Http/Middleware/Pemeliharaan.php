<?php

namespace App\Http\Middleware;

use App\Services\AppSettings;
use Closure;
use Illuminate\Http\Request;

/** Mode pemeliharaan: semua API ditolak (503, code MAINTENANCE) kecuali untuk Super Admin, login/logout, dan /status. */
class Pemeliharaan
{
    public function handle(Request $request, Closure $next)
    {
        $m = AppSettings::pemeliharaan();
        if (! $m['aktif'] || preg_match('#^api/v1/(status|auth/login|auth/logout)$#', $request->path())) {
            return $next($request);
        }
        $u = $request->user('sanctum');
        if ($u && (int) $u->id_role === 1) {
            return $next($request);
        }

        return response()->json([
            'message' => $m['pesan'] !== '' ? $m['pesan'] : 'Aplikasi sedang dalam pemeliharaan. Silakan coba lagi nanti.',
            'code' => 'MAINTENANCE', 'sampai' => $m['sampai'],
        ], 503);
    }
}
