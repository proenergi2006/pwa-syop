<?php

namespace App\Http\Controllers;

use App\Models\AclUser;
use App\Models\FaceTemplate;
use App\Services\AuthLog;
use App\Support\LegacyPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $user = AclUser::query()
            ->where('username', $data['username'])
            ->where('is_active', 1)
            ->first();

        // Sama persis dengan web lama (htmlspecialchars + garam + crypt); lihat LegacyPassword.
        $ok = LegacyPassword::check($data['password'], $user?->password);

        if (! $user || ! $ok) {
            AuthLog::record('login_fail', $request, $user ? (int) $user->id_user : null, $data['username']);

            return response()->json(['message' => 'Username atau password salah.'], 422);
        }

        if (! self::roleAllowed((int) $user->id_role)) {
            return response()->json([
                'message' => 'Akun Anda belum memiliki akses aplikasi ini.',
            ], 403);
        }

        DB::table('acl_user')->where('id_user', $user->id_user)->update([
            'last_login_time' => now(),
        ]);

        $token = $user->createToken(
            'orca-x-mobile',
            ['*'],
            now()->addMinutes(config('orca.token_ttl_minutes')),
        )->plainTextToken;

        AuthLog::record('login_ok', $request, (int) $user->id_user, $user->username);

        return response()->json([
            'token'       => $token,
            'auth_method' => 'password',
            'user'        => $this->profileFor($user),
        ]);
    }

    /** Semua id_role yang boleh masuk aplikasi (gabungan peran semua modul). */
    /** Boleh masuk bila perannya terdaftar di salah satu modul, atau ada modul yang terbuka untuk semua peran ('*'). */
    public static function roleAllowed(int $role): bool
    {
        return in_array('*', self::allowedRoles(), true) || in_array($role, self::allowedRoles(), true);
    }

    public static function allowedRoles(): array
    {
        return array_values(array_unique(array_merge(...array_values(config('orca.module_roles')))));
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        \App\Services\ClientVersion::catat($request, $user);

        return response()->json([
            'user'        => $this->profileFor($user),
            // Token password = '*', token face login = ['face'] (perlu password untuk memutuskan).
            'auth_method' => $user->tokenCan('*') ? 'password' : 'face',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Berhasil keluar.']);
    }

    public function profileFor(AclUser $user): array
    {
        $role = DB::table('acl_role')->where('id_role', $user->id_role)->value('role_name');

        return [
            'id'         => $user->id_user,
            'username'   => $user->username,
            'fullname'   => $user->fullname,
            'email'      => $user->email_user,
            'id_role'    => (int) $user->id_role,
            'role_name'  => $role,
            'id_wilayah' => $user->id_wilayah,
            'foto'       => $user->foto,
            'modules'    => collect(config('orca.module_roles'))->keys()->filter(fn ($m) => \App\Http\Middleware\ModuleAccess::allows($m, (int) $user->id_role))->values()->all(),
            'face_enrolled' => FaceTemplate::where('id_user', $user->id_user)->exists(),
            'photo_v'    => ($t = DB::table('mobile_user_photos')->where('id_user', $user->id_user)->value('updated_at')) ? strtotime($t) : null,
        ];
    }
}
