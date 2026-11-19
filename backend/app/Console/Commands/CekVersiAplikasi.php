<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use App\Services\PushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Deteksi rilis versi baru aplikasi: membaca /version.json (ditulis saat build frontend) lalu, bila versinya berubah, mengirim SATU push
 * "Versi baru tersedia" ke pengguna yang aplikasinya belum memakai versi itu (versi dilaporkan aplikasi lewat header X-App-Version;
 * perangkat yang belum pernah melapor dianggap masih versi lama). Pemeriksaan pertama hanya mencatat versi sebagai acuan.
 */
class CekVersiAplikasi extends Command
{
    protected $signature = 'orca:cek-versi {--dry : hanya tampilkan, jangan kirim}';

    protected $description = 'Kirim push bila ada versi aplikasi baru yang dirilis';

    public function handle(PushService $push): int
    {
        $url = config('orca.version_url') ?: rtrim((string) config('app.url'), '/').'/version.json';
        try {
            $res = Http::timeout(8)->acceptJson()->get($url);
            $versi = $res->successful() ? trim((string) ($res->json('version') ?? '')) : '';
        } catch (\Throwable $e) {
            $this->warn("Tidak bisa membaca {$url}: {$e->getMessage()}");

            return self::SUCCESS;
        }
        if ($versi === '') {
            $this->warn("Versi tidak terbaca dari {$url}.");

            return self::SUCCESS;
        }

        $lama = DB::table('mobile_settings')->where('nama', 'app_versi')->value('nilai');
        if ($lama === $versi) {
            return self::SUCCESS;
        }
        if (! $this->option('dry')) {
            DB::table('mobile_settings')->upsert([['nama' => 'app_versi', 'nilai' => $versi, 'diubah_pada' => now()]], ['nama'], ['nilai', 'diubah_pada']);
        }
        if ($lama === null) {
            $this->info("Versi acuan dicatat: {$versi}");

            return self::SUCCESS;
        }

        // pengguna berlangganan push yang belum memakai versi baru
        $sudah = DB::table('mobile_client_versions')->where('versi', $versi)->pluck('id_user');
        $ids = PushSubscription::query()->whereNotIn('id_user', $sudah)->distinct()->pluck('id_user')->all();
        $this->info("Versi baru {$versi} (sebelumnya {$lama}); pengguna yang perlu diberi tahu: ".count($ids));
        if ($this->option('dry') || ! $ids) {
            return self::SUCCESS;
        }
        $n = $push->send($ids, [
            'title' => 'Versi baru tersedia', 'body' => 'ORCA X Mobile versi baru siap dipakai. Buka aplikasi lalu ketuk Perbarui sekarang.',
            'url' => '/profil', 'tag' => 'app-update',
            'en' => ['title' => 'New version available', 'body' => 'A new version of ORCA X Mobile is ready. Open the app and tap Update now.'],
        ]);
        $this->info("Push terkirim: {$n}");

        return self::SUCCESS;
    }
}
