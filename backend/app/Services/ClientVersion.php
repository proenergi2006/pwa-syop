<?php

namespace App\Services;

use App\Models\AclUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Mencatat versi aplikasi (header X-App-Version) yang sedang dipakai tiap pengguna, paling sering sekali per 30 menit per pengguna. */
class ClientVersion
{
    public static function catat(Request $r, ?AclUser $u = null): void
    {
        $u ??= $r->user();
        $v = trim((string) $r->header('X-App-Version', ''));
        if (! $u || $v === '' || strlen($v) > 40 || ! preg_match('/^[A-Za-z0-9._-]+$/', $v)) {
            return;
        }
        if (! Cache::add("appver:{$u->id_user}:{$v}", 1, 1800)) {
            return;
        }
        try {
            DB::table('mobile_client_versions')->upsert([['id_user' => $u->id_user, 'versi' => $v, 'dilihat_pada' => now()]], ['id_user'], ['versi', 'dilihat_pada']);
        } catch (\Throwable) { /* tabel belum dimigrasi: abaikan */ }
    }
}
