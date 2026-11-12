<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShippingTest extends TestCase
{
    use DatabaseTransactions;

    private function as(int $role, array $abilities = ['*']): AclUser
    {
        $u = AclUser::where('id_role', $role)->where('is_active', 1)->firstOrFail();
        Sanctum::actingAs($u, $abilities);

        return $u;
    }

    /** Salin SI selesai menjadi SI yang menunggu CEO (dibatalkan otomatis oleh rollback). */
    private function pending(): int
    {
        $row = (array) DB::table('new_pro_inventory_vendor_po_ship_req')->orderBy('id_master')->first();
        unset($row['id_master']);
        $row['status'] = 2;
        $row['cfo_result'] = 1;
        $row['ceo_result'] = 0;
        $row['ceo_summary'] = null;
        $row['ceo_pic'] = null;

        return DB::table('new_pro_inventory_vendor_po_ship_req')->insertGetId($row);
    }

    public function test_hanya_ceo(): void
    {
        $this->as(7);
        $this->getJson('/api/v1/shipping')->assertStatus(403);
        $this->as(21);
        $this->getJson('/api/v1/shipping?tab=all')->assertOk()->assertJsonStructure(['data', 'waiting', 'meta']);
    }

    public function test_daftar_menunggu_dan_detail(): void
    {
        $this->as(21);
        $id = $this->pending();
        $r = $this->getJson('/api/v1/shipping')->assertOk();
        $this->assertContains($id, array_column($r->json('data'), 'id'));
        $d = $this->getJson("/api/v1/shipping/$id")->assertOk();
        $this->assertTrue($d->json('can_decide'));
        $this->assertNotEmpty($d->json('nama_vendor'));
    }

    public function test_setujui_lalu_tidak_bisa_diputuskan_lagi(): void
    {
        $u = $this->as(21);
        $id = $this->pending();
        $this->postJson("/api/v1/shipping/$id/keputusan", ['keputusan' => 'approve'])->assertOk();
        $row = DB::table('new_pro_inventory_vendor_po_ship_req')->where('id_master', $id)->first();
        $this->assertSame(1, (int) $row->ceo_result);
        $this->assertSame(3, (int) $row->status);
        $this->assertSame($u->fullname, $row->ceo_pic);
        $this->postJson("/api/v1/shipping/$id/keputusan", ['keputusan' => 'approve'])->assertStatus(409);
    }

    public function test_tolak_wajib_alasan(): void
    {
        $this->as(21);
        $id = $this->pending();
        $this->postJson("/api/v1/shipping/$id/keputusan", ['keputusan' => 'reject'])->assertStatus(422);
        $this->postJson("/api/v1/shipping/$id/keputusan", ['keputusan' => 'reject', 'catatan' => 'Data kapal belum lengkap'])->assertOk();
        $this->assertSame(2, (int) DB::table('new_pro_inventory_vendor_po_ship_req')->where('id_master', $id)->value('ceo_result'));
    }

    public function test_sesi_wajah_butuh_password(): void
    {
        $this->as(21, ['face']);
        $id = $this->pending();
        $this->postJson("/api/v1/shipping/$id/keputusan", ['keputusan' => 'approve'])->assertStatus(403)->assertJsonPath('code', 'STEP_UP_REQUIRED');
    }

    public function test_ditolak_cfo_tidak_bisa_diputuskan_ceo(): void
    {
        $this->as(21);
        $id = $this->pending();
        DB::table('new_pro_inventory_vendor_po_ship_req')->where('id_master', $id)->update(['cfo_result' => 0]);
        $this->postJson("/api/v1/shipping/$id/keputusan", ['keputusan' => 'approve'])->assertStatus(409);
    }

    public function test_catatan_disimpan_escaped_dan_detail_lengkap(): void
    {
        $this->as(21);
        $id = $this->pending();
        $d = $this->getJson("/api/v1/shipping/$id")->assertOk();
        $this->assertArrayHasKey('shipping_line', $d->json());
        $this->assertArrayHasKey('asal_angkut', $d->json());
        $this->assertArrayHasKey('tujuan_angkut', $d->json());
        $this->postJson("/api/v1/shipping/$id/keputusan", ['keputusan' => 'approve', 'catatan' => 'OK <i>"siap"</i>'])->assertOk();
        $this->assertStringNotContainsString('<i>', DB::table('new_pro_inventory_vendor_po_ship_req')->where('id_master', $id)->value('ceo_summary'));
        $this->getJson("/api/v1/shipping/$id")->assertJsonPath('ceo_summary', 'OK <i>"siap"</i>');
    }
}
