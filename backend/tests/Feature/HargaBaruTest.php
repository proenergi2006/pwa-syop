<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Persetujuan pengajuan perubahan harga. Accurate selalu DITIRUKAN (Http::fake); tidak ada panggilan ke Accurate sungguhan. */
class HargaBaruTest extends TestCase
{
    use DatabaseTransactions;

    private const BM_CAB5 = 384;
    private const BM_CAB2 = 583;
    private const OM = 584;
    private const CEO = 122;
    private int $pnw = 59970;  // disposisi 3 (BM), cabang 5, tanpa peringatan, tanpa POC
    private int $bm = self::BM_CAB5;

    /** Penawaran cabang 2 yang punya POC dan satu rencana kirim ber-Sales Order Accurate. */
    private function useRich(): void
    {
        $this->pnw = 68828;
        $this->bm = self::BM_CAB2;
    }

    private function as(int $id): AclUser
    {
        $u = AclUser::findOrFail($id);
        Sanctum::actingAs($u, ['*']);

        return $u;
    }

    private function rincian(float $hd, float $oa = 650, float $ppn = 1452, float $pbbkb = 941): string
    {
        return json_encode([
            ['rinci' => 1, 'rincian' => 'Harga Dasar', 'nilai' => 0, 'biaya' => (string) $hd],
            ['rinci' => 1, 'rincian' => 'Ongkos Angkut', 'nilai' => 0, 'biaya' => (string) $oa],
            ['rinci' => 1, 'rincian' => 'PPN', 'nilai' => 11, 'biaya' => (string) $ppn],
            ['rinci' => 1, 'rincian' => 'PBBKB', 'nilai' => 7.5, 'biaya' => (string) $pbbkb],
        ]);
    }

    /** Buat pengajuan harga baru aktif untuk penawaran uji pada tahap tertentu. @return int id_request */
    private function pengajuan(int $stage = 3, array $baru = []): int
    {
        $p = DB::table('pro_penawaran')->where('id_penawaran', $this->pnw)->first();
        DB::table('pro_penawaran')->where('id_penawaran', $this->pnw)->update([
            'is_edit_harga' => 1, 'flag_disposisi' => $stage, 'flag_approval' => 0, 'tipe_penawaran' => 'SEMENTARA',
            'sm_wil_result' => null, 'om_result' => null, 'coo_result' => null, 'ceo_result' => null,
        ]);
        $idReq = DB::table('pro_penawaran_perubahan_harga_request')->insertGetId([
            'id_penawaran' => $this->pnw, 'pengajuan_ke' => 1, 'tier' => 'II', 'status_pengajuan' => 'WAITING_APPROVAL',
            'current_approval' => $stage === 3 ? 'BM' : ($stage === 4 ? 'OM' : 'CEO'), 'submitted_by' => 1, 'submitted_name' => 'Marketing Uji', 'submitted_time' => now(),
        ]);
        $snap = fn (string $tipe, array $o) => DB::table('pro_penawaran_perubahan_harga')->insert($o + [
            'id_request' => $idReq, 'id_penawaran' => $this->pnw, 'tipe' => $tipe, 'harga_dasar' => 21000, 'harga_asli' => 18000, 'detail_rincian' => $this->rincian(18000),
            'refund_tawar' => 0, 'other_cost' => 0, 'harga_tier' => 18000, 'tier' => 'II', 'created_by' => 'Marketing Uji', 'created_time' => now(),
            'pembulatan' => 1, 'gabung_oa' => 0, 'all_in' => 0, 'gabung_pbbkb' => 0, 'gabung_pbbkboa' => 0, 'catatan' => 'Penyesuaian harga',
        ]);
        $snap('LAMA', ['harga_dasar' => $p->harga_dasar, 'harga_asli' => $p->harga_asli, 'detail_rincian' => $p->detail_rincian, 'refund_tawar' => $p->refund_tawar, 'other_cost' => $p->other_cost, 'harga_tier' => (int) $p->harga_tier, 'tier' => $p->tier ?: 'II']);
        $snap('BARU', $baru + ['harga_dasar' => 20500, 'harga_asli' => 17500, 'detail_rincian' => $this->rincian(17500), 'harga_tier' => 17400]);

        return $idReq;
    }

    private function keputusan(string $k = 'approve', array $extra = [])
    {
        return $this->postJson('/api/v1/penawaran/'.$this->pnw.'/keputusan', ['keputusan' => $k, 'catatan' => 'Catatan uji'] + $extra);
    }

    public function test_detail_memuat_perbandingan_lama_dan_baru(): void
    {
        $this->pengajuan();
        $this->as($this->bm);
        $d = $this->getJson('/api/v1/penawaran/'.$this->pnw)->assertOk();
        $d->assertJsonPath('unsupported', false)->assertJsonPath('can_act', true)
            ->assertJsonPath('perubahan_harga.aktif', true)->assertJsonPath('perubahan_harga.posisi', 'BM')
            ->assertJsonPath('perubahan_harga.baru.harga_dasar', 20500)->assertJsonPath('perubahan_harga.baru.catatan', 'Penyesuaian harga');
        $this->assertNotEmpty($d->json('perubahan_harga.lama.rincian'));
        $this->assertCount(1, $d->json('perubahan_harga.riwayat'));
    }

    public function test_bm_meneruskan_ke_om_mencatat_p002_dan_posisi_request(): void
    {
        $id = $this->pengajuan();
        $this->as($this->bm);
        $this->keputusan('approve', ['teruskan' => true])->assertOk()->assertJsonPath('next_stage', 4);

        $this->assertSame('OM', DB::table('pro_penawaran_perubahan_harga_request')->where('id_request', $id)->value('current_approval'));
        $this->assertSame('WAITING_APPROVAL', DB::table('pro_penawaran_perubahan_harga_request')->where('id_request', $id)->value('status_pengajuan'));
        $h = DB::table('pro_penawaran_perubahan_harga_approval_history')->where('id_request', $id)->first();
        $this->assertSame('BM', $h->approval_level);
        $this->assertSame(1, (int) $h->approval_step);
        $this->assertSame(1, DB::table('pro_approval_hist')->where('id_penawaran', $this->pnw)->where('kd_approval', 'P002')->count());
        $this->assertEquals(59970 === $this->pnw ? DB::table('pro_penawaran')->where('id_penawaran', $this->pnw)->value('harga_dasar') : 0, DB::table('pro_penawaran_perubahan_harga')->where('id_request', $id)->where('tipe', 'LAMA')->value('harga_dasar')); // harga belum berubah
    }

    public function test_ceo_final_menerapkan_harga_baru(): void
    {
        $this->useRich();
        $id = $this->pengajuan(6);
        $poc = DB::table('pro_po_customer')->where('id_penawaran', $this->pnw)->count();
        $this->as(self::CEO);
        $this->keputusan('approve')->assertOk()->assertJsonPath('final', true);

        $p = DB::table('pro_penawaran')->where('id_penawaran', $this->pnw)->first();
        $this->assertEquals(20500, (float) $p->harga_dasar);
        $this->assertEquals(17500, (float) $p->harga_asli);
        $this->assertSame(1, (int) $p->flag_approval);
        $this->assertSame('FINAL', $p->tipe_penawaran);
        $this->assertSame('APPROVED', DB::table('pro_penawaran_perubahan_harga_request')->where('id_request', $id)->value('status_pengajuan'));
        $this->assertSame($poc, DB::table('pro_po_customer')->where('id_penawaran', $this->pnw)->where('harga_poc', 20500)->count());
        $this->assertSame('Marketing Uji', $p->lastupdate_by);
    }

    public function test_tolak_menandai_request_ditolak_dan_membuka_pengajuan_ulang(): void
    {
        $id = $this->pengajuan();
        $before = (float) DB::table('pro_penawaran')->where('id_penawaran', $this->pnw)->value('harga_dasar');
        $this->as($this->bm);
        $this->keputusan('reject')->assertOk();

        $this->assertSame('REJECTED', DB::table('pro_penawaran_perubahan_harga_request')->where('id_request', $id)->value('status_pengajuan'));
        $this->assertSame(0, (int) DB::table('pro_penawaran')->where('id_penawaran', $this->pnw)->value('is_edit_harga'));
        $this->assertEquals($before, (float) DB::table('pro_penawaran')->where('id_penawaran', $this->pnw)->value('harga_dasar')); // harga tidak berubah
        $this->assertSame(1, DB::table('pro_approval_hist')->where('id_penawaran', $this->pnw)->where('kd_approval', 'P002')->where('result', '2')->count());
    }

    public function test_ceo_final_menerapkan_other_cost_dari_pengajuan(): void
    {
        $id = $this->pengajuan(6, ['other_cost' => 150]);
        DB::table('pro_other_cost_detail')->insert(['id_penawaran' => $this->pnw, 'keterangan' => 'Lama', 'nominal' => 99, 'created_time' => now()]);
        DB::table('pro_other_cost_detail_perubahan_harga')->insert([
            ['id_request' => $id, 'id_penawaran' => $this->pnw, 'tipe' => 'BARU', 'keterangan' => 'Biaya A', 'nominal' => 100, 'created_time' => now()],
            ['id_request' => $id, 'id_penawaran' => $this->pnw, 'tipe' => 'BARU', 'keterangan' => 'Biaya B', 'nominal' => 50, 'created_time' => now()],
        ]);
        $this->as(self::CEO);
        $this->keputusan('approve')->assertOk();
        $this->assertSame(['Biaya A', 'Biaya B'], DB::table('pro_other_cost_detail')->where('id_penawaran', $this->pnw)->orderBy('id_master')->pluck('keterangan')->all());
        $this->assertSame('CEO', DB::table('pro_penawaran_perubahan_harga_approval_history')->where('id_request', $id)->value('approval_level'));
        $this->assertSame('APPROVED', DB::table('pro_penawaran_perubahan_harga_request')->where('id_request', $id)->value('status_pengajuan'));
    }

    public function test_penawaran_ber_invoice_tidak_bisa_disetujui_dan_tidak_menulis(): void
    {
        $this->useRich();
        $id = $this->pengajuan(6);
        $poc = DB::table('pro_po_customer')->where('id_penawaran', $this->pnw)->value('id_poc');
        $before = (float) DB::table('pro_penawaran')->where('id_penawaran', $this->pnw)->value('harga_dasar');
        $dsd = DB::table('pro_invoice_admin_detail')->orderByDesc('id_dsd')->value('id_dsd');
        if (! $poc || ! $dsd) {
            $this->markTestSkipped('Tidak ada data POC/invoice untuk simulasi.');
        }
        // Simulasi: ada pengiriman (DS detail) milik POC ini yang sudah masuk invoice.
        $row = (array) DB::table('pro_po_ds_detail')->where('id_dsd', $dsd)->first();
        if (! $row) {
            $this->markTestSkipped('DS detail untuk invoice tidak ditemukan.');
        }
        DB::table('pro_po_ds_detail')->where('id_dsd', $dsd)->update(['id_poc' => $poc]);
        $this->as(self::CEO);
        $this->keputusan('approve')->assertStatus(409)->assertJsonPath('code', 'HAS_INVOICE');
        $this->assertEquals($before, (float) DB::table('pro_penawaran')->where('id_penawaran', $this->pnw)->value('harga_dasar'));
        $this->assertSame('WAITING_APPROVAL', DB::table('pro_penawaran_perubahan_harga_request')->where('id_request', $id)->value('status_pengajuan'));
    }

    // ---- Accurate (ditiru)

    private function accurateOn(): void
    {
        config(['accurate.enabled' => true, 'accurate.token' => 'tok-uji', 'accurate.secret' => 'rahasia-uji', 'accurate.rate_window' => 0]);
        Http::preventStrayRequests();
    }

    private function soUji(): void
    {
        $plan = DB::table('pro_po_customer_plan as c')->join('pro_po_customer as b', 'b.id_poc', '=', 'c.id_poc')->where('b.id_penawaran', $this->pnw)->value('c.id_plan');
        if (! $plan) {
            $this->markTestSkipped('Penawaran uji tidak punya rencana kirim.');
        }
        DB::table('pro_po_customer_plan')->where('id_plan', $plan)->update(['status_plan' => 1, 'id_accurate' => 4242]);
    }

    public function test_final_memperbarui_harga_item_sales_order_accurate(): void
    {
        $this->useRich();
        $this->pengajuan(6);
        $this->soUji();
        $this->accurateOn();
        $B = 'https://zeus.accurate.id/accurate/api';
        Http::fake([
            $B.'/sales-order/detail.do*' => Http::response(['s' => true, 'd' => ['detailItem' => [
                ['id' => 1, 'item' => ['no' => 'HSD-1']], ['id' => 2, 'item' => ['no' => 'NS-001']], ['id' => 3, 'item' => ['no' => 'PBBKB']], ['id' => 4, 'item' => ['no' => 'NS-009']],
            ]]]),
            $B.'/sales-order/save.do' => Http::response(['s' => true, 'r' => ['id' => 4242]]),
        ]);
        $this->as(self::CEO);
        $this->keputusan('approve')->assertOk()->assertJsonPath('accurate_manual', false);

        Http::assertSent(function (HttpRequest $r) {
            if (! str_ends_with($r->url(), 'sales-order/save.do')) {
                return false;
            }
            $d = $r->data();
            $price = collect($d['detailItem'])->pluck('unitPrice', 'id')->all();
            // jenis penawaran penawaran uji menentukan susunan; yang pasti: id 4242, cabang terisi, dan PPN = 1452 (rincian baru)
            return (string) $d['id'] === '4242' && isset($d['branchName']) && count($price) === 4 && $price[4] == 1452;
        });
    }

    public function test_accurate_menolak_membatalkan_persetujuan(): void
    {
        $this->useRich();
        $id = $this->pengajuan(6);
        $this->soUji();
        $this->accurateOn();
        Http::fake(['https://zeus.accurate.id/accurate/api/sales-order/detail.do*' => Http::response(['s' => false, 'd' => ['Token tidak valid']])]);
        $before = (float) DB::table('pro_penawaran')->where('id_penawaran', $this->pnw)->value('harga_dasar');
        $this->as(self::CEO);
        $r = $this->keputusan('approve')->assertStatus(502)->assertJsonPath('code', 'ACCURATE');
        $this->assertStringContainsString('Token tidak valid - Detail Accurate SO ID 4242', $r->json('message'));
        $this->assertEquals($before, (float) DB::table('pro_penawaran')->where('id_penawaran', $this->pnw)->value('harga_dasar'));
        $this->assertSame('WAITING_APPROVAL', DB::table('pro_penawaran_perubahan_harga_request')->where('id_request', $id)->value('status_pengajuan'));
    }

    public function test_tanpa_accurate_aktif_menandai_perlu_ubah_manual(): void
    {
        $this->useRich();
        $this->pengajuan(6);
        $this->soUji();
        Http::preventStrayRequests();
        $this->as(self::CEO);
        $this->keputusan('approve')->assertOk()->assertJsonPath('accurate_manual', true);
        Http::assertNothingSent();
    }
}
