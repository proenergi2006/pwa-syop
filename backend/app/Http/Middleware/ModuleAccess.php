<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Membatasi modul per id_role. Pemakaian: ModuleAccess::class.':inventory'. */
class ModuleAccess
{
    public function handle(Request $request, Closure $next, string $module)
    {
        $user = $request->user();
        if (! $user || ! self::allows($module, (int) $user->id_role)) {
            return response()->json(['message' => 'Akun Anda tidak memiliki akses ke modul ini.'], 403);
        }

        return $next($request);
    }

    /** Peran '*' di config berarti semua peran boleh memakai modul itu. */
    public static function allows(string $module, int $role): bool
    {
        $roles = (array) config("orca.module_roles.$module", []);

        return in_array('*', $roles, true) || in_array($role, $roles, true);
    }
}
