<?php

namespace Tests\Feature;

use App\Support\LegacyPassword;
use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApprovalTest extends TestCase
{
    use DatabaseTransactions; // semua perubahan di-rollback

    private const BM_CAB5   = 384;   // BM cabang 5
    private const BM_CAB2   = 583;   // BM cabang 2 (bukan pemilik penawaran cabang 5)
    private const OM        = 584;   // OM
    private const CEO       = 122;
    private const PNW_BM    = 59970; // disposisi 3, cabang 5, tanpa warning
    private const PNW_OM_T3 = 68863; // disposisi 4, tier III

    private function as(int $id): AclUser
    {
        $u = AclUser::findOrFail($id);
        Sanctum::actingAs($u, ['*']); // sama seperti token hasil login password

        return $u;
    }

    private function row(int $id): object
    {
        return DB::table('pro_penawaran')->where('id_penawaran', $id)->first();
    }

    // ---------------------------------------------------------------- login

    public function test_login_dengan_hash_2a_berhasil_dan_password_salah_ditolak(): void
    {
        $hash = LegacyPassword::make('Rahasia123');
        DB::table('acl_user')->where('id_user', self::BM_CAB5)->update(['password' => $hash]);
        $username = AclUser::find(self::BM_CAB5)->username;

        $this->postJson('/api/v1/auth/login', ['username' => $username, 'password' => 'salah'])
            ->assertStatus(422);

        $this->postJson('/api/v1/auth/login', ['username' => $username, 'password' => 'Rahasia123'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'fullname', 'id_role', 'role_name']]);
    }

    public function test_user_nonaktif_tidak_bisa_login(): void
    {
        $hash = LegacyPassword::make('Rahasia123');
        DB::table('acl_user')->where('id_user', self::BM_CAB5)->update(['password' => $hash, 'is_active' => 0]);
        $username = AclUser::find(self::BM_CAB5)->username;

        $this->postJson('/api/v1/auth/login', ['username' => $username, 'password' => 'Rahasia123'])
            ->assertStatus(422);
    }

    public function test_role_lain_bisa_masuk_tapi_tanpa_modul_penawaran_403(): void
    {
        $uid = DB::table('acl_user')->where('is_active', 1)->whereNotIn('id_role', [7, 6, 3, 21, 1, 4, 5])->value('id_user');
        DB::table('acl_user')->where('id_user', $uid)->update(['password' => LegacyPassword::make('Rahasia123')]);

        $res = $this->postJson('/api/v1/auth/login', [
            'username' => AclUser::find($uid)->username, 'password' => 'Rahasia123',
        ])->assertOk()->assertJsonPath('user.modules', ['mobil', 'reservasi']);

        $this->withToken($res->json('token'))->getJson('/api/v1/penawaran')->assertStatus(403);
    }

    public function test_tanpa_token_ditolak_401(): void
    {
        $this->getJson('/api/v1/penawaran')->assertStatus(401);
    }

    // ----------------------------------------------------------------- daftar

    public function test_daftar_bm_hanya_cabang_sendiri(): void
    {
        $this->as(self::BM_CAB5);
        $res = $this->getJson('/api/v1/penawaran')->assertOk();

        $ids = collect($res->json('data'))->pluck('id_penawaran');
        $this->assertContains(self::PNW_BM, $ids);
        foreach ($res->json('data') as $r) {
            $this->assertSame('Pontianak', $r['nama_cabang']);
            $this->assertSame(3, (int) $r['flag_disposisi']);
        }
    }

    public function test_bm_cabang_lain_tidak_melihat_dan_tidak_bisa_memutuskan(): void
    {
        $this->as(self::BM_CAB2);
        $ids = collect($this->getJson('/api/v1/penawaran')->json('data'))->pluck('id_penawaran');
        $this->assertNotContains(self::PNW_BM, $ids);

        $this->postJson('/api/v1/penawaran/' . self::PNW_BM . '/keputusan', ['keputusan' => 'approve'])
            ->assertStatus(409)->assertJsonPath('code', 'NOT_YOUR_TURN');
        $this->assertSame(0, (int) $this->row(self::PNW_BM)->flag_approval);
    }

    public function test_pencarian_dan_pagination(): void
    {
        $this->as(self::BM_CAB5);
        $this->getJson('/api/v1/penawaran?per_page=1')->assertOk()->assertJsonPath('meta.per_page', 1);
        $this->getJson('/api/v1/penawaran?q=tidak-ada-xyz-999')->assertOk()->assertJsonPath('meta.total', 0);
    }

    // ---------------------------------------------------------------- keputusan

    public function test_bm_setuju_final_menulis_kolom_dan_riwayat(): void
    {
        $u = $this->as(self::BM_CAB5);
        $histBefore = DB::table('pro_approval_hist')->where('id_penawaran', self::PNW_BM)->count();

        $this->postJson('/api/v1/penawaran/' . self::PNW_BM . '/keputusan', ['keputusan' => 'approve', 'catatan' => 'OK'])
            ->assertOk()->assertJsonPath('final', true);

        $r = $this->row(self::PNW_BM);
        $this->assertSame(1, (int) $r->flag_approval);
        $this->assertSame(1, (int) $r->sm_wil_result);
        $this->assertSame($u->fullname, $r->sm_wil_pic);
        $this->assertSame((int) $u->id_user, (int) $r->pic_approval);
        $this->assertNotNull($r->tgl_approval);
        $this->assertSame(3, (int) $r->flag_disposisi); // tidak naik tahap bila final

        $this->assertSame($histBefore + 1, DB::table('pro_approval_hist')->where('id_penawaran', self::PNW_BM)->count());
    }

    public function test_bm_teruskan_ke_om(): void
    {
        $this->as(self::BM_CAB5);
        $this->postJson('/api/v1/penawaran/' . self::PNW_BM . '/keputusan', ['keputusan' => 'approve', 'teruskan' => true])
            ->assertOk()->assertJsonPath('next_stage', 4);

        $r = $this->row(self::PNW_BM);
        $this->assertSame(4, (int) $r->flag_disposisi);
        $this->assertSame(0, (int) $r->flag_approval);
    }

    public function test_bm_dengan_warning_wajib_ke_ceo_walau_klien_minta_final(): void
    {
        DB::table('pro_penawaran')->where('id_penawaran', self::PNW_BM)->update(['other_cost' => 60]);

        $this->as(self::BM_CAB5);
        $detail = $this->getJson('/api/v1/penawaran/' . self::PNW_BM)->assertOk();
        $this->assertTrue($detail->json('decision_form.forward_locked'));

        $this->postJson('/api/v1/penawaran/' . self::PNW_BM . '/keputusan', ['keputusan' => 'approve', 'teruskan' => false])
            ->assertOk()->assertJsonPath('next_stage', 6);

        $r = $this->row(self::PNW_BM);
        $this->assertSame(6, (int) $r->flag_disposisi);
        $this->assertSame(0, (int) $r->flag_approval);
    }

    public function test_tolak_wajib_alasan(): void
    {
        $this->as(self::BM_CAB5);
        $this->postJson('/api/v1/penawaran/' . self::PNW_BM . '/keputusan', ['keputusan' => 'reject'])
            ->assertStatus(422)->assertJsonPath('code', 'NOTE_REQUIRED');
        $this->assertSame(0, (int) $this->row(self::PNW_BM)->flag_approval);
    }

    public function test_tolak_final_menyembunyikan_penawaran(): void
    {
        $this->as(self::BM_CAB5);
        $this->postJson('/api/v1/penawaran/' . self::PNW_BM . '/keputusan', ['keputusan' => 'reject', 'catatan' => 'Harga terlalu rendah'])
            ->assertOk()->assertJsonPath('final', true);

        $r = $this->row(self::PNW_BM);
        $this->assertSame(2, (int) $r->flag_approval);
        $this->assertSame(2, (int) $r->sm_wil_result);
        $this->assertSame('No', $r->view);
    }

    public function test_keputusan_ganda_ditolak_409(): void
    {
        $this->as(self::BM_CAB5);
        $url = '/api/v1/penawaran/' . self::PNW_BM . '/keputusan';
        $this->postJson($url, ['keputusan' => 'approve'])->assertOk();
        $this->postJson($url, ['keputusan' => 'approve'])->assertStatus(409);
    }

    public function test_om_tier_iii_default_diteruskan_ke_ceo(): void
    {
        $this->as(self::OM);
        $detail = $this->getJson('/api/v1/penawaran/' . self::PNW_OM_T3)->assertOk();
        $this->assertTrue($detail->json('decision_form.forward_default'));

        // klien tidak mengirim 'teruskan' => ikuti default web lama (tier III => CEO)
        $this->postJson('/api/v1/penawaran/' . self::PNW_OM_T3 . '/keputusan', ['keputusan' => 'approve'])
            ->assertOk()->assertJsonPath('next_stage', 6);
        $this->assertSame(6, (int) $this->row(self::PNW_OM_T3)->flag_disposisi);
    }

    public function test_ceo_selalu_final(): void
    {
        DB::table('pro_penawaran')->where('id_penawaran', self::PNW_OM_T3)->update(['flag_disposisi' => 6]);

        $this->as(self::CEO);
        $this->postJson('/api/v1/penawaran/' . self::PNW_OM_T3 . '/keputusan', ['keputusan' => 'approve', 'teruskan' => true])
            ->assertOk()->assertJsonPath('final', true);

        $r = $this->row(self::PNW_OM_T3);
        $this->assertSame(1, (int) $r->flag_approval);
        $this->assertSame(1, (int) $r->ceo_result);
    }

    public function test_pengajuan_harga_tanpa_data_baru_ditolak_tanpa_menulis_apa_pun(): void
    {
        DB::table('pro_penawaran')->where('id_penawaran', self::PNW_BM)->update(['is_edit_harga' => 1]);
        $before = (array) $this->row(self::PNW_BM);

        $this->as(self::BM_CAB5);
        $this->postJson('/api/v1/penawaran/' . self::PNW_BM . '/keputusan', ['keputusan' => 'approve'])
            ->assertStatus(422)->assertJsonPath('code', 'HARGA_BARU_MISSING');

        $this->assertEquals($before, (array) $this->row(self::PNW_BM));
    }

    public function test_riwayat_menampilkan_keputusan_user(): void
    {
        $this->as(self::BM_CAB5);
        $this->postJson('/api/v1/penawaran/' . self::PNW_BM . '/keputusan', ['keputusan' => 'approve'])->assertOk();

        $res = $this->getJson('/api/v1/penawaran/riwayat?keputusan=1')->assertOk();
        $this->assertContains(self::PNW_BM, collect($res->json('data'))->pluck('id_penawaran'));
    }
    // ------------------------------------------------------------ detail lengkap

    public function test_detail_memuat_blok_seperti_web(): void
    {
        $this->as(self::BM_CAB5);
        $d = $this->getJson('/api/v1/penawaran/' . self::PNW_BM)->assertOk()
            ->assertJsonStructure([
                'penawaran', 'customer' => ['nama_customer', 'cabang_invoice', 'marketing', 'top', 'pic', 'telepon', 'alamat'],
                'pricelist' => ['masa_awal', 'masa_akhir', 'rekomendasi_harga_dasar', 'rekomendasi_oa', 'tiers'],
                'rincian', 'rincian_note', 'totals' => ['refund_total', 'other_total'],
                'other_costs', 'status_approval' => ['text', 'tone'], 'timeline', 'history_approval', 'other_penawaran',
            ]);

        $this->assertSame('CREDIT 35 hari Setelah Invoice diterima', $d->json('customer.top'));
        $this->assertSame('Verifikasi BM Pontianak', $d->json('status_approval.text'));
        $this->assertSame('wait', $d->json('status_approval.tone'));
        $this->assertSame(['Harga Tier I BM'], array_column($d->json('pricelist.tiers'), 'label')); // BM hanya tier I
        $ids = array_column($d->json('other_penawaran'), 'id_penawaran');
        $this->assertNotContains(self::PNW_BM, $ids);                        // penawaran ini tidak ikut daftar "lainnya"
        $this->assertLessThanOrEqual(9, count($ids));
    }

    public function test_tier_harga_mengikuti_peran(): void
    {
        $this->as(self::CEO);
        $labels = array_column($this->getJson('/api/v1/penawaran/' . self::PNW_BM)->json('pricelist.tiers'), 'label');
        $this->assertSame(['Harga Tier I BM', 'Harga Tier II OM', 'Harga Tier III COO', 'Harga Tier III CEO'], $labels);

        $this->as(self::OM);
        $labels = array_column($this->getJson('/api/v1/penawaran/' . self::PNW_BM)->json('pricelist.tiers'), 'label');
        $this->assertSame(['Harga Tier I BM', 'Harga Tier II OM'], $labels);
    }

    public function test_riwayat_approval_dikelompokkan_per_pengajuan_dan_status_berubah(): void
    {
        $this->as(self::BM_CAB5);
        $this->postJson('/api/v1/penawaran/' . self::PNW_BM . '/keputusan', ['keputusan' => 'approve', 'catatan' => 'Siap'])->assertOk();

        $d = $this->getJson('/api/v1/penawaran/' . self::PNW_BM)->assertOk();
        $this->assertCount(1, $d->json('history_approval'));
        $a = $d->json('history_approval.0.approvals.0');
        $this->assertSame('Disetujui', $a['hasil']);
        $this->assertSame('Siap', $a['catatan']);
        $this->assertSame('Branch Manager', $a['peran']);                    // awalan "Role " dibuang
        $this->assertSame('Disetujui BM Pontianak', $d->json('status_approval.text'));
        $this->assertSame('ok', $d->json('status_approval.tone'));
    }

    public function test_total_refund_dan_other_cost_menandai_batas(): void
    {
        DB::table('pro_penawaran')->where('id_penawaran', self::PNW_BM)->update(['refund_tawar' => 200, 'other_cost' => 10]);
        $this->as(self::BM_CAB5);
        $t = $this->getJson('/api/v1/penawaran/' . self::PNW_BM)->json('totals');

        $this->assertEquals(200 * 7000, $t['refund_total']);
        $this->assertTrue($t['refund_over']);        // 1.400.000 > 1.000.000
        $this->assertFalse($t['other_over']);        // 70.000
    }
    // ------------------------------------------------ daftar informatif & ringkasan

    public function test_daftar_bisa_diurut_terlama_dulu_dan_membawa_penanda_peringatan(): void
    {
        DB::table('pro_penawaran')->where('id_penawaran', self::PNW_BM)->update(['other_cost' => 60]);

        $this->as(self::BM_CAB5);
        $asc  = collect($this->getJson('/api/v1/penawaran?sort=asc')->assertOk()->json('data'));
        $desc = collect($this->getJson('/api/v1/penawaran?sort=desc')->json('data'));

        $this->assertSame($asc->pluck('id_penawaran')->all(), $desc->pluck('id_penawaran')->reverse()->values()->all());
        $row = $asc->firstWhere('id_penawaran', self::PNW_BM);
        $this->assertGreaterThanOrEqual(1, $row['peringatan']);                       // other cost 60 > 50
        $this->assertArrayNotHasKey('detail_rincian', $row);                          // rincian tidak bocor ke daftar
        $this->assertSame(0, $asc->firstWhere('id_penawaran', 59971)['peringatan']);  // yang lain bersih
    }

    public function test_ringkasan_menghitung_menunggu_terlama_dan_keputusan_7_hari(): void
    {
        $this->as(self::BM_CAB5);
        $r = $this->getJson('/api/v1/penawaran/ringkasan')->assertOk();
        $this->assertSame(3, $r->json('menunggu'));
        $this->assertNotNull($r->json('terlama'));

        $this->postJson('/api/v1/penawaran/' . self::PNW_BM . '/keputusan', ['keputusan' => 'approve'])->assertOk();
        $this->postJson('/api/v1/penawaran/59971/keputusan', ['keputusan' => 'reject', 'catatan' => 'x'])->assertOk();

        $r = $this->getJson('/api/v1/penawaran/ringkasan')->assertOk();
        $this->assertSame(1, $r->json('menunggu'));
        $this->assertSame(1, $r->json('disetujui_7hari'));
        // penolakan BM juga mencatat riwayat P001 (aturan web lama)
        $this->assertSame(1, $r->json('ditolak_7hari'));
    }

    public function test_price_check_membandingkan_harga_dasar_dengan_tier(): void
    {
        $this->as(self::BM_CAB5);
        $pc = $this->getJson('/api/v1/penawaran/' . self::PNW_BM)->assertOk()->json('price_check');

        $this->assertEquals(10750, $pc['harga_dasar']);                 // komponen "Harga Dasar" di rincian
        $this->assertSame('Harga Tier I BM', $pc['ringkas']['label']);
        $this->assertEquals(12000, $pc['ringkas']['nilai']);
        $this->assertEquals(-1250, $pc['ringkas']['selisih']);          // 10.750 - 12.000
        $this->assertTrue($pc['ringkas']['di_bawah']);

        $this->as(self::CEO);
        $labels = array_column($this->getJson('/api/v1/penawaran/' . self::PNW_BM)->json('price_check.items'), 'label');
        // CEO berhak melihat 4 tier, tetapi tier yang harganya belum diisi (0) tidak dibandingkan:
        // di data uji tier III CEO = 0, jadi hanya 3 yang muncul.
        $this->assertSame(['Harga Tier I BM', 'Harga Tier II OM', 'Harga Tier III COO'], $labels);
    }
}
