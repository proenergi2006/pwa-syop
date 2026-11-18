<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportRsspTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('new_pro_inventory_depot') || ! Schema::hasTable('pro_forecast_supply') || ! Schema::hasTable('pro_forecast_sales')) {
            $this->markTestSkipped('Tabel RSSP tidak ada.');
        }
        Cache::flush();
        Http::fake(['tanggalmerah.upset.dev/*' => Http::response(['success' => true, 'data' => [['date' => '2025-10-17', 'name' => 'Hari Uji', 'type' => 'holiday']]])]);
    }

    private function ceo(): AclUser
    {
        Sanctum::actingAs($u = AclUser::where('id_role', 21)->where('is_active', 1)->firstOrFail(), ['*']);

        return $u;
    }

    /** Hari dan terminal yang punya penjualan, supaya tes tidak bergantung bulan berjalan. */
    private function sampel(): object
    {
        $x = DB::table('new_pro_inventory_depot as d')->join('pro_master_terminal as t', 't.id_master', '=', 'd.id_terminal')
            ->where('t.is_active', 1)->whereIn('t.kategori_terminal', [1, 3])->whereNotIn('t.id_master', [92, 101])->where('d.out_inven', '>', 0)
            ->whereBetween('d.tanggal_inven', ['2025-01-01', '2025-12-31'])->orderByDesc('d.out_inven')->first(['d.id_terminal', 'd.tanggal_inven', 't.id_cabang']);
        $this->assertNotNull($x, 'Butuh data penjualan 2025.');

        return $x;
    }

    public function test_hanya_ceo_yang_boleh(): void
    {
        foreach ([6, 7, 5, 11] as $role) {
            $u = AclUser::where('id_role', $role)->where('is_active', 1)->first();
            if (! $u) {
                continue;
            }
            Sanctum::actingAs($u, ['*']);
            $this->getJson('/api/v1/report/rssp')->assertForbidden();
            $this->getJson('/api/v1/report/rssp/detail?type=sales&tid=1&tanggal=2025-10-01')->assertForbidden();
        }
        $this->ceo();
        $this->getJson('/api/v1/report/rssp/cabang')->assertOk()->assertJsonStructure(['data' => [['id', 'nama']]]);
    }

    public function test_matriks_bulanan_dan_sales_sama_dengan_database(): void
    {
        $this->ceo();
        $s = $this->sampel();
        $bulan = (int) date('n', strtotime($s->tanggal_inven));
        $r = $this->getJson("/api/v1/report/rssp?cabang={$s->id_cabang}&bulan=$bulan&tahun=2025")->assertOk()
            ->assertJsonStructure(['cabang', 'nama_cabang', 'bulan', 'tahun', 'jumlah_hari', 'tgl_actual', 'hari', 'depots', 'forecast', 'total_sales', 'stock_akhir_total']);
        $n = $r->json('jumlah_hari');
        $this->assertCount($n, $r->json('hari'));
        $this->assertCount($n, $r->json('total_sales'));
        $this->assertSame($n, $r->json('tgl_actual')); // bulan lampau: semua hari actual
        foreach ($r->json('depots') as $d) {
            foreach (['stock_awal', 'loss', 'supply', 'supply_fc', 'transfer', 'sales', 'plan', 'stock_akhir'] as $k) {
                $this->assertCount($n, $d[$k], $k);
            }
        }

        $db = (float) DB::table('new_pro_inventory_depot as d')->join('pro_master_terminal as t', 't.id_master', '=', 'd.id_terminal')
            ->where('t.id_cabang', $s->id_cabang)->where('t.is_active', 1)->whereIn('t.kategori_terminal', [1, 3])->whereNotIn('t.id_master', [92, 101])
            ->whereBetween('d.tanggal_inven', [sprintf('2025-%02d-01', $bulan), sprintf('2025-%02d-%02d', $bulan, $n)])->sum('d.out_inven');
        $this->assertEqualsWithDelta($db, array_sum($r->json('total_sales')), 0.5);
        $this->assertGreaterThan(0, $db);
    }

    public function test_stok_berkesinambungan_antar_hari(): void
    {
        $this->ceo();
        $s = $this->sampel();
        $bulan = (int) date('n', strtotime($s->tanggal_inven));
        $r = $this->getJson("/api/v1/report/rssp?cabang={$s->id_cabang}&bulan=$bulan&tahun=2025")->json();
        foreach ($r['depots'] as $d) {
            for ($i = 1; $i < $r['jumlah_hari']; $i++) {
                // stok awal hari ini = stok akhir kemarin
                $this->assertEqualsWithDelta($d['stock_akhir'][$i - 1], $d['stock_awal'][$i], 0.001);
            }
        }
        $jumlahAkhir = array_map(fn ($i) => array_sum(array_column(array_column($r['depots'], 'stock_akhir'), $i)), range(0, $r['jumlah_hari'] - 1));
        $this->assertEqualsWithDelta($jumlahAkhir[count($jumlahAkhir) - 1], end($r['stock_akhir_total']), 0.01);
    }

    public function test_mode_nasional_satu_baris_per_cabang(): void
    {
        $this->ceo();
        $r = $this->getJson('/api/v1/report/rssp?cabang=nasional&bulan=10&tahun=2025')->assertOk();
        $this->assertSame('nasional', $r->json('cabang'));
        $cab = DB::table('pro_master_cabang')->where('is_active', 1)->where('id_master', '<>', 1)->whereNotIn('id_master', [92, 101])->count();
        $this->assertCount($cab, $r->json('depots'));
    }

    public function test_hari_libur_dan_akhir_pekan(): void
    {
        $this->ceo();
        $r = $this->getJson('/api/v1/report/rssp?cabang=nasional&bulan=10&tahun=2025')->json('hari');
        $this->assertTrue($r[16]['weekend']); // 17 Okt (libur uji)
        $this->assertSame('Hari Uji', $r[16]['tip']);
        $this->assertTrue($r[3]['weekend']);   // 4 Okt 2025 = Sabtu
        $this->assertSame('Akhir Pekan', $r[3]['tip']);
        $this->assertFalse($r[0]['weekend']);  // 1 Okt 2025 = Rabu
    }

    public function test_detail_sel(): void
    {
        $this->ceo();
        $s = $this->sampel();
        $tgl = $s->tanggal_inven;
        $d = $this->getJson("/api/v1/report/rssp/detail?type=sales&tid={$s->id_terminal}&tanggal=$tgl")->assertOk()->assertJsonStructure(['type', 'data', 'total']);
        $this->assertSame('sales', $d->json('type'));
        $this->getJson("/api/v1/report/rssp/detail?type=sales&scope=cabang&tid={$s->id_cabang}&tanggal=$tgl")->assertOk();
        foreach (['supply', 'loss', 'transfer'] as $t) {
            $this->getJson("/api/v1/report/rssp/detail?type=$t&tid={$s->id_terminal}&tanggal=$tgl")->assertOk()->assertJsonPath('type', $t);
        }
        $this->getJson("/api/v1/report/rssp/detail?type=forecast&tanggal=$tgl")->assertOk()->assertJsonStructure(['data', 'total']);
    }

    public function test_detail_parameter_tidak_valid(): void
    {
        $this->ceo();
        $this->getJson('/api/v1/report/rssp/detail?type=sales&tid=1')->assertStatus(422);
        $this->getJson('/api/v1/report/rssp/detail?type=hack&tid=1&tanggal=2025-10-01')->assertStatus(422);
        $this->getJson("/api/v1/report/rssp/detail?type=sales&tid=1&tanggal=2025-10-01' OR 1=1")->assertStatus(422);
        $this->getJson('/api/v1/report/rssp?cabang=abc&bulan=99&tahun=1900')->assertOk();
    }
}
