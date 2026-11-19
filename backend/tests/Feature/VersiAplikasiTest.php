<?php

namespace Tests\Feature;

use App\Models\AclUser;
use App\Models\PushSubscription;
use App\Services\PushService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VersiAplikasiTest extends TestCase
{
    use DatabaseTransactions;

    private array $sent = [];
    private $resp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sent = [];
        $self = $this;
        $this->app->instance(PushService::class, new class($self) extends PushService {
            public function __construct(private $t) {}

            public function send(array $userIds, array $payload): int
            {
                $this->t->rekam($userIds, $payload);

                return count($userIds);
            }
        });
        DB::table('mobile_settings')->where('nama', 'app_versi')->delete();
        DB::table('mobile_push_subscriptions')->delete();
        config(['orca.version_url' => 'https://app.test/version.json']);
        Http::fake(fn () => ($this->resp)());
    }

    public function rekam(array $ids, array $payload): void
    {
        $this->sent[] = [$ids, $payload];
    }

    private function sub(AclUser $u): void
    {
        PushSubscription::create(['id_user' => $u->id_user, 'endpoint_hash' => sha1('v'.$u->id_user), 'endpoint' => 'https://example.test/'.$u->id_user, 'p256dh' => 'x', 'auth' => 'y']);
    }

    private function versi(string $v): void
    {
        $this->resp = fn () => Http::response(['version' => $v, 'built_at' => '2026-11-19T10:00:00Z']);
    }

    private function jalankan(): void
    {
        $this->artisan('orca:cek-versi')->assertSuccessful();
    }

    public function test_versi_dicatat_dari_header_dan_tidak_menulis_ulang_terus(): void
    {
        Cache::flush();
        $u = AclUser::where('id_role', 21)->where('is_active', 1)->firstOrFail();
        Sanctum::actingAs($u, ['*']);
        $this->getJson('/api/v1/badges', ['X-App-Version' => 'abc123'])->assertOk();
        $this->assertSame('abc123', DB::table('mobile_client_versions')->where('id_user', $u->id_user)->value('versi'));
        DB::table('mobile_client_versions')->where('id_user', $u->id_user)->update(['versi' => 'ditimpa-manual']);
        $this->getJson('/api/v1/badges', ['X-App-Version' => 'abc123'])->assertOk(); // masih dalam 30 menit: tidak ditulis ulang
        $this->assertSame('ditimpa-manual', DB::table('mobile_client_versions')->where('id_user', $u->id_user)->value('versi'));
        $this->getJson('/api/v1/badges', ['X-App-Version' => 'xyz9'])->assertOk(); // versi berbeda langsung dicatat
        $this->assertSame('xyz9', DB::table('mobile_client_versions')->where('id_user', $u->id_user)->value('versi'));
        // header tidak valid diabaikan
        $this->getJson('/api/v1/badges', ['X-App-Version' => 'bad value!'])->assertOk();
        $this->assertSame('xyz9', DB::table('mobile_client_versions')->where('id_user', $u->id_user)->value('versi'));
    }

    public function test_pemeriksaan_pertama_hanya_mencatat_acuan(): void
    {
        $this->sub(AclUser::where('id_role', 21)->where('is_active', 1)->firstOrFail());
        $this->versi('v1');
        $this->jalankan();
        $this->assertSame('v1', DB::table('mobile_settings')->where('nama', 'app_versi')->value('nilai'));
        $this->assertSame([], $this->sent);
        $this->jalankan(); // tidak berubah: tidak ada apa-apa
        $this->assertSame([], $this->sent);
    }

    public function test_versi_baru_diumumkan_sekali_hanya_ke_yang_masih_versi_lama(): void
    {
        $lama = AclUser::where('id_role', 21)->where('is_active', 1)->firstOrFail();
        $baru = AclUser::where('id_role', 7)->where('is_active', 1)->firstOrFail();
        $takTahu = AclUser::where('id_role', 6)->where('is_active', 1)->firstOrFail();
        foreach ([$lama, $baru, $takTahu] as $u) {
            $this->sub($u);
        }
        DB::table('mobile_client_versions')->upsert([['id_user' => $lama->id_user, 'versi' => 'v1', 'dilihat_pada' => now()], ['id_user' => $baru->id_user, 'versi' => 'v2', 'dilihat_pada' => now()]], ['id_user']);

        $this->versi('v1');
        $this->jalankan(); // acuan
        $this->versi('v2');
        $this->jalankan();
        $this->assertCount(1, $this->sent);
        [$ids, $p] = $this->sent[0];
        $this->assertEqualsCanonicalizing([$lama->id_user, $takTahu->id_user], $ids); // yang sudah v2 tidak diganggu; yang belum pernah melapor dianggap lama
        $this->assertSame('Versi baru tersedia', $p['title']);
        $this->assertSame('/profil', $p['url']);
        $this->assertSame('app-update', $p['tag']);
        $this->assertSame('v2', DB::table('mobile_settings')->where('nama', 'app_versi')->value('nilai'));

        $this->jalankan(); // versi sama: tidak mengirim lagi
        $this->assertCount(1, $this->sent);
        $this->versi('v3');
        $this->jalankan();
        $this->assertCount(2, $this->sent);
    }

    public function test_gagal_membaca_version_json_tidak_mengirim_apa_pun(): void
    {
        $this->sub(AclUser::where('id_role', 21)->where('is_active', 1)->firstOrFail());
        DB::table('mobile_settings')->insert(['nama' => 'app_versi', 'nilai' => 'v1', 'diubah_pada' => now()]);
        $this->resp = fn () => Http::response('Not Found', 404);
        $this->jalankan();
        $this->resp = fn () => Http::response('<html>bukan json</html>', 200);
        $this->jalankan();
        $this->resp = fn () => Http::response(['version' => ''], 200);
        $this->jalankan();
        $this->assertSame([], $this->sent);
        $this->assertSame('v1', DB::table('mobile_settings')->where('nama', 'app_versi')->value('nilai'));
    }
}
