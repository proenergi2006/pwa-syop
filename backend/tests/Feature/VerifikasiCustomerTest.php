<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VerifikasiCustomerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('pro_customer_verification')) {
            $this->markTestSkipped('Tabel verifikasi customer tidak ada.');
        }
    }

    /** BM wilayah dengan satu verifikasi yang diset menunggu BM (dibatalkan oleh rollback). */
    private function pendingBm(): array
    {
        $v = DB::table('pro_customer_verification as e')->join('pro_customer as a', 'a.id_customer', '=', 'e.id_customer')
            ->join('pro_customer_review as g', 'g.id_verification', '=', 'e.id_verification')
            ->where('e.is_evaluated', 1)->where('e.is_reviewed', 1)->where('a.id_wilayah', 2)->first(['e.id_verification', 'e.id_customer']);
        DB::table('pro_customer_verification')->where('id_verification', $v->id_verification)->update(['disposisi_result' => 2, 'sm_result' => 0, 'om_result' => 0, 'is_approved' => 0, 'role_approve' => 0]);
        $bm = AclUser::where('id_role', 7)->where('id_wilayah', 2)->where('is_active', 1)->first() ?? AclUser::where('id_role', 7)->where('is_active', 1)->firstOrFail();
        DB::table('acl_user')->where('id_user', $bm->id_user)->update(['id_wilayah' => 2]);
        Sanctum::actingAs(AclUser::find($bm->id_user), ['*']);

        return [(int) $v->id_verification, (int) $v->id_customer];
    }

    public function test_peran_lain_ditolak(): void
    {
        Sanctum::actingAs(AclUser::where('id_role', 5)->where('is_active', 1)->firstOrFail(), ['*']);
        $this->getJson('/api/v1/verifikasi-customer')->assertForbidden();
    }

    public function test_daftar_detail_dan_badge(): void
    {
        [$id] = $this->pendingBm();
        $this->getJson('/api/v1/verifikasi-customer?filter=menunggu&per_page=50')->assertOk()->assertJsonFragment(['id' => $id, 'menunggu' => true]);
        $this->getJson("/api/v1/verifikasi-customer/$id")->assertOk()->assertJsonPath('data.bisa_putus', true)->assertJsonStructure(['data' => ['evaluasi', 'customer', 'review']]);
        $this->getJson('/api/v1/badges')->assertOk()->assertJsonPath('verifikasi_customer', fn ($n) => $n >= 1);
    }

    public function test_bm_setuju_final_dan_membuat_customer_di_accurate(): void
    {
        config(['accurate.enabled' => true, 'accurate.token' => 't', 'accurate.secret' => 's']);
        Http::fake(['*' => Http::response(['s' => true, 'r' => ['id' => 777001]])]);
        [$id, $idc] = $this->pendingBm();
        $this->postJson("/api/v1/verifikasi-customer/$id/keputusan", ['result' => 1, 'summary' => "ok\nsiap"])->assertOk();
        $v = DB::table('pro_customer_verification')->where('id_verification', $id)->first();
        $this->assertSame([3, 1, 1, 7], [(int) $v->disposisi_result, (int) $v->is_approved, (int) $v->sm_result, (int) $v->role_approve]);
        $this->assertSame('ok<br />siap', $v->sm_summary);
        $c = DB::table('pro_customer')->where('id_customer', $idc)->first();
        $this->assertSame(1, (int) $c->is_verified);
        $this->assertSame(777001, (int) $c->id_accurate);
        $this->assertStringStartsWith('TEMP', $c->kode_pelanggan);
        $this->postJson("/api/v1/verifikasi-customer/$id/keputusan", ['result' => 1, 'summary' => 'lagi'])->assertStatus(409);
    }

    public function test_accurate_gagal_membatalkan_keputusan(): void
    {
        config(['accurate.enabled' => true, 'accurate.token' => 't', 'accurate.secret' => 's']);
        Http::fake(['*' => Http::response(['s' => false, 'd' => ['Nama sudah ada']])]);
        [$id] = $this->pendingBm();
        $this->postJson("/api/v1/verifikasi-customer/$id/keputusan", ['result' => 1, 'summary' => 'ok'])->assertStatus(502)->assertJsonPath('message', 'Nama sudah ada - Response dari Accurate');
        $this->assertSame(0, (int) DB::table('pro_customer_verification')->where('id_verification', $id)->value('is_approved'));
    }

    public function test_bm_tolak(): void
    {
        [$id, $idc] = $this->pendingBm();
        $this->postJson("/api/v1/verifikasi-customer/$id/keputusan", ['result' => 2, 'summary' => 'kurang lengkap'])->assertOk();
        $v = DB::table('pro_customer_verification')->where('id_verification', $id)->first();
        $this->assertSame([0, 2, 7], [(int) $v->disposisi_result, (int) $v->is_approved, (int) $v->role_approve]);
    }

    public function test_bm_wilayah_lain_tidak_bisa_membuka(): void
    {
        [$id] = $this->pendingBm();
        $bm = AclUser::where('id_role', 7)->where('id_wilayah', '!=', 2)->where('is_active', 1)->firstOrFail();
        Sanctum::actingAs($bm, ['*']);
        $this->getJson("/api/v1/verifikasi-customer/$id")->assertNotFound();
    }
}
