<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PoSupplierCloseTest extends TestCase
{
    use DatabaseTransactions;

    private int $po;

    private function siap(?int $accurate = null): void
    {
        Sanctum::actingAs(AclUser::where('id_role', 5)->where('is_active', 1)->firstOrFail(), ['*']);
        $this->po = (int) DB::table('new_pro_inventory_vendor_po')->where('ceo_result', 1)->where('harga_tebus', '>', 0)->value('id_master');
        DB::table('new_pro_inventory_vendor_po')->where('id_master', $this->po)->update(['revert_ceo' => 0, 'is_close' => 0, 'is_cancel' => 0, 'volume_po' => 1000, 'id_accurate' => $accurate, 'volume_close' => 0]);
        DB::table('new_pro_inventory_vendor_po_receive')->where('id_po_supplier', $this->po)->delete();
        DB::table('new_pro_inventory_vendor_po_receive')->insert(['id_po_receive' => 209901000093001, 'id_po_supplier' => $this->po, 'no_terima' => 'X', 'nama_pic' => 'u', 'tgl_terima' => '2026-11-20',
            'volume_bol' => 700, 'volume_terima' => 700, 'harga_tebus' => 1, 'file_upload' => '', 'file_upload_ori' => '', 'created_time' => now(), 'created_ip' => '1', 'created_by' => 'u']);
    }

    public function test_hanya_purchasing(): void
    {
        Sanctum::actingAs(AclUser::where('id_role', 21)->where('is_active', 1)->firstOrFail(), ['*']);
        $this->getJson('/api/v1/po-supplier/1/close')->assertForbidden();
        $this->postJson('/api/v1/po-supplier/1/close', [])->assertForbidden();
    }

    public function test_close_tanpa_accurate(): void
    {
        $this->siap();
        $f = $this->getJson("/api/v1/po-supplier/{$this->po}/close")->assertOk()->json();
        $this->assertSame(300.0, (float) $f['volume_close']);
        $this->postJson("/api/v1/po-supplier/{$this->po}/close", [])->assertStatus(422);
        $this->postJson("/api/v1/po-supplier/{$this->po}/close", ['tanggal' => '20/11/2026'])->assertStatus(422);
        $this->postJson("/api/v1/po-supplier/{$this->po}/close", ['tanggal' => '2026-11-21'])->assertOk();
        $po = DB::table('new_pro_inventory_vendor_po')->where('id_master', $this->po)->first();
        $this->assertSame(1, (int) $po->is_close);
        $this->assertSame('2026-11-21', $po->tanggal_close);
        $this->assertSame(300, (int) $po->volume_close);
        $this->getJson("/api/v1/po-supplier/{$this->po}/close")->assertStatus(409)->assertJsonPath('code', 'TIDAK_BISA_CLOSE');
        $this->assertSame(['lihat', 'ri', 'cetak_penuh'], $this->getJson("/api/v1/po-supplier/{$this->po}")->json('aksi'));
    }

    public function test_belum_disetujui_tidak_bisa_close(): void
    {
        $this->siap();
        DB::table('new_pro_inventory_vendor_po')->where('id_master', $this->po)->update(['ceo_result' => 0]);
        $this->postJson("/api/v1/po-supplier/{$this->po}/close", ['tanggal' => '2026-11-21'])->assertStatus(409);
    }

    public function test_close_accurate_sukses_dan_gagal(): void
    {
        $this->siap(555);
        config(['accurate.enabled' => true, 'accurate.token' => 't', 'accurate.secret' => 's']);
        Http::fake(['*purchase-order/save.do' => Http::sequence()->push(['s' => false, 'd' => ['PO sudah diproses']])->push(['s' => true, 'r' => ['id' => 555]])]);
        $u = "/api/v1/po-supplier/{$this->po}/close";
        $this->postJson($u, ['tanggal' => '2026-11-21'])->assertStatus(502)->assertJsonPath('message', 'PO sudah diproses - Response dari Accurate');
        $this->assertSame(0, (int) DB::table('new_pro_inventory_vendor_po')->where('id_master', $this->po)->value('is_close'));
        $this->postJson($u, ['tanggal' => '2026-11-21'])->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'purchase-order/save.do') && $r['id'] == 555 && $r['manualClosed'] === true && $r['closeReason'] === 'CLOSE PO 21/11/2026 - volume close = 300');
        $this->assertSame(1, (int) DB::table('new_pro_inventory_vendor_po')->where('id_master', $this->po)->value('is_close'));
    }
}
