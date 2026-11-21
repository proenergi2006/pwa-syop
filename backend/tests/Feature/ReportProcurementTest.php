<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportProcurementTest extends TestCase
{
    use DatabaseTransactions;

    private function purch(): void
    {
        Sanctum::actingAs(AclUser::where('id_role', 5)->where('is_active', 1)->firstOrFail(), ['*']);
    }

    public function test_hanya_procurement(): void
    {
        foreach ([21, 7, 6] as $role) {
            Sanctum::actingAs(AclUser::where('id_role', $role)->where('is_active', 1)->firstOrFail(), ['*']);
            foreach (['opsi', 'loaded-truck', 'loaded-kapal', 'stok-truck', 'stok-operasional'] as $u) {
                $this->getJson("/api/v1/report/procurement/$u")->assertForbidden();
            }
        }
        $this->purch();
        $m = $this->getJson('/api/v1/me')->status();
        $this->assertContains('report_procurement', $this->getJson('/api/v1/auth/me')->json('user.modules') ?? []);
    }

    public function test_rekap_loaded_truck_dan_kapal(): void
    {
        $this->purch();
        foreach (['loaded-truck' => true, 'loaded-kapal' => false] as $u => $truck) {
            $r = $this->getJson("/api/v1/report/procurement/$u?per_page=5")->assertOk()->json();
            $this->assertNotEmpty($r['data'], $u);
            $this->assertLessThanOrEqual(5, count($r['data']));
            $x = $r['data'][0];
            $this->assertContains($x['status'], ['Belum Loading', 'Loading', 'Delivered', 'Cancel', '']);
            $this->assertEqualsWithDelta(array_sum(array_column($r['data'], 'qty')), $r['total_halaman'], 0.001);
            // kata kunci pada nomor DR
            $c = $this->getJson("/api/v1/report/procurement/$u?q=".urlencode($x['nomor_dr']))->assertOk()->json('data');
            $this->assertContains($x['nomor_dr'], array_column($c, 'nomor_dr'));
            // tanggal loaded
            if ($x['tgl_loaded']) {
                $t = $this->getJson("/api/v1/report/procurement/$u?dari={$x['tgl_loaded']}&sampai={$x['tgl_loaded']}")->json('data');
                foreach ($t as $b) {
                    $this->assertSame($x['tgl_loaded'], $b['tgl_loaded']);
                }
            }
        }
        $this->assertNotNull($r['data'][0]['terminal']);
        $this->assertNull($this->getJson('/api/v1/report/procurement/loaded-kapal?per_page=1')->json('data.0.transportir'));
        $this->assertNotEmpty($this->getJson('/api/v1/report/procurement/opsi')->json('cabang'));
    }

    public function test_export_xlsx(): void
    {
        $this->purch();
        foreach (['loaded-truck', 'loaded-kapal'] as $u) {
            $r = $this->get("/api/v1/report/procurement/$u/export?dari=2026-01-01&sampai=2026-03-31");
            $r->assertOk();
            $this->assertStringContainsString('spreadsheetml', $r->headers->get('Content-Type'));
            $this->assertStringStartsWith('PK', $r->getContent());
        }
    }

    public function test_history_stok_truck_dan_ubah_tanggal(): void
    {
        $this->purch();
        $r = $this->getJson('/api/v1/report/procurement/stok-truck?per_page=5')->assertOk()->json();
        $this->assertNotEmpty($r['data']);
        foreach ($r['data'] as $x) {
            $this->assertContains($x['jenis'], ['Out Stock Virtual', 'Loaded']);
            $this->assertSame($x['jenis'] === 'Loaded', $x['bisa_ubah']);
        }
        $loaded = DB::table('new_pro_inventory_depot')->where('id_jenis', 7)->where('tanggal_inven', '>', '2024-09-01')->orderByDesc('id_master')->first();
        $virtual = DB::table('new_pro_inventory_depot')->where('id_jenis', 6)->first();
        $this->putJson("/api/v1/report/procurement/stok-truck/{$loaded->id_master}", ['tanggal' => 'x'])->assertStatus(422);
        $this->putJson("/api/v1/report/procurement/stok-truck/{$virtual->id_master}", ['tanggal' => '2026-02-01'])->assertStatus(409);
        $this->putJson("/api/v1/report/procurement/stok-truck/{$loaded->id_master}", ['tanggal' => '2026-02-01'])->assertOk();
        $this->assertSame('2026-02-01', DB::table('new_pro_inventory_depot')->where('id_master', $loaded->id_master)->value('tanggal_inven'));
        if ($loaded->id_dsd) {
            $this->assertSame('2026-02-01', DB::table('pro_po_ds_detail')->where('id_dsd', $loaded->id_dsd)->value('tanggal_loading'));
        }
    }

    public function test_history_stok_operasional(): void
    {
        $this->purch();
        $r = $this->getJson('/api/v1/report/procurement/stok-operasional?per_page=10')->assertOk()->json();
        $this->assertGreaterThan(0, $r['meta']['total']);
        $this->assertNotEmpty($r['data']);
        $tipe = array_unique(array_column($r['data'], 'tipe'));
        $this->assertNotEmpty(array_intersect($tipe, ['bpuj', 'operasional']));
        foreach ($r['data'] as $x) {
            $this->assertArrayHasKey('detail', $x);
            if ($x['tipe'] === 'bpuj') {
                $this->assertArrayHasKey('pengajuan', $x['detail']);
            }
        }
        // kata kunci memakai nomor baris pertama
        $n = $r['data'][0]['nomor'];
        if ($n) {
            $c = $this->getJson('/api/v1/report/procurement/stok-operasional?q='.urlencode($n))->assertOk()->json('data');
            $this->assertContains($n, array_column($c, 'nomor'));
        }
        // halaman 2 berbeda
        if ($r['meta']['last'] > 1) {
            $p2 = $this->getJson('/api/v1/report/procurement/stok-operasional?per_page=10&page=2')->json('data');
            $this->assertNotSame($r['data'][0]['nomor'].$r['data'][0]['tgl_potong'], ($p2[0]['nomor'] ?? '').($p2[0]['tgl_potong'] ?? ''));
        }
    }
}
