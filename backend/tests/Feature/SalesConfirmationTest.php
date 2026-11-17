<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesConfirmationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('pro_sales_confirmation_approval') || ! Schema::hasTable('pro_sales_confirmation_log')) {
            $this->markTestSkipped('Tabel approval/log Sales Confirmation tidak ada di database ini.');
        }
    }

    private function bm(int $wil = 2): AclUser
    {
        $bm = AclUser::where('id_role', 7)->where('is_active', 1)->firstOrFail();
        DB::table('acl_user')->where('id_user', $bm->id_user)->update(['id_wilayah' => $wil]);
        Sanctum::actingAs($u = AclUser::find($bm->id_user), ['*']);

        return $u;
    }

    /** SC cabang 2 yang menunggu BM, dari SC lama yang ada (dikembalikan oleh rollback). */
    private function sc(): object
    {
        $sc = DB::table('pro_sales_confirmation as n')->join('pro_po_customer as a', 'a.id_poc', '=', 'n.id_poc')->join('pro_customer as b', 'a.id_customer', '=', 'b.id_customer')
            ->join('pro_master_produk as p', 'a.produk_poc', '=', 'p.id_master')->join('pro_penawaran as f', 'f.id_penawaran', '=', 'a.id_penawaran')
            ->join('pro_master_kabupaten as c', 'b.kab_customer', '=', 'c.id_kab')->join('pro_master_provinsi as d', 'b.prov_customer', '=', 'd.id_prov')
            ->join('acl_user as e', 'b.id_marketing', '=', 'e.id_user')
            ->where('n.id_wilayah', 2)->first(['n.*']);
        DB::table('pro_sales_confirmation')->where('id', $sc->id)->update(['disposisi' => 2, 'flag_approval' => 0, 'role_approved' => null, 'tgl_approved' => null, 'add_cl' => '5000000']);
        DB::table('pro_sales_confirmation_approval')->where('id_sales', $sc->id)->delete();
        DB::table('pro_sales_confirmation_approval')->insert(['id_sales' => $sc->id, 'adm_result' => 1, 'adm_summary' => 'supply', 'adm_pic' => 'Finance', 'adm_result_date' => now(), 'bm_result' => 0]);

        return $sc;
    }

    public function test_peran_lain_ditolak(): void
    {
        Sanctum::actingAs(AclUser::where('id_role', 5)->where('is_active', 1)->firstOrFail(), ['*']);
        $this->getJson('/api/v1/sales-confirmation')->assertForbidden();
    }

    public function test_daftar_detail_dan_badge(): void
    {
        $sc = $this->sc();
        $this->bm();
        $this->getJson('/api/v1/sales-confirmation?filter=menunggu&per_page=50')->assertOk()->assertJsonFragment(['id' => (int) $sc->id, 'menunggu' => true]);
        $this->getJson("/api/v1/sales-confirmation/{$sc->id}")->assertOk()->assertJsonPath('data.bisa_putus', true)->assertJsonStructure(['data' => ['customer', 'ar', 'po', 'pembayaran', 'jadwal', 'finance', 'bm']]);
        $this->getJson('/api/v1/badges')->assertJsonPath('sc', fn ($n) => $n >= 1);
    }

    public function test_bm_setuju_menyetujui_po_dan_mencadangkan_plafon(): void
    {
        $sc = $this->sc();
        DB::table('pro_po_customer')->where('id_poc', $sc->id_poc)->update(['created_time' => '2026-01-10 08:00:00', 'poc_approved' => 0]);
        $before = (float) DB::table('pro_customer')->where('id_customer', $sc->id_customer)->value('credit_limit_reserved');
        $this->bm();
        $this->postJson("/api/v1/sales-confirmation/{$sc->id}/keputusan", ['result' => 1, 'summary' => "ok\nsiap"])->assertOk();
        $n = DB::table('pro_sales_confirmation')->where('id', $sc->id)->first();
        $this->assertSame([1, 7, 2], [(int) $n->flag_approval, (int) $n->role_approved, (int) $n->disposisi]);
        $this->assertSame(1, (int) DB::table('pro_sales_confirmation_approval')->where('id_sales', $sc->id)->value('bm_result'));
        $poc = DB::table('pro_po_customer')->where('id_poc', $sc->id_poc)->first();
        $this->assertSame([1, 1, 1], [(int) $poc->poc_approved, (int) $poc->sm_result, (int) $poc->po_notif]);
        $this->assertSame('ok<br />siap', $poc->sm_summary);
        $c = DB::table('pro_customer')->where('id_customer', $sc->id_customer)->first();
        $this->assertEqualsWithDelta($before + (float) $sc->po_amount, (float) $c->credit_limit_reserved, 0.01);
        $this->assertSame(5000000.0, (float) $c->credit_limit_temp);
        $this->assertSame(1, DB::table('pro_history_ar_customer')->where('id_poc', $sc->id_poc)->where('keterangan', 'Generate PO Customer')->where('created_time', '>=', now()->subMinute())->count());
        $this->assertSame(1, DB::table('pro_sales_confirmation_log')->where('id_sales', $sc->id)->count());
        $this->postJson("/api/v1/sales-confirmation/{$sc->id}/keputusan", ['result' => 1, 'summary' => 'x'])->assertStatus(409);
    }

    public function test_bm_tolak_mengembalikan_ke_finance(): void
    {
        $sc = $this->sc();
        $this->bm();
        $this->postJson("/api/v1/sales-confirmation/{$sc->id}/keputusan", ['result' => 2, 'summary' => 'tidak'])->assertOk();
        $n = DB::table('pro_sales_confirmation')->where('id', $sc->id)->first();
        $this->assertSame([2, 7, 1], [(int) $n->flag_approval, (int) $n->role_approved, (int) $n->disposisi]);
    }

    public function test_cabang_lain_tidak_terlihat(): void
    {
        $sc = $this->sc();
        $this->bm(3);
        $this->getJson("/api/v1/sales-confirmation/{$sc->id}")->assertNotFound();
    }
}
