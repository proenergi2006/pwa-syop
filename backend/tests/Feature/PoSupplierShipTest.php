<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PoSupplierShipTest extends TestCase
{
    use DatabaseTransactions;

    private int $po;

    private function siap(int $jenisKirim = 2): void
    {
        Sanctum::actingAs(AclUser::where('id_role', 5)->where('is_active', 1)->firstOrFail(), ['*']);
        $this->po = (int) DB::table('new_pro_inventory_vendor_po')->where('ceo_result', 1)->where('harga_tebus', '>', 0)->value('id_master');
        DB::table('new_pro_inventory_vendor_po')->where('id_master', $this->po)->update(['revert_ceo' => 0, 'is_close' => 0, 'is_cancel' => 0, 'jenis_kirim' => $jenisKirim, 'volume_po' => 5000]);
        DB::table('new_pro_inventory_vendor_po_ship_req')->where('id_vendor_po', $this->po)->delete();
    }

    private function isi(array $o = []): array
    {
        $kapal = DB::table('pro_master_oa_kapal')->first();
        $terminal = (int) DB::table('pro_master_terminal')->where('is_active', 1)->value('id_master');

        return array_merge([
            'id_transportir' => (int) $kapal->id_transportir, 'tipe_kapal' => 2, 'id_vessel' => (int) $kapal->id_master, 'load_port' => $terminal, 'discharge_terminal_id' => $terminal,
            'volume' => 5000, 'tgl_etl_awal' => '2026-12-01', 'tgl_etl_akhir' => '2026-12-03', 'cargo' => 'High-speed Diesel (B40)', 'leadtime' => 3, 'country' => 'Indonesia',
            'freight' => 120.5, 'demurrage' => 1000000, 'losstype' => 'R3', 'loss' => 0.5, 'satuan' => 'GOV', 'shipper' => 'PT Shipper', 'signee_name' => 'PT Consignee', 'bl_ship' => 'BL on board',
        ], $o);
    }

    public function test_hanya_purchasing(): void
    {
        Sanctum::actingAs(AclUser::where('id_role', 21)->where('is_active', 1)->firstOrFail(), ['*']);
        $this->getJson('/api/v1/po-supplier/1/shipping')->assertForbidden();
        $this->postJson('/api/v1/po-supplier/1/shipping', [])->assertForbidden();
    }

    public function test_hanya_po_kapal(): void
    {
        $this->siap(1);
        $this->getJson("/api/v1/po-supplier/{$this->po}/shipping")->assertStatus(409)->assertJsonPath('code', 'BUKAN_KAPAL');
    }

    public function test_tambah_ubah_dan_cancel(): void
    {
        Mail::fake();
        $this->siap();
        $u = "/api/v1/po-supplier/{$this->po}/shipping";
        $s = $this->getJson($u)->assertOk()->json();
        $this->assertTrue($s['bisa_tambah']);
        $this->assertMatchesRegularExpression('#^\d{3}/PE-Purch/250/[IVX]+/\d{4}$#', $s['baru']['nomor_req']);
        $this->assertNotEmpty($s['opsi']['transportir']);

        $this->postJson($u, $this->isi(['freight' => 0]))->assertStatus(422);
        $this->postJson($u, $this->isi(['losstype' => 'X9']))->assertStatus(422);
        $this->postJson($u, $this->isi(['tgl_etl_akhir' => '2026-11-01']))->assertStatus(422);
        $this->postJson($u, $this->isi(['tipe_kapal' => 1]))->assertStatus(422); // TB wajib
        $r = $this->postJson($u, $this->isi())->assertCreated()->json();
        $row = DB::table('new_pro_inventory_vendor_po_ship_req')->where('id_master', $r['id'])->first();
        $this->assertSame($r['nomor_req'], $row->nomor_req);
        $this->assertSame('R3', $row->losstype);
        $this->assertSame(0, (int) $row->status);
        $this->assertSame('INDONESIA', $row->flag);
        $this->postJson($u, $this->isi())->assertStatus(409)->assertJsonPath('code', 'SUDAH_ADA');

        $s = $this->getJson($u)->json();
        $this->assertTrue($s['ada']);
        $this->assertTrue($s['bisa_ubah']);
        $this->assertFalse($s['bisa_cancel']);
        $this->assertCount(4, $s['request']['langkah']);
        // PO terdaftar punya tombol Shipping di daftar
        $this->assertContains('ship', $this->getJson("/api/v1/po-supplier/{$this->po}")->json('aksi'));

        // ubah mengulang proses dari awal
        DB::table('new_pro_inventory_vendor_po_ship_req')->where('id_master', $r['id'])->update(['cfo_result' => 2, 'nomor_si' => 'SI/1', 'log_pic' => 'Logistik']);
        $this->putJson("$u/{$r['id']}", $this->isi(['cargo' => 'Pertamax']))->assertOk();
        $row = DB::table('new_pro_inventory_vendor_po_ship_req')->where('id_master', $r['id'])->first();
        $this->assertSame('Pertamax', $row->cargo_name);
        $this->assertNull($row->nomor_si);
        $this->assertSame(0, (int) $row->cfo_result);
        $this->assertNull($row->log_pic);

        // sedang diproses: tidak bisa diubah; cancel hanya setelah status 4 / CEO setuju
        DB::table('new_pro_inventory_vendor_po_ship_req')->where('id_master', $r['id'])->update(['status' => 2]);
        $this->putJson("$u/{$r['id']}", $this->isi())->assertStatus(409)->assertJsonPath('code', 'TIDAK_BISA_UBAH');
        $this->postJson("$u/{$r['id']}/cancel", ['ket_cancel' => 'batal'])->assertStatus(409)->assertJsonPath('code', 'TIDAK_BISA_CANCEL');
        DB::table('new_pro_inventory_vendor_po_ship_req')->where('id_master', $r['id'])->update(['status' => 4, 'ceo_result' => 1]);
        $this->postJson("$u/{$r['id']}/cancel", [])->assertStatus(422);
        $this->postJson("$u/{$r['id']}/cancel", ['ket_cancel' => 'batal'])->assertOk();
        $this->assertSame(1, (int) DB::table('new_pro_inventory_vendor_po_ship_req')->where('id_master', $r['id'])->value('is_cancel'));
        $this->assertTrue($this->getJson($u)->json('bisa_tambah')); // setelah cancel bisa buat baru
    }

    public function test_daftar_kapal_per_transportir(): void
    {
        $this->siap();
        $k = DB::table('pro_master_oa_kapal')->first();
        $r = $this->getJson('/api/v1/po-supplier/kapal?transportir='.$k->id_transportir)->assertOk()->json('data');
        $this->assertContains((int) $k->id_master, array_column($r, 'id'));
    }

    public function test_cetak_shipping_instruction(): void
    {
        Mail::fake();
        $this->siap();
        $u = "/api/v1/po-supplier/{$this->po}/shipping";
        $id = $this->postJson($u, $this->isi(['tgl_etl_awal' => '2026-12-01', 'tgl_etl_akhir' => '2026-12-03']))->json('id');
        $this->getJson("$u/$id/cetak")->assertStatus(409)->assertJsonPath('code', 'BELUM_DISETUJUI');
        $this->assertFalse($this->getJson($u)->json('bisa_cetak'));
        DB::table('new_pro_inventory_vendor_po_ship_req')->where('id_master', $id)->update(['status' => 4, 'ceo_result' => 1]);
        $this->assertTrue($this->getJson($u)->json('bisa_cetak'));
        $c = $this->getJson("$u/$id/cetak")->assertOk()->json();
        $this->assertSame('01 - 03 Desember 2026', $c['laycan']);
        $this->assertSame('5,000 (GOV)', $c['quantity']);
        $this->assertSame('PT Shipper', $c['shipper']);
        $this->assertNotEmpty($c['kapal']);
        DB::table('new_pro_inventory_vendor_po_ship_req')->where('id_master', $id)->update(['etl_date_last' => '2026-12-01']);
        $this->assertSame('01 Desember 2026', $this->getJson("$u/$id/cetak")->json('laycan'));
    }
}
