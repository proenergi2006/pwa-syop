<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Pengaturan aplikasi sederhana di tabel mobile_settings (nama => nilai teks/JSON). */
class AppSettings
{
    public static function get(string $nama): ?string
    {
        try {
            $v = DB::table('mobile_settings')->where('nama', $nama)->value('nilai');

            return $v === null ? null : (string) $v;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function put(string $nama, ?string $nilai): void
    {
        DB::table('mobile_settings')->upsert([['nama' => $nama, 'nilai' => $nilai, 'diubah_pada' => now()]], ['nama'], ['nilai', 'diubah_pada']);
    }

    // ---- mode pemeliharaan ----

    /** @return array{aktif:bool,pesan:string,sampai:?string} Mode aktif otomatis berakhir bila waktu "sampai" sudah lewat. */
    public static function pemeliharaan(): array
    {
        $r = Cache::remember('setting_maintenance', 15, function () {
            $j = json_decode((string) self::get('maintenance'), true);

            return is_array($j) ? $j : [];
        });
        $aktif = ! empty($r['aktif']);
        $sampai = $r['sampai'] ?? null;
        if ($aktif && $sampai && strtotime($sampai) <= time()) {
            $aktif = false;
        }

        return ['aktif' => $aktif, 'pesan' => (string) ($r['pesan'] ?? ''), 'sampai' => $sampai];
    }

    public static function simpanPemeliharaan(bool $aktif, ?string $pesan, ?string $sampai): void
    {
        self::put('maintenance', json_encode(['aktif' => $aktif, 'pesan' => (string) $pesan, 'sampai' => $sampai], JSON_UNESCAPED_UNICODE));
        Cache::forget('setting_maintenance');
    }

    // ---- pemantauan ----

    public static function detak(): void
    {
        try {
            self::put('hb_scheduler', now()->toDateTimeString());
        } catch (\Throwable) { /* abaikan */ }
    }

    /** Catat kegagalan push untuk dasbor kesehatan (hitungan per jam + alasan terakhir). */
    public static function catatPushGagal(string $alasan): void
    {
        try {
            $k = 'push_gagal:'.date('YmdH');
            Cache::add($k, 0, 90000);
            Cache::increment($k);
            self::put('push_gagal_terakhir', json_encode(['waktu' => now()->toDateTimeString(), 'pesan' => mb_substr($alasan, 0, 200)], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable) { /* abaikan */ }
    }

    public static function pushGagal24Jam(): int
    {
        $n = 0;
        for ($i = 0; $i < 24; $i++) {
            $n += (int) Cache::get('push_gagal:'.date('YmdH', strtotime("-$i hours")), 0);
        }

        return $n;
    }
}
