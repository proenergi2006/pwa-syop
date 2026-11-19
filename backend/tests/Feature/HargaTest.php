<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HargaTest extends TestCase
{
    use DatabaseTransactions;

    private const T = 'pro_master_harga_minyak';
    private const P = ['periode_awal' => '2031-01-01', 'periode_akhir' => '2031-01-14'];

    private function as(int $role, array $abilities = ['*']): AclUser
    {
        $u = AclUser::where('id_role', $role)->where('is_active', 1)->firstOrFail();
        Sanctum::actingAs($u, $abilities);

        return $u;
    }

    private function item(array $o = []): array
    {
        $area = DB::table('pro_master_area')->where('is_active', 1)->value('id_master');
        $produk = DB::table('pro_master_produk')->where('is_active', 1)->value('id_master');

        return array_merge(['area' => $area, 'produk' => $produk, 'normal' => 21000, 'sm' => 13000, 'om' => 12800, 'coo' => 12700, 'ceo' => 12600, 'note' => 'Uji'], $o);
    }

    public function test_hanya_ceo(): void
    {
        $this->as(7);
        $this->getJson('/api/v1/harga')->assertStatus(403);
        $this->as(21);
        $this->getJson('/api/v1/harga?per_page=3')->assertOk()->assertJsonStructure(['data' => [['periode_awal', 'nama_area', 'harga_normal', 'harga_ceo', 'dipakai']], 'meta']);
        $this->getJson('/api/v1/harga/options')->assertOk()->assertJsonStructure(['area', 'produk']);
    }

    public function test_tambah_lalu_detail_ubah_hapus(): void
    {
        $u = $this->as(21);
        $it = $this->item();
        $this->postJson('/api/v1/harga', self::P + ['items' => [$it]])->assertStatus(201);
        $row = DB::table(self::T)->where('periode_awal', '2031-01-01')->where('id_area', $it['area'])->first();
        $this->assertSame(21000, (int) $row->harga_normal);
        $this->assertSame(1, (int) $row->is_approved);
        $this->assertSame($u->fullname, $row->created_by);

        $key = ['awal' => '2031-01-01', 'akhir' => '2031-01-14', 'area' => $it['area'], 'produk' => $it['produk']];
        $this->getJson('/api/v1/harga/item?'.http_build_query($key))->assertOk()->assertJsonPath('harga_om', 12800);

        $this->putJson('/api/v1/harga/item', $key + ['h' => ['normal' => 21500, 'sm' => 13100, 'om' => 12900, 'coo' => 12800, 'ceo' => 12700, 'note' => 'Naik']])->assertOk();
        $row = DB::table(self::T)->where('periode_awal', '2031-01-01')->where('id_area', $it['area'])->first();
        $this->assertSame(21500, (int) $row->harga_normal);
        $this->assertSame(1, (int) $row->is_edited);

        $this->deleteJson('/api/v1/harga/item', $key)->assertOk();
        $this->assertFalse(DB::table(self::T)->where('periode_awal', '2031-01-01')->exists());
    }

    public function test_duplikat_ditolak(): void
    {
        $this->as(21);
        $it = $this->item();
        $this->postJson('/api/v1/harga', self::P + ['items' => [$it]])->assertStatus(201);
        $this->postJson('/api/v1/harga', self::P + ['items' => [$it]])->assertStatus(409)->assertJsonPath('code', 'EXISTS');
        $this->postJson('/api/v1/harga', ['periode_awal' => '2031-02-01', 'periode_akhir' => '2031-02-14', 'items' => [$it, $it]])->assertStatus(422)->assertJsonPath('code', 'DUPLICATE_ROW');
    }

    public function test_validasi(): void
    {
        $this->as(21);
        $this->postJson('/api/v1/harga', ['periode_awal' => '2031-01-10', 'periode_akhir' => '2031-01-01', 'items' => [$this->item()]])->assertStatus(422);
        $this->postJson('/api/v1/harga', self::P + ['items' => [$this->item(['om' => null])]])->assertStatus(422);
        $this->postJson('/api/v1/harga', self::P + ['items' => [$this->item(['normal' => 0])]])->assertStatus(422);
        $this->postJson('/api/v1/harga', self::P + ['items' => [$this->item(['area' => 999999])]])->assertStatus(422)->assertJsonPath('code', 'INVALID_REF');
        $this->assertFalse(DB::table(self::T)->where('periode_awal', '2031-01-01')->exists());
    }

    public function test_coo_ceo_kosong_jadi_nol(): void
    {
        $this->as(21);
        $it = $this->item(['coo' => null, 'ceo' => null]);
        $this->postJson('/api/v1/harga', self::P + ['items' => [$it]])->assertStatus(201);
        $row = DB::table(self::T)->where('periode_awal', '2031-01-01')->first();
        $this->assertSame(0, (int) $row->harga_coo);
    }

    public function test_harga_yang_dipakai_penawaran_tidak_bisa_dihapus(): void
    {
        $this->as(21);
        $r = DB::table(self::T.' as h')->join('pro_penawaran as p', fn ($j) => $j
            ->whereRaw('DATE(p.masa_awal) = h.periode_awal')->whereRaw('DATE(p.masa_akhir) = h.periode_akhir')
            ->whereRaw('CAST(p.id_area AS UNSIGNED) = h.id_area')->whereRaw('CAST(p.produk_tawar AS UNSIGNED) = h.produk'))
            ->where('h.pajak', 1)->select('h.*')->first();
        if (! $r) {
            $this->markTestSkipped('Tidak ada harga yang dipakai penawaran.');
        }
        $this->deleteJson('/api/v1/harga/item', ['awal' => $r->periode_awal, 'akhir' => $r->periode_akhir, 'area' => $r->id_area, 'produk' => $r->produk])
            ->assertStatus(409)->assertJsonPath('code', 'IN_USE');
    }

    public function test_sesi_wajah_tidak_butuh_password(): void
    {
        $this->as(21, ['face']);
        $this->postJson('/api/v1/harga', self::P + ['items' => [$this->item()]])->assertCreated();
        $this->assertTrue(DB::table(self::T)->where('periode_awal', '2031-01-01')->exists());
    }
}
