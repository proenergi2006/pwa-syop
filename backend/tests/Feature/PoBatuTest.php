<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PoBatuTest extends TestCase
{
    use DatabaseTransactions;

    private const PO = 'new_pro_inventory_vendor_po_crushed_stone';

    private function as(int $role): AclUser
    {
        $u = AclUser::where('id_role', $role)->where('is_active', 1)->firstOrFail();
        Sanctum::actingAs($u, ['*']);

        return $u;
    }

    /** Salin PO batu selesai menjadi PO yang menunggu CEO (di-rollback otomatis). */
    private function pending(int $id = 209901000000101, string $nomor = 'TEST/BATU/CEO'): int
    {
        $row = (array) DB::table(self::PO)->where('disposisi_po', 4)->where('is_cancel', 0)->orderByDesc('id_master')->first();
        $row['id_master'] = $id;
        $row['nomor_po'] = $nomor;
        $row['disposisi_po'] = 2;
        $row['cfo_result'] = 1;
        $row['ceo_result'] = 0;
        $row['revert_cfo'] = 0;
        $row['revert_ceo'] = 0;
        $row['ceo_summary'] = null;
        DB::table(self::PO)->insert($row);

        return $id;
    }

    public function test_hanya_ceo(): void
    {
        foreach ([5, 7, 6] as $role) {
            $this->as($role);
            $this->getJson('/api/v1/po-batu')->assertForbidden();
            $this->postJson('/api/v1/po-batu/1/keputusan', ['keputusan' => 'approve'])->assertForbidden();
        }
        $this->as(21);
        $this->getJson('/api/v1/po-batu')->assertOk();
        $this->assertContains('po_batu', $this->getJson('/api/v1/auth/me')->json('user.modules') ?? []);
    }

    public function test_daftar_detail_dan_hitungan_menunggu(): void
    {
        $this->as(21);
        $sebelum = $this->getJson('/api/v1/po-batu?tab=wait')->assertOk()->json('waiting');
        $id = $this->pending();

        $w = $this->getJson('/api/v1/po-batu?tab=wait')->assertOk()->json();
        $this->assertSame($sebelum + 1, $w['waiting']);
        $this->assertContains('TEST/BATU/CEO', array_column($w['data'], 'nomor_po'));
        $this->assertSame($sebelum + 1, $this->getJson('/api/v1/badges')->json('po_batu'));

        $done = $this->getJson('/api/v1/po-batu?tab=done')->assertOk()->json('data');
        $this->assertNotEmpty($done);
        $this->assertNotContains('TEST/BATU/CEO', array_column($done, 'nomor_po'));
        $c = $this->getJson('/api/v1/po-batu?tab=all&q='.urlencode('TEST/BATU'))->assertOk()->json('data');
        $this->assertSame(['TEST/BATU/CEO'], array_column($c, 'nomor_po'));

        $d = $this->getJson("/api/v1/po-batu/$id")->assertOk()->json();
        $this->assertTrue($d['can_decide']);
        $this->assertSame('TEST/BATU/CEO', $d['nomor_po']);
        $this->getJson('/api/v1/po-batu/999')->assertNotFound();
    }

    public function test_setujui(): void
    {
        $u = $this->as(21);
        $id = $this->pending();
        $this->postJson("/api/v1/po-batu/$id/keputusan", ['keputusan' => 'approve', 'catatan' => "OK\nlanjut"])->assertOk();
        $r = DB::table(self::PO)->where('id_master', $id)->first();
        $this->assertSame(4, (int) $r->disposisi_po);
        $this->assertSame(1, (int) $r->ceo_result);
        $this->assertSame($u->fullname, $r->ceo_pic);
        $this->assertSame('OK<br />lanjut', $r->ceo_summary);
        $this->postJson("/api/v1/po-batu/$id/keputusan", ['keputusan' => 'approve'])->assertStatus(409);
    }

    public function test_kembalikan_catatan_opsional_dan_gain_loss_tidak_disentuh(): void
    {
        $this->as(21);
        $id = $this->pending();
        $gl = DB::table('new_pro_inventory_gain_loss_crushed_stone')->insertGetId(['id_po_supplier' => $id, 'disposisi_gain_loss' => 1, 'ceo_result' => 0]);
        $this->postJson("/api/v1/po-batu/$id/keputusan", ['keputusan' => 'return'])->assertOk(); // web: catatan pengembalian tidak wajib
        $r = DB::table(self::PO)->where('id_master', $id)->first();
        $this->assertSame(5, (int) $r->disposisi_po);
        $this->assertSame(1, (int) $r->revert_ceo);
        $this->assertSame('', (string) $r->revert_ceo_summary);
        $this->assertSame(1, (int) DB::table('new_pro_inventory_gain_loss_crushed_stone')->where('id_master', $gl)->value('disposisi_gain_loss')); // tidak disentuh

        $id2 = $this->pending(209901000000102, 'TEST/BATU/CEO/2');
        $this->postJson("/api/v1/po-batu/$id2/keputusan", ['keputusan' => 'return', 'catatan' => 'Revisi harga'])->assertOk();
        $this->assertSame('Revisi harga', DB::table(self::PO)->where('id_master', $id2)->value('revert_ceo_summary'));
    }

    public function test_cetak_po(): void
    {
        $this->as(21);
        $id = $this->pending();
        DB::table(self::PO)->where('id_master', $id)->update(['terms_condition' => "1. Pembayaran NET 30<br />2. Barang sesuai spesifikasi", 'kd_tax' => 'EC', 'keterangan' => 'QC &amp; Receiving<br />Baris 2']);
        $r = $this->getJson("/api/v1/po-batu/$id/cetak")->assertOk()->json();
        $po = DB::table(self::PO)->where('id_master', $id)->first();
        $this->assertSame('TEST/BATU/CEO', $r['kepala']['nomor_po']);
        $this->assertSame('00'.str_pad((string) $id, 6, '0', STR_PAD_LEFT), $r['kepala']['barcode']);
        $this->assertSame('Vica Krisdianatha', $r['kepala']['penandatangan']);
        $this->assertSame('-', $r['baris']['pajak']); // kode tax EC tampil "-"
        $harga = (float) $po->harga_po > 0 ? (float) $po->harga_po : (float) $po->harga_tebus;
        $this->assertSame(number_format($harga * (float) $po->volume_po, 2, '.', ','), $r['sub_total']);
        $this->assertSame('IDR '.number_format((float) $po->total_order, 2, '.', ','), $r['total_order']);
        $this->assertSame(['Pembayaran NET 30', 'Barang sesuai spesifikasi'], $r['syarat']);
        $this->assertSame("QC & Receiving\nBaris 2", $r['catatan']); // entitas HTML dan <br /> menjadi teks biasa
        $this->assertNotSame('', $r['terbilang']);
        $this->getJson('/api/v1/po-batu/999/cetak')->assertNotFound();

        $this->as(5);
        $this->getJson("/api/v1/po-batu/$id/cetak")->assertForbidden();
    }
}
