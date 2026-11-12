<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use DatabaseTransactions;

    private function as(int $role): void
    {
        Sanctum::actingAs(AclUser::where('id_role', $role)->where('is_active', 1)->firstOrFail(), ['*']);
    }

    public function test_ceo_melihat_sisa_stok_dan_ringkasan(): void
    {
        $this->as(21);
        $r = $this->getJson('/api/v1/inventory?per_page=5')->assertOk();
        $r->assertJsonStructure(['data', 'summary' => ['liter', 'po', 'per_produk'], 'meta' => ['total', 'last']]);
        $this->assertGreaterThan(0, $r->json('summary.liter'));
        foreach ($r->json('data') as $row) {
            $this->assertGreaterThan(0, (float) $row['sisa_inven']);
        }
    }

    public function test_filter_produk_membatasi_hasil(): void
    {
        $this->as(21);
        $id = $this->getJson('/api/v1/inventory')->json('summary.per_produk.0.id_produk');
        $r = $this->getJson('/api/v1/inventory?produk=' . $id)->assertOk();
        $this->assertSame($this->getJson('/api/v1/inventory')->json('meta.total'), $r->json('meta.total'));
        $this->getJson('/api/v1/inventory?produk=999999')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_bm_tanpa_akses_inventory_403(): void
    {
        $this->as(7);
        $this->getJson('/api/v1/inventory')->assertStatus(403);
    }

    public function test_peran_inventory_tidak_boleh_penawaran(): void
    {
        $this->as(5);
        $this->getJson('/api/v1/inventory')->assertOk();
        $this->getJson('/api/v1/penawaran')->assertStatus(403);
    }

    public function test_filter_options(): void
    {
        $this->as(21);
        $this->getJson('/api/v1/inventory/filter')->assertOk()->assertJsonStructure(['produk', 'cabang', 'vendor', 'terminal', 'kategori']);
    }

    public function test_stok_per_terminal_hanya_ceo(): void
    {
        $this->as(21);
        $r = $this->getJson('/api/v1/inventory/terminal')->assertOk();
        $this->assertNotEmpty($r->json('data'));
        // Per terminal dihitung neto (baris PO bersaldo minus ikut mengurangi), jadi sedikit di bawah total baris positif.
        $this->assertEqualsWithDelta($r->json('summary.stok'), $this->getJson('/api/v1/inventory')->json('summary.liter'), 0.02 * $r->json('summary.stok'));
        foreach ($r->json('data') as $t) {
            $this->assertGreaterThan(0, $t['sisa']);
        }
        $this->as(4);
        $this->getJson('/api/v1/inventory/terminal')->assertStatus(403);
    }

    public function test_om_semua_terminal_tanpa_cogs(): void
    {
        $this->as(21);
        $semua = $this->getJson('/api/v1/inventory/terminal')->assertOk()->json('data');
        $this->as(6);
        $r = $this->getJson('/api/v1/inventory/terminal')->assertOk();
        $this->assertCount(count($semua), $r->json('data'));
        $this->assertFalse($r->json('cogs'));
        $this->assertArrayNotHasKey('wavg', $r->json('data.0'));
        $this->assertArrayNotHasKey('nilai', $r->json('data.0'));
        $this->assertArrayNotHasKey('wavg', $r->json('summary'));
    }

    public function test_purchasing_semua_terminal_dengan_cogs(): void
    {
        $this->as(21);
        $ceo = $this->getJson('/api/v1/inventory/terminal')->assertOk();
        $this->as(5);
        $r = $this->getJson('/api/v1/inventory/terminal')->assertOk();
        $this->assertCount(count($ceo->json('data')), $r->json('data'));
        $this->assertTrue($r->json('cogs'));
        $this->assertSame('semua', $r->json('scope'));
        $this->assertSame($ceo->json('data.0.wavg'), $r->json('data.0.wavg'));
        $this->assertArrayHasKey('wavg', $r->json('summary'));
        $this->getJson('/api/v1/inventory')->assertOk();
    }

    public function test_bm_hanya_terminal_cabangnya_tanpa_cogs(): void
    {
        $bm = AclUser::where('id_role', 7)->where('is_active', 1)->whereIn('id_wilayah', DB::table('pro_master_terminal')->pluck('id_cabang'))->firstOrFail();
        Sanctum::actingAs($bm, ['*']);
        $r = $this->getJson('/api/v1/inventory/terminal')->assertOk();
        $this->assertFalse($r->json('cogs'));
        $this->assertArrayNotHasKey('wavg', $r->json('data.0') ?? []);
        $nama = DB::table('pro_master_cabang')->where('id_master', $bm->id_wilayah)->value('nama_cabang');
        foreach ($r->json('data') as $t) {
            $this->assertSame($nama, $t['cabang']);
        }
    }
}
