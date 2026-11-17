<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PermohonanLcrTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('pro_customer_update') || ! Schema::hasTable('pro_customer_lcr')) {
            $this->markTestSkipped('Tabel permohonan/LCR tidak ada.');
        }
    }

    private function bm(int $wil = 2): AclUser
    {
        $bm = AclUser::where('id_role', 7)->where('is_active', 1)->firstOrFail();
        DB::table('acl_user')->where('id_user', $bm->id_user)->update(['id_wilayah' => $wil]);
        Sanctum::actingAs($u = AclUser::find($bm->id_user), ['*']);

        return $u;
    }

    private function permohonan(int $wil = 2): int
    {
        $c = DB::table('pro_customer')->where('id_wilayah', $wil)->first();

        return DB::table('pro_customer_update')->insertGetId([
            'id_customer' => $c->id_customer, 'judul' => 'Uji permohonan', 'pesan' => 'pesan', 'kategori' => 1, 'flag_disposisi' => 2, 'flag_approval' => 0, 'om_result' => 0, 'created_time' => now(),
        ]);
    }

    public function test_peran_lain_ditolak(): void
    {
        Sanctum::actingAs(AclUser::where('id_role', 5)->where('is_active', 1)->firstOrFail(), ['*']);
        $this->getJson('/api/v1/permohonan')->assertForbidden();
        $this->getJson('/api/v1/lcr')->assertForbidden();
    }

    public function test_permohonan_setuju_dan_tolak(): void
    {
        $id = $this->permohonan();
        $this->bm();
        $this->getJson('/api/v1/permohonan?filter=menunggu&per_page=50')->assertOk()->assertJsonFragment(['id' => $id, 'menunggu' => true]);
        $this->getJson('/api/v1/badges')->assertJsonPath('permohonan', fn ($n) => $n >= 1);
        $this->getJson("/api/v1/permohonan/$id")->assertOk()->assertJsonPath('data.bisa_putus', true);
        $this->postJson("/api/v1/permohonan/$id/keputusan", ['result' => 1, 'summary' => "ok\nlanjut"])->assertOk();
        $x = DB::table('pro_customer_update')->where('id_cu', $id)->first();
        $this->assertSame([1, 1, 2], [(int) $x->om_result, (int) $x->flag_approval, (int) $x->flag_disposisi]);
        $this->assertSame('ok<br />lanjut', $x->om_summary);
        $this->postJson("/api/v1/permohonan/$id/keputusan", ['result' => 2, 'summary' => 'x'])->assertStatus(409);

        $id2 = $this->permohonan();
        $this->postJson("/api/v1/permohonan/$id2/keputusan", ['result' => 2, 'summary' => 'tidak'])->assertOk();
        $this->assertSame(2, (int) DB::table('pro_customer_update')->where('id_cu', $id2)->value('flag_approval'));
    }

    public function test_permohonan_cabang_lain_tidak_terlihat(): void
    {
        $id = $this->permohonan(2);
        $this->bm(3);
        $this->getJson("/api/v1/permohonan/$id")->assertNotFound();
        $this->postJson("/api/v1/permohonan/$id/keputusan", ['result' => 1, 'summary' => 'x'])->assertNotFound();
    }

    public function test_lcr_daftar_detail_dan_gambar(): void
    {
        $l = DB::table('pro_customer_lcr as a')->join('pro_customer as d', 'a.id_customer', '=', 'd.id_customer')->where('d.id_wilayah', 2)->first(['a.id_lcr']);
        DB::table('pro_customer_lcr')->where('id_lcr', $l->id_lcr)->update(['flag_disposisi' => 2, 'flag_approval' => 0, 'sm_result' => 0, 'layout_lokasi' => 'peta.png']);
        $this->bm();
        $this->getJson('/api/v1/lcr?filter=menunggu&per_page=50')->assertOk()->assertJsonFragment(['id' => (int) $l->id_lcr, 'menunggu' => true]);
        $this->getJson("/api/v1/lcr/{$l->id_lcr}")->assertOk()->assertJsonStructure(['data' => ['umum', 'lokasi', 'bongkar', 'gambar', 'logistik', 'bm']]);
        $dir = sys_get_temp_dir().'/legacy-'.uniqid();
        mkdir($dir.'/files/uploaded_user/files', 0777, true);
        file_put_contents($dir.'/files/uploaded_user/files/peta.png', 'PNG');
        config(['orca.web_files' => $dir]);
        $this->get("/api/v1/lcr/{$l->id_lcr}/gambar/peta")->assertOk();
        $this->get("/api/v1/lcr/{$l->id_lcr}/gambar/layout")->assertNotFound();
        $this->get("/api/v1/lcr/{$l->id_lcr}/gambar/jalan/0")->assertNotFound();
    }

    public function test_lcr_keputusan_bm(): void
    {
        $l = DB::table('pro_customer_lcr as a')->join('pro_customer as d', 'a.id_customer', '=', 'd.id_customer')->where('d.id_wilayah', 2)->first(['a.id_lcr']);
        DB::table('pro_customer_lcr')->where('id_lcr', $l->id_lcr)->update(['flag_disposisi' => 2, 'flag_approval' => 0, 'sm_result' => 0]);
        $this->bm();
        $this->postJson("/api/v1/lcr/{$l->id_lcr}/keputusan", ['result' => 1, 'summary' => "ok\nsiap"])->assertOk();
        $x = DB::table('pro_customer_lcr')->where('id_lcr', $l->id_lcr)->first();
        $this->assertSame([1, 1, 2], [(int) $x->sm_result, (int) $x->flag_approval, (int) $x->flag_disposisi]);
        $this->assertSame('ok<br />siap', $x->sm_summary);
        $this->postJson("/api/v1/lcr/{$l->id_lcr}/keputusan", ['result' => 2, 'summary' => 'x'])->assertStatus(409);
    }

    public function test_lcr_cabang_lain_tidak_terlihat(): void
    {
        $l = DB::table('pro_customer_lcr as a')->join('pro_customer as d', 'a.id_customer', '=', 'd.id_customer')->where('d.id_wilayah', 2)->first(['a.id_lcr']);
        $this->bm(3);
        $this->getJson("/api/v1/lcr/{$l->id_lcr}")->assertNotFound();
    }
}
