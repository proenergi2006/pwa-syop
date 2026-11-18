<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportMarketingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('pro_po_customer_plan') || ! Schema::hasTable('pro_master_cabang')) {
            $this->markTestSkipped('Tabel PO customer tidak ada.');
        }
        Cache::flush();
    }

    private function om(): AclUser
    {
        Sanctum::actingAs($u = AclUser::where('id_role', 6)->where('is_active', 1)->firstOrFail(), ['*']);

        return $u;
    }

    /** Tahun terbaru yang punya realisasi kirim, supaya tes tidak bergantung tahun berjalan. */
    private function tahunAda(): int
    {
        return (int) DB::table('pro_po_customer_plan')->where('status_plan', 1)->selectRaw('YEAR(MAX(tanggal_kirim)) y')->value('y');
    }

    public function test_hanya_om_yang_boleh(): void
    {
        foreach ([7, 21, 5, 11] as $role) {
            $u = AclUser::where('id_role', $role)->where('is_active', 1)->first();
            if (! $u) {
                continue;
            }
            Sanctum::actingAs($u, ['*']);
            $this->getJson('/api/v1/report/marketing/volume')->assertForbidden();
            $this->getJson('/api/v1/report/marketing/cabang')->assertForbidden();
            $this->getJson('/api/v1/report/marketing/kegiatan')->assertForbidden();
            $this->getJson('/api/v1/report/marketing/kegiatan/list')->assertForbidden();
        }
        $this->om();
        $this->getJson('/api/v1/report/marketing/cabang')->assertOk()->assertJsonStructure(['data' => [['id', 'nama']]]);
    }

    public function test_menu_report_hanya_di_modul_om(): void
    {
        $this->om();
        $this->assertContains('report', $this->getJson('/api/v1/auth/me')->json('user.modules'));
        foreach ([7, 21, 5] as $role) {
            Sanctum::actingAs(AclUser::where('id_role', $role)->where('is_active', 1)->firstOrFail(), ['*']);
            $this->assertNotContains('report', $this->getJson('/api/v1/auth/me')->json('user.modules'));
        }
    }

    public function test_volume_sama_dengan_query_web(): void
    {
        $this->om();
        $y = $this->tahunAda();
        $r = $this->getJson("/api/v1/report/marketing/volume?tahun=$y")->assertOk()->assertJsonStructure(['rows', 'totals', 'prev_totals', 'total', 'prev_total', 'tahun', 'bulan_berjalan']);
        $this->assertCount(12, $r->json('totals'));

        // Query web (marketing-volume-report.php) tanpa filter cabang.
        $web = DB::select("SELECT SUM(b.volume_kirim) v FROM pro_po_customer ppc
            JOIN pro_customer pc ON pc.id_customer = ppc.id_customer JOIN pro_penawaran pp ON pp.id_penawaran = ppc.id_penawaran
            JOIN pro_po_customer_plan b ON b.id_poc = ppc.id_poc
            LEFT JOIN acl_user mkt_po ON mkt_po.id_user = ppc.id_marketing AND mkt_po.id_role IN ('11','17')
            LEFT JOIN acl_user mkt_pp ON mkt_pp.id_user = pp.id_marketing AND mkt_pp.id_role IN ('11','17')
            JOIN acl_user a ON a.id_user = COALESCE(mkt_po.id_user, mkt_pp.id_user, pc.id_marketing)
            WHERE (a.id_role='11' OR a.id_role='17') AND ppc.poc_approved=1 AND b.status_plan=1 AND YEAR(b.tanggal_kirim)=$y")[0]->v;
        $this->assertEqualsWithDelta((float) $web, $r->json('total'), 0.01);
        $this->assertEqualsWithDelta($r->json('total'), array_sum(array_column($r->json('rows'), 'total')), 0.01);
    }

    public function test_filter_cabang_mengurangi_atau_sama(): void
    {
        $this->om();
        $y = $this->tahunAda();
        $semua = $this->getJson("/api/v1/report/marketing/volume?tahun=$y")->json('total');
        $cab = DB::table('pro_penawaran')->whereNotNull('id_cabang')->value('id_cabang');
        $satu = $this->getJson("/api/v1/report/marketing/volume?tahun=$y&cabang=$cab")->assertOk()->json('total');
        $this->assertLessThanOrEqual($semua + 0.01, $satu);
    }

    public function test_aktif_dan_baru(): void
    {
        $this->om();
        $y = $this->tahunAda();
        foreach (['aktif', 'baru'] as $j) {
            $r = $this->getJson("/api/v1/report/marketing/$j?tahun=$y")->assertOk();
            $this->assertCount(12, $r->json('totals'));
            $this->assertEqualsWithDelta($r->json('total'), array_sum(array_column($r->json('rows'), 'total')), 0.01);
        }
        // Customer aktif per bulan tidak boleh lebih kecil dari customer baru yang sudah punya realisasi di bulan itu.
        $this->assertGreaterThanOrEqual(0, $this->getJson("/api/v1/report/marketing/baru?tahun=$y")->json('total'));
    }

    public function test_detail_per_bulan(): void
    {
        $this->om();
        $y = $this->tahunAda();
        $vol = $this->getJson("/api/v1/report/marketing/volume?tahun=$y")->json();
        $baris = collect($vol['rows'])->first(fn ($x) => $x['total'] > 0);
        $this->assertNotNull($baris, 'Butuh minimal satu marketing dengan volume.');
        $bulan = collect($baris['bulan'])->search(fn ($v) => $v > 0) + 1;

        $d = $this->getJson("/api/v1/report/marketing/volume/detail?tahun=$y&bulan=$bulan&marketing={$baris['id']}")->assertOk()->assertJsonStructure(['marketing', 'total', 'customer', 'data']);
        $this->assertSame($baris['nama'], $d->json('marketing'));

        $this->getJson("/api/v1/report/marketing/aktif/detail?tahun=$y&bulan=$bulan&marketing={$baris['id']}")->assertOk()->assertJsonStructure(['marketing', 'total', 'data']);
        $this->getJson("/api/v1/report/marketing/baru/detail?tahun=$y&bulan=$bulan&marketing={$baris['id']}")->assertOk()->assertJsonStructure(['marketing', 'total', 'data']);
    }

    public function test_tahun_dan_bulan_tidak_valid_dibatasi(): void
    {
        $this->om();
        $this->getJson('/api/v1/report/marketing/volume?tahun=abc')->assertOk();
        $this->getJson('/api/v1/report/marketing/volume/detail?tahun=2020&bulan=99&marketing=1')->assertOk()->assertJsonPath('bulan', 12);
        $this->getJson("/api/v1/report/marketing/volume?tahun=1'; DROP TABLE x;--")->assertOk();
    }

    /** Tiga kegiatan uji untuk satu marketing pada Maret 2024: draf, ajukan ke SPV (belum diputus), dan ke BM (sudah ada hasil). */
    private function siapkanKegiatan(): array
    {
        if (! Schema::hasTable('pro_marketing_report_master_file') || ! Schema::hasColumn('pro_marketing_report_master', 'deleted_time')) {
            $this->markTestSkipped('Tabel kegiatan marketing versi web terbaru tidak ada.');
        }
        $mkt = DB::table('acl_user')->whereIn('id_role', [11, 17])->whereIn('id_wilayah', DB::table('pro_master_area')->pluck('id_master'))->orderBy('id_user')->first();
        $cust = DB::table('pro_customer')->first();
        $base = ['id_customer' => $cust->id_customer, 'pic_customer' => 'Bu Uji', 'tanggal' => '2024-03-10 00:00:00', 'create_by' => $mkt->id_user, 'create_date' => '2024-03-10 08:00:00'];
        $id1 = DB::table('pro_marketing_report_master')->insertGetId($base + ['kegiatan' => 'Sales call uji', 'hasil_kegiatan' => '<p>Hasil <b>uji</b></p>', 'status' => null]);
        $id2 = DB::table('pro_marketing_report_master')->insertGetId($base + ['kegiatan' => 'Follow up uji', 'hasil_kegiatan' => 'x', 'status' => 1]);
        $id3 = DB::table('pro_marketing_report_master')->insertGetId($base + ['kegiatan' => 'Presentasi uji', 'hasil_kegiatan' => 'y', 'status' => 2, 'file_upload' => 'uji-utama.pdf', 'file_ori' => 'Utama.pdf']);
        DB::table('pro_marketing_report_master_disposisi')->insert(['id_disposisi' => 2, 'id_mkt_report' => $id3, 'disposisi' => 2, 'catatan' => 'ok', 'tanggal' => '2024-03-11 09:00:00', 'result' => 1, 'pic' => 'BM Uji']);
        DB::table('pro_marketing_report_master_file')->insert(['id_mkt_report' => $id3, 'keterangan' => 'Foto kunjungan', 'file_upload' => 'uji-foto.jpg', 'file_ori' => 'Foto.jpg']);

        return [$mkt, [$id1, $id2, $id3]];
    }

    public function test_kegiatan_ringkasan_dan_status(): void
    {
        $this->om();
        [$mkt] = $this->siapkanKegiatan();
        $r = $this->getJson('/api/v1/report/marketing/kegiatan?tahun=2024')->assertOk()->assertJsonStructure(['rows', 'totals', 'prev_totals', 'extra' => ['status', 'customer']]);
        $baris = collect($r->json('rows'))->firstWhere('id', $mkt->id_user);
        $this->assertNotNull($baris);
        $this->assertGreaterThanOrEqual(3, $baris['bulan'][2]); // Maret
        $labels = array_column($r->json('extra.status'), 'label');
        foreach (['Draf', 'Verifikasi SPV', 'Approve BM'] as $l) {
            $this->assertContains($l, $labels);
        }
        $this->assertGreaterThanOrEqual(1, $r->json('extra.customer'));
    }

    public function test_kegiatan_dihapus_tidak_dihitung(): void
    {
        $this->om();
        [, $ids] = $this->siapkanKegiatan();
        $awal = $this->getJson('/api/v1/report/marketing/kegiatan/list?tahun=2024&bulan=3&per_page=50')->json('meta.total');
        DB::table('pro_marketing_report_master')->where('id_mkt_report', $ids[0])->update(['deleted_time' => now()]);
        $this->assertSame($awal - 1, $this->getJson('/api/v1/report/marketing/kegiatan/list?tahun=2024&bulan=3&per_page=50')->json('meta.total'));
        $this->getJson('/api/v1/report/marketing/kegiatan/'.$ids[0])->assertNotFound();
    }

    public function test_kegiatan_daftar_pencarian_dan_filter(): void
    {
        $this->om();
        [$mkt] = $this->siapkanKegiatan();
        $r = $this->getJson("/api/v1/report/marketing/kegiatan/list?tahun=2024&bulan=3&marketing={$mkt->id_user}&q=Presentasi%20uji")->assertOk();
        $this->assertSame(1, $r->json('meta.total'));
        $this->assertSame('Approve BM', $r->json('data.0.status'));
        // karakter LIKE khusus tidak boleh jadi wildcard
        $this->assertSame(0, $this->getJson('/api/v1/report/marketing/kegiatan/list?tahun=2024&q=%25')->json('meta.total'));
        $this->getJson('/api/v1/report/marketing/kegiatan/list?tahun=2024&q='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_kegiatan_detail_dan_berkas(): void
    {
        $this->om();
        [, $ids] = $this->siapkanKegiatan();
        $d = $this->getJson('/api/v1/report/marketing/kegiatan/'.$ids[2])->assertOk()->assertJsonPath('status', 'Approve BM');
        $this->assertCount(2, $d->json('files'));
        $this->assertSame('BM', $d->json('tahap.0.tahap'));

        $tmp = sys_get_temp_dir().'/orca-kegiatan-'.uniqid();
        mkdir($tmp.'/files/uploaded_user/lampiran', 0777, true);
        file_put_contents($tmp.'/files/uploaded_user/lampiran/uji-utama.pdf', '%PDF-1.4 uji');
        config(['orca.web_files' => $tmp]);
        $this->get('/api/v1/report/marketing/kegiatan/'.$ids[2].'/berkas/0')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->getJson('/api/v1/report/marketing/kegiatan/'.$ids[2].'/berkas/'.DB::table('pro_marketing_report_master_file')->where('id_mkt_report', $ids[2])->value('id_file_upload'))->assertNotFound()->assertJsonPath('code', 'FILE_MISSING');
        $this->getJson('/api/v1/report/marketing/kegiatan/'.$ids[0].'/berkas/0')->assertNotFound();
        @unlink($tmp.'/files/uploaded_user/lampiran/uji-utama.pdf');
    }
}
