<?php

namespace Tests\Feature;

use App\Models\AclUser;
use App\Services\ForecastDefault;
use App\Services\WaitingCounts;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ForecastBmTest extends TestCase
{
    use DatabaseTransactions;

    /** Bulan uji jauh di masa depan supaya tidak bertabrakan dengan data nyata. */
    private const B = 3;
    private const Y = 2031;

    /** BM yang cabangnya punya customer; cabang BM itu dipakai sebagai cabang uji. */
    private function bm(array $abilities = ['*']): AclUser
    {
        $u = AclUser::where('id_role', 7)->where('is_active', 1)->whereIn('id_wilayah', DB::table('pro_customer')->select('id_wilayah'))->firstOrFail();
        Sanctum::actingAs($u, $abilities);

        return $u;
    }

    private function as(int $role): AclUser
    {
        $u = AclUser::where('id_role', $role)->where('is_active', 1)->firstOrFail();
        Sanctum::actingAs($u);

        return $u;
    }

    private function customer(int $cabang, int $skip = 0): int
    {
        return (int) DB::table('pro_customer')->where('id_wilayah', $cabang)->where('id_customer', '!=', $skip)->value('id_customer');
    }

    private function approval(int $cabang, int $periode, string $status, int $bulan = self::B, int $tahun = self::Y): int
    {
        return DB::table('pro_forecast_approval')->insertGetId(['id_wilayah' => $cabang, 'bulan' => $bulan, 'tahun' => $tahun, 'periode' => $periode, 'total_forecast' => 1, 'status' => $status, 'created_by' => 'Uji']);
    }

    private function tgl(int $hari, int $bulan = self::B, int $tahun = self::Y): string
    {
        return sprintf('%04d-%02d-%02d', $tahun, $bulan, $hari);
    }

    private function baris(int $cust): int
    {
        return DB::table('pro_forecast')->where('id_customer', $cust)->whereYear('tanggal', self::Y)->whereMonth('tanggal', self::B)->count();
    }

    private function data(int $periode = 1, int $bulan = self::B, int $tahun = self::Y)
    {
        return $this->getJson("/api/v1/forecast-bm/data?bulan=$bulan&tahun=$tahun&periode=$periode");
    }

    private function mails(): array
    {
        return Mail::mailer()->getSymfonyTransport()->messages()->map(fn ($m) => $m->getOriginalMessage())->all();
    }

    public function test_hanya_bm_yang_boleh(): void
    {
        foreach ([6, 5, 3, 21] as $role) {
            $this->as($role);
            $this->getJson('/api/v1/forecast-bm/data')->assertForbidden();
            $this->postJson('/api/v1/forecast-bm/isi', [])->assertForbidden();
        }
        $this->bm();
        $this->getJson('/api/v1/forecast-bm/data')->assertOk();
        $this->assertContains('forecast_bm', $this->getJson('/api/v1/auth/me')->json('user.modules'));
    }

    public function test_data_hanya_cabang_bm_sendiri(): void
    {
        $u = $this->bm();
        Cache::forget('rssp-libur:'.self::Y);
        Http::fake(['tanggalmerah.upset.dev/*' => Http::response(['success' => true, 'data' => [['date' => '2031-03-04', 'name' => 'Libur Uji', 'type' => 'national']]])]);
        $cust = $this->customer((int) $u->id_wilayah);
        DB::table('pro_forecast')->insert([['id_customer' => $cust, 'tanggal' => $this->tgl(3), 'qty' => 4], ['id_customer' => $cust, 'tanggal' => $this->tgl(20), 'qty' => 6]]);
        $d = $this->getJson('/api/v1/forecast-bm/data?cabang=99999&bulan=3&tahun=2031&periode=1')->assertOk()->json(); // parameter cabang diabaikan
        $this->assertSame((int) $u->id_wilayah, $d['cabang']['id']);
        $row = collect($d['customers'])->firstWhere('id', $cust);
        $this->assertCount(14, $row['hari']);
        $this->assertEquals(4, $row['hari'][2]);
        $this->assertFalse($row['default'][2]);
        $this->assertEquals(4, $row['total']);
        $this->assertEquals(4, $d['total_periode']);
        $this->assertEquals(10, $d['total_bulan']);
        $this->assertSame('Libur Uji', $d['kalender'][3]['libur']);
        $this->assertFalse($d['terkunci']);
        $this->assertEquals(6, collect($this->data(2)->json('customers'))->firstWhere('id', $cust)['total']);
        $this->assertSame('2031-02-25', $d['tanggal_default']);
    }

    public function test_isi_sel_simpan_hapus_dan_hanya_cabang_sendiri(): void
    {
        $u = $this->bm();
        $cab = (int) $u->id_wilayah;
        $cust = $this->customer($cab);
        $r = $this->postJson('/api/v1/forecast-bm/isi', ['id_customer' => $cust, 'items' => [['tanggal' => $this->tgl(3), 'qty' => 4.5], ['tanggal' => $this->tgl(4), 'qty' => 2]]])->assertOk();
        $this->assertEquals(6.5, $r->json('total'));
        $this->assertSame(2, $this->baris($cust));
        $this->postJson('/api/v1/forecast-bm/isi', ['id_customer' => $cust, 'items' => [['tanggal' => $this->tgl(3), 'qty' => null]]])->assertOk()->assertJsonPath('total', 2);
        $this->assertSame(1, $this->baris($cust));
        $this->postJson('/api/v1/forecast-bm/isi', ['id_customer' => $cust, 'items' => [['tanggal' => $this->tgl(3), 'qty' => -1]]])->assertStatus(422);
        $this->postJson('/api/v1/forecast-bm/isi', ['id_customer' => $cust, 'items' => [['tanggal' => '03/03/2031', 'qty' => 1]]])->assertStatus(422);
        // customer cabang lain ditolak
        $lain = (int) DB::table('pro_customer')->where('id_wilayah', '!=', $cab)->whereNotNull('id_wilayah')->value('id_customer');
        $this->postJson('/api/v1/forecast-bm/isi', ['id_customer' => $lain, 'items' => [['tanggal' => $this->tgl(3), 'qty' => 1]]])->assertNotFound();
    }

    public function test_terkunci_hanya_setelah_disetujui_ceo(): void
    {
        $u = $this->bm();
        $cab = (int) $u->id_wilayah;
        $cust = $this->customer($cab);
        $item = fn (int $h) => ['id_customer' => $cust, 'items' => [['tanggal' => $this->tgl($h), 'qty' => 1]]];
        $this->approval($cab, 1, 'WAITING');
        $this->postJson('/api/v1/forecast-bm/isi', $item(3))->assertOk(); // menunggu CEO: BM masih boleh mengubah
        $this->assertFalse($this->data(1)->json('terkunci'));
        $this->approval($cab, 1, 'APPROVED');
        $this->postJson('/api/v1/forecast-bm/isi', $item(4))->assertStatus(409)->assertJsonPath('code', 'LOCKED');
        $this->assertTrue($this->data(1)->json('terkunci'));
        $this->assertSame('APPROVED', $this->data(1)->json('review.1.status'));
        $this->postJson('/api/v1/forecast-bm/isi', $item(20))->assertOk(); // periode 2 tetap terbuka
    }

    public function test_default_tampil_abu_abu_dan_tersimpan_saat_sel_diubah(): void
    {
        $u = $this->bm();
        // cari cabang dan bulan yang punya default dari realisasi loading bulan lalu
        $found = null;
        foreach (DB::table('pro_master_cabang')->where('is_active', 1)->pluck('id_master') as $cab) {
            foreach ([2, 3, 4, 5, 6] as $bulan) {
                $map = ForecastDefault::map((int) $cab, $bulan, 2026, 1);
                if ($map) {
                    $found = [(int) $cab, $bulan, 2026, array_key_first($map), $map[array_key_first($map)]];
                    break 2;
                }
            }
        }
        if (! $found) {
            $this->markTestSkipped('Tidak ada data realisasi loading untuk membentuk default di database uji.');
        }
        [$cab, $bulan, $tahun, $cust, $hari] = $found;
        DB::table('acl_user')->where('id_user', $u->id_user)->update(['id_wilayah' => $cab]);
        $u->id_wilayah = $cab; // pengguna yang sedang login (objek yang sama dipakai request)
        DB::table('pro_forecast')->where('id_customer', $cust)->whereYear('tanggal', $tahun)->whereMonth('tanggal', $bulan)->whereRaw('day(tanggal) <= 14')->delete();
        $this->approval($cab, 1, 'REJECTED', $bulan, $tahun); // periode terbuka untuk default
        $d1 = array_key_first($hari);
        $q1 = $hari[$d1];

        $d = $this->data(1, $bulan, $tahun)->assertOk()->json();
        $this->assertTrue($d['ada_default']);
        $row = collect($d['customers'])->firstWhere('id', $cust);
        $this->assertEquals($q1, $row['hari'][$d1 - 1]);
        $this->assertTrue($row['default'][$d1 - 1]);
        $this->assertSame(0, DB::table('pro_forecast')->where('id_customer', $cust)->whereYear('tanggal', $tahun)->whereMonth('tanggal', $bulan)->whereRaw('day(tanggal) <= 14')->count()); // baru tampilan

        // mengubah satu sel menyimpan semua default customer itu dulu
        $edit = $d1 === 14 ? 13 : 14;
        $this->postJson('/api/v1/forecast-bm/isi', ['id_customer' => $cust, 'items' => [['tanggal' => $this->tgl($edit, $bulan, $tahun), 'qty' => 99]]])->assertOk();
        $this->assertEquals($q1, DB::table('pro_forecast')->where('id_customer', $cust)->where('tanggal', $this->tgl($d1, $bulan, $tahun))->value('qty'));
        $row = collect($this->data(1, $bulan, $tahun)->json('customers'))->firstWhere('id', $cust);
        $this->assertFalse($row['default'][$d1 - 1]);
        $this->assertEquals($q1, $row['hari'][$d1 - 1]);
        $this->assertEquals(99, $row['hari'][$edit - 1]);

        // mengosongkan sel default: sel lain tetap, sel itu kosong
        $this->postJson('/api/v1/forecast-bm/isi', ['id_customer' => $cust, 'items' => [['tanggal' => $this->tgl($d1, $bulan, $tahun), 'qty' => null]]])->assertOk();
        $row = collect($this->data(1, $bulan, $tahun)->json('customers'))->firstWhere('id', $cust);
        $this->assertNull($row['hari'][$d1 - 1]);
        $this->assertEquals(99, $row['hari'][$edit - 1]);
    }

    public function test_default_tidak_tampil_bila_periode_menunggu_atau_sudah_disetujui(): void
    {
        $u = $this->bm();
        $this->assertFalse(ForecastDefault::terbuka(0, 1, 2031, 1) === false); // tanpa approval: terbuka
        $cab = (int) $u->id_wilayah;
        $this->approval($cab, 1, 'WAITING');
        $this->assertFalse(ForecastDefault::terbuka($cab, self::B, self::Y, 1));
        $this->assertSame([], ForecastDefault::tampil($cab, self::B, self::Y, 1));
        $this->assertSame(0, ForecastDefault::simpan($cab, self::B, self::Y, 1));
        $this->assertFalse($this->data(1)->json('ada_default'));
    }

    public function test_request_qty_ke_om_dan_email(): void
    {
        $u = $this->bm();
        $cab = (int) $u->id_wilayah;
        $cust = $this->customer($cab);
        $body = ['id_customer' => $cust, 'tanggal' => $this->tgl(10), 'qty_request' => 25, 'alasan' => 'Customer minta tambahan'];
        $r = $this->postJson('/api/v1/forecast-bm/request', $body)->assertCreated();
        $q = DB::table('pro_forecast_request')->where('id_request', $r->json('id'))->first();
        $this->assertSame('WAITING_OM', $q->status);
        $this->assertSame(25, (int) $q->qty_request);
        $this->assertSame((int) $u->id_user, (int) $q->bm_pic);
        $this->assertNotNull($q->bm_tanggal);
        $m = $this->mails();
        $this->assertCount(1, $m);
        $this->assertSame('[BM] Request Penambahan Forecast – Menunggu Review OM', $m[0]->getSubject());
        $expected = DB::table('acl_user')->where('id_role', 6)->where('is_active', 1)->whereNotNull('email_user')->where('email_user', '!=', '')->pluck('email_user')->unique()->values()->all();
        $this->assertEqualsCanonicalizing($expected, array_map(fn ($x) => $x->getAddress(), $m[0]->getTo()));
        $this->assertStringContainsString('Customer minta tambahan', $m[0]->getHtmlBody());

        // validasi
        $this->postJson('/api/v1/forecast-bm/request', ['alasan' => ''] + $body)->assertStatus(422);
        $this->postJson('/api/v1/forecast-bm/request', ['qty_request' => 0] + $body)->assertStatus(422);
        $this->postJson('/api/v1/forecast-bm/request', ['tanggal' => 'besok'] + $body)->assertStatus(422);
        $lain = (int) DB::table('pro_customer')->where('id_wilayah', '!=', $cab)->whereNotNull('id_wilayah')->value('id_customer');
        $this->postJson('/api/v1/forecast-bm/request', ['id_customer' => $lain] + $body)->assertNotFound();

        // OM langsung melihatnya menunggu keputusannya
        $this->as(6);
        $this->assertContains((int) $r->json('id'), array_column($this->getJson('/api/v1/forecast-om/request?tab=wait')->json('data'), 'id'));
    }

    public function test_riwayat_request_hanya_yang_diajukan_bm_itu_sendiri(): void
    {
        $u = $this->bm();
        $cab = (int) $u->id_wilayah;
        $mine = $this->customer($cab);
        $base = ['id_customer' => $mine, 'tanggal' => $this->tgl(10), 'qty_request' => 5, 'alasan' => 'A', 'bm_tanggal' => now()];
        $a = DB::table('pro_forecast_request')->insertGetId($base + ['status' => 'REJECTED_OM', 'bm_pic' => $u->id_user, 'om_catatan' => 'Tidak ada stok', 'om_tanggal' => now()]);
        $lainBm = DB::table('pro_forecast_request')->insertGetId($base + ['status' => 'WAITING_OM', 'bm_pic' => $u->id_user + 100000]); // BM lain, customer cabang yang sama
        $r = $this->getJson('/api/v1/forecast-bm/request')->assertOk();
        $ids = array_column($r->json('data'), 'id');
        $this->assertContains($a, $ids);
        $this->assertNotContains($lainBm, $ids);
        $row = collect($r->json('data'))->firstWhere('id', $a);
        $this->assertSame('Tidak ada stok', $row['om_catatan']);
        $this->assertNotNull($row['bm_tanggal']);
        $this->assertNotNull($row['om_tanggal']);
        $this->assertNull($row['ceo_tanggal']);
        $this->assertNotEmpty($row['nama_cabang']);
        // request yang baru diajukan lewat aplikasi langsung muncul
        $new = $this->postJson('/api/v1/forecast-bm/request', ['id_customer' => $mine, 'tanggal' => $this->tgl(11), 'qty_request' => 7, 'alasan' => 'baru'])->assertCreated()->json('id');
        $this->assertContains($new, array_column($this->getJson('/api/v1/forecast-bm/request')->json('data'), 'id'));
    }

    public function test_cari_customer_dan_notifikasi_periode_ditolak(): void
    {
        $u = $this->bm();
        $cab = (int) $u->id_wilayah;
        $nama = DB::table('pro_customer')->where('id_wilayah', $cab)->whereNotNull('nama_customer')->value('nama_customer');
        $this->assertNotEmpty($this->getJson('/api/v1/forecast-bm/customer?q='.urlencode(substr($nama, 0, 5)))->assertOk()->json('data'));
        $this->assertSame([], $this->getJson('/api/v1/forecast-bm/customer?q='.urlencode('zzzzz-tidak-ada-zzzzz'))->json('data'));

        $b = $this->getJson('/api/v1/badges')->assertOk()->json('forecast_bm');
        $rev = $this->approval($cab, 1, 'REJECTED');
        $lain = (int) DB::table('pro_master_cabang')->where('id_master', '!=', $cab)->value('id_master');
        $this->approval($lain, 1, 'REJECTED'); // cabang lain tidak ikut
        $this->approval($cab, 2, 'REJECTED', 1, 2020); // bulan lampau tidak dihitung
        $this->assertSame($b + 1, $this->getJson('/api/v1/badges')->json('forecast_bm'));
        $items = WaitingCounts::items(AclUser::find($u->id_user));
        $this->assertArrayHasKey($rev, $items['forecast_bm']);
        $this->as(6);
        $this->assertSame(0, $this->getJson('/api/v1/badges')->json('forecast_bm'));
    }

    public function test_sesi_wajah_tanpa_password(): void
    {
        $u = $this->bm(['read']);
        $cust = $this->customer((int) $u->id_wilayah);
        $this->postJson('/api/v1/forecast-bm/isi', ['id_customer' => $cust, 'items' => [['tanggal' => $this->tgl(3), 'qty' => 1]]])->assertOk();
        $this->postJson('/api/v1/forecast-bm/request', ['id_customer' => $cust, 'tanggal' => $this->tgl(10), 'qty_request' => 5, 'alasan' => 'uji'])->assertCreated();
    }
}
