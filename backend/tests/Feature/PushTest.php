<?php

namespace Tests\Feature;

use App\Models\AclUser;
use App\Models\PushSubscription;
use App\Services\PushService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class PushTest extends TestCase
{
    use DatabaseTransactions;

    private const OM = 584;
    private const CEO = 122;
    private const PNW_OM_T3 = 68863;

    private const SUB = [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
        'keys' => ['p256dh' => 'BPubKeyDummy', 'auth' => 'AuthDummy'],
    ];

    private function as(int $id): AclUser
    {
        $u = AclUser::findOrFail($id);
        Sanctum::actingAs($u, ['*']);

        return $u;
    }

    public function test_perlu_login(): void
    {
        $this->getJson('/api/v1/push/key')->assertUnauthorized();
        $this->postJson('/api/v1/push/subscribe', self::SUB)->assertUnauthorized();
    }

    public function test_kunci_publik_tersedia_dan_kunci_privat_tidak_bocor(): void
    {
        $this->as(self::CEO);
        $r = $this->getJson('/api/v1/push/key')->assertOk()->assertJsonStructure(['enabled', 'public_key']);
        $this->assertStringNotContainsString((string) config('orca.push.private_key'), $r->getContent());
    }

    public function test_daftar_status_dan_cabut_langganan(): void
    {
        $this->as(self::CEO);
        $base = PushSubscription::where('id_user', self::CEO)->count(); // bisa ada langganan nyata hasil uji manual
        $this->postJson('/api/v1/push/subscribe', self::SUB)->assertOk();
        $this->postJson('/api/v1/push/subscribe', self::SUB)->assertOk(); // idempoten
        $this->assertSame($base + 1, PushSubscription::where('id_user', self::CEO)->count());

        $this->getJson('/api/v1/push/status?endpoint=' . urlencode(self::SUB['endpoint']))
            ->assertOk()->assertJson(['subscribed' => true]);

        $this->postJson('/api/v1/push/unsubscribe', ['endpoint' => self::SUB['endpoint']])->assertOk();
        $this->assertSame($base, PushSubscription::where('id_user', self::CEO)->count());
    }

    public function test_endpoint_non_https_ditolak(): void
    {
        $this->as(self::CEO);
        $this->postJson('/api/v1/push/subscribe', ['endpoint' => 'http://x.test/a'] + self::SUB)->assertStatus(422);
    }

    public function test_user_lain_tidak_bisa_mencabut_langganan_milik_orang(): void
    {
        $this->as(self::CEO);
        $this->postJson('/api/v1/push/subscribe', self::SUB)->assertOk();
        $n = PushSubscription::where('id_user', self::CEO)->count();

        $this->as(self::OM);
        $this->postJson('/api/v1/push/unsubscribe', ['endpoint' => self::SUB['endpoint']])->assertOk();
        $this->assertSame($n, PushSubscription::where('id_user', self::CEO)->count());
    }

    public function test_meneruskan_ke_ceo_mengirim_push_ke_ceo(): void
    {
        $sent = null;
        $this->mock(PushService::class, function (MockInterface $m) use (&$sent) {
            $m->shouldReceive('send')->once()->andReturnUsing(function ($ids, $payload) use (&$sent) {
                $sent = [$ids, $payload];

                return 1;
            });
        });

        $this->as(self::OM);
        $this->postJson('/api/v1/penawaran/' . self::PNW_OM_T3 . '/keputusan', ['keputusan' => 'approve', 'teruskan' => true])
            ->assertOk()->assertJson(['next_stage' => 6]);

        $this->assertContains(self::CEO, $sent[0]);
        $this->assertSame('/approval/' . self::PNW_OM_T3, $sent[1]['url']);
        $this->assertStringNotContainsString('Rp', $sent[1]['body']); // tanpa data harga
    }

    public function test_push_gagal_tidak_membatalkan_approval(): void
    {
        $this->mock(PushService::class, fn (MockInterface $m) => $m->shouldReceive('send')->andThrow(new \RuntimeException('boom')));

        $this->as(self::OM);
        $this->postJson('/api/v1/penawaran/' . self::PNW_OM_T3 . '/keputusan', ['keputusan' => 'approve', 'teruskan' => true])
            ->assertOk();
    }

    public function test_push_service_asli_tidak_error_saat_membangun_pengirim(): void
    {
        // Memakai PushService sungguhan (tanpa mock) dengan endpoint tak terjangkau:
        // harus selesai tanpa melempar galat konstruktor, dan mengembalikan 0 (tidak terkirim).
        \App\Models\PushSubscription::create([
            'id_user' => 999999, 'endpoint_hash' => hash('sha256', 'x'), 'endpoint' => 'https://127.0.0.1:9/none',
            'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
            'auth' => 'tBHItJI5svbpez7KI4CCXg',
        ]);
        $this->assertSame(0, app(PushService::class)->send([999999], ['title' => 't', 'body' => 'b']));
    }

    public function test_bahasa_langganan_tersimpan_dan_menolak_nilai_lain(): void
    {
        $this->as(self::CEO);
        $this->postJson('/api/v1/push/subscribe', self::SUB + ['lang' => 'en'])->assertOk();
        $this->assertSame('en', PushSubscription::where('endpoint_hash', hash('sha256', self::SUB['endpoint']))->value('lang'));

        $this->postJson('/api/v1/push/subscribe', self::SUB + ['lang' => 'fr'])->assertStatus(422);
        $this->postJson('/api/v1/push/subscribe', self::SUB)->assertOk(); // tanpa lang = id
        $this->assertSame('id', PushSubscription::where('endpoint_hash', hash('sha256', self::SUB['endpoint']))->value('lang'));
    }
}
