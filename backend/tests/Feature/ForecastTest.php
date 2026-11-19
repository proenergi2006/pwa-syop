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

class ForecastTest extends TestCase
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

    private function cabang(): int
    {
        return (int) DB::table('pro_customer')->whereNotNull('id_wilayah')->where('id_wilayah', '>', 0)->join('pro_master_cabang', 'pro_master_cabang.id_master', '=', 'pro_customer.id_wilayah')
            ->where('pro_master_cabang.is_active', 1)->value('id_wilayah');
    }

    private function customer(int $cabang): int
    {
        return (int) DB::table('pro_customer')->where('id_wilayah', $cabang)->value('id_customer');
    }

    private function approval(int $cabang, int $periode, string $status = 'WAITING', float $total = 10): int
    {
        return DB::table('pro_forecast_approval')->insertGetId([
            'id_wilayah' => $cabang, 'bulan' => self::B, 'tahun' => self::Y, 'periode' => $periode, 'total_forecast' => $total, 'catatan_om' => 'Catatan OM uji',
            'status' => $status, 'created_by' => 'Uji OM',
        ]);
    }

    private function forecast(int $customer, int $hari, float $qty): void
    {
        DB::table('pro_forecast')->insert(['id_customer' => $customer, 'tanggal' => sprintf('%04d-%02d-%02d', self::Y, self::B, $hari), 'qty' => $qty]);
    }

    private function request(int $customer, string $status = 'WAITING_CEO', int $qty = 5): int
    {
        return DB::table('pro_forecast_request')->insertGetId([
            'id_customer' => $customer, 'tanggal' => sprintf('%04d-%02d-10', self::Y, self::B), 'qty_request' => $qty, 'alasan' => 'Permintaan uji', 'status' => $status,
            'om_pic' => 'Uji OM', 'om_catatan' => 'OM setuju',
        ]);
    }

    private function mails(): array
    {
        return Mail::mailer()->getSymfonyTransport()->messages()->map(fn ($m) => $m->getOriginalMessage())->all();
    }

    private function recipients($mail): array
    {
        return array_map(fn ($a) => $a->getAddress(), $mail->getTo());
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

    public function test_hanya_ceo_yang_boleh(): void
    {
        foreach ([7, 6, 5, 3] as $role) {
            $this->as($role);
            $this->getJson('/api/v1/forecast/rekap')->assertForbidden();
            $this->postJson('/api/v1/forecast/periode/setujui', [])->assertForbidden();
        }
        $this->as(21);
        $this->getJson('/api/v1/forecast/rekap?bulan='.self::B.'&tahun='.self::Y)->assertOk();
        $this->assertContains('forecast', $this->getJson('/api/v1/auth/me')->json('user.modules'));
    }

    public function test_rekap_menaruh_yang_menunggu_di_atas_dan_menghitung_status(): void
    {
        $this->as(21);
        $cab = $this->cabang();
        $cust = $this->customer($cab);
        $this->forecast($cust, 3, 4);
        $this->forecast($cust, 20, 6);
        $this->approval($cab, 1, 'WAITING', 4);
        $this->approval($cab, 2, 'REJECTED', 6);
        $r = $this->getJson('/api/v1/forecast/rekap?bulan='.self::B.'&tahun='.self::Y)->assertOk();
        $this->assertSame(31, $r->json('jumlah_hari'));
        $this->assertSame(1, $r->json('count.waiting'));
        $this->assertSame(1, $r->json('count.rejected'));
        $first = $r->json('cabang.0');
        $this->assertSame($cab, $first['id']);
        $this->assertSame([1], $first['waiting']);
        $this->assertSame('WAITING', $first['per'][1]['status']);
        $this->assertSame('Catatan OM uji', $first['per'][1]['catatan_om']);
        $this->assertEquals(4, $first['per'][1]['total']);
        $this->assertEquals(6, $first['per'][2]['total']);
        // Periode yang belum diajukan memakai forecast berjalan.
        $other = collect($r->json('cabang'))->first(fn ($c) => $c['id'] !== $cab);
        $this->assertSame('', $other['per'][1]['status']);
    }

    public function test_approval_terakhir_yang_berlaku(): void
    {
        $this->as(21);
        $cab = $this->cabang();
        $this->approval($cab, 1, 'REJECTED');
        $this->approval($cab, 1, 'WAITING');
        $r = $this->getJson('/api/v1/forecast/rekap?bulan='.self::B.'&tahun='.self::Y)->assertOk();
        $row = collect($r->json('cabang'))->firstWhere('id', $cab);
        $this->assertSame('WAITING', $row['per'][1]['status']);
        $this->assertSame(1, $r->json('count.waiting'));
    }

    public function test_detail_cabang_matriks_harian_ringkasan_alokasi_dan_riwayat_tolak(): void
    {
        $this->as(21);
        $this->fakeLibur();
        $cab = $this->cabang();
        $cust = $this->customer($cab);
        $this->forecast($cust, 1, 3);
        $this->forecast($cust, 15, 7);
        $rej = $this->approval($cab, 1, 'REJECTED');
        DB::table('pro_forecast_approval')->where('id_approval', $rej)->update(['catatan_ceo' => 'Terlalu tinggi', 'ceo_date' => now()]);
        $this->approval($cab, 1, 'WAITING');
        DB::table('pro_forecast_allocate')->insert(['id_wilayah' => $cab, 'bulan' => self::B, 'tahun' => self::Y, 'allocate_p1' => 20, 'allocate_p2' => 30]);

        $d = $this->getJson("/api/v1/forecast/cabang?cabang=$cab&bulan=".self::B.'&tahun='.self::Y)->assertOk()->json();
        $this->assertCount(31, $d['kalender']);
        $this->assertSame('Libur Uji', $d['kalender'][3]['libur']);
        $this->assertTrue($d['kalender'][0]['akhir_pekan']); // 1 Maret 2031 hari Sabtu
        $this->assertCount(1, $d['customers']);
        $this->assertEquals(3, $d['customers'][0]['hari'][0]);
        $this->assertEquals(7, $d['customers'][0]['hari'][14]);
        $this->assertEquals(10, $d['customers'][0]['total']);
        $this->assertEquals(10, $d['total_bulan']);
        $this->assertEquals(3, $d['total_hari'][0]);
        $this->assertEquals(3, $d['review'][1]['total']);
        $this->assertEquals(7, $d['review'][2]['total']);
        $this->assertTrue($d['review'][1]['can_decide']);
        $this->assertFalse($d['review'][2]['can_decide']);
        $this->assertSame('WAITING', $d['review'][1]['status']);
        $this->assertCount(1, $d['review'][1]['riwayat_tolak']);
        $this->assertSame('Terlalu tinggi', $d['review'][1]['riwayat_tolak'][0]->catatan_ceo ?? $d['review'][1]['riwayat_tolak'][0]['catatan_ceo']);
        $this->assertEquals(50, $d['alokasi']['total']);
        $this->assertTrue($d['alokasi']['melebihi']);
        $row = collect($d['ringkasan'])->firstWhere('id', $cab);
        $this->assertEquals(10, $row['total']);
        $this->assertEquals(10, $d['ringkasan_total']);
        $this->getJson('/api/v1/forecast/cabang?cabang=99999&bulan=3&tahun=2031')->assertNotFound();
    }

    public function test_setuju_periode_mengubah_status_dan_email_om_purchasing(): void
    {
        $this->as(21);
        $cab = $this->cabang();
        $id = $this->approval($cab, 1);
        $this->postJson('/api/v1/forecast/periode/setujui', ['items' => [['cabang' => $cab, 'periode' => 1]], 'bulan' => self::B, 'tahun' => self::Y, 'catatan' => 'OK'])->assertOk()->assertJsonPath('disetujui', 1);
        $a = DB::table('pro_forecast_approval')->where('id_approval', $id)->first();
        $this->assertSame('APPROVED', $a->status);
        $this->assertSame('OK', $a->catatan_ceo);
        $this->assertNotNull($a->ceo_date);
        $this->assertNotNull($a->ceo_pic);
        $m = $this->mails();
        $this->assertCount(1, $m);
        $this->assertStringStartsWith('Forecast Disetujui CEO - Cabang ', $m[0]->getSubject());
        $this->assertEqualsCanonicalizing($this->expected([6, 5]), $this->recipients($m[0]));
        // Sudah diputuskan: tidak bisa dua kali.
        $this->postJson('/api/v1/forecast/periode/setujui', ['items' => [['cabang' => $cab, 'periode' => 1]], 'bulan' => self::B, 'tahun' => self::Y])->assertStatus(409)->assertJsonPath('code', 'NOT_PENDING');
    }

    public function test_setuju_banyak_sekaligus_melewati_yang_tidak_menunggu(): void
    {
        $this->as(21);
        $cab = $this->cabang();
        $a1 = $this->approval($cab, 1);
        $this->approval($cab, 2, 'APPROVED');
        $r = $this->postJson('/api/v1/forecast/periode/setujui', ['items' => [['cabang' => $cab, 'periode' => 1], ['cabang' => $cab, 'periode' => 2]], 'bulan' => self::B, 'tahun' => self::Y])->assertOk();
        $this->assertSame(1, $r->json('disetujui'));
        $this->assertSame([['cabang' => $cab, 'periode' => 2]], $r->json('dilewati'));
        $this->assertSame('APPROVED', DB::table('pro_forecast_approval')->where('id_approval', $a1)->value('status'));
    }

    public function test_tolak_periode_wajib_alasan(): void
    {
        $this->as(21);
        $cab = $this->cabang();
        $id = $this->approval($cab, 2);
        $body = ['cabang' => $cab, 'periode' => 2, 'bulan' => self::B, 'tahun' => self::Y];
        $this->postJson('/api/v1/forecast/periode/tolak', $body)->assertStatus(422);
        $this->postJson('/api/v1/forecast/periode/tolak', $body + ['alasan' => 'Revisi qty'])->assertOk();
        $a = DB::table('pro_forecast_approval')->where('id_approval', $id)->first();
        $this->assertSame('REJECTED', $a->status);
        $this->assertSame('Revisi qty', $a->catatan_ceo);
        $m = $this->mails();
        $this->assertStringStartsWith('Forecast DITOLAK CEO - Cabang ', $m[0]->getSubject());
        $this->assertStringContainsString('Revisi qty', $m[0]->getHtmlBody());
        $this->postJson('/api/v1/forecast/periode/tolak', $body + ['alasan' => 'lagi'])->assertStatus(409);
    }

    public function test_daftar_request_dan_setuju_menambah_qty_forecast(): void
    {
        $this->as(21);
        $cab = $this->cabang();
        $cust = $this->customer($cab);
        $this->forecast($cust, 10, 4);
        $id = $this->request($cust, 'WAITING_CEO', 5);
        $wait = $this->request($cust, 'WAITING', 1); // belum sampai CEO
        $list = $this->getJson('/api/v1/forecast/request?bulan='.self::B.'&tahun='.self::Y)->assertOk();
        $this->assertSame(1, $list->json('waiting'));
        $row = collect($list->json('data'))->firstWhere('id', $id);
        $this->assertTrue($row['can_decide']);
        $this->assertFalse(collect($list->json('data'))->firstWhere('id', $wait)['can_decide']);

        $this->postJson("/api/v1/forecast/request/$id/setujui")->assertOk();
        $this->assertEquals(9, DB::table('pro_forecast')->where('id_customer', $cust)->where('tanggal', '2031-03-10')->value('qty')); // 4 + 5
        $q = DB::table('pro_forecast_request')->where('id_request', $id)->first();
        $this->assertSame('APPROVED', $q->status);
        $this->assertNotNull($q->ceo_tanggal);
        $m = $this->mails();
        $this->assertSame('Request Forecast Disetujui CEO', $m[0]->getSubject());
        $this->assertEqualsCanonicalizing($this->expected([6, 5]), $this->recipients($m[0]));
        $this->postJson("/api/v1/forecast/request/$id/setujui")->assertStatus(409);
        $this->postJson("/api/v1/forecast/request/$wait/setujui")->assertStatus(409);
        $this->assertEquals(9, DB::table('pro_forecast')->where('id_customer', $cust)->where('tanggal', '2031-03-10')->value('qty')); // tidak bertambah dua kali
    }

    public function test_setuju_request_membuat_baris_forecast_baru_bila_belum_ada(): void
    {
        $this->as(21);
        $cust = $this->customer($this->cabang());
        $id = $this->request($cust, 'WAITING_CEO', 7);
        $this->postJson("/api/v1/forecast/request/$id/setujui")->assertOk();
        $this->assertEquals(7, DB::table('pro_forecast')->where('id_customer', $cust)->where('tanggal', '2031-03-10')->value('qty'));
    }

    public function test_tolak_request_wajib_alasan_dan_tidak_mengubah_forecast(): void
    {
        $this->as(21);
        $cust = $this->customer($this->cabang());
        $id = $this->request($cust);
        $this->postJson("/api/v1/forecast/request/$id/tolak", [])->assertStatus(422);
        $this->postJson("/api/v1/forecast/request/$id/tolak", ['alasan' => 'Stok tidak cukup'])->assertOk();
        $q = DB::table('pro_forecast_request')->where('id_request', $id)->first();
        $this->assertSame('REJECTED', $q->status);
        $this->assertSame('Stok tidak cukup', $q->ceo_catatan);
        $this->assertSame(0, DB::table('pro_forecast')->where('id_customer', $cust)->where('tanggal', '2031-03-10')->count());
        $this->postJson("/api/v1/forecast/request/$id/tolak", ['alasan' => 'lagi'])->assertStatus(409);
    }

    public function test_sesi_wajah_tidak_perlu_konfirmasi_password(): void
    {
        $this->as(21, ['read']);
        $cab = $this->cabang();
        $id = $this->approval($cab, 1);
        $req = $this->request($this->customer($cab));
        $this->postJson('/api/v1/forecast/periode/setujui', ['items' => [['cabang' => $cab, 'periode' => 1]], 'bulan' => self::B, 'tahun' => self::Y])->assertOk();
        $this->postJson("/api/v1/forecast/request/$req/setujui")->assertOk();
        $this->assertSame('APPROVED', DB::table('pro_forecast_approval')->where('id_approval', $id)->value('status'));
    }

    public function test_badge_dan_item_menunggu_untuk_periode_dan_request(): void
    {
        $this->as(21);
        $b = $this->getJson('/api/v1/badges')->assertOk()->json();
        $cab = $this->cabang();
        $id = $this->approval($cab, 1);
        $this->approval($cab, 1, 'REJECTED'); // baris lama yang sudah bukan yang terakhir tidak dihitung (yang terakhir WAITING sudah ada di atas, ini lebih baru -> REJECTED)
        $req = $this->request($this->customer($cab));
        $after = $this->getJson('/api/v1/badges')->json();
        $this->assertSame($b['forecast'], $after['forecast']); // terakhir REJECTED: tidak menunggu
        $this->assertSame($b['forecast_request'] + 1, $after['forecast_request']);
        $items = WaitingCounts::items(AclUser::where('id_role', 21)->where('is_active', 1)->first());
        $this->assertArrayHasKey($req, $items['forecast_request']);
        $this->assertArrayNotHasKey($id, $items['forecast']);
        $id2 = $this->approval($cab, 2);
        $items = WaitingCounts::items(AclUser::where('id_role', 21)->where('is_active', 1)->first());
        $this->assertArrayHasKey($id2, $items['forecast']);
        $this->assertStringContainsString('P2', $items['forecast'][$id2]);
        $this->as(7);
        $this->assertSame(0, $this->getJson('/api/v1/badges')->json('forecast'));
    }
}
