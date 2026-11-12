<?php

namespace App\Services;

use App\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Illuminate\Support\Facades\Log;

/**
 * Kirim Web Push ke perangkat milik user. Kegagalan tidak boleh membatalkan approval,
 * jadi semua galat ditelan dan dicatat. Langganan yang sudah dicabut (404/410) dihapus.
 */
class PushService
{
    public function enabled(): bool
    {
        return (bool) (config('orca.push.public_key') && config('orca.push.private_key'));
    }

    /**
     * @param  int[]  $userIds
     * @param  array{title:string, body:string, url?:string, tag?:string, badge?:int, en?:array{title:string, body:string}}  $payload
     *         Kunci 'en' (opsional) = isi untuk perangkat berbahasa Inggris.
     * @return int jumlah push yang berhasil diantar ke layanan push
     */
    public function send(array $userIds, array $payload): int
    {
        $userIds = array_values(array_unique(array_filter($userIds)));
        if ($userIds === [] || ! $this->enabled()) {
            return 0;
        }

        try {
            $subs = PushSubscription::whereIn('id_user', $userIds)->get();
            if ($subs->isEmpty()) {
                return 0;
            }

            $webPush = new WebPush(['VAPID' => [
                'subject'    => config('orca.push.subject'),
                'publicKey'  => config('orca.push.public_key'),
                'privateKey' => config('orca.push.private_key'),
            ]], ['TTL' => config('orca.push.ttl'), 'urgency' => 'high', 'timeout' => 10]);

            $enc = fn (array $p) => json_encode(
                ['title' => $p['title'], 'body' => $p['body'], 'url' => $p['url'] ?? '/', 'tag' => $p['tag'] ?? null, 'badge' => $p['badge'] ?? null],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
            $jsonId = $enc($payload);
            $jsonEn = isset($payload['en']) ? $enc($payload['en'] + $payload) : $jsonId;
            $byEndpoint = [];
            foreach ($subs as $s) {
                $byEndpoint[$s->endpoint] = $s;
                $webPush->queueNotification(
                    Subscription::create(['endpoint' => $s->endpoint, 'publicKey' => $s->p256dh, 'authToken' => $s->auth]),
                    $s->lang === 'en' ? $jsonEn : $jsonId,
                );
            }

            $ok = 0;
            foreach ($webPush->flush() as $report) {
                $sub = $byEndpoint[$report->getEndpoint()] ?? null;
                if ($report->isSuccess()) {
                    $ok++;
                    $sub?->forceFill(['last_sent_at' => now()])->save();
                } elseif ($report->isSubscriptionExpired()) {
                    $sub?->delete();
                } else {
                    Log::warning('[Push] gagal: ' . $report->getReason());
                }
            }

            return $ok;
        } catch (\Throwable $e) {
            Log::warning('[Push] galat: ' . $e->getMessage());

            return 0;
        }
    }
}
