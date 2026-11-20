<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PoSupplierLossGainTest extends TestCase
{
    use DatabaseTransactions;

    private int $po;

    private function siap(float $bol = 1000, float $terima = 1010, ?int $accurate = null): void
    {
        Sanctum::actingAs(AclUser::where('id_role', 5)->where('is_active', 1)->firstOrFail(), ['*']);
        $this->po = (int) DB::table('new_pro_inventory_vendor_po')->where('ceo_result', 1)->where('harga_tebus', '>', 0)->value('id_master');
        DB::table('new_pro_inventory_vendor_po')->where('id_master', $this->po)->update([
            'revert_ceo' => 0, 'is_close' => 0, 'is_cancel' => 0, 'volume_po' => 1000, 'harga_tebus' => 10000, 'kd_tax' => 'EC', 'nilai_pbbkb' => 2, 'id_accurate' => $accurate, 'disposisi_po' => 4,
        ]);
        DB::table('new_pro_inventory_vendor_po_receive')->where('id_po_supplier', $this->po)->delete();
        DB::table('new_pro_inventory_gain_loss')->where('id_po_supplier', $this->po)->delete();
        DB::table('new_pro_inventory_vendor_po_receive')->insert([
            'id_po_receive' => 209901000090001, 'id_po_supplier' => $this->po, 'id_accurate' => $accurate ? 321 : null, 'no_terima' => 'RI/LG/1', 'nama_pic' => 'Uji', 'tgl_terima' => date('Y-m-d'),
            'volume_bol' => $bol, 'volume_terima' => $terima, 'harga_tebus' => 10000, 'file_upload' => '', 'file_upload_ori' => '', 'created_time' => now(), 'created_ip' => '1', 'created_by' => 'uji',
        ]);
    }

    private function isi(array $o = []): array
    {
        return array_merge(['jenis' => 1, 'ket' => 'catatan PO', 'ket_loss_gain' => 'selisih BL'], $o);
    }

    public function test_hanya_purchasing(): void
    {
        Sanctum::actingAs(AclUser::where('id_role', 21)->where('is_active', 1)->firstOrFail(), ['*']);
        $this->getJson('/api/v1/po-supplier/1/loss-gain')->assertForbidden();
    }

    public function test_rumus_form_seperti_web(): void
    {
        $this->siap();
        $f = $this->getJson("/api/v1/po-supplier/{$this->po}/loss-gain")->assertOk()->json();
        // RI 1010 > BL 1000: harga RI = BL*harga/RI
        $this->assertEqualsWithDelta(1000 * 10000 / 1010, $f['harga_ri'], 0.0001);
        $this->assertEqualsWithDelta(1010 * $f['harga_ri'], $f['subtotal'], 0.01);
        $this->assertEqualsWithDelta($f['subtotal'] * 0.11, $f['ppn_11'], 0.0001);
        $this->assertEqualsWithDelta($f['subtotal'] * 0.003, $f['pph_22'], 0.0001);
        $this->assertEqualsWithDelta($f['subtotal'] * 0.02, $f['pbbkb'], 0.0001);
        $this->assertSame(10.0, (float) $f['volume_loss_gain']);
        $this->assertSame(1, $f['jenis_default']);
    }

    public function test_tidak_bisa_bila_volume_sama_atau_terkunci(): void
    {
        $this->siap(1000, 1000);
        $this->getJson("/api/v1/po-supplier/{$this->po}/loss-gain")->assertStatus(409)->assertJsonPath('code', 'TIDAK_PERLU');
        $this->siap();
        DB::table('new_pro_inventory_vendor_po')->where('id_master', $this->po)->update(['is_close' => 1]);
        $this->postJson("/api/v1/po-supplier/{$this->po}/loss-gain", $this->isi())->assertStatus(409)->assertJsonPath('code', 'PO_TERKUNCI');
    }

    public function test_gain_tanpa_accurate_memperbarui_po_dan_kembali_ke_verifikasi(): void
    {
        $this->siap();
        $f = $this->getJson("/api/v1/po-supplier/{$this->po}/loss-gain")->json();
        $this->postJson("/api/v1/po-supplier/{$this->po}/loss-gain", $this->isi(['ket_loss_gain' => '']))->assertStatus(422);
        $this->postJson("/api/v1/po-supplier/{$this->po}/loss-gain", $this->isi())->assertCreated();
        $po = DB::table('new_pro_inventory_vendor_po')->where('id_master', $this->po)->first();
        $this->assertSame(1, (int) $po->disposisi_po);
        $this->assertSame(0, (int) $po->ceo_result);
        $this->assertEqualsWithDelta(10000.0, (float) $po->harga_po, 0.001);
        $this->assertEqualsWithDelta($f['harga_ri'], (float) $po->harga_tebus, 0.0001);
        $this->assertEqualsWithDelta($f['total_order'], (float) $po->total_order, 0.0001);
        $this->assertSame(1010.0, (float) $po->volume_ri);
        $this->assertDatabaseHas('new_pro_inventory_gain_loss', ['id_po_supplier' => $this->po, 'jenis' => 1, 'volume' => 10, 'disposisi_gain_loss' => 1]);
    }

    public function test_loss_wajib_akun_dan_mencatat_penyesuaian_stok(): void
    {
        $this->siap(1000, 990, 555);
        $u = "/api/v1/po-supplier/{$this->po}/loss-gain";
        $this->postJson($u, $this->isi(['jenis' => 2]))->assertStatus(422); // akun + nomor wajib
        // Accurate nonaktif: stok tetap dicatat, PO tidak berubah
        $r = $this->postJson($u, $this->isi(['jenis' => 2, 'akun_penyesuaian' => '5101', 'nomor_penyesuaian' => 'ADJ/1']))->assertCreated()->json();
        $this->assertTrue($r['accurate_manual']);
        $this->assertDatabaseHas('new_pro_inventory_depot', ['id_po_supplier' => $this->po, 'id_jenis' => 3, 'adj_inven' => -10]);
        $this->assertSame(4, (int) DB::table('new_pro_inventory_vendor_po')->where('id_master', $this->po)->value('disposisi_po'));
        $this->assertDatabaseHas('new_pro_inventory_gain_loss', ['id_po_supplier' => $this->po, 'jenis' => 2, 'volume' => -10]);
    }

    public function test_loss_accurate_sukses_dan_gagal_rollback(): void
    {
        $this->siap(1000, 990, 555);
        config(['accurate.enabled' => true, 'accurate.token' => 't', 'accurate.secret' => 's']);
        Http::fake([
            '*purchase-order/save.do' => Http::response(['s' => true, 'r' => ['id' => 555]]),
            '*purchase-order/detail.do*' => Http::response(['s' => true, 'd' => ['detailItem' => [['item' => ['no' => 'ITM-9', 'itemType' => 'INVENTORY']]]]]),
            '*item-adjustment/save.do' => Http::sequence()->push(['s' => true, 'r' => ['id' => 4242]])->push(['s' => false, 'd' => ['Akun tidak valid']]),
        ]);
        $u = "/api/v1/po-supplier/{$this->po}/loss-gain";
        $this->postJson($u, $this->isi(['jenis' => 2, 'akun_penyesuaian' => '5101', 'nomor_penyesuaian' => 'ADJ/1']))->assertCreated();
        $this->assertSame(4242, (int) DB::table('new_pro_inventory_depot')->where('id_po_supplier', $this->po)->where('id_jenis', 3)->value('id_accurate'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'item-adjustment/save.do') && $r['detailItem'][0]['itemAdjustmentType'] === 'ADJUSTMENT_OUT' && $r['detailItem'][0]['itemNo'] === 'ITM-9' && $r['detailItem'][0]['quantity'] == 10);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'purchase-order/save.do') && ($r['closeReason'] ?? '') === 'Close PO - loss');

        $this->postJson($u, $this->isi(['jenis' => 2, 'akun_penyesuaian' => '9', 'nomor_penyesuaian' => 'ADJ/2']))->assertStatus(502)->assertJsonPath('message', 'Akun tidak valid - Response dari Accurate (adjustment stock)');
        $this->assertSame(1, DB::table('new_pro_inventory_gain_loss')->where('id_po_supplier', $this->po)->count());
        $this->assertSame(1, DB::table('new_pro_inventory_depot')->where('id_po_supplier', $this->po)->where('id_jenis', 3)->count());
    }

    public function test_gain_accurate_membuat_ulang_po_dan_ri(): void
    {
        $this->siap(1000, 1010, 555);
        config(['accurate.enabled' => true, 'accurate.token' => 't', 'accurate.secret' => 's']);
        DB::table('pro_master_vendor')->where('id_master', DB::table('new_pro_inventory_vendor_po')->where('id_master', $this->po)->value('id_vendor'))->update(['kode_vendor' => 'V-UJI']);
        $item = ['item' => ['no' => 'ITM-1', 'itemType' => 'INVENTORY'], 'quantity' => 1000, 'unitPrice' => 10000, 'useTax1' => true, 'warehouse' => ['name' => 'PTK']];
        Http::fake([
            '*receive-item/delete.do' => Http::response(['s' => true]),
            '*purchase-order/detail.do*' => Http::response(['s' => true, 'd' => ['detailItem' => [$item], 'detailExpense' => []]]),
            '*purchase-order/save.do' => Http::sequence()
                ->push(['s' => true, 'r' => ['id' => 555]])
                ->push(['s' => true, 'r' => ['id' => 900, 'number' => 'X.', 'vendor' => ['vendorNo' => 'V-UJI'], 'detailItem' => [$item, $item]]])
                ->push(['s' => true, 'r' => ['id' => 900]]),
            '*receive-item/save.do' => Http::response(['s' => true, 'r' => ['id' => 901]]),
        ]);
        $this->postJson("/api/v1/po-supplier/{$this->po}/loss-gain", $this->isi())->assertCreated();
        $this->assertSame(900, (int) DB::table('new_pro_inventory_vendor_po')->where('id_master', $this->po)->value('id_accurate'));
        $this->assertSame(901, (int) DB::table('new_pro_inventory_vendor_po_receive')->where('id_po_supplier', $this->po)->value('id_accurate'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'receive-item/delete.do') && $r['id'] == 321);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'purchase-order/save.do') && isset($r['detailItem']) && count($r['detailItem']) === 2 && str_contains((string) $r['number'], '.') && $r['detailItem'][1]['quantity'] == 10);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'receive-item/save.do') && count($r['detailItem']) === 2);
    }

    public function test_gain_accurate_gagal_hapus_ri_membatalkan_semua(): void
    {
        $this->siap(1000, 1010, 555);
        config(['accurate.enabled' => true, 'accurate.token' => 't', 'accurate.secret' => 's']);
        Http::fake([
            '*purchase-order/save.do' => Http::response(['s' => true, 'r' => ['id' => 555]]),
            '*receive-item/delete.do' => Http::response(['s' => false, 'd' => ['RI sudah dipakai']]),
        ]);
        $this->postJson("/api/v1/po-supplier/{$this->po}/loss-gain", $this->isi())->assertStatus(502)->assertJsonPath('message', 'RI sudah dipakai - Response dari Accurate');
        $this->assertSame(4, (int) DB::table('new_pro_inventory_vendor_po')->where('id_master', $this->po)->value('disposisi_po'));
        $this->assertSame(0, DB::table('new_pro_inventory_gain_loss')->where('id_po_supplier', $this->po)->count());
    }

    public function test_lampiran_pdf(): void
    {
        $this->siap();
        $dasar = sys_get_temp_dir().'/orca-lg-'.uniqid();
        mkdir($dasar.'/files/uploaded_user', 0775, true);
        config(['orca.web_files' => $dasar]);
        $this->post("/api/v1/po-supplier/{$this->po}/loss-gain", $this->isi() + ['file_template' => UploadedFile::fake()->create('bol.pdf', 30, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $g = DB::table('new_pro_inventory_gain_loss')->where('id_po_supplier', $this->po)->first();
        $this->assertFileExists($dasar.'/files/uploaded_user/lampiran/'.$g->file_upload);
        $this->get("/api/v1/po-supplier/{$this->po}/loss-gain/{$g->id_master}/lampiran")->assertOk();
        $this->siap();
        $this->post("/api/v1/po-supplier/{$this->po}/loss-gain", $this->isi() + ['file_template' => UploadedFile::fake()->create('x.exe', 3)], ['Accept' => 'application/json'])->assertStatus(422);
    }
}
