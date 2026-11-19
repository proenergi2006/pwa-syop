<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProdukTest extends TestCase
{
    use DatabaseTransactions;

    private function as(int $role, array $ab = ['*']): AclUser
    {
        $u = AclUser::where('id_role', $role)->where('is_active', 1)->firstOrFail();
        Sanctum::actingAs($u, $ab);

        return $u;
    }

    public function test_hanya_ceo(): void
    {
        $this->as(7);
        $this->getJson('/api/v1/produk')->assertStatus(403);
        $this->postJson('/api/v1/produk', ['jenis' => 'X', 'merk' => 'Y', 'aktif' => true])->assertStatus(403);
        $this->as(21);
        $this->getJson('/api/v1/produk')->assertOk()->assertJsonStructure(['data' => [['id', 'jenis', 'merk', 'aktif']], 'counts', 'meta']);
    }

    public function test_tambah_detail_ubah_dan_filter(): void
    {
        $u = $this->as(21);
        $id = $this->postJson('/api/v1/produk', ['jenis' => 'Uji <b>X</b>', 'merk' => "Merk \"Uji\"", 'catatan' => "baris1\nbaris2", 'aktif' => false])->assertStatus(201)->json('id');
        $row = DB::table('pro_master_produk')->where('id_master', $id)->first();
        $this->assertStringNotContainsString('<b>', $row->jenis_produk); // aman untuk web
        $this->assertSame('baris1<br />baris2', $row->catatan_produk);
        $this->assertSame($u->fullname, $row->created_by);
        $this->assertSame(0, (int) $row->is_active);

        $this->getJson("/api/v1/produk/$id")->assertOk()->assertJsonPath('jenis', 'Uji <b>X</b>')->assertJsonPath('catatan', "baris1\nbaris2")->assertJsonPath('aktif', false);
        $this->assertContains($id, array_column($this->getJson('/api/v1/produk?status=inactive&q=Uji')->json('data'), 'id'));
        $this->assertNotContains($id, array_column($this->getJson('/api/v1/produk?status=active&per_page=100')->json('data'), 'id'));

        $this->putJson("/api/v1/produk/$id", ['jenis' => 'Uji2', 'merk' => 'M2', 'catatan' => '', 'aktif' => true])->assertOk();
        $row = DB::table('pro_master_produk')->where('id_master', $id)->first();
        $this->assertSame('Uji2', $row->jenis_produk);
        $this->assertSame(1, (int) $row->is_active);
        $this->assertSame($u->fullname, $row->lastupdate_by);
    }

    public function test_validasi_dan_404(): void
    {
        $this->as(21);
        $this->postJson('/api/v1/produk', ['jenis' => '', 'merk' => 'Y', 'aktif' => true])->assertStatus(422);
        $this->postJson('/api/v1/produk', ['jenis' => str_repeat('a', 26), 'merk' => 'Y', 'aktif' => true])->assertStatus(422);
        $this->putJson('/api/v1/produk/99999999', ['jenis' => 'a', 'merk' => 'b', 'aktif' => true])->assertStatus(404);
        $this->getJson('/api/v1/produk/99999999')->assertStatus(404);
    }

    public function test_sesi_wajah_tidak_butuh_password(): void
    {
        $this->as(21, ['face']);
        $this->postJson('/api/v1/produk', ['jenis' => 'X', 'merk' => 'Y', 'aktif' => true])->assertCreated();
    }
}
