<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdjustmentStockTest extends TestCase
{
    use DatabaseTransactions;

    private const T = 'new_pro_inventory_depot';

    private function purch(): void
    {
        Sanctum::actingAs(AclUser::where('id_role', 5)->where('is_active', 1)->firstOrFail(), ['*']);
    }

    private function accurateOn(): void
    {
        config(['accurate.enabled' => true, 'accurate.token' => 't', 'accurate.secret' => 's']);
    }

    /** Baris dasar new_pro_inventory_depot untuk uji; produk dan terminal nyata (FK). */
    private function baris(array $o): int
    {
        $p = DB::table('pro_master_produk')->where('is_active', 1)->value('id_master');
        $t = DB::table('pro_master_terminal')->where('is_active', 1)->orderBy('id_master')->pluck('id_master');

        return DB::table(self::T)->insertGetId(array_merge([
            'id_datanya' => 'uji-'.uniqid(), 'id_jenis' => 3, 'id_produk' => $p, 'id_terminal' => $t[0], 'tanggal_inven' => '2026-11-20', 'adj_inven' => 100, 'keterangan' => 'uji adj',
            'created_time' => now(), 'created_ip' => '127.0.0.1', 'created_by' => 'uji',
        ], $o));
    }

    private function transfer(?int $accOut = null, ?int $accIn = null): array
    {
        $t = DB::table('pro_master_terminal')->where('is_active', 1)->orderBy('id_master')->pluck('id_master');
        $ds = 'uji-tf-'.uniqid();
        $out = $this->baris(['id_datanya' => $ds, 'id_jenis' => 4, 'id_terminal' => $t[0], 'adj_inven' => -250, 'keterangan' => 'uji tf', 'id_accurate' => $accOut]);
        $in = $this->baris(['id_datanya' => $ds, 'id_jenis' => 4, 'id_terminal' => $t[1], 'adj_inven' => 250, 'harga' => 9000, 'tanggal_inven' => '2026-11-21', 'keterangan' => 'uji tf', 'id_accurate' => $accIn]);

        return [$ds, $out, $in];
    }

    public function test_hanya_purchasing(): void
    {
        foreach ([21, 7] as $role) {
            Sanctum::actingAs(AclUser::where('id_role', $role)->where('is_active', 1)->firstOrFail(), ['*']);
            $this->getJson('/api/v1/adjustment-stock')->assertForbidden();
            $this->deleteJson('/api/v1/adjustment-stock/1')->assertForbidden();
        }
    }

    public function test_daftar_menggabung_transfer_dan_filter(): void
    {
        $this->purch();
        $adj = $this->baris(['keterangan' => 'uji-adj-unik', 'adj_inven' => -75.5]);
        [, $out] = $this->transfer();

        $r = $this->getJson('/api/v1/adjustment-stock?q=uji-adj-unik')->assertOk()->json();
        $this->assertSame(1, $r['meta']['total']);
        $this->assertSame($adj, $r['data'][0]['id']);
        $this->assertSame('Adjustment', $r['data'][0]['jenis']);
        $this->assertEquals(-75.5, $r['data'][0]['nilai']);

        $tf = $this->getJson('/api/v1/adjustment-stock?q=uji tf&jenis=4&tanggal=2026-11-20')->assertOk()->json();
        $this->assertSame(1, $tf['meta']['total']); // satu baris per pasangan OUT-IN, bukan dua
        $this->assertSame('Transfer Stock', $tf['data'][0]['jenis']);
        $this->assertEquals(250, $tf['data'][0]['nilai']); // total OUT dikali -1 seperti web, jadi positif
        $this->assertNotEmpty($tf['data'][0]['terminal_ke']);
        $this->assertSame($out, $tf['data'][0]['id']);

        $this->assertSame(0, $this->getJson('/api/v1/adjustment-stock?q=uji-adj-unik&jenis=4')->json('meta.total'));
    }

    public function test_detail_adjustment_dan_transfer(): void
    {
        $this->purch();
        $po = DB::table('new_pro_inventory_vendor_po')->first(['id_master', 'nomor_po']);
        $adj = $this->baris(['id_po_supplier' => $po->id_master]);
        $d = $this->getJson("/api/v1/adjustment-stock/{$adj}")->assertOk()->json();
        $this->assertSame('Adjustment', $d['jenis']);
        $this->assertSame($po->nomor_po, $d['nomor_po']);
        $this->assertEquals(100, $d['volume']);

        [, $out] = $this->transfer();
        $t = $this->getJson("/api/v1/adjustment-stock/{$out}")->assertOk()->json();
        $this->assertSame('Transfer Stock', $t['jenis']);
        $this->assertEquals(250, $t['total']); // dipositifkan (abs), bukan minus
        $this->assertNotEquals($t['terminal_dari'], $t['terminal_ke']);
        $this->assertEquals(9000, $t['harga_transfer']);
        $this->assertSame('2026-11-21', $t['tanggal_terima']);

        $this->getJson('/api/v1/adjustment-stock/999999999')->assertNotFound();
    }

    public function test_hapus_tanpa_accurate_menghapus_kedua_sisi_transfer(): void
    {
        $this->purch();
        [$ds, $out] = $this->transfer();
        $this->deleteJson("/api/v1/adjustment-stock/{$out}")->assertOk();
        $this->assertSame(0, DB::table(self::T)->where('id_datanya', $ds)->count());
    }

    public function test_hapus_menghapus_dokumen_accurate_dulu(): void
    {
        $this->purch();
        $this->accurateOn();
        Http::fake(['*' => Http::response(['s' => true, 'd' => ['ok']], 200)]);
        [$ds, $out] = $this->transfer(701, 702);

        $this->deleteJson("/api/v1/adjustment-stock/{$out}")->assertOk();
        $this->assertSame(0, DB::table(self::T)->where('id_datanya', $ds)->count());
        Http::assertSentCount(2);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'item-adjustment/delete.do') && in_array($req['id'], [701, 702], true));
    }

    public function test_hapus_gagal_di_accurate_tidak_menghapus_data(): void
    {
        $this->purch();
        $this->accurateOn();
        Http::fake(['*' => Http::response(['s' => false, 'd' => ['Dokumen sudah dipakai']], 200)]);
        [$ds, $out] = $this->transfer(711, 712);

        $r = $this->deleteJson("/api/v1/adjustment-stock/{$out}")->assertStatus(422)->json();
        $this->assertStringContainsString('Dokumen sudah dipakai - Response dari Accurate', $r['message']);
        $this->assertSame(2, DB::table(self::T)->where('id_datanya', $ds)->count());
    }

    public function test_hapus_purchase_return_memakai_endpoint_retur(): void
    {
        $this->purch();
        $this->accurateOn();
        Http::fake(['*' => Http::response(['s' => true, 'd' => ['ok']], 200)]);
        $pr = $this->baris(['id_jenis' => 5, 'adj_inven' => -40, 'id_accurate' => 801]);

        $this->deleteJson("/api/v1/adjustment-stock/{$pr}")->assertOk();
        $this->assertSame(0, DB::table(self::T)->where('id_master', $pr)->count());
        Http::assertSent(fn ($req) => str_contains($req->url(), 'purchase-return/delete.do') && $req['id'] === 801);
    }

    public function test_hapus_data_terkirim_saat_accurate_mati_ditolak(): void
    {
        $this->purch();
        config(['accurate.enabled' => false]);
        $adj = $this->baris(['id_accurate' => 901]);
        $this->deleteJson("/api/v1/adjustment-stock/{$adj}")->assertStatus(422)->assertJsonPath('code', 'ACCURATE_OFF');
        $this->assertSame(1, DB::table(self::T)->where('id_master', $adj)->count());
    }

    /** Cari kombinasi nyata: produk, terminal asal dengan PO bersisa, terminal tujuan lain. */
    private function kombinasi(): array
    {
        foreach ($this->getJson('/api/v1/adjustment-stock/form-opsi')->json('produk') as $p) {
            $term = $this->getJson('/api/v1/adjustment-stock/terminal?produk='.$p['id'])->json('data');
            if (count($term) < 2) {
                continue;
            }
            foreach ($term as $t) {
                $po = $this->getJson('/api/v1/adjustment-stock/po-transfer?terminal='.$t['id'].'&produk='.$p['id'])->json('data');
                if ($po) {
                    $ke = collect($term)->firstWhere(fn ($x) => $x['id'] !== $t['id']);

                    return ['produk' => $p['id'], 'dari' => $t['id'], 'ke' => $ke['id'], 'po' => $po[0]];
                }
            }
        }
        $this->markTestSkipped('Tidak ada data PO bersisa di DB lokal.');
    }

    private function isiTransfer(array $k, array $o = []): array
    {
        return array_merge([
            'id_jenis' => 4, 'id_produk' => $k['produk'], 'tanggal' => '2026-11-20', 'tanggal_terima' => '2026-11-21', 'terminal_dari' => $k['dari'], 'terminal_ke' => $k['ke'],
            'rincian' => [['id_po_supplier' => $k['po']['id_po_supplier'], 'id_po_receive' => $k['po']['id_po_receive'], 'volume' => 10]], 'harga_transfer' => 9500.5,
            'keterangan' => 'uji transfer', 'nomor_penyesuaian' => 'ADJ-UJI-1', 'nomor_penyesuaian_terima' => 'ADJ-UJI-2', 'kode_item' => 'ITM-A', 'kode_item_terima' => 'ITM-B', 'akun_penyesuaian' => '5101',
        ], $o);
    }

    public function test_opsi_form_dan_daftar_po(): void
    {
        $this->purch();
        $k = $this->kombinasi();
        $this->assertGreaterThan(0, $k['po']['sisa']);
        // terminal tanpa Data Awal untuk produk itu tidak mengembalikan PO
        $this->assertSame([], $this->getJson('/api/v1/adjustment-stock/po-transfer?terminal=999999&produk='.$k['produk'])->json('data'));
    }

    public function test_transfer_validasi(): void
    {
        $this->purch();
        $k = $this->kombinasi();
        config(['accurate.enabled' => false]);
        $this->postJson('/api/v1/adjustment-stock', $this->isiTransfer($k, ['terminal_ke' => $k['dari']]))->assertStatus(422);
        $this->postJson('/api/v1/adjustment-stock', $this->isiTransfer($k, ['rincian' => [['id_po_supplier' => $k['po']['id_po_supplier'], 'id_po_receive' => $k['po']['id_po_receive'], 'volume' => 0]]]))->assertStatus(422);
        $over = (int) ceil($k['po']['sisa']) + 1000;
        $r = $this->postJson('/api/v1/adjustment-stock', $this->isiTransfer($k, ['rincian' => [['id_po_supplier' => $k['po']['id_po_supplier'], 'id_po_receive' => $k['po']['id_po_receive'], 'volume' => $over]]]))->assertStatus(422)->json();
        $this->assertStringContainsString('melebihi sisa', $r['message']);
        $this->postJson('/api/v1/adjustment-stock', $this->isiTransfer($k, ['id_jenis' => 3]))->assertStatus(422);
    }

    public function test_transfer_tanpa_accurate_membuat_pasangan_out_in(): void
    {
        $this->purch();
        $k = $this->kombinasi();
        config(['accurate.enabled' => false]);
        $r = $this->postJson('/api/v1/adjustment-stock', $this->isiTransfer($k, ['nomor_penyesuaian' => null, 'nomor_penyesuaian_terima' => null, 'kode_item' => null, 'kode_item_terima' => null, 'akun_penyesuaian' => null]))->assertCreated()->json();
        $this->assertTrue($r['accurate_manual']);
        $rows = DB::table(self::T)->where('keterangan', 'uji transfer')->where('id_jenis', 4)->orderBy('adj_inven')->get();
        $this->assertCount(2, $rows);
        $this->assertEquals(-10, $rows[0]->adj_inven);
        $this->assertSame($k['dari'], (int) $rows[0]->id_terminal);
        $this->assertSame('2026-11-20', $rows[0]->tanggal_inven);
        $this->assertEquals(10, $rows[1]->adj_inven);
        $this->assertSame($k['ke'], (int) $rows[1]->id_terminal);
        $this->assertSame('2026-11-21', $rows[1]->tanggal_inven);
        $this->assertSame((int) 9501, (int) round($rows[1]->harga)); // kolom harga bertipe integer
        $this->assertSame($rows[0]->id_datanya, $rows[1]->id_datanya);
        $this->assertSame(1, $this->getJson('/api/v1/adjustment-stock?q=uji transfer&jenis=4')->json('meta.total'));
    }

    public function test_transfer_dengan_accurate_mengirim_out_lalu_in(): void
    {
        $this->purch();
        $this->accurateOn();
        $k = $this->kombinasi();
        Http::fake(['*' => Http::sequence()->push(['s' => true, 'r' => ['id' => 5001]], 200)->push(['s' => true, 'r' => ['id' => 5002]], 200)]);
        $this->postJson('/api/v1/adjustment-stock', $this->isiTransfer($k))->assertCreated();
        $ids = DB::table(self::T)->where('keterangan', 'uji transfer')->orderBy('adj_inven')->pluck('id_accurate')->all();
        $this->assertSame([5001, 5002], array_map('intval', $ids));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'item-adjustment/save.do') && ($req['detailItem'][0]['itemAdjustmentType'] ?? '') === 'ADJUSTMENT_OUT'
            && $req['number'] === 'ADJ-UJI-1' && $req['detailItem'][0]['quantity'] == 10 && $req['transDate'] === '20/11/2026');
        Http::assertSent(fn ($req) => str_contains($req->url(), 'item-adjustment/save.do') && ($req['detailItem'][0]['itemAdjustmentType'] ?? '') === 'ADJUSTMENT_IN'
            && $req['number'] === 'ADJ-UJI-2' && $req['detailItem'][0]['unitCost'] == 9500.5 && $req['transDate'] === '21/11/2026');
    }

    public function test_transfer_gagal_di_in_membatalkan_out_di_accurate(): void
    {
        $this->purch();
        $this->accurateOn();
        $k = $this->kombinasi();
        Http::fake(['*' => Http::sequence()->push(['s' => true, 'r' => ['id' => 6001]], 200)->push(['s' => false, 'd' => ['Nomor sudah dipakai']], 200)->push(['s' => true, 'd' => ['ok']], 200)]);
        $r = $this->postJson('/api/v1/adjustment-stock', $this->isiTransfer($k))->assertStatus(502)->json();
        $this->assertStringContainsString('Nomor sudah dipakai', $r['message']);
        $this->assertSame(0, DB::table(self::T)->where('keterangan', 'uji transfer')->count());
        Http::assertSent(fn ($req) => str_contains($req->url(), 'item-adjustment/delete.do') && $req['id'] === '6001');
    }

    private function isiAdj(array $k, array $o = []): array
    {
        return array_merge([
            'id_jenis' => 3, 'id_produk' => $k['produk'], 'tanggal' => '2026-11-20', 'id_terminal' => $k['dari'], 'id_po_supplier' => $k['po']['id_po_supplier'], 'id_po_receive' => $k['po']['id_po_receive'],
            'tanda' => '+', 'volume' => 12.5, 'harga' => 9100, 'keterangan' => 'uji adjustment', 'nomor_penyesuaian' => 'ADJ-UJI-9', 'kode_item' => 'ITM-A', 'akun_penyesuaian' => '5101',
        ], $o);
    }

    public function test_adjustment_validasi(): void
    {
        $this->purch();
        $k = $this->kombinasi();
        config(['accurate.enabled' => false]);
        $this->postJson('/api/v1/adjustment-stock', $this->isiAdj($k, ['tanda' => '+', 'harga' => null]))->assertStatus(422); // gain wajib harga
        $this->postJson('/api/v1/adjustment-stock', $this->isiAdj($k, ['volume' => 0]))->assertStatus(422);
        $this->postJson('/api/v1/adjustment-stock', $this->isiAdj($k, ['id_po_receive' => 999999999]))->assertStatus(422);
        $r = $this->postJson('/api/v1/adjustment-stock', $this->isiAdj($k, ['tanda' => '-', 'volume' => $k['po']['sisa'] + 5000]))->assertStatus(422)->json();
        $this->assertStringContainsString('melebihi sisa', $r['message']);
        $this->assertSame(0, DB::table(self::T)->where('keterangan', 'uji adjustment')->count());
    }

    public function test_adjustment_gain_dan_loss_tanpa_accurate(): void
    {
        $this->purch();
        $k = $this->kombinasi();
        config(['accurate.enabled' => false]);
        $this->postJson('/api/v1/adjustment-stock', $this->isiAdj($k, ['nomor_penyesuaian' => null, 'kode_item' => null, 'akun_penyesuaian' => null]))->assertCreated()->assertJsonPath('accurate_manual', true);
        $this->postJson('/api/v1/adjustment-stock', $this->isiAdj($k, ['tanda' => '-', 'volume' => 3, 'harga' => null, 'nomor_penyesuaian' => null, 'kode_item' => null, 'akun_penyesuaian' => null]))->assertCreated();
        $rows = DB::table(self::T)->where('keterangan', 'uji adjustment')->where('id_jenis', 3)->orderBy('adj_inven')->get();
        $this->assertCount(2, $rows);
        $this->assertEquals(-3, $rows[0]->adj_inven);
        $this->assertEquals(12.5, $rows[1]->adj_inven);
        $this->assertSame($k['dari'], (int) $rows[1]->id_terminal);
        $this->assertSame(0, (int) $rows[1]->harga); // seperti web, harga hanya untuk Accurate
        $this->assertSame(2, $this->getJson('/api/v1/adjustment-stock?q=uji adjustment&jenis=3')->json('meta.total'));
    }

    public function test_adjustment_ke_accurate_gain_in_loss_out(): void
    {
        $this->purch();
        $this->accurateOn();
        $k = $this->kombinasi();
        Http::fake(['*' => Http::sequence()->push(['s' => true, 'r' => ['id' => 7001]], 200)->push(['s' => true, 'r' => ['id' => 7002]], 200)]);
        $this->postJson('/api/v1/adjustment-stock', $this->isiAdj($k))->assertCreated();
        $this->postJson('/api/v1/adjustment-stock', $this->isiAdj($k, ['tanda' => '-', 'volume' => 4, 'nomor_penyesuaian' => 'ADJ-UJI-10']))->assertCreated();
        $this->assertSame([7001, 7002], DB::table(self::T)->where('keterangan', 'uji adjustment')->orderBy('id_master')->pluck('id_accurate')->map(fn ($v) => (int) $v)->all());
        Http::assertSent(fn ($req) => ($req['detailItem'][0]['itemAdjustmentType'] ?? '') === 'ADJUSTMENT_IN' && $req['detailItem'][0]['unitCost'] == 9100
            && $req['detailItem'][0]['quantity'] == 12.5 && $req['transDate'] === '20/11/2026' && $req['adjustmentAccountNo'] === '5101');
        Http::assertSent(fn ($req) => ($req['detailItem'][0]['itemAdjustmentType'] ?? '') === 'ADJUSTMENT_OUT' && ! isset($req['detailItem'][0]['unitCost']) && $req['number'] === 'ADJ-UJI-10');
    }

    public function test_adjustment_ditolak_accurate_tidak_tersimpan(): void
    {
        $this->purch();
        $this->accurateOn();
        $k = $this->kombinasi();
        Http::fake(['*' => Http::response(['s' => false, 'd' => ['Akun tidak ditemukan']], 200)]);
        $r = $this->postJson('/api/v1/adjustment-stock', $this->isiAdj($k))->assertStatus(502)->json();
        $this->assertStringContainsString('Akun tidak ditemukan - Response dari Accurate', $r['message']);
        $this->assertSame(0, DB::table(self::T)->where('keterangan', 'uji adjustment')->count());
    }
}
