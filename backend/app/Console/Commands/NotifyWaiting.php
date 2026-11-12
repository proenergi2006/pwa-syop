<?php

namespace App\Console\Commands;

use App\Models\AclUser;
use App\Models\PushSubscription;
use App\Services\PushService;
use App\Services\WaitingCounts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pemantau approval: item PO, SI, TI, DR, dan penawaran dibuat oleh aplikasi web lama, jadi backend ini tidak tahu kapan ada yang baru.
 * Perintah ini dijalankan tiap menit (scheduler) dan mengirim Web Push ke user yang jumlah "menunggu keputusan"-nya BERTAMBAH.
 * Notifikasi berisi jumlah total supaya ikon aplikasi (Badging API) ikut menampilkan angka walau aplikasi tertutup.
 */
class NotifyWaiting extends Command
{
    protected $signature = 'orca:notify-waiting {--dry : hanya tampilkan, jangan kirim}';

    protected $description = 'Kirim push ke user yang punya approval baru menunggu keputusan';

    private const LABEL = ['penawaran' => ['penawaran', 'offer'], 'po' => ['PO', 'PO'], 'shipping' => ['SI', 'SI'], 'trucking' => ['TI', 'TI'], 'dr' => ['DR', 'DR']];
    private const URL = ['penawaran' => '/approval', 'po' => '/po', 'shipping' => '/shipping', 'trucking' => '/trucking', 'dr' => '/dr'];

    public function handle(PushService $push): int
    {
        $ids = PushSubscription::query()->distinct()->pluck('id_user');
        $sent = 0;
        foreach (AclUser::whereIn('id_user', $ids)->where('is_active', 1)->get() as $user) {
            try {
                $now = WaitingCounts::for($user);
            } catch (\Throwable $e) {
                $this->warn("user {$user->id_user}: {$e->getMessage()}");

                continue;
            }
            $total = array_sum($now);
            $state = DB::table('mobile_notify_state')->where('id_user', $user->id_user)->first();
            $prev = $state ? (json_decode($state->counts, true) ?: []) : null;

            $new = [];
            if ($prev !== null) {
                foreach (WaitingCounts::MODULES as $m) {
                    $d = ($now[$m] ?? 0) - ($prev[$m] ?? 0);
                    if ($d > 0) {
                        $new[$m] = $d;
                    }
                }
            }

            $sentNow = false;
            if ($new) {
                $id = collect($new)->map(fn ($n, $m) => "{$n} ".self::LABEL[$m][0].' baru')->implode(' · ');
                $en = collect($new)->map(fn ($n, $m) => "{$n} new ".self::LABEL[$m][1])->implode(' · ');
                $url = count($new) === 1 ? self::URL[array_key_first($new)] : '/approval';
                if ($this->option('dry')) {
                    $this->line("user {$user->id_user} ({$user->username}): {$id} (total {$total})");
                } else {
                    $sent += $push->send([$user->id_user], [
                        'title' => 'Menunggu keputusan Anda',
                        'body'  => "{$id} · total {$total}",
                        'url'   => $url,
                        'tag'   => 'waiting',
                        'badge' => $total,
                        'en'    => ['title' => 'Awaiting your decision', 'body' => "{$en} · total {$total}", 'badge' => $total],
                    ]);
                    $sentNow = true;
                }
            }

            if (! $this->option('dry')) {
                DB::table('mobile_notify_state')->updateOrInsert(['id_user' => $user->id_user], [
                    'counts' => json_encode($now), 'updated_at' => now(), 'created_at' => now(),
                ] + ($sentNow ? ['notified_at' => now()] : []));
            }
        }
        $this->info("Selesai. Push terkirim: {$sent}");

        return self::SUCCESS;
    }
}
