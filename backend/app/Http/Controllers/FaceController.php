<?php

namespace App\Http\Controllers;

use App\Models\AclUser;
use App\Models\FaceTemplate;
use App\Services\AuthLog;
use App\Services\FaceService;
use App\Services\PasswordGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

class FaceController extends Controller
{
    public function __construct(private readonly FaceService $face) {}

    /** Status pendaftaran wajah milik user yang login. */
    public function status(Request $request): JsonResponse
    {
        $t = FaceTemplate::where('id_user', $request->user()->id_user)->latest()->first(['created_at']);

        return response()->json(['enrolled' => (bool) $t, 'enrolled_at' => $t?->created_at]);
    }

    /** Daftarkan wajah (menggantikan yang lama). Wajib password + persetujuan. */
    public function enroll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password'   => ['required', 'string'],
            'consent'    => ['accepted'],
            'descriptor' => ['required', 'array'],
        ], ['consent.accepted' => 'Anda harus menyetujui penggunaan data wajah.']);

        $user = $request->user();
        if (! PasswordGate::check($user, $data['password'], $request)) {
            return $this->passwordFail($user);
        }

        try {
            $descriptor = $this->face->validate($data['descriptor']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'FACE_INVALID'], 422);
        }

        DB::transaction(function () use ($user, $descriptor, $request) {
            FaceTemplate::where('id_user', $user->id_user)->delete();
            FaceTemplate::create([
                'id_user' => $user->id_user,
                'template' => $descriptor,
                'device'  => mb_substr((string) $request->userAgent(), 0, 190),
            ]);
        });

        AuthLog::record('face_enroll', $request, (int) $user->id_user, $user->username);

        return response()->json(['message' => 'Wajah berhasil didaftarkan.', 'enrolled' => true]);
    }

    /** Hapus data wajah (hak pengguna atas data biometriknya). */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);

        $user = $request->user();
        if (! PasswordGate::check($user, $data['password'], $request)) {
            return $this->passwordFail($user);
        }

        FaceTemplate::where('id_user', $user->id_user)->delete();
        AuthLog::record('face_remove', $request, (int) $user->id_user, $user->username);

        return response()->json(['message' => 'Data wajah dihapus.', 'enrolled' => false]);
    }

    /** Login 1:1: username + embedding wajah. Respons gagal sengaja seragam (anti enumerasi user). */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username'   => ['required', 'string', 'max:50'],
            'descriptor' => ['required', 'array'],
        ]);

        $key = 'face:' . sha1(mb_strtolower($data['username']));
        $max = (int) config('orca.face.max_attempts');

        if (RateLimiter::tooManyAttempts($key, $max)) {
            AuthLog::record('face_locked', $request, null, $data['username']);

            return response()->json([
                'message' => 'Terlalu banyak percobaan wajah. Masuk dengan kata sandi, atau coba lagi nanti.',
                'code'    => 'FACE_LOCKED',
            ], 429);
        }

        $fail = function (?AclUser $u = null, ?float $dist = null) use ($key, $request, $data) {
            RateLimiter::hit($key, (int) config('orca.face.lock_minutes') * 60);
            AuthLog::record('face_fail', $request, $u ? (int) $u->id_user : null, $data['username'], $dist);

            return response()->json([
                'message' => 'Wajah tidak dikenali. Coba lagi atau masuk dengan kata sandi.',
                'code'    => 'FACE_MISMATCH',
            ], 422);
        };

        try {
            $descriptor = $this->face->validate($data['descriptor']);
        } catch (InvalidArgumentException) {
            return $fail();
        }

        $user = AclUser::query()->where('username', $data['username'])->where('is_active', 1)->first();
        if (! $user || ! AuthController::roleAllowed((int) $user->id_role)) {
            return $fail();
        }

        $distance = $this->face->bestDistance((int) $user->id_user, $descriptor);
        if ($distance === null || ! $this->face->matches($distance)) {
            return $fail($user, $distance);
        }

        RateLimiter::clear($key);
        FaceTemplate::where('id_user', $user->id_user)->update(['last_used_at' => now()]);
        DB::table('acl_user')->where('id_user', $user->id_user)->update(['last_login_time' => now()]);
        AuthLog::record('face_ok', $request, (int) $user->id_user, $user->username, $distance);

        // Kemampuan terbatas ('face'): boleh membaca, tidak boleh memutuskan tanpa password.
        $token = $user->createToken(
            'orca-x-mobile-face',
            ['face'],
            now()->addMinutes(config('orca.face.token_ttl_minutes')),
        )->plainTextToken;

        return response()->json([
            'token'       => $token,
            'auth_method' => 'face',
            'user'        => app(AuthController::class)->profileFor($user),
        ]);
    }

    private function passwordFail(AclUser $user): JsonResponse
    {
        return PasswordGate::locked($user)
            ? response()->json(['message' => 'Terlalu banyak salah kata sandi. Coba lagi 15 menit lagi.', 'code' => 'PASSWORD_LOCKED'], 429)
            : response()->json(['message' => 'Kata sandi salah.', 'code' => 'PASSWORD_WRONG'], 422);
    }
}
