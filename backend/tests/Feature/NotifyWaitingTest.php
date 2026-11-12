<?php

namespace Tests\Feature;

use App\Models\AclUser;
use App\Models\PushSubscription;
use App\Services\PushService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotifyWaitingTest extends TestCase
{
    use DatabaseTransactions;

    private array $sent = [];

    private function fakePush(): void
    {
        $this->sent = [];
        $self = $this;
        $this->app->instance(PushService::class, new class($self) extends PushService {
            public function __construct(private $t) {}

            public function send(array $userIds, array $payload): int
            {
                $this->t->recordSent($userIds, $payload);

                return count($userIds);
            }
        });
    }

    public function recordSent(array $ids, array $payload): void
    {
        $this->sent[] = [$ids, $payload];
    }

    private function subscribe(AclUser $u): void
    {
        PushSubscription::create(['id_user' => $u->id_user, 'endpoint_hash' => sha1('t'.$u->id_user), 'endpoint' => 'https://example.test/'.$u->id_user, 'p256dh' => 'x', 'auth' => 'y']);
    }

    private function newTi(): void
    {
        $row = (array) DB::table('pro_trucking_instruction_req')->where('status', 7)->whereNotNull('id_transportir')->orderByDesc('id_master')->first();
        unset($row['id_master']);
        $row = ['status' => 0, 'ceo_result' => 0, 'is_cancel' => 0, 'is_received' => 0, 'nomor_ti' => 'UJI/NOTIF/'.uniqid()] + $row;
        DB::table('pro_trucking_instruction_req')->insert($row);
    }

    public function test_pertama_kali_hanya_merekam_lalu_kirim_saat_bertambah(): void
    {
        $ceo = AclUser::where('id_role', 21)->where('is_active', 1)->firstOrFail();
        DB::table('mobile_push_subscriptions')->delete();
        DB::table('mobile_notify_state')->where('id_user', $ceo->id_user)->delete();
        $this->subscribe($ceo);
        $this->fakePush();

        $this->artisan('orca:notify-waiting')->assertSuccessful();
        $this->assertCount(0, $this->sent); // rekaman awal, tidak membanjiri

        $this->artisan('orca:notify-waiting')->assertSuccessful();
        $this->assertCount(0, $this->sent); // tidak ada yang baru

        $this->newTi();
        $this->artisan('orca:notify-waiting')->assertSuccessful();
        $this->assertCount(1, $this->sent);
        [$ids, $p] = $this->sent[0];
        $this->assertSame([$ceo->id_user], $ids);
        $this->assertStringContainsString('1 TI baru', $p['body']);
        $this->assertSame('/trucking', $p['url']);
        $this->assertIsInt($p['badge']);
        $this->assertGreaterThanOrEqual(1, $p['badge']);

        $this->artisan('orca:notify-waiting')->assertSuccessful();
        $this->assertCount(1, $this->sent); // tidak diulang
    }

    public function test_peran_tanpa_modul_tidak_diberi_notifikasi_modul_itu(): void
    {
        $bm = AclUser::where('id_role', 7)->where('is_active', 1)->firstOrFail();
        DB::table('mobile_push_subscriptions')->delete();
        DB::table('mobile_notify_state')->where('id_user', $bm->id_user)->delete();
        $this->subscribe($bm);
        $this->fakePush();

        $this->artisan('orca:notify-waiting')->assertSuccessful();
        $this->newTi();
        $this->artisan('orca:notify-waiting')->assertSuccessful();
        $this->assertCount(0, $this->sent);
    }
}
