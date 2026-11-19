<?php

namespace App\Console\Commands;

use App\Models\AclUser;
use App\Models\PushSubscription;
use App\Services\PushService;
use App\Services\WaitingCounts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pemantau approval: item PO, SI, TI, DR, dan penawaran dibuat oleh aplikasi web lama, jadi backend ini tidak tahu kapan ada yang baru.
 * Perintah ini dijalankan tiap menit (scheduler). Ia membandingkan DAFTAR item yang menunggu keputusan tiap user dengan catatan terakhir,
 * lalu mengirim Web Push yang menyebut kode dokumen yang BARU (mis. "PO baru: 023/EMLI/SMD/XI/25") dan total yang menunggu,
 * supaya ikon aplikasi (Badging API) ikut menampilkan angka walau aplikasi tertutup.
 * Membandingkan id (bukan jumlah) juga menangkap kasus satu item diputuskan dan satu item baru masuk di rentang yang sama.
 */
class NotifyWaiting extends Command
{
    protected $signature = 'orca:notify-waiting {--dry : hanya tampilkan, jangan kirim}';

    protected $description = 'Kirim push ke user yang punya approval baru menunggu keputusan';

    private const LABEL = ['penawaran' => ['Penawaran', 'offer'], 'po' => ['PO', 'PO'], 'shipping' => ['SI', 'SI'], 'trucking' => ['TI', 'TI'], 'dr' => ['DR', 'DR'], 'trucking_dr' => ['Trucking DR', 'Trucking DR'], 'verifikasi_customer' => ['Verifikasi Customer', 'Verifikasi Customer'], 'refund' => ['Penerima Refund', 'Penerima Refund'], 'permohonan' => ['Permohonan', 'Permohonan'], 'lcr' => ['LCR', 'LCR'], 'sc' => ['Sales Confirmation', 'Sales Confirmation'], 'oa' => ['Verifikasi OA', 'OA Verification'], 'forecast' => ['Forecast', 'Forecast'], 'forecast_request' => ['Request Forecast', 'Forecast Request'], 'forecast_om' => ['Request Qty', 'Qty Request'], 'forecast_revisi' => ['Forecast ditolak CEO', 'Forecast rejected by CEO'], 'forecast_bm' => ['Forecast ditolak CEO', 'Forecast rejected by CEO']];
    private const URL = ['penawaran' => '/approval', 'po' => '/po', 'shipping' => '/shipping', 'trucking' => '/trucking', 'dr' => '/dr', 'trucking_dr' => '/trucking-dr', 'verifikasi_customer' => '/verifikasi-customer', 'refund' => '/refund-penerima', 'permohonan' => '/permohonan', 'lcr' => '/lcr', 'sc' => '/sales-confirmation', 'oa' => '/verifikasi-oa', 'forecast' => '/forecast', 'forecast_request' => '/forecast?tab=request', 'forecast_om' => '/forecast-om?tab=request', 'forecast_revisi' => '/forecast-om', 'forecast_bm' => '/forecast-bm'];
    private const MAX_CODES = 2;   // kode yang ditampilkan per modul
    private const MAX_LINES = 3;   // baris modul per notifikasi

    public function handle(PushService $push): int
    {
        $ids = PushSubscription::query()->distinct()->pluck('id_user');
        $sent = 0;
        foreach (AclUser::whereIn('id_user', $ids)->where('is_active', 1)->get() as $user) {
            try {
                $items = WaitingCounts::items($user);
            } catch (\Throwable $e) {
                $this->warn("user {$user->id_user}: {$e->getMessage()}");

                continue;
            }
            $counts = array_map('count', $items);
            $total = array_sum($counts);
            $state = DB::table('mobile_notify_state')->where('id_user', $user->id_user)->first();
            $prev = ($state && $state->items) ? (json_decode($state->items, true) ?: []) : null;

            // modul => daftar kode dokumen yang baru muncul sejak catatan terakhir
            $new = [];
            if ($prev !== null) {
                foreach (WaitingCounts::MODULES as $m) {
                    if (! array_key_exists($m, $prev)) {
                        continue; // modul baru di catatan lama: rekam dulu sebagai baseline, jangan kirim semua yang sudah menunggu
                    }
                    $known = array_flip(array_map('intval', $prev[$m] ?? []));
                    foreach ($items[$m] ?? [] as $id => $label) {
                        if (! isset($known[(int) $id])) {
                            $new[$m][] = (string) $label;
                        }
                    }
                }
            }

            $sentNow = false;
            if ($new) {
                $bodyId = $this->body($new, $total, 'id');
                $bodyEn = $this->body($new, $total, 'en');
                $url = count($new) === 1 ? self::URL[array_key_first($new)] : '/approval';
                if ($this->option('dry')) {
                    $this->line("user {$user->id_user} ({$user->username}):\n{$bodyId}");
                } else {
                    $sent += $push->send([$user->id_user], [
                        'title' => 'Menunggu keputusan Anda',
                        'body'  => $bodyId,
                        'url'   => $url,
                        'tag'   => 'waiting',
                        'badge' => $total,
                        'en'    => ['title' => 'Awaiting your decision', 'body' => $bodyEn, 'badge' => $total],
                    ]);
                    $sentNow = true;
                }
            }

            if (! $this->option('dry')) {
                DB::table('mobile_notify_state')->updateOrInsert(['id_user' => $user->id_user], [
                    'counts' => json_encode($counts),
                    'items'  => json_encode(array_map(fn ($a) => array_map('intval', array_keys($a)), $items)),
                    'updated_at' => now(), 'created_at' => now(),
                ] + ($sentNow ? ['notified_at' => now()] : []));
            }
        }
        $this->info("Selesai. Push terkirim: {$sent}");

        return self::SUCCESS;
    }

    /** Isi notifikasi: satu baris per modul yang punya item baru (maks. beberapa kode), lalu total yang menunggu. */
    private function body(array $new, int $total, string $lang): string
    {
        $en = $lang === 'en';
        $lines = [];
        foreach (array_slice($new, 0, self::MAX_LINES, true) as $m => $labels) {
            $n = count($labels);
            $name = self::LABEL[$m][$en ? 1 : 0];
            $shown = array_map(fn ($l) => Str::limit($l, 44), array_slice($labels, 0, self::MAX_CODES));
            $rest = $n - count($shown);
            $codes = implode(', ', $shown).($rest > 0 ? ($en ? " +{$rest} more" : " +{$rest} lainnya") : '');
            $lines[] = $en
                ? ($n === 1 ? "New {$name}: {$codes}" : "{$n} new {$name}: {$codes}")
                : ($n === 1 ? "{$name} baru: {$codes}" : "{$n} {$name} baru: {$codes}");
        }
        $hidden = count($new) - count($lines);
        if ($hidden > 0) {
            $lines[] = $en ? "+{$hidden} other types" : "+{$hidden} jenis lain";
        }
        $lines[] = $en ? "Total awaiting: {$total}" : "Total menunggu: {$total}";

        return implode("\n", $lines);
    }
}
