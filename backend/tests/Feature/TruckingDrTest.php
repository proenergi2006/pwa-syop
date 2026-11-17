<?php

namespace Tests\Feature;

use App\Models\AclUser;
use App\Models\PushSubscription;
use App\Services\PushService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TruckingDrTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['orca.trucking_dr_produk' => (int) DB::table('pro_master_produk')->orderBy('id_master')->value('id_master')]); // salinan lokal tidak punya produk 15
        if (! Schema::hasTable('pro_pr_trucking') || ! Schema::hasColumn('new_pro_inventory_depot', 'id_pr_ti')) {
            $this->markTestSkipped('Tabel Trucking DR tidak ada di database ini.');
        }
    }

    private function as(int $role): AclUser
    {
        $u = AclUser::where('id_role', $role)->where('is_active', 1)->firstOrFail();
        Sanctum::actingAs($u, ['*']);

        return $u;
    }

    /** DR menunggu Purchasing dengan dua baris TI (dibatalkan otomatis oleh rollback). */
    private function pending(int $lines = 2): int
    {
        $plans = DB::table('pro_trucking_plan')->orderBy('id_plan')->limit($lines)->pluck('id_plan');
        $id = DB::table('pro_pr_trucking')->insertGetId(['nomor_pr_ti' => 'UJI/DR/TI/'.uniqid(), 'tanggal_pr_ti' => now()->toDateString(), 'id_wilayah' => 3, 'disposisi_dr' => 0, 'purchasing_result' => 0]);
        foreach ($plans as $i => $p) {
            // id besar supaya tidak bentrok dengan baris pro_po_ds_trucking_detail (loading/delivered) milik data salinan
            DB::table('pro_pr_trucking_detail')->insert(['id_prd_ti' => 900000 + $id * 10 + $i, 'id_pr_ti' => $id, 'id_plan' => $p, 'volume' => 24000, 'vol_ori' => 24000, 'vol_ori_pr' => 24000]);
        }

        return $id;
    }

    private function pos(int $id): array
    {
        return $this->getJson("/api/v1/trucking-dr/$id/po-supplier")->assertOk()->json('data');
    }

    private function payload(int $id, array $po): array
    {
        $lines = [];
        foreach ($this->getJson("/api/v1/trucking-dr/$id")->assertOk()->json('lines') as $l) {
            $lines[] = ['id_prd' => $l['id_prd'], 'volume' => $l['vol_ori'], 'po' => ['id_po_supplier' => $po['id_po_supplier'], 'id_po_receive' => $po['id_po_receive'], 'id_terminal' => $po['id_terminal']]];
        }

        return ['catatan' => 'OK', 'lines' => $lines];
    }

    private function bigPo(int $id, int $need = 48000): array
    {
        foreach ($this->pos($id) as $p) {
            if ($p['sisa'] >= $need) {
                return $p;
            }
        }
        $this->markTestSkipped('Tidak ada PO Supplier dengan stok cukup.');
    }

    public function test_hanya_procurement_yang_boleh(): void
    {
        $this->as(21);
        $this->getJson('/api/v1/trucking-dr')->assertForbidden();
        $this->as(5);
        $this->getJson('/api/v1/trucking-dr')->assertOk()->assertJsonStructure(['data', 'waiting', 'cabang', 'meta']);
        $this->assertContains('trucking_dr', $this->getJson('/api/v1/me')->json('user.modules') ?? $this->getJson('/api/v1/me')->json('modules') ?? ['trucking_dr']);
    }

    public function test_daftar_dan_detail(): void
    {
        $this->as(5);
        $id = $this->pending();
        $list = $this->getJson('/api/v1/trucking-dr?q=UJI/DR')->assertOk()->json('data');
        $this->assertSame($id, $list[0]['id']);
        $this->assertCount(2, $list[0]['nomor_ti']);
        $d = $this->getJson("/api/v1/trucking-dr/$id")->assertOk()->assertJsonPath('can_save', true)->assertJsonPath('status', 'Pending Procurement');
        $this->assertCount(2, $d->json('lines'));
        $this->assertNotEmpty($d->json('lines.0.loading_port'));
    }

    public function test_simpan_verifikasi_lalu_revisi(): void
    {
        $this->as(5);
        $id = $this->pending();
        $po = $this->bigPo($id);
        $this->postJson("/api/v1/trucking-dr/$id/verifikasi", $this->payload($id, $po))->assertOk();

        $h = DB::table('pro_pr_trucking')->where('id_pr_ti', $id)->first();
        $this->assertSame(1, (int) $h->disposisi_dr);
        $this->assertSame(1, (int) $h->purchasing_result);
        $det = DB::table('pro_pr_trucking_detail')->where('id_pr_ti', $id)->get();
        foreach ($det as $x) {
            $this->assertStringStartsWith('LO/TI/', $x->nomor_lo_pr_ti);
            $this->assertSame(24000, (int) $x->vol_potongan);
            $this->assertSame($po['nomor_po'], $x->nomor_po_supplier);
        }
        $this->assertSame(2, DB::table('new_pro_inventory_depot')->where('id_pr_ti', $id)->where('id_jenis', 4)->where('adj_inven', -24000)->count());
        $this->postJson("/api/v1/trucking-dr/$id/verifikasi", $this->payload($id, $po))->assertStatus(409);

        $this->postJson("/api/v1/trucking-dr/$id/revisi")->assertOk();
        $this->assertSame(0, (int) DB::table('pro_pr_trucking')->where('id_pr_ti', $id)->value('disposisi_dr'));
        $this->assertSame(0, DB::table('new_pro_inventory_depot')->where('id_pr_ti', $id)->count());
        $this->assertNull(DB::table('pro_pr_trucking_detail')->where('id_pr_ti', $id)->value('nomor_lo_pr_ti'));
    }

    public function test_split_dan_validasi_volume(): void
    {
        $this->as(5);
        $id = $this->pending(1);
        $po = $this->bigPo($id, 24000);
        $ref = ['id_po_supplier' => $po['id_po_supplier'], 'id_po_receive' => $po['id_po_receive'], 'id_terminal' => $po['id_terminal']];
        $line = $this->getJson("/api/v1/trucking-dr/$id")->json('lines.0');

        $this->postJson("/api/v1/trucking-dr/$id/verifikasi", ['lines' => [['id_prd' => $line['id_prd'], 'volume' => 10000, 'po' => $ref, 'split' => ['volume' => 5000, 'po' => $ref]]]])
            ->assertStatus(422);
        $this->postJson("/api/v1/trucking-dr/$id/verifikasi", ['lines' => [['id_prd' => $line['id_prd'], 'volume' => 10000, 'po' => $ref, 'split' => ['volume' => 14000, 'po' => $ref]]]])->assertOk();
        $this->assertSame(14000, (int) DB::table('new_pro_inventory_potongan_stock')->where('id_pr_ti', $id)->value('volume'));
        $this->assertSame(1, (int) DB::table('pro_pr_trucking_detail')->where('id_pr_ti', $id)->value('is_split'));
        $this->assertSame(2, DB::table('new_pro_inventory_depot')->where('id_pr_ti', $id)->count());
    }

    public function test_stok_tidak_cukup_ditolak(): void
    {
        $this->as(5);
        $id = $this->pending(1);
        $po = $this->pos($id)[0];
        $line = $this->getJson("/api/v1/trucking-dr/$id")->json('lines.0');
        DB::table('pro_pr_trucking_detail')->where('id_prd_ti', $line['id_prd'])->update(['volume' => 999999999, 'vol_ori' => 999999999, 'vol_ori_pr' => 999999999]);
        $this->postJson("/api/v1/trucking-dr/$id/verifikasi", ['lines' => [['id_prd' => $line['id_prd'], 'volume' => 999999999, 'po' => ['id_po_supplier' => $po['id_po_supplier'], 'id_po_receive' => $po['id_po_receive'], 'id_terminal' => $po['id_terminal']]]]])
            ->assertStatus(422)->assertJsonPath('code', 'STOCK');
        $this->assertSame(0, (int) DB::table('pro_pr_trucking')->where('id_pr_ti', $id)->value('purchasing_result'));
    }

    public function test_kembalikan_ke_dp(): void
    {
        $this->as(5);
        $id = $this->pending(1);
        $plan = DB::table('pro_pr_trucking_detail')->where('id_pr_ti', $id)->value('id_plan');
        $ti = DB::table('pro_trucking_plan')->where('id_plan', $plan)->value('id_trucking');
        DB::table('pro_trucking_instruction_req')->where('id_master', $ti)->update(['status' => 7]);
        $this->postJson("/api/v1/trucking-dr/$id/kembali-dp")->assertOk();
        $this->assertSame(0, DB::table('pro_pr_trucking')->where('id_pr_ti', $id)->count());
        $this->assertSame(0, DB::table('pro_pr_trucking_detail')->where('id_pr_ti', $id)->count());
        $this->assertSame(0, DB::table('pro_trucking_plan')->where('id_plan', $plan)->count());
        $this->assertSame(1, (int) DB::table('pro_trucking_instruction_req')->where('id_master', $ti)->value('status'));
        $this->getJson("/api/v1/trucking-dr/$id")->assertNotFound();
    }

    private array $sent = [];

    public function recordSent(array $ids, array $payload): void
    {
        $this->sent[] = [$ids, $payload];
    }

    private function listenPush(AclUser $u): void
    {
        DB::table('mobile_push_subscriptions')->delete();
        DB::table('mobile_notify_state')->where('id_user', $u->id_user)->delete();
        PushSubscription::create(['id_user' => $u->id_user, 'endpoint_hash' => sha1('t'.$u->id_user), 'endpoint' => 'https://example.test/'.$u->id_user, 'p256dh' => 'x', 'auth' => 'y']);
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

    public function test_badge_dan_notifikasi_untuk_dr_trucking_baru(): void
    {
        $u = $this->as(5);
        $this->listenPush($u);
        $before = $this->getJson('/api/v1/badges')->assertOk()->json('trucking_dr');

        $this->artisan('orca:notify-waiting')->assertSuccessful();
        $this->assertCount(0, $this->sent); // rekaman awal

        $id = $this->pending();
        $nomor = DB::table('pro_pr_trucking')->where('id_pr_ti', $id)->value('nomor_pr_ti');
        $this->assertSame($before + 1, $this->getJson('/api/v1/badges')->json('trucking_dr'));
        $this->artisan('orca:notify-waiting')->assertSuccessful();
        $this->assertCount(1, $this->sent);
        $p = $this->sent[0][1];
        $this->assertStringContainsString("Trucking DR baru: {$nomor}", $p['body']);
        $this->assertSame('/trucking-dr', $p['url']);
        $this->assertGreaterThanOrEqual(1, $p['badge']);

        $this->artisan('orca:notify-waiting')->assertSuccessful();
        $this->assertCount(1, $this->sent); // tidak diulang

        // setelah diverifikasi, tidak lagi dihitung menunggu
        DB::table('pro_pr_trucking')->where('id_pr_ti', $id)->update(['purchasing_result' => 1, 'disposisi_dr' => 1]);
        $this->assertSame($before, $this->getJson('/api/v1/badges')->json('trucking_dr'));
    }

    public function test_modul_baru_di_catatan_lama_hanya_baseline_tidak_membanjiri(): void
    {
        $u = $this->as(5);
        $this->listenPush($u);
        $this->pending();
        // catatan lama dari sebelum modul Trucking DR ada: tanpa kunci trucking_dr
        DB::table('mobile_notify_state')->insert(['id_user' => $u->id_user, 'counts' => '{}', 'items' => json_encode(array_diff_key(array_map(fn ($x) => array_map('intval', array_keys($x)), \App\Services\WaitingCounts::items($u)), ['trucking_dr' => 1])), 'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('orca:notify-waiting')->assertSuccessful();
        $this->assertCount(0, $this->sent);
        $this->assertArrayHasKey('trucking_dr', json_decode(DB::table('mobile_notify_state')->where('id_user', $u->id_user)->value('items'), true));
    }
}
