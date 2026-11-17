<?php

namespace App\Http\Controllers\Concerns;

use App\Services\PasswordGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait StepUp
{
    /** Sesi hasil login wajah wajib mengonfirmasi password sebelum menulis data master. Null = lolos. */
    private function stepUp(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user->tokenCan('*')) {
            return null;
        }
        $password = (string) $request->input('password', '');
        if ($password === '') {
            return response()->json(['message' => 'Masukkan kata sandi untuk menyimpan perubahan.', 'code' => 'STEP_UP_REQUIRED'], 403);
        }
        if (! PasswordGate::check($user, $password, $request)) {
            return PasswordGate::locked($user)
                ? response()->json(['message' => 'Terlalu banyak salah kata sandi. Coba lagi 15 menit lagi.', 'code' => 'PASSWORD_LOCKED'], 429)
                : response()->json(['message' => 'Kata sandi salah.', 'code' => 'STEP_UP_FAILED'], 422);
        }

        return null;
    }
}
