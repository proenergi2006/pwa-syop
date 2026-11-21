<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PoSupplierFormTest extends TestCase
{
    use DatabaseTransactions;

    private function purch(): void
    {
        Sanctum::actingAs(AclUser::where('id_role', 5)->where('is_active', 1)->firstOrFail(), ['*']);
    }

    private function dasar(): array
    {
        $vendor = (int) DB::table('pro_master_vendor')->where('is_active', 1)->whereNotNull('inisial_vendor')->value('id_master');
        DB::table('pro_master_vendor')->where('id_master', $vendor)->update(['inisial_vendor' => 'UJI', 'kode_vendor' => 'V-UJI']);

        return [
            'tanggal' => '2026-11-20', 'id_produk' => (int) DB::table('pro_master_produk')->where('is_active', 1)->value('id_master'), 'id_vendor' => $vendor,
            'id_terminal' => (int) DB::table('pro_master_terminal')->where('is_active', 1)->value('id_master'), 'jenis_kirim' => 1, 'terms' => 'NET', 'terms_day' => 14, 'kd_tax' => 'E',
            'volume_po' => 10000, 'harga_dasar' => 10000, 'kategori_oa' => 1, 'pbbkb_tawar' => 0, 'ket' => 'Catatan PO uji',
        ];
    }

    public function test_hanya_purchasing(): void
    {
        foreach ([21, 7] as $role) {
            Sanctum::actingAs(AclUser::where('id_role', $role)->where('is_active', 1)->firstOrFail(), ['*']);
            $this->getJson('/api/v1/po-supplier/form-opsi')->assertForbidden();
            $this->postJson('/api/v1/po-supplier', [])->assertForbidden();
            $this->postJson('/api/v1/po-supplier/hitung', [])->assertForbidden();
        }
    }

    public function test_opsi_dan_rumus_hitung(): void
    {
        $this->purch();
        $o = $this->getJson('/api/v1/po-supplier/form-opsi')->assertOk()->json();
        $this->assertNotEmpty($o['produk']);
        $this->assertNotEmpty($o['terminal']);
        $this->assertNotEmpty($o['vendor']);
        // Tanpa OA, EC, PBBKB 5%: sub 100jt, PPN 11jt, PPh 0,3% = 300rb, PBBKB 5jt
        $h = $this->postJson('/api/v1/po-supplier/hitung', ['volume_po' => 10000, 'harga_dasar' => 10000, 'kategori_oa' => 1, 'kd_tax' => 'EC', 'pbbkb_tawar' => 5])->assertOk()->json();
        $this->assertEqualsWithDelta(100000000, $h['subtotal'], 0.001);
        $this->assertEqualsWithDelta(11000000, $h['ppn_11'], 0.001);
        $this->assertEqualsWithDelta(300000, $h['pph_22'], 0.001);
        $this->assertEqualsWithDelta(5000000, $h['pbbkb'], 0.001);
        $this->assertEqualsWithDelta(116300000, $h['total_order'], 0.001);
        $this->assertEqualsWithDelta(100000000 * 11 / 12, $h['dpp_11_12'], 0.001);
        // Dengan OA 500: plat Hitam, PPN dari harga + OA; plat Kuning, PPN hanya dari harga dasar
        $hitam = $this->postJson('/api/v1/po-supplier/hitung', ['volume_po' => 1000, 'harga_dasar' => 10000, 'kategori_oa' => 2, 'ongkos_angkut' => 500, 'kategori_plat' => 'Hitam', 'kd_tax' => 'E', 'pbbkb_tawar' => 0])->json();
        $kuning = $this->postJson('/api/v1/po-supplier/hitung', ['volume_po' => 1000, 'harga_dasar' => 10000, 'kategori_oa' => 2, 'ongkos_angkut' => 500, 'kategori_plat' => 'Kuning', 'kd_tax' => 'E', 'pbbkb_tawar' => 0])->json();
        $this->assertEqualsWithDelta(10500000, $hitam['subtotal'], 0.001);
        $this->assertEqualsWithDelta(1155000, $hitam['ppn_11'], 0.001);
        $this->assertEqualsWithDelta(1100000, $kuning['ppn_11'], 0.001);
        // iuran migas dicentang: bawaan 0,25% x harga x volume masuk subtotal
        $m = $this->postJson('/api/v1/po-supplier/hitung', ['volume_po' => 1000, 'harga_dasar' => 10000, 'kategori_oa' => 1, 'kd_tax' => 'E', 'pbbkb_tawar' => 0, 'iuran_migas' => true])->json();
        $this->assertEqualsWithDelta(25000, $m['nominal_iuran'], 0.001);
        $this->assertEqualsWithDelta(10025000, $m['subtotal'], 0.001);
    }

    public function test_tambah_tanpa_accurate(): void
    {
        Mail::fake();
        $this->purch();
        $d = $this->dasar();
        $this->postJson('/api/v1/po-supplier', array_diff_key($d, ['ket' => 1]))->assertStatus(422);
        $this->postJson('/api/v1/po-supplier', ['terms_day' => null] + $d)->assertStatus(422); // NET wajib hari
        $r = $this->postJson('/api/v1/po-supplier', $d)->assertCreated()->json();
        $this->assertMatchesRegularExpression('#^\d{3}/UJI/[A-Z0-9.\-]+/(I|II|III|IV|V|VI|VII|VIII|IX|X|XI|XII)/26$#', $r['nomor_po']);
        $po = DB::table('new_pro_inventory_vendor_po')->where('id_master', $r['id'])->first();
        $this->assertSame(1, (int) $po->disposisi_po);
        $this->assertEqualsWithDelta(100000000, (float) $po->subtotal, 0.001);
        $this->assertEqualsWithDelta(111000000, (float) $po->total_order, 0.001);
        $this->assertSame('NET', $po->terms);
        $this->assertNull($po->id_accurate);
        $r2 = $this->postJson('/api/v1/po-supplier', $d)->assertCreated()->json();
        $this->assertSame((int) substr($r['nomor_po'], 0, 3) + 1, (int) substr($r2['nomor_po'], 0, 3));
    }

    public function test_ubah_po_belum_disetujui_dan_batas(): void
    {
        Mail::fake();
        $this->purch();
        $id = $this->postJson('/api/v1/po-supplier', $this->dasar())->json('id');
        $f = $this->getJson("/api/v1/po-supplier/$id/ubah")->assertOk()->json();
        $this->assertFalse($f['perlu_pengajuan_ulang']);
        $d = $this->dasar();
        unset($d['id_produk'], $d['id_vendor']);
        $this->putJson("/api/v1/po-supplier/$id", ['volume_po' => 20000, 'kd_tax' => 'EC'] + $d)->assertOk();
        $po = DB::table('new_pro_inventory_vendor_po')->where('id_master', $id)->first();
        $this->assertSame(20000.0, (float) $po->volume_po);
        $this->assertEqualsWithDelta(200000000 * 0.003, (float) $po->pph_22, 0.001);
        // sudah ada penerimaan -> perubahan harga (belum tersedia)
        DB::table('new_pro_inventory_vendor_po_receive')->insert(['id_po_receive' => 209901000091001, 'id_po_supplier' => $id, 'no_terima' => 'X', 'nama_pic' => 'u', 'tgl_terima' => '2026-11-20', 'volume_bol' => 1, 'volume_terima' => 1,
            'harga_tebus' => 1, 'file_upload' => '', 'file_upload_ori' => '', 'created_time' => now(), 'created_ip' => '1', 'created_by' => 'u']);
        $this->getJson("/api/v1/po-supplier/$id/ubah")->assertStatus(409)->assertJsonPath('code', 'PERUBAHAN_HARGA');
        $this->putJson("/api/v1/po-supplier/$id", $d)->assertStatus(409);
    }

    public function test_ubah_po_disetujui_butuh_pengajuan_ulang_dan_riwayat(): void
    {
        Mail::fake();
        $this->purch();
        $id = $this->postJson('/api/v1/po-supplier', $this->dasar())->json('id');
        DB::table('new_pro_inventory_vendor_po')->where('id_master', $id)->update(['ceo_result' => 1, 'cfo_result' => 1, 'revert_ceo' => 0, 'disposisi_po' => 4]);
        $this->assertTrue($this->getJson("/api/v1/po-supplier/$id/ubah")->json('perlu_pengajuan_ulang'));
        $d = $this->dasar();
        unset($d['id_produk'], $d['id_vendor']);
        $this->putJson("/api/v1/po-supplier/$id", $d)->assertStatus(422); // catatan pengajuan ulang wajib
        $this->putJson("/api/v1/po-supplier/$id", ['ket_resubmission' => 'ubah volume'] + $d)->assertOk();
        $po = DB::table('new_pro_inventory_vendor_po')->where('id_master', $id)->first();
        $this->assertSame(1, (int) $po->resubmission_count);
        $this->assertSame(1, (int) $po->is_resubmission);
        $this->assertSame(1, (int) $po->disposisi_po);
        $this->assertSame(0, (int) $po->ceo_result);
        $this->assertSame(1, DB::table('new_pro_inventory_vendor_po_history')->where('id_po_supplier', $id)->count());
    }

    public function test_tambah_dengan_accurate_dan_rollback(): void
    {
        Mail::fake();
        $this->purch();
        config(['accurate.enabled' => true, 'accurate.token' => 't', 'accurate.secret' => 's']);
        Http::fake(['*purchase-order/save.do' => Http::sequence()
            ->push(['s' => true, 'r' => ['id' => 700]])->push(['s' => true, 'r' => ['id' => 700]])->push(['s' => false, 'd' => ['Kode item tidak ada']])]);
        $d = $this->dasar() + ['kode_item' => 'ITM-1', 'keterangan_item1' => 'bbm'];
        $this->postJson('/api/v1/po-supplier', array_diff_key($d, ['kode_item' => 1]))->assertStatus(422);
        $r = $this->postJson('/api/v1/po-supplier', $d)->assertCreated()->json();
        $this->assertSame(700, (int) DB::table('new_pro_inventory_vendor_po')->where('id_master', $r['id'])->value('id_accurate'));
        Http::assertSent(fn ($q) => str_contains($q->url(), 'save.do') && ($q['number'] ?? null) === $r['nomor_po'] && $q['paymentTermName'] === 'NET 14' && $q['detailItem'][0]['itemNo'] === 'ITM-1' && $q['detailItem'][0]['quantity'] == 10000);
        Http::assertSent(fn ($q) => str_contains($q->url(), 'save.do') && ($q['closeReason'] ?? '') === 'Menunggu Approve' && $q['id'] == 700);
        $n = DB::table('new_pro_inventory_vendor_po')->count();
        $this->postJson('/api/v1/po-supplier', $d)->assertStatus(502)->assertJsonPath('message', 'Kode item tidak ada - Response dari Accurate');
        $this->assertSame($n, DB::table('new_pro_inventory_vendor_po')->count());
    }

    public function test_akun_wajib_menurut_pilihan(): void
    {
        $this->purch();
        config(['accurate.enabled' => true, 'accurate.token' => 't', 'accurate.secret' => 's']);
        Http::fake();
        $d = $this->dasar() + ['kode_item' => 'ITM-1'];
        $this->postJson('/api/v1/po-supplier', ['kd_tax' => 'EC'] + $d)->assertStatus(422)->assertJsonPath('errors.kode_item3.0', 'Akun PPH22 Accurate wajib diisi.');
        $this->postJson('/api/v1/po-supplier', ['pbbkb_tawar' => 5] + $d)->assertStatus(422)->assertJsonPath('errors.kode_biaya1.0', 'Akun PBBKB Accurate wajib diisi.');
        $this->postJson('/api/v1/po-supplier', ['kategori_oa' => 2, 'jenis_oa' => 0, 'ongkos_angkut' => 100, 'kategori_plat' => 'Hitam'] + $d)->assertStatus(422)->assertJsonPath('errors.kode_item2.0', 'Kode OA Accurate wajib diisi.');
    }

    public function test_ubah_dengan_accurate_hapus_lalu_buat_ulang(): void
    {
        Mail::fake();
        $this->purch();
        $id = $this->postJson('/api/v1/po-supplier', $this->dasar())->json('id');
        DB::table('new_pro_inventory_vendor_po')->where('id_master', $id)->update(['id_accurate' => 700]);
        config(['accurate.enabled' => true, 'accurate.token' => 't', 'accurate.secret' => 's']);
        Http::fake([
            '*purchase-order/delete.do' => Http::response(['s' => true]),
            '*purchase-order/detail.do*' => Http::response(['s' => true, 'd' => ['detailItem' => [['item' => ['no' => 'ITM-1', 'itemType' => 'INVENTORY', 'name' => 'HSD'], 'detailNotes' => 'bbm']], 'detailExpense' => [['expenseName' => 'PBBKB', 'expenseNotes' => 'null', 'account' => ['no' => '2101'], 'allocateToItemCost' => true, 'expenseAmount' => 5]]]]),
            '*purchase-order/save.do' => Http::sequence()->push(['s' => true, 'r' => ['id' => 800]])->push(['s' => true, 'r' => ['id' => 800]]),
        ]);
        $f = $this->getJson("/api/v1/po-supplier/$id/ubah")->assertOk()->json();
        $this->assertSame('ITM-1', $f['accurate']['kode_item']);
        $this->assertSame('2101', $f['accurate']['biaya']['pbbkb']['akun']);
        $d = $this->dasar();
        unset($d['id_produk'], $d['id_vendor']);
        $this->putJson("/api/v1/po-supplier/$id", ['kode_item' => 'ITM-1'] + $d)->assertOk();
        $this->assertSame(800, (int) DB::table('new_pro_inventory_vendor_po')->where('id_master', $id)->value('id_accurate'));
        Http::assertSent(fn ($q) => str_contains($q->url(), 'purchase-order/delete.do') && $q['id'] == 700);
    }

    /** PO disetujui dengan satu RI, siap untuk perubahan harga. */
    private function poDenganRi(int $jenisHarga = 1): int
    {
        $id = $this->postJson('/api/v1/po-supplier', ['kategori_plat' => null, 'kd_tax' => 'EC'] + $this->dasar())->json('id');
        DB::table('new_pro_inventory_vendor_po')->where('id_master', $id)->update(['ceo_result' => 1, 'cfo_result' => 1, 'revert_ceo' => 0, 'disposisi_po' => 4, 'jenis_harga' => $jenisHarga, 'is_price_changed' => 0, 'keterangan' => 'catatan lama', 'kategori_plat' => 'Kuning']);
        DB::table('new_pro_inventory_vendor_po_receive')->insert(['id_po_receive' => 209901000092001, 'id_po_supplier' => $id, 'no_terima' => 'X', 'nama_pic' => 'u', 'tgl_terima' => '2026-11-20', 'volume_bol' => 100, 'volume_terima' => 100,
            'harga_tebus' => 10000, 'file_upload' => '', 'file_upload_ori' => '', 'created_time' => now(), 'created_ip' => '1', 'created_by' => 'u']);

        return (int) $id;
    }

    public function test_harga_sementara_lalu_perubahan_harga(): void
    {
        Mail::fake();
        $this->purch();
        $id = $this->poDenganRi(1);
        $this->getJson("/api/v1/po-supplier/$id/ubah-harga")->assertStatus(409)->assertJsonPath('code', 'BUKAN_SEMENTARA');
        $this->postJson("/api/v1/po-supplier/$id/harga-sementara")->assertOk();
        $this->assertSame(2, (int) DB::table('new_pro_inventory_vendor_po')->where('id_master', $id)->value('jenis_harga'));
        $this->postJson("/api/v1/po-supplier/$id/harga-sementara")->assertStatus(409); // sudah sementara
        $f = $this->getJson("/api/v1/po-supplier/$id/ubah-harga")->assertOk()->json();
        $this->assertSame(10000, (int) $f['volume_po']);
        $d = $this->dasar();
        unset($d['id_produk'], $d['id_vendor'], $d['volume_po'], $d['id_terminal'], $d['ket']);
        $this->putJson("/api/v1/po-supplier/$id/ubah-harga", ['kd_tax' => 'EC', 'harga_dasar' => 12000, 'jenis_harga' => 1, 'volume_po' => 5, 'ket' => 'diabaikan'] + $d)->assertStatus(422); // pengajuan ulang wajib
        $this->putJson("/api/v1/po-supplier/$id/ubah-harga", ['kd_tax' => 'EC', 'harga_dasar' => 12000, 'jenis_harga' => 1, 'volume_po' => 5, 'ket' => 'diabaikan', 'ket_resubmission' => 'harga final'] + $d)->assertOk();
        $po = DB::table('new_pro_inventory_vendor_po')->where('id_master', $id)->first();
        $this->assertEqualsWithDelta(12000.0, (float) $po->harga_tebus, 0.001);
        $this->assertSame(10000.0, (float) $po->volume_po);          // volume terkunci
        $this->assertSame('Kuning', $po->kategori_plat);              // plat tetap
        $this->assertSame('catatan lama', $po->keterangan);           // catatan PO tetap
        $this->assertEqualsWithDelta(120000000.0, (float) $po->subtotal, 0.001);
        $this->assertSame(1, (int) $po->is_price_changed);
        $this->assertSame(1, (int) $po->disposisi_po);
        $this->assertSame(0, (int) $po->ceo_result);
        $this->assertSame(1, (int) $po->resubmission_count);
        $this->assertSame(1, (int) DB::table('new_pro_inventory_vendor_po_history')->where('id_po_supplier', $id)->where('is_price_changed', 1)->count());
        $this->postJson("/api/v1/po-supplier/$id/harga-sementara")->assertStatus(409); // sudah ada perubahan harga
    }

    public function test_perubahan_harga_ke_accurate_menyimpan_po_yang_sama(): void
    {
        Mail::fake();
        $this->purch();
        $id = $this->poDenganRi(2);
        DB::table('new_pro_inventory_vendor_po')->where('id_master', $id)->update(['id_accurate' => 700]);
        config(['accurate.enabled' => true, 'accurate.token' => 't', 'accurate.secret' => 's']);
        Http::fake([
            '*purchase-order/detail.do*' => Http::sequence()->push(['s' => true, 'd' => ['number' => '001/UJI', 'detailItem' => [
                ['id' => 1, 'item' => ['no' => 'ITM-1', 'itemType' => 'INVENTORY'], 'quantity' => 10000, 'unitPrice' => 10000, 'warehouse' => ['name' => 'PTK'], 'department' => ['name' => 'Pontianak']],
                ['id' => 2, 'item' => ['no' => 'OA-1', 'itemType' => 'SERVICE'], 'quantity' => 10000, 'unitPrice' => 0, 'warehouse' => null, 'department' => ['name' => 'Pontianak']]],
                'detailExpense' => [['id' => 9, 'account' => ['no' => '5301'], 'expenseName' => 'PPH 22', 'expenseNotes' => '', 'allocateToItemCost' => true, 'expenseAmount' => 1]]]])->push(['s' => false, 'd' => ['PO tidak ada']]),
            '*purchase-order/save.do' => Http::response(['s' => true, 'r' => ['id' => 700]]),
        ]);
        $d = $this->dasar();
        unset($d['id_produk'], $d['id_vendor'], $d['volume_po'], $d['id_terminal'], $d['ket']);
        $this->putJson("/api/v1/po-supplier/$id/ubah-harga", ['kd_tax' => 'EC', 'harga_dasar' => 12000, 'jenis_harga' => 1, 'ket_resubmission' => 'final', 'kode_item' => 'ITM-1', 'kode_item3' => '5301'] + $d)->assertOk();
        Http::assertSent(fn ($q) => str_contains($q->url(), 'purchase-order/save.do') && $q['id'] == 700 && $q['number'] === '001/UJI' && $q['detailItem'][0]['unitPrice'] == 12000 && $q['detailItem'][0]['id'] == 1
            && $q['detailExpense'][0]['id'] == 9 && $q['detailExpense'][0]['expenseAmount'] == 360000 && ! isset($q['manualClosed']));
        // gagal Accurate: perubahan dibatalkan
        DB::table('new_pro_inventory_vendor_po')->where('id_master', $id)->update(['jenis_harga' => 2, 'is_price_changed' => 0, 'harga_tebus' => 10000, 'ceo_result' => 1]);
        $this->putJson("/api/v1/po-supplier/$id/ubah-harga", ['kd_tax' => 'EC', 'harga_dasar' => 13000, 'jenis_harga' => 1, 'ket_resubmission' => 'x', 'kode_item' => 'ITM-1', 'kode_item3' => '5301'] + $d)->assertStatus(502);
        $this->assertEqualsWithDelta(10000.0, (float) DB::table('new_pro_inventory_vendor_po')->where('id_master', $id)->value('harga_tebus'), 0.001);
    }
}
