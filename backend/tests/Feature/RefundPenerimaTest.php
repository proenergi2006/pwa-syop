<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RefundPenerimaTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('pro_master_penerima_refund')) {
            $this->markTestSkipped('Tabel penerima refund tidak ada.');
        }
    }

    private function baru(): int
    {
        $c = DB::table('pro_customer')->where('id_wilayah', 2)->first();
        $mkt = AclUser::where('id_role', 11)->where('is_active', 1)->first();

        return DB::table('pro_master_penerima_refund')->insertGetId([
            'id_customer' => $c->id_customer, 'nama' => 'budi uji', 'divisi' => 'finance', 'bank' => 'BCA', 'kode_bank' => '014', 'no_rekening' => '123', 'atas_nama' => 'Budi',
            'no_ktp' => '999', 'foto_ktp' => 'foto_ktp_1_a.pdf', 'is_active' => 1, 'is_bm' => 0, 'is_ceo' => 0, 'created_by' => $mkt?->fullname ?? 'uji',
        ]);
    }

    private function bm(): AclUser
    {
        $bm = AclUser::where('id_role', 7)->where('is_active', 1)->firstOrFail();
        DB::table('acl_user')->where('id_user', $bm->id_user)->update(['id_wilayah' => 2]);
        Sanctum::actingAs($u = AclUser::find($bm->id_user), ['*']);

        return $u;
    }

    public function test_peran_lain_ditolak(): void
    {
        Sanctum::actingAs(AclUser::where('id_role', 5)->where('is_active', 1)->firstOrFail(), ['*']);
        $this->getJson('/api/v1/refund-penerima')->assertForbidden();
    }

    public function test_alur_bm_lalu_ceo(): void
    {
        $id = $this->baru();
        $this->bm();
        $this->getJson('/api/v1/refund-penerima?filter=menunggu&per_page=50')->assertOk()->assertJsonFragment(['id' => $id, 'menunggu' => true]);
        $this->getJson('/api/v1/badges')->assertJsonPath('refund', fn ($n) => $n >= 1);
        $this->postJson("/api/v1/refund-penerima/$id/keputusan", ['result' => 1, 'catatan' => 'ok <b>'])->assertOk();
        $x = DB::table('pro_master_penerima_refund')->where('id', $id)->first();
        $this->assertSame([1, 0], [(int) $x->is_bm, (int) $x->is_ceo]);
        $this->assertSame('ok &lt;b&gt;', $x->catatan_bm);
        $this->postJson("/api/v1/refund-penerima/$id/keputusan", ['result' => 1])->assertStatus(409);

        Sanctum::actingAs(AclUser::where('id_role', 21)->where('is_active', 1)->firstOrFail(), ['*']);
        $this->getJson("/api/v1/refund-penerima/$id")->assertOk()->assertJsonPath('data.bisa_putus', true);
        $this->postJson("/api/v1/refund-penerima/$id/keputusan", ['result' => 1])->assertOk();
        $this->assertSame(1, (int) DB::table('pro_master_penerima_refund')->where('id', $id)->value('is_ceo'));
    }

    public function test_bm_tolak_membuat_ceo_tidak_melihat(): void
    {
        $id = $this->baru();
        $this->bm();
        $this->postJson("/api/v1/refund-penerima/$id/keputusan", ['result' => 2, 'catatan' => 'salah'])->assertOk();
        Sanctum::actingAs(AclUser::where('id_role', 21)->where('is_active', 1)->firstOrFail(), ['*']);
        $this->getJson("/api/v1/refund-penerima/$id")->assertNotFound();
    }

    public function test_ceo_tidak_bisa_sebelum_bm(): void
    {
        $id = $this->baru();
        Sanctum::actingAs(AclUser::where('id_role', 21)->where('is_active', 1)->firstOrFail(), ['*']);
        $this->postJson("/api/v1/refund-penerima/$id/keputusan", ['result' => 1])->assertNotFound();
    }

    public function test_berkas_dilayani_dan_aman(): void
    {
        $dir = sys_get_temp_dir().'/legacy-'.uniqid();
        mkdir($dir.'/files/uploaded_user/ktp_penerima_refund', 0777, true);
        file_put_contents($dir.'/files/uploaded_user/ktp_penerima_refund/foto_ktp_1_a.pdf', '%PDF-1.4 uji');
        file_put_contents($dir.'/files/uploaded_user/rahasia.txt', 'x');
        config(['orca.web_files' => $dir]);
        $id = $this->baru();
        $this->bm();
        $r = $this->get("/api/v1/refund-penerima/$id/berkas/ktp")->assertOk();
        $this->assertStringContainsString('application/pdf', $r->headers->get('Content-Type'));
        $this->get("/api/v1/refund-penerima/$id/berkas/npwp")->assertNotFound();
        DB::table('pro_master_penerima_refund')->where('id', $id)->update(['foto_ktp' => '../rahasia.txt']);
        $this->get("/api/v1/refund-penerima/$id/berkas/ktp")->assertNotFound();
    }
}
