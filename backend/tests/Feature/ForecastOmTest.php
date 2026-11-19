<?php

namespace Tests\Feature;

use App\Models\AclUser;
use App\Services\WaitingCounts;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ForecastOmTest extends TestCase
{
    use DatabaseTransactions;

    /** Bulan uji jauh di masa depan supaya tidak bertabrakan dengan data nyata. */
    private const B = 3;
    private const Y = 2031;

    private function as(int $role, array $abilities = ['*']): AclUser
    {
        $u = AclUser::where('id_role', $role)->where('is_active', 1)->firstOrFail();
        Sanctum::actingAs($u, $abilities);

        return $u;
    }

    private function cabang(int $skip = 0): int
    {
        return (int) DB::table('pro_customer')->join('pro_master_cabang', 'pro_master_cabang.id_master', '=', 'pro_customer.id_wilayah')
            ->where('pro_master_cabang.is_active', 1)->where('pro_customer.id_wilayah', '!=', $skip)->value('id_wilayah');
    }

    private function customer(int $cabang): int
    {
        return (int) DB::table('pro_customer')->where('id_wilayah', $cabang)->value('id_customer');
    }

    private function approval(int $cabang, int $periode, string $status = 'WAITING', float $total = 10, int $bulan = self::B, int $tahun = self::Y): int
    {
        return DB::table('pro_forecast_approval')->insertGetId([
            'id_wilayah' => $cabang, 'bulan' => $bulan, 'tahun' => $tahun, 'periode' => $periode, 'total_forecast' => $total, 'catatan_om' => 'Catatan OM uji',
            'status' => $status, 'created_by' => 'Uji OM',
        ]);
    }

    private function forecast(int $customer, int $hari, float $qty): void
    {
        DB::table('pro_forecast')->insert(['id_customer' => $customer, 'tanggal' => sprintf('%04d-%02d-%02d', self::Y, self::B, $hari), 'qty' => $qty]);
    }

    private function baris(int $cust): int
    {
        return DB::table('pro_forecast')->where('id_customer', $cust)->whereYear('tanggal', self::Y)->whereMonth('tanggal', self::B)->count();
    }

    private function tgl(int $hari): string
    {
        return sprintf('%04d-%02d-%02d', self::Y, self::B, $hari);
    }

    private function request(int $customer, string $status = 'WAITING_OM', int $qty = 5): int
    {
        return DB::table('pro_forecast_request')->insertGetId([
            'id_customer' => $customer, 'tanggal' => $this->tgl(10), 'qty_request' => $qty, 'alasan' => 'Permintaan BM uji', 'status' => $status, 'bm_pic' => 1, 'bm_tanggal' => now(),
        ]);
    }

    private function mails(): array
    {
        return Mail::mailer()->getSymfonyTransport()->messages()->map(fn ($m) => $m->getOriginalMessage())->all();
    }

    private function expected(array $roles): array
    {
        return array_values(array_unique(DB::table('acl_user')->whereIn('id_role', $roles)->where('is_active', 1)->whereNotNull('email_user')->where('email_user', '!=', '')->pluck('email_user')->all()));
    }

    private function fakeLibur(): void
    {
        Cache::forget('rssp-libur:'.self::Y);
        Http::fake(['tanggalmerah.upset.dev/*' => Http::response(['success' => true, 'data' => [['date' => '2031-03-04', 'name' => 'Libur Uji', 'type' => 'national']]])]);
    }

    private function data(int $cabang, int $periode = 1)
    {
        return $this->getJson("/api/v1/forecast-om/data?cabang=$cabang&bulan=".self::B.'&tahun='.self::Y."&periode=$periode");
    }

    public function test_hanya_om_yang_boleh(): void
    {
        foreach ([7, 5, 3, 21] as $role) {
            $this->as($role);
            $this->getJson('/api/v1/forecast-om/cabang')->assertForbidden();
            $this->postJson('/api/v1/forecast-om/isi', [])->assertForbidden();
        }
        $this->as(6);
        $this->getJson('/api/v1/forecast-om/cabang')->assertOk();
        $this->assertContains('forecast_om', $this->getJson('/api/v1/auth/me')->json('user.modules'));
    }

    public function test_cabang_aktif_dan_cabang_awal_milik_akun(): void
    {
        $u = $this->as(6);
        $r = $this->getJson('/api/v1/forecast-om/cabang')->assertOk();
        $ids = array_column($r->json('data'), 'id');
        $this->assertContains((int) $u->id_wilayah, $ids);
        $this->assertSame((int) $u->id_wilayah, $r->json('default'));
    }

    public function test_data_periode_customer_total_dan_status(): void
    {
        $this->as(6);
        $this->fakeLibur();
        $cab = $this->cabang();
        $cust = $this->customer($cab);
        $this->forecast($cust, 3, 4);
        $this->forecast($cust, 20, 6);
        $rej = $this->approval($cab, 2, 'REJECTED');
        DB::table('pro_forecast_approval')->where('id_approval', $rej)->update(['catatan_ceo' => 'Terlalu tinggi', 'ceo_date' => now()]);

        $d = $this->data($cab, 1)->assertOk()->json();
        $this->assertSame(1, $d['periode']);
        $this->assertSame([1, 14], [$d['hari_awal'], $d['hari_akhir']]);
        $this->assertCount(14, $d['kalender']);
        $row = collect($d['customers'])->firstWhere('id', $cust);
        $this->assertCount(14, $row['hari']);
        $this->assertEquals(4, $row['hari'][2]);
        $this->assertNull($row['hari'][0]);
        $this->assertEquals(4, $row['total']);
        $this->assertEquals(4, $d['total_periode']);
        $this->assertEquals(10, $d['total_bulan']);
        $this->assertEquals(4, $d['review'][1]['total']);
        $this->assertEquals(6, $d['review'][2]['total']);
        $this->assertFalse($d['terkunci']);
        $this->assertSame('Libur Uji', $d['kalender'][3]['libur']);
        $this->assertSame('REJECTED', $d['review'][2]['status']);
        $this->assertSame('Terlalu tinggi', $d['review'][2]['catatan_ceo']);
        $cb = collect($d['cabangan'])->firstWhere('id', $cab);
        $this->assertSame('REJECTED', $cb['per'][2]['status']);
        $this->assertSame('Terlalu tinggi', $cb['per'][2]['catatan_ceo']);
        $this->assertEquals(10, $cb['total']);

        $p2 = $this->data($cab, 2)->assertOk()->json();
        $this->assertSame([15, 31], [$p2['hari_awal'], $p2['hari_akhir']]);
        $this->assertCount(17, $p2['kalender']);
        $this->assertEquals(6, collect($p2['customers'])->firstWhere('id', $cust)['total']);
        $this->getJson('/api/v1/forecast-om/data?cabang=99999&bulan=3&tahun=2031')->assertNotFound();
    }

    public function test_data_periode_menunggu_terkunci_dan_kredit(): void
    {
        $this->as(6);
        $cab = $this->cabang();
        $cust = $this->customer($cab);
        $this->forecast($cust, 5, 3);
        $this->approval($cab, 1, 'WAITING');
        $d = $this->data($cab, 1)->assertOk()->json();
        $this->assertTrue($d['terkunci']);
        $this->assertFalse($this->data($cab, 2)->json('terkunci'));
        $k = collect($d['kredit'])->first();
        $this->assertArrayHasKey('risiko', $k);
        $this->assertArrayHasKey('sisa', $k);
    }

    public function test_isi_sel_simpan_ubah_hapus_dan_total(): void
    {
        $this->as(6);
        $cust = $this->customer($this->cabang());
        $r = $this->postJson('/api/v1/forecast-om/isi', ['id_customer' => $cust, 'items' => [['tanggal' => $this->tgl(3), 'qty' => 4.5]]])->assertOk();
        $this->assertEquals(4.5, $r->json('total'));
        $this->assertEquals(4.5, DB::table('pro_forecast')->where('id_customer', $cust)->where('tanggal', $this->tgl(3))->value('qty'));
        $this->postJson('/api/v1/forecast-om/isi', ['id_customer' => $cust, 'items' => [['tanggal' => $this->tgl(3), 'qty' => 6], ['tanggal' => $this->tgl(4), 'qty' => 2]]])->assertOk()->assertJsonPath('total', 8);
        $this->assertSame(2, $this->baris($cust));
        $this->postJson('/api/v1/forecast-om/isi', ['id_customer' => $cust, 'items' => [['tanggal' => $this->tgl(3), 'qty' => null]]])->assertOk()->assertJsonPath('total', 2);
        $this->assertSame(1, $this->baris($cust));
        // mengosongkan sel terakhir customer tanpa default tidak meninggalkan penanda
        $this->postJson('/api/v1/forecast-om/isi', ['id_customer' => $cust, 'items' => [['tanggal' => $this->tgl(4), 'qty' => '']]])->assertOk();
        $this->assertSame(0, $this->baris($cust));
        $this->postJson('/api/v1/forecast-om/isi', ['id_customer' => $cust, 'items' => [['tanggal' => $this->tgl(5), 'qty' => 0]]])->assertOk();
        $this->assertSame(1, $this->baris($cust));
    }

    public function test_isi_ditolak_bila_tidak_valid_atau_periode_terkunci(): void
    {
        $this->as(6);
        $cab = $this->cabang();
        $cust = $this->customer($cab);
        $this->postJson('/api/v1/forecast-om/isi', ['id_customer' => $cust, 'items' => [['tanggal' => $this->tgl(3), 'qty' => -1]]])->assertStatus(422);
        $this->postJson('/api/v1/forecast-om/isi', ['id_customer' => $cust, 'items' => [['tanggal' => $this->tgl(3), 'qty' => 'abc']]])->assertStatus(422);
        $this->postJson('/api/v1/forecast-om/isi', ['id_customer' => $cust, 'items' => [['tanggal' => '03/03/2031', 'qty' => 1]]])->assertStatus(422);
        $this->postJson('/api/v1/forecast-om/isi', ['id_customer' => 99999999, 'items' => [['tanggal' => $this->tgl(3), 'qty' => 1]]])->assertNotFound();

        $this->approval($cab, 1, 'WAITING');
        $this->postJson('/api/v1/forecast-om/isi', ['id_customer' => $cust, 'items' => [['tanggal' => $this->tgl(3), 'qty' => 1]]])->assertStatus(409)->assertJsonPath('code', 'LOCKED');
        $this->postJson('/api/v1/forecast-om/isi', ['id_customer' => $cust, 'items' => [['tanggal' => $this->tgl(20), 'qty' => 1]]])->assertOk(); // periode 2 masih terbuka
        $this->approval($cab, 2, 'APPROVED');
        $this->postJson('/api/v1/forecast-om/isi', ['id_customer' => $cust, 'items' => [['tanggal' => $this->tgl(21), 'qty' => 1]]])->assertStatus(409);
        $this->assertSame(1, $this->baris($cust));
        // setelah ditolak CEO periode terbuka lagi
        $this->approval($cab, 1, 'REJECTED');
        $this->postJson('/api/v1/forecast-om/isi', ['id_customer' => $cust, 'items' => [['tanggal' => $this->tgl(3), 'qty' => 2]]])->assertOk();
    }

    public function test_sesi_wajah_tidak_perlu_konfirmasi_password(): void
    {
        $this->as(6, ['read']); // sesi hasil login wajah
        $cab = $this->cabang();
        $cust = $this->customer($cab);
        $this->forecast($cust, 9, 2);
        $this->postJson('/api/v1/forecast-om/isi', ['id_customer' => $cust, 'items' => [['tanggal' => $this->tgl(3), 'qty' => 1]]])->assertOk();
        $this->assertSame(2, $this->baris($cust));
        $this->postJson('/api/v1/forecast-om/ajukan', ['cabang' => [$cab], 'bulan' => self::B, 'tahun' => self::Y, 'periode' => 1])->assertOk();
        $id = $this->request($cust);
        $this->postJson("/api/v1/forecast-om/request/$id/keputusan", ['keputusan' => 'approve'])->assertOk();
        $this->assertSame('WAITING_CEO', DB::table('pro_forecast_request')->where('id_request', $id)->value('status'));
    }

    public function test_cari_customer_cabang(): void
    {
        $this->as(6);
        $cab = $this->cabang();
        $nama = DB::table('pro_customer')->where('id_wilayah', $cab)->whereNotNull('nama_customer')->value('nama_customer');
        $r = $this->getJson("/api/v1/forecast-om/customer?cabang=$cab&q=".urlencode(substr($nama, 0, 5)))->assertOk();
        $this->assertNotEmpty($r->json('data'));
        $this->assertLessThanOrEqual(30, count($r->json('data')));
        $this->assertSame([], $this->getJson("/api/v1/forecast-om/customer?cabang=$cab&q=".urlencode('zzzzz-tidak-ada-zzzzz'))->json('data'));
    }

    public function test_ajukan_ke_ceo_menghitung_total_melewati_yang_sudah_diajukan_dan_email(): void
    {
        $this->as(6);
        $a = $this->cabang();
        $b = $this->cabang($a);
        $ca = $this->customer($a);
        $cb = $this->customer($b);
        $this->forecast($ca, 3, 4);
        $this->forecast($ca, 9, 2.5);
        $this->forecast($ca, 20, 100); // periode 2, tidak ikut total periode 1
        $this->forecast($cb, 3, 7);
        $this->approval($b, 1, 'WAITING');

        $body = ['cabang' => [$a, $b, $a], 'bulan' => self::B, 'tahun' => self::Y, 'periode' => 1, 'catatan' => 'Mohon disetujui'];
        $r = $this->postJson('/api/v1/forecast-om/ajukan', $body)->assertOk();
        $this->assertSame(1, $r->json('diajukan'));
        $this->assertCount(1, $r->json('dilewati'));
        $row = DB::table('pro_forecast_approval')->where('id_wilayah', $a)->where('bulan', self::B)->where('tahun', self::Y)->where('periode', 1)->first();
        $this->assertSame('WAITING', $row->status);
        $this->assertEquals(6.5, $row->total_forecast);
        $this->assertSame('Mohon disetujui', $row->catatan_om);
        $this->assertNotEmpty($row->created_by);
        $this->assertSame(1, DB::table('pro_forecast_approval')->where('id_wilayah', $b)->where('periode', 1)->where('bulan', self::B)->where('tahun', self::Y)->count()); // yang menunggu tidak diduplikasi

        $m = $this->mails();
        $this->assertCount(1, $m);
        $this->assertSame('Persetujuan Forecast Cabang - Periode 1 (1 cabang)', $m[0]->getSubject());
        $this->assertEqualsCanonicalizing($this->expected([21, 5]), array_map(fn ($x) => $x->getAddress(), $m[0]->getTo()));
        $this->assertStringContainsString('Mohon disetujui', $m[0]->getHtmlBody());

        // periode jadi terkunci, dan CEO melihatnya menunggu
        $this->assertTrue($this->data($a, 1)->json('terkunci'));
        $this->postJson('/api/v1/forecast-om/ajukan', $body)->assertStatus(409)->assertJsonPath('code', 'NOT_SUBMITTED');
    }

    public function test_ajukan_ulang_setelah_ditolak_dan_validasi(): void
    {
        $this->as(6);
        $a = $this->cabang();
        $this->approval($a, 2, 'REJECTED');
        $this->postJson('/api/v1/forecast-om/ajukan', ['cabang' => [$a], 'bulan' => self::B, 'tahun' => self::Y, 'periode' => 2])->assertOk();
        $this->assertSame('WAITING', DB::table('pro_forecast_approval')->where('id_wilayah', $a)->where('periode', 2)->where('bulan', self::B)->where('tahun', self::Y)->orderByDesc('id_approval')->value('status'));
        $this->postJson('/api/v1/forecast-om/ajukan', ['cabang' => [], 'bulan' => self::B, 'tahun' => self::Y, 'periode' => 1])->assertStatus(422);
        $this->postJson('/api/v1/forecast-om/ajukan', ['cabang' => [$a], 'bulan' => 13, 'tahun' => self::Y, 'periode' => 1])->assertStatus(422);
        $this->postJson('/api/v1/forecast-om/ajukan', ['cabang' => [$a], 'bulan' => self::B, 'tahun' => self::Y, 'periode' => 3])->assertStatus(422);
    }

    public function test_request_qty_om_setuju_meneruskan_ke_ceo(): void
    {
        $this->as(6);
        $cust = $this->customer($this->cabang());
        $id = $this->request($cust);
        $lain = $this->request($cust, 'WAITING_CEO');
        $wait = $this->getJson('/api/v1/forecast-om/request?tab=wait')->assertOk();
        $ids = array_column($wait->json('data'), 'id');
        $this->assertContains($id, $ids);
        $this->assertNotContains($lain, $ids);
        $this->assertTrue(collect($wait->json('data'))->firstWhere('id', $id)['can_decide']);
        $this->assertContains($lain, array_column($this->getJson('/api/v1/forecast-om/request?tab=all')->json('data'), 'id'));

        $u = $this->postJson("/api/v1/forecast-om/request/$id/keputusan", ['keputusan' => 'approve'])->assertOk();
        $q = DB::table('pro_forecast_request')->where('id_request', $id)->first();
        $this->assertSame('WAITING_CEO', $q->status);
        $this->assertNotNull($q->om_tanggal);
        $this->assertNotNull($q->om_pic);
        $this->postJson("/api/v1/forecast-om/request/$id/keputusan", ['keputusan' => 'approve'])->assertStatus(409)->assertJsonPath('code', 'NOT_PENDING');
        // sekarang CEO melihatnya menunggu keputusannya
        $this->as(21);
        $this->assertSame(1, collect($this->getJson('/api/v1/forecast/request?bulan='.self::B.'&tahun='.self::Y)->json('data'))->where('id', $id)->where('can_decide', true)->count());
    }

    public function test_request_qty_om_tolak_wajib_catatan(): void
    {
        $this->as(6);
        $id = $this->request($this->customer($this->cabang()));
        $this->postJson("/api/v1/forecast-om/request/$id/keputusan", ['keputusan' => 'reject'])->assertStatus(422)->assertJsonPath('code', 'NOTE_REQUIRED');
        $this->postJson("/api/v1/forecast-om/request/$id/keputusan", ['keputusan' => 'reject', 'catatan' => 'Tidak ada stok'])->assertOk();
        $q = DB::table('pro_forecast_request')->where('id_request', $id)->first();
        $this->assertSame('REJECTED_OM', $q->status);
        $this->assertSame('Tidak ada stok', $q->om_catatan);
        $this->postJson("/api/v1/forecast-om/request/99999999/keputusan", ['keputusan' => 'approve'])->assertStatus(409);
    }

    public function test_badge_item_dan_menunggu_untuk_request_dan_periode_ditolak(): void
    {
        $u = $this->as(6);
        $b = $this->getJson('/api/v1/badges')->assertOk()->json();
        $cab = $this->cabang();
        $req = $this->request($this->customer($cab));
        $rev = $this->approval($cab, 1, 'REJECTED');
        $this->approval($cab, 2, 'WAITING');
        $this->approval($cab, 1, 'REJECTED', 10, 1, 2020); // bulan lampau tidak dihitung
        $after = $this->getJson('/api/v1/badges')->json();
        $this->assertSame($b['forecast_om'] + 1, $after['forecast_om']);
        $this->assertSame($b['forecast_revisi'] + 1, $after['forecast_revisi']);
        $items = WaitingCounts::items(AclUser::find($u->id_user));
        $this->assertArrayHasKey($req, $items['forecast_om']);
        $this->assertArrayHasKey($rev, $items['forecast_revisi']);
        $this->assertStringContainsString('P1', $items['forecast_revisi'][$rev]);

        $m = $this->getJson('/api/v1/forecast-om/menunggu')->assertOk()->json();
        $this->assertContains($req, array_column($m['requests'], 'id'));
        $row = collect($m['revisi'])->first(fn ($x) => $x['cabang'] === $cab && $x['bulan'] === self::B && $x['periode'] === 1);
        $this->assertNotNull($row);
        $this->as(7);
        $this->assertSame(0, $this->getJson('/api/v1/badges')->json('forecast_om'));
    }
}
