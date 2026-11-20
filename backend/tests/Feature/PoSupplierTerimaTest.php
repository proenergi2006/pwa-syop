<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PoSupplierTerimaTest extends TestCase
{
    use DatabaseTransactions;

    private int $po;

    private function siap(): void
    {
        Sanctum::actingAs(AclUser::where('id_role', 5)->where('is_active', 1)->firstOrFail(), ['*']);
        $this->po = (int) DB::table('new_pro_inventory_vendor_po')->where('ceo_result', 1)->where('harga_tebus', '>', 0)->value('id_master');
        DB::table('new_pro_inventory_vendor_po')->where('id_master', $this->po)->update(['revert_ceo' => 0, 'is_close' => 0, 'is_cancel' => 0, 'volume_po' => 1000, 'id_accurate' => 555]);
        DB::table('new_pro_inventory_vendor_po_receive')->where('id_po_supplier', $this->po)->delete();
        DB::table('new_pro_inventory_depot')->where('id_po_supplier', $this->po)->delete();
    }

    private function isi(array $o = []): array
    {
        return array_merge(['tgl_terima' => date('Y-m-d'), 'no_terima' => 'RI/UJI/1', 'volume_bol' => 400, 'volume_terima' => 398, 'nama_pic' => 'Budi'], $o);
    }

    public function test_hanya_purchasing(): void
    {
        foreach ([21, 7] as $role) {
            Sanctum::actingAs(AclUser::where('id_role', $role)->where('is_active', 1)->firstOrFail(), ['*']);
            $this->getJson('/api/v1/po-supplier/1/terima')->assertForbidden();
            $this->postJson('/api/v1/po-supplier/1/terima', $this->isi())->assertForbidden();
        }
    }

    public function test_tambah_ubah_hapus_tanpa_accurate(): void
    {
        $this->siap();
        $f = $this->getJson("/api/v1/po-supplier/{$this->po}/terima/form")->assertOk()->json();
        $this->assertSame(1000.0, (float) $f['volume_terima']);
        $this->assertTrue($this->getJson("/api/v1/po-supplier/{$this->po}/terima")->json('bisa_tambah'));

        $id = $this->postJson("/api/v1/po-supplier/{$this->po}/terima", $this->isi())->assertCreated()->json('id');
        $this->assertDatabaseHas('new_pro_inventory_depot', ['id_po_receive' => $id, 'id_jenis' => 21, 'in_inven' => 398, 'id_datanya' => 'generated_po']);
        $this->assertDatabaseHas('new_pro_inventory_harga_tebus', ['id_po_receive' => $id]);
        $l = $this->getJson("/api/v1/po-supplier/{$this->po}/terima")->json();
        $this->assertSame(398.0, (float) $l['total_terima']);
        $this->assertTrue($l['ri'][0]['bisa_ubah']);

        $this->putJson("/api/v1/po-supplier/{$this->po}/terima/$id", $this->isi(['volume_terima' => 300]))->assertStatus(422); // catatan edit wajib
        $this->putJson("/api/v1/po-supplier/{$this->po}/terima/$id", $this->isi(['volume_terima' => 300, 'ket_updated' => 'koreksi']))->assertOk();
        $this->assertDatabaseHas('new_pro_inventory_depot', ['id_po_receive' => $id, 'id_jenis' => 21, 'in_inven' => 300]);
        $this->assertSame(300.0, (float) DB::table('new_pro_inventory_vendor_po_receive')->where('id_po_receive', $id)->value('volume_terima'));

        $this->deleteJson("/api/v1/po-supplier/{$this->po}/terima/$id")->assertOk();
        $this->assertDatabaseMissing('new_pro_inventory_vendor_po_receive', ['id_po_receive' => $id]);
        $this->assertDatabaseMissing('new_pro_inventory_depot', ['id_po_receive' => $id]);
    }

    public function test_validasi_dan_aturan(): void
    {
        $this->siap();
        $u = "/api/v1/po-supplier/{$this->po}/terima";
        $this->postJson($u, $this->isi(['nama_pic' => '']))->assertStatus(422);
        $this->postJson($u, $this->isi(['tgl_terima' => '09/06/2026']))->assertStatus(422);
        $awal = DB::table('new_pro_inventory_depot as d')->join(DB::raw('(select id_terminal from new_pro_inventory_vendor_po where id_master = '.$this->po.') p'), 'p.id_terminal', '=', 'd.id_terminal')->where('d.id_jenis', 1)->value('d.tanggal_inven');
        if ($awal) {
            $this->postJson($u, $this->isi(['tgl_terima' => date('Y-m-d', strtotime($awal.' -1 day'))]))->assertStatus(422)->assertJsonPath('code', 'TANGGAL');
        }
        $this->postJson($u, $this->isi(['volume_terima' => 1000]))->assertCreated();
        $this->postJson($u, $this->isi())->assertStatus(409)->assertJsonPath('code', 'PENUH');
        DB::table('new_pro_inventory_vendor_po')->where('id_master', $this->po)->update(['is_close' => 1]);
        $this->postJson($u, $this->isi())->assertStatus(409)->assertJsonPath('code', 'PO_TERKUNCI');
        $this->assertFalse($this->getJson($u)->json('ri.0.bisa_ubah'));
    }

    public function test_hapus_ditolak_bila_stok_terpakai(): void
    {
        $this->siap();
        $id = $this->postJson("/api/v1/po-supplier/{$this->po}/terima", $this->isi())->json('id');
        $depot = (array) DB::table('new_pro_inventory_depot')->where('id_po_receive', $id)->first();
        unset($depot['id_master']);
        DB::table('new_pro_inventory_depot')->insert(array_merge($depot, ['id_datanya' => 'dr-uji', 'id_jenis' => 2, 'in_inven' => 0, 'out_inven' => 10]));
        $this->deleteJson("/api/v1/po-supplier/{$this->po}/terima/$id")->assertStatus(409)->assertJsonPath('code', 'STOK_TERPAKAI');
        $this->assertDatabaseHas('new_pro_inventory_vendor_po_receive', ['id_po_receive' => $id]);
    }

    public function test_accurate_sukses_dan_gagal(): void
    {
        $this->siap();
        config(['accurate.enabled' => true, 'accurate.token' => 't', 'accurate.secret' => 's']);
        Http::fake([
            '*purchase-order/detail.do*' => Http::response(['s' => true, 'd' => ['vendor' => ['vendorNo' => 'V1'], 'toAddress' => 'Jakarta', 'detailItem' => [['item' => ['no' => 'ITM-1'], 'unitPrice' => 12000]]]]),
            '*receive-item/save.do' => Http::sequence()->push(['s' => true, 'r' => ['id' => 777]])->push(['s' => false, 'd' => ['Nomor sudah dipakai']]),
            '*receive-item/delete.do' => Http::response(['s' => true]),
        ]);
        $u = "/api/v1/po-supplier/{$this->po}/terima";
        $id = $this->postJson($u, $this->isi())->assertCreated()->json('id');
        $this->assertSame(777, (int) DB::table('new_pro_inventory_vendor_po_receive')->where('id_po_receive', $id)->value('id_accurate'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'receive-item/save.do') && $r['detailItem'][0]['itemNo'] === 'ITM-1' && $r['detailItem'][0]['quantity'] == 400 && $r['receiveNumber'] === 'RI/UJI/1');
        $this->deleteJson("$u/$id")->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'receive-item/delete.do'));

        $this->postJson($u, $this->isi(['no_terima' => 'RI/UJI/2']))->assertStatus(502)->assertJsonPath('message', 'Nomor sudah dipakai - Response dari Accurate');
        $this->assertSame(0, DB::table('new_pro_inventory_vendor_po_receive')->where('id_po_supplier', $this->po)->count());
        $this->assertSame(0, DB::table('new_pro_inventory_depot')->where('id_po_supplier', $this->po)->count());
    }

    public function test_unggah_lampiran_tambah_ganti_hapus(): void
    {
        $this->siap();
        $dasar = sys_get_temp_dir().'/orca-web-'.uniqid();
        mkdir($dasar.'/files/uploaded_user', 0775, true);
        config(['orca.web_files' => $dasar]);
        $u = "/api/v1/po-supplier/{$this->po}/terima";
        $f1 = \Illuminate\Http\UploadedFile::fake()->create('bukti terima.pdf', 50, 'application/pdf');
        $id = $this->post($u, $this->isi() + ['file_template' => $f1], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $row = DB::table('new_pro_inventory_vendor_po_receive')->where('id_po_receive', $id)->first();
        $this->assertMatchesRegularExpression('#^\d{6}/terimaposupplier_'.$id.'_[0-9a-f]{32}\.pdf$#', $row->file_upload);
        $this->assertSame('bukti terima.pdf', $row->file_upload_ori);
        $this->assertFileExists($dasar.'/files/uploaded_user/lampiran/'.$row->file_upload);
        $this->get("$u/$id/lampiran")->assertOk();

        $f2 = \Illuminate\Http\UploadedFile::fake()->image('baru.png');
        $this->post("$u/$id", $this->isi(['ket_updated' => 'ganti', '_method' => 'PUT']) + ['file_template' => $f2], ['Accept' => 'application/json'])->assertOk();
        $baru = DB::table('new_pro_inventory_vendor_po_receive')->where('id_po_receive', $id)->value('file_upload');
        $this->assertNotSame($row->file_upload, $baru);
        $this->assertFileExists($dasar.'/files/uploaded_user/lampiran/'.$baru);
        $this->assertFileDoesNotExist($dasar.'/files/uploaded_user/lampiran/'.$row->file_upload);

        $this->deleteJson("$u/$id")->assertOk();
        $this->assertFileDoesNotExist($dasar.'/files/uploaded_user/lampiran/'.$baru);
    }

    public function test_lampiran_jenis_dan_ukuran_ditolak(): void
    {
        $this->siap();
        $u = "/api/v1/po-supplier/{$this->po}/terima";
        $this->post($u, $this->isi() + ['file_template' => \Illuminate\Http\UploadedFile::fake()->create('x.php', 5)], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post($u, $this->isi() + ['file_template' => \Illuminate\Http\UploadedFile::fake()->create('besar.pdf', 11000, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(0, DB::table('new_pro_inventory_vendor_po_receive')->where('id_po_supplier', $this->po)->count());
    }
}
