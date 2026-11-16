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

    private function newTi(): string
    {
        $row = (array) DB::table('pro_trucking_instruction_req')->where('status', 7)->whereNotNull('id_transportir')->orderByDesc('id_master')->first();
        unset($row['id_master']);
        $nomor = 'UJI/NOTIF/'.uniqid();
        $row = ['status' => 0, 'ceo_result' => 0, 'is_cancel' => 0, 'is_received' => 0, 'nomor_ti' => $nomor] + $row;
        DB::table('pro_trucking_instruction_req')->insert($row);

        return $nomor;
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

        $nomor = $this->newTi();
        $this->artisan('orca:notify-waiting')->assertSuccessful();
        $this->assertCount(1, $this->sent);
        [$ids, $p] = $this->sent[0];
        $this->assertSame([$ceo->id_user], $ids);
        $this->assertStringContainsString("TI baru: {$nomor}", $p['body']); // menyebut kode dokumen yang baru
        $this->assertStringContainsString('Total menunggu:', $p['body']);
        $this->assertStringContainsString("New TI: {$nomor}", $p['en']['body']);
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

    public function test_satu_diputuskan_dan_satu_baru_di_rentang_yang_sama_tetap_diberitahu(): void
    {
        $ceo = AclUser::where('id_role', 21)->where('is_active', 1)->firstOrFail();
        DB::table('mobile_push_subscriptions')->delete();
        DB::table('mobile_notify_state')->where('id_user', $ceo->id_user)->delete();
        $this->subscribe($ceo);
        $this->fakePush();

        $lama = $this->newTi();
        $this->artisan('orca:notify-waiting')->assertSuccessful(); // rekaman awal
        $this->assertCount(0, $this->sent);

        // TI lama diputuskan CEO dan TI baru masuk: jumlahnya tetap, tetapi item baru harus tetap memicu notifikasi.
        DB::table('pro_trucking_instruction_req')->where('nomor_ti', $lama)->update(['status' => 1]);
        $baru = $this->newTi();
        $this->artisan('orca:notify-waiting')->assertSuccessful();

        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString($baru, $this->sent[0][1]['body']);
        $this->assertStringNotContainsString($lama, $this->sent[0][1]['body']);
    }

    public function test_banyak_item_baru_diringkas_dengan_sisa_jumlahnya(): void
    {
        $ceo = AclUser::where('id_role', 21)->where('is_active', 1)->firstOrFail();
        DB::table('mobile_push_subscriptions')->delete();
        DB::table('mobile_notify_state')->where('id_user', $ceo->id_user)->delete();
        $this->subscribe($ceo);
        $this->fakePush();

        $this->artisan('orca:notify-waiting')->assertSuccessful();
        foreach (range(1, 4) as $i) {
            $this->newTi();
        }
        $this->artisan('orca:notify-waiting')->assertSuccessful();

        $body = $this->sent[0][1]['body'];
        $this->assertStringContainsString('4 TI baru:', $body);
        $this->assertStringContainsString('+2 lainnya', $body); // dua kode ditampilkan, sisanya diringkas
    }
}
