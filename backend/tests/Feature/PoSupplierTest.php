<?php

namespace Tests\Feature;

use App\Models\AclUser;
use App\Support\Terbilang;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PoSupplierTest extends TestCase
{
    use DatabaseTransactions;

    private function purch(): void
    {
        Sanctum::actingAs(AclUser::where('id_role', 5)->where('is_active', 1)->firstOrFail(), ['*']);
    }

    public function test_hanya_purchasing(): void
    {
        foreach ([21, 7, 6] as $role) {
            Sanctum::actingAs(AclUser::where('id_role', $role)->where('is_active', 1)->firstOrFail(), ['*']);
            $this->getJson('/api/v1/po-supplier')->assertForbidden();
            $this->getJson('/api/v1/po-supplier/1/cetak')->assertForbidden();
        }
    }

    public function test_daftar_detail_dan_filter(): void
    {
        $this->purch();
        $r = $this->getJson('/api/v1/po-supplier?per_page=5')->assertOk()->json();
        $this->assertNotEmpty($r['data']);
        $po = $r['data'][0];
        $this->assertArrayHasKey('aksi', $po);
        $cari = $this->getJson('/api/v1/po-supplier?q='.urlencode($po['nomor_po']))->assertOk()->json('data');
        $this->assertContains($po['id'], array_column($cari, 'id'));
        $d = $this->getJson('/api/v1/po-supplier/'.$po['id'])->assertOk()->json();
        $this->assertSame($po['nomor_po'], $d['nomor_po']);
        $this->getJson('/api/v1/po-supplier/99999999')->assertNotFound();
        $this->assertNotEmpty($this->getJson('/api/v1/po-supplier/opsi')->json('terminal'));
    }

    public function test_aksi_mengikuti_aturan_web(): void
    {
        $this->purch();
        $id = DB::table('new_pro_inventory_vendor_po')->where('ceo_result', 1)->where('is_close', 0)->where('is_cancel', 0)->where('harga_tebus', '>', 0)->value('id_master');
        $this->assertNotNull($id);
        DB::table('new_pro_inventory_vendor_po')->where('id_master', $id)->update(['revert_ceo' => 0, 'jenis_kirim' => 1, 'resubmission_count' => 0]);
        $a = $this->getJson('/api/v1/po-supplier/'.$id)->json('aksi');
        $this->assertSame(['edit', 'terima', 'cetak', 'close'], $a);
        DB::table('new_pro_inventory_vendor_po')->where('id_master', $id)->update(['is_close' => 1]);
        $this->assertSame(['lihat', 'cetak_penuh'], $this->getJson('/api/v1/po-supplier/'.$id)->json('aksi'));
    }

    public function test_cetak_standar(): void
    {
        $this->purch();
        $id = DB::table('new_pro_inventory_vendor_po')->where('harga_tebus', '>', 0)->value('id_master');
        DB::table('new_pro_inventory_vendor_po')->where('id_master', $id)->update([
            'harga_po' => 10000, 'volume_po' => 1000, 'kategori_oa' => 2, 'ongkos_angkut' => 100, 'kategori_plat' => 'Hitam',
            'iuran_migas' => 1, 'nominal_migas' => 25000, 'nilai_pbbkb' => 0, 'kd_tax' => 'EC',
        ]);
        $c = $this->getJson("/api/v1/po-supplier/$id/cetak")->assertOk()->json();
        $h = $c['halaman'][0];
        $this->assertCount(3, $h['baris']);
        $this->assertSame('IDR 10,000,000', $h['baris'][0]['jumlah']);
        $this->assertSame('IDR 100,000', $h['baris'][1]['jumlah']);
        $this->assertSame('10,100,000.00', $h['sub_total']);
        // EC: total = subtotal + ppn11 + pph(0,3%) + pbbkb + OA ... + iuran
        $this->assertSame('IDR 11,255,000.00', $h['total_order']);
        $g = $this->getJson("/api/v1/po-supplier/$id/cetak?mode=gainloss")->assertOk()->json();
        $this->assertSame('gainloss', $g['mode']);
    }

    public function test_barcode_memakai_param_encrypt_web(): void
    {
        $this->purch();
        config(['orca.param_key' => str_repeat('ab', 32)]);
        $id = DB::table('new_pro_inventory_vendor_po')->where('harga_tebus', '>', 0)->value('id_master');
        $url = $this->getJson("/api/v1/po-supplier/$id/cetak")->json('kepala.barcode');
        $enc = substr($url, strlen(config('orca.po_barcode_url')));
        $this->assertSame(32 * (int) ceil(strlen((string) $id) / 16), strlen($enc));
        $this->assertSame((string) $id, \App\Support\ParamCrypt::decrypt($enc));
        config(['orca.param_key' => null]);
        $this->assertNull($this->getJson("/api/v1/po-supplier/$id/cetak")->json('kepala.barcode'));
    }

    public function test_terbilang(): void
    {
        $this->assertSame('One Million Two Hundred Thirty Four Thousand Five Hundred Sixty Seven', Terbilang::inggris(1234567));
        $this->assertSame('', Terbilang::inggris(0));
        $this->assertSame('Seven Hundred Twenty Three Million Four Hundred Fifty Thousand', Terbilang::inggris(723450000));
        $this->assertSame('Four Teen', Terbilang::inggris(14)); // kebiasaan helper web
    }
}
