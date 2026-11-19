<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

class RsspInputTest extends TestCase
{
    use DatabaseTransactions;

    private const TGL = '2031-03-05'; // periode 1, jauh di masa depan
    private const TGL2 = '2031-03-20'; // periode 2

    private function as(int $role): AclUser
    {
        $u = AclUser::where('id_role', $role)->where('is_active', 1)->firstOrFail();
        Sanctum::actingAs($u, ['*']);

        return $u;
    }

    /** Dua terminal aktif di satu cabang. @return array{0:int,1:int,2:int} [cabang, terminal1, terminal2] */
    private function terminals(): array
    {
        $cab = (int) DB::table('pro_master_terminal')->where('is_active', 1)->whereIn('kategori_terminal', [1, 3])->whereNotIn('id_master', [92, 101])
            ->groupBy('id_cabang')->havingRaw('count(*) >= 2')->orderBy('id_cabang')->value('id_cabang');
        $t = DB::table('pro_master_terminal')->where('is_active', 1)->whereIn('kategori_terminal', [1, 3])->whereNotIn('id_master', [92, 101])->where('id_cabang', $cab)->orderBy('id_master')->limit(2)->pluck('id_master')->all();

        return [$cab, (int) $t[0], (int) $t[1]];
    }

    private function body(string $jenis, int $tid, string $tanggal = self::TGL, array $extra = []): array
    {
        return ['jenis' => $jenis, 'tid' => $tid, 'tanggal' => $tanggal] + $extra;
    }

    private function approve(int $cab, int $periode = 1): void
    {
        DB::table('pro_forecast_approval')->insert(['id_wilayah' => $cab, 'bulan' => 3, 'tahun' => 2031, 'periode' => $periode, 'total_forecast' => 1, 'status' => 'APPROVED', 'created_by' => 'Uji']);
    }

    private function forecast(int $cab, float $qtyDepan, string $tgl = self::TGL): void
    {
        DB::table('pro_forecast')->insert(['id_customer' => DB::table('pro_customer')->where('id_wilayah', $cab)->value('id_customer'), 'tanggal' => $tgl, 'qty' => $qtyDepan]);
    }

    public function test_input_hanya_untuk_purchasing(): void
    {
        [, $t1] = $this->terminals();
        foreach ([6, 21, 7, 3] as $role) {
            $this->as($role);
            $this->getJson('/api/v1/report/rssp/input?'.http_build_query($this->body('supply', $t1)))->assertForbidden();
            $this->postJson('/api/v1/report/rssp/input', $this->body('supply', $t1, self::TGL, ['qty' => 1]))->assertForbidden();
            $this->postJson('/api/v1/report/rssp/input/hapus', $this->body('supply', $t1))->assertForbidden();
        }
        $this->as(5);
        $this->getJson('/api/v1/report/rssp/input?'.http_build_query($this->body('supply', $t1)))->assertOk();
        $this->assertContains('rssp_input', $this->getJson('/api/v1/auth/me')->json('user.modules'));
        $this->as(6);
        $this->assertNotContains('rssp_input', $this->getJson('/api/v1/auth/me')->json('user.modules'));
    }

    public function test_forecast_supply_simpan_ubah_dan_hapus(): void
    {
        $u = $this->as(5);
        [$cab, $t1] = $this->terminals();
        $q = http_build_query($this->body('supply', $t1));
        $s = $this->getJson("/api/v1/report/rssp/input?$q")->assertOk()->json();
        $this->assertFalse($s['ada']);
        $this->assertFalse($s['terkunci']);
        $this->assertSame($cab, $s['terminal']['cabang_id']);
        $this->assertNotEmpty($s['terminal']['nama']);

        $this->postJson('/api/v1/report/rssp/input', $this->body('supply', $t1, self::TGL, ['qty' => 12000.4, 'keterangan' => 'Kapal <b>A</b> & "B"']))->assertOk();
        $row = DB::table('pro_forecast_supply')->where('id_terminal', $t1)->where('tanggal', self::TGL)->first();
        $this->assertSame(12000, (int) $row->qty);
        $this->assertSame($u->fullname, $row->created_by);
        $this->assertSame('Kapal &lt;b&gt;A&lt;/b&gt; &amp; &quot;B&quot;', $row->keterangan); // ter-escape seperti web
        $s = $this->getJson("/api/v1/report/rssp/input?$q")->json();
        $this->assertTrue($s['ada']);
        $this->assertSame(12000, $s['qty']);
        $this->assertSame('Kapal <b>A</b> & "B"', $s['keterangan']); // dibaca kembali apa adanya

        $this->postJson('/api/v1/report/rssp/input', $this->body('supply', $t1, self::TGL, ['qty' => 5000, 'keterangan' => '']))->assertOk();
        $this->assertSame(1, DB::table('pro_forecast_supply')->where('id_terminal', $t1)->where('tanggal', self::TGL)->count()); // diperbarui, bukan dobel
        $row = DB::table('pro_forecast_supply')->where('id_terminal', $t1)->where('tanggal', self::TGL)->first();
        $this->assertSame(5000, (int) $row->qty);
        $this->assertSame($u->fullname, $row->lastupdate_by);

        $this->postJson('/api/v1/report/rssp/input/hapus', $this->body('supply', $t1))->assertOk();
        $this->assertSame(0, DB::table('pro_forecast_supply')->where('id_terminal', $t1)->where('tanggal', self::TGL)->count());
    }

    public function test_forecast_supply_ditolak_bila_sudah_ada_realisasi_gr(): void
    {
        $this->as(5);
        $x = DB::table('new_pro_inventory_depot as d')->join('pro_master_terminal as t', 't.id_master', '=', 'd.id_terminal')
            ->where('t.is_active', 1)->where('t.kategori_terminal', 1)->whereNotIn('t.id_master', [92, 101])->where('d.in_inven', '>', 0)->orderByDesc('d.tanggal_inven')->first(['d.id_terminal', 'd.tanggal_inven']);
        $this->assertNotNull($x, 'Butuh data realisasi GR.');
        $tgl = substr($x->tanggal_inven, 0, 10);
        $this->getJson('/api/v1/report/rssp/input?'.http_build_query($this->body('supply', (int) $x->id_terminal, $tgl)))->assertOk()->assertJsonPath('terkunci', true);
        $this->postJson('/api/v1/report/rssp/input', $this->body('supply', (int) $x->id_terminal, $tgl, ['qty' => 1000]))->assertStatus(409)->assertJsonPath('code', 'ACTUAL');
        $this->assertSame(0, DB::table('pro_forecast_supply')->where('id_terminal', $x->id_terminal)->where('tanggal', $tgl)->count());
    }

    public function test_forecast_sales_plan_butuh_persetujuan_ceo(): void
    {
        $this->as(5);
        [$cab, $t1] = $this->terminals();
        $this->forecast($cab, 5);
        $s = $this->getJson('/api/v1/report/rssp/input?'.http_build_query($this->body('plan', $t1)))->assertOk()->json();
        $this->assertFalse($s['disetujui']);
        $this->assertSame(1, $s['periode']);
        $this->postJson('/api/v1/report/rssp/input', $this->body('plan', $t1, self::TGL, ['qty' => 1000]))->assertStatus(409)->assertJsonPath('code', 'NOT_APPROVED');
        $this->approve($cab, 1); // periode 2 belum
        $this->postJson('/api/v1/report/rssp/input', $this->body('plan', $t1, self::TGL, ['qty' => 1000]))->assertOk();
        $this->forecast($cab, 5, self::TGL2);
        $this->postJson('/api/v1/report/rssp/input', $this->body('plan', $t1, self::TGL2, ['qty' => 1000]))->assertStatus(409)->assertJsonPath('code', 'NOT_APPROVED');
    }

    public function test_forecast_sales_plan_tidak_boleh_melebihi_forecast_cabang(): void
    {
        $this->as(5);
        [$cab, $t1, $t2] = $this->terminals();
        $this->approve($cab, 1);
        $this->forecast($cab, 5); // 5 (angka depan) = 5.000 L
        $s = $this->getJson('/api/v1/report/rssp/input?'.http_build_query($this->body('plan', $t1)))->json();
        $this->assertEquals(5000, $s['total_forecast']);
        $this->assertEquals(0, $s['dialokasi_lain']);
        $this->assertEquals(5000, $s['sisa']);

        $this->postJson('/api/v1/report/rssp/input', $this->body('plan', $t1, self::TGL, ['qty' => 3000, 'keterangan' => 'Alokasi 1']))->assertOk();
        // terminal kedua: sisa tinggal 2.000
        $s2 = $this->getJson('/api/v1/report/rssp/input?'.http_build_query($this->body('plan', $t2)))->json();
        $this->assertEquals(3000, $s2['dialokasi_lain']);
        $this->assertEquals(2000, $s2['sisa']);
        $r = $this->postJson('/api/v1/report/rssp/input', $this->body('plan', $t2, self::TGL, ['qty' => 2500]))->assertStatus(422)->assertJsonPath('code', 'OVER_ALLOCATION');
        $this->assertStringContainsString('Melebihi sisa alokasi', $r->json('message'));
        $this->assertStringContainsString('2.000,0 L', $r->json('message'));
        $this->postJson('/api/v1/report/rssp/input', $this->body('plan', $t2, self::TGL, ['qty' => 2000]))->assertOk();
        // mengubah alokasi sendiri dihitung tanpa alokasi sendiri (sisa = 5000 - 2000 = 3000 untuk terminal 1)
        $this->postJson('/api/v1/report/rssp/input', $this->body('plan', $t1, self::TGL, ['qty' => 3000, 'keterangan' => 'Alokasi 1']))->assertOk();
        $this->postJson('/api/v1/report/rssp/input', $this->body('plan', $t1, self::TGL, ['qty' => 3001]))->assertStatus(422);
        $this->assertSame('Alokasi 1', self::plain(DB::table('pro_forecast_sales')->where('id_terminal', $t1)->where('tanggal', self::TGL)->value('keterangan')));
        // hapus mengembalikan sisa
        $this->postJson('/api/v1/report/rssp/input/hapus', $this->body('plan', $t1))->assertOk();
        $this->assertEquals(3000, $this->getJson('/api/v1/report/rssp/input?'.http_build_query($this->body('plan', $t1)))->json('sisa'));
    }

    private static function plain(string $s): string
    {
        return html_entity_decode($s, ENT_QUOTES);
    }

    public function test_validasi_dan_cache(): void
    {
        $this->as(5);
        [$cab, $t1] = $this->terminals();
        $this->postJson('/api/v1/report/rssp/input', $this->body('lain', $t1, self::TGL, ['qty' => 1]))->assertStatus(422);
        $this->postJson('/api/v1/report/rssp/input', $this->body('supply', $t1, '05/03/2031', ['qty' => 1]))->assertStatus(422);
        $this->postJson('/api/v1/report/rssp/input', $this->body('supply', 0, self::TGL, ['qty' => 1]))->assertStatus(422);
        $this->postJson('/api/v1/report/rssp/input', $this->body('supply', 99999999, self::TGL, ['qty' => 1]))->assertNotFound();
        $this->postJson('/api/v1/report/rssp/input', $this->body('supply', $t1, self::TGL, ['qty' => -5]))->assertStatus(422);
        $this->postJson('/api/v1/report/rssp/input', $this->body('supply', $t1, self::TGL, ['qty' => 'abc']))->assertStatus(422);
        $this->postJson('/api/v1/report/rssp/input', $this->body('supply', $t1, self::TGL, []))->assertStatus(422);
        $this->postJson('/api/v1/report/rssp/input', $this->body('supply', 92, self::TGL, ['qty' => 1]))->assertNotFound(); // terminal yang dikecualikan

        // hasil Report RSSP di-cache; input langsung menghapusnya
        foreach ([(string) $cab, 'nasional'] as $k) {
            Cache::put("rssp:$k:3:2031:".date('Ymd'), ['x' => 1], 600);
        }
        $this->postJson('/api/v1/report/rssp/input', $this->body('supply', $t1, self::TGL, ['qty' => 1000]))->assertOk();
        foreach ([(string) $cab, 'nasional'] as $k) {
            $this->assertFalse(Cache::has("rssp:$k:3:2031:".date('Ymd')));
        }
    }

    public function test_forecast_supply_ikut_tampil_di_report_dan_saldo(): void
    {
        $this->as(5);
        [$cab, $t1] = $this->terminals();
        $tgl = date('Y-m-d', strtotime('+3 days')); // belum lewat, jadi forecast supply ikut dihitung
        [$y, $m, $d] = array_map('intval', explode('-', $tgl));
        $awal = $this->getJson("/api/v1/report/rssp?cabang=$cab&bulan=$m&tahun=$y")->assertOk()->json();
        $dp = collect($awal['depots'])->firstWhere('id', $t1);
        $this->postJson('/api/v1/report/rssp/input', $this->body('supply', $t1, $tgl, ['qty' => 40000]))->assertOk();
        $baru = $this->getJson("/api/v1/report/rssp?cabang=$cab&bulan=$m&tahun=$y")->assertOk()->json();
        $dq = collect($baru['depots'])->firstWhere('id', $t1);
        if (($dp['supply'][$d - 1] ?? 0) == 0) {
            $this->assertEquals(40000, $dq['supply'][$d - 1]);
            $this->assertTrue($dq['supply_fc'][$d - 1]);
            $this->assertEquals($dp['stock_akhir'][$d - 1] + 40000, $dq['stock_akhir'][$d - 1]);
        }
    }

    public function test_unduh_excel_sama_dengan_layar(): void
    {
        foreach ([7, 3] as $role) {
            $this->as($role);
            $this->get('/api/v1/report/rssp/export?cabang=nasional&bulan=10&tahun=2025')->assertForbidden();
        }
        foreach ([5, 6, 21] as $role) {
            $this->as($role);
            $r = $this->get('/api/v1/report/rssp/export?cabang=2&bulan=10&tahun=2025');
            $r->assertOk();
            $this->assertStringContainsString('spreadsheetml.sheet', $r->headers->get('Content-Type'));
            $this->assertStringContainsString('RSSP-Fuel-Jakarta-10-2025.xlsx', $r->headers->get('Content-Disposition'));
            $this->assertSame('PK', substr($r->getContent(), 0, 2));
        }
        $tmp = tempnam(sys_get_temp_dir(), 'rssp').'.xlsx';
        file_put_contents($tmp, $r->getContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp) === true);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($tmp);
        $this->assertStringContainsString('RSSP FUEL - JAKARTA - OKTOBER 2025', $sheet);
        $this->assertStringContainsString('STOCK AWAL', $sheet);
        $this->assertStringContainsString('TOTAL P1', $sheet);
        $this->assertStringContainsString('TOTAL ACTUAL SALES', $sheet);
        $this->assertStringContainsString('STOCK AKHIR (TOTAL)', $sheet);
        $this->assertStringContainsString('01-Okt', $sheet);
        // angka total sales hari pertama sama dengan yang tampil di layar (dibulatkan 2 desimal)
        $d = $this->getJson('/api/v1/report/rssp?cabang=2&bulan=10&tahun=2025')->json();
        $nama = $d['depots'][0]['nama'];
        $this->assertStringContainsString(htmlspecialchars($nama, ENT_XML1 | ENT_QUOTES), $sheet);
        $stock = round($d['stock_akhir_total'][0], 2);
        $this->assertStringContainsString('<v>'.rtrim(rtrim(sprintf('%.4F', $stock), '0'), '.').'</v>', $sheet);
    }

    public function test_excel_mode_nasional(): void
    {
        $this->as(21);
        $r = $this->get('/api/v1/report/rssp/export?cabang=nasional&bulan=10&tahun=2025')->assertOk();
        $this->assertStringContainsString('RSSP-Fuel-Semua-Cabang-10-2025.xlsx', $r->headers->get('Content-Disposition'));
    }
}
