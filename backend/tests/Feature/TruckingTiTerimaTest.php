<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TruckingTiTerimaTest extends TestCase
{
    use DatabaseTransactions;

    private int $ti;

    private int $prd;

    private int $produk;

    private function siap(float $realisasi = 16000, array $outs = [[10000, 1], [6000, 2]]): void
    {
        Sanctum::actingAs(AclUser::where('id_role', 5)->where('is_active', 1)->firstOrFail(), ['*']);
        $this->produk = (int) DB::table('pro_master_produk')->value('id_master');
        config(['orca.trucking_dr_produk' => $this->produk]);
        $this->ti = (int) DB::table('pro_trucking_instruction_req as f')->join('pro_trucking_plan as e', 'e.id_trucking', '=', 'f.id_master')->join('pro_pr_trucking_detail as c', 'c.id_plan', '=', 'e.id_plan')
            ->join('pro_po_ds_trucking_detail as g', 'g.id_prd_ti', '=', 'c.id_prd_ti')->join('pro_po_trucking_detail as a', 'a.id_pod_ti', '=', 'g.id_pod_ti')
            ->where('f.is_cancel', 0)->orderByDesc('f.id_master')->value('f.id_master');
        $this->prd = (int) DB::table('pro_pr_trucking_detail as c')->join('pro_trucking_plan as e', 'c.id_plan', '=', 'e.id_plan')->where('e.id_trucking', $this->ti)->value('c.id_prd_ti');
        DB::table('pro_trucking_instruction_req')->where('id_master', $this->ti)->update(['status' => 5, 'is_received' => 0, 'is_cancel' => 0]);
        DB::table('pro_po_ds_trucking_detail')->where('id_prd_ti', $this->prd)->update(['realisasi_volume' => $realisasi, 'tgl_realisasi' => '2026-11-10 09:00:00', 'is_cancel' => 0, 'is_request' => 0]);
        DB::table('new_pro_inventory_depot')->where('id_prd_ti', $this->prd)->delete();
        $terminal = (int) DB::table('pro_trucking_instruction_req')->where('id_master', $this->ti)->value('loading_port');
        foreach ($outs as $i => [$vol, $po]) {
            DB::table('new_pro_inventory_depot')->insert([
                'id_datanya' => 'dr-uji-'.$i, 'id_jenis' => 3, 'id_produk' => $this->produk, 'id_terminal' => $terminal, 'id_po_supplier' => 700000 + $po, 'id_po_receive' => 800000 + $po, 'tanggal_inven' => '2026-11-01',
                'adj_inven' => -$vol, 'keterangan' => 'potong uji', 'created_time' => now(), 'created_ip' => '1', 'created_by' => 'u', 'id_pr_ti' => 1, 'id_prd_ti' => $this->prd,
            ]);
        }
    }

    private function isi(array $o = []): array
    {
        return array_merge(['harga_transfer' => 12000, 'keterangan' => 'transfer uji'], $o);
    }

    public function test_hanya_purchasing(): void
    {
        Sanctum::actingAs(AclUser::where('id_role', 21)->where('is_active', 1)->firstOrFail(), ['*']);
        $this->getJson('/api/v1/trucking-ti/1/terima')->assertForbidden();
        $this->postJson('/api/v1/trucking-ti/1/terima', [])->assertForbidden();
    }

    public function test_halaman_terima(): void
    {
        $this->siap();
        $r = $this->getJson("/api/v1/trucking-ti/{$this->ti}/terima")->assertOk()->json();
        $this->assertTrue($r['bisa_received']);
        $this->assertNotEmpty($r['baris']);
        $this->assertEqualsWithDelta(16000, collect($r['baris'])->firstWhere('id_prd', $this->prd)['realisasi'], 0.001);
        $this->assertCount(2, $r['grup_po']);
    }

    public function test_received_tanpa_accurate_memindahkan_stok_dan_split(): void
    {
        $this->siap(4000);
        $this->postJson("/api/v1/trucking-ti/{$this->ti}/terima", [])->assertStatus(422); // harga wajib
        $r = $this->postJson("/api/v1/trucking-ti/{$this->ti}/terima", $this->isi())->assertOk()->json();
        $this->assertTrue($r['accurate_manual']);
        $ti = DB::table('pro_trucking_instruction_req')->where('id_master', $this->ti)->first();
        $this->assertSame(7, (int) $ti->status);
        $this->assertSame(1, (int) $ti->is_received);
        $ins = DB::table('new_pro_inventory_depot')->where('id_prd_ti', $this->prd)->where('adj_inven', '>', 0)->orderBy('id_master')->get();
        $this->assertCount(2, $ins);
        // realisasi 4000 < volume potong selain baris pertama (6000): dibagi sebanding 10000:6000 -> 2500 + sisa 1500
        $this->assertEqualsWithDelta(2500, (float) $ins[0]->adj_inven, 0.001);
        $this->assertEqualsWithDelta(1500, (float) $ins[1]->adj_inven, 0.001);
        $this->assertSame(4, (int) $ins[0]->id_jenis);
        $this->assertSame('Received TI '.$ti->nomor_ti, $ins[0]->keterangan);
        $this->assertSame('Received TI '.$ti->nomor_ti.' (SPLIT)', $ins[1]->keterangan);
        $this->assertSame($ti->discharging_port, (int) $ins[0]->id_terminal);
        $this->assertEqualsWithDelta(12000, (float) $ins[0]->harga, 0.001);
        $this->assertSame('2026-11-10', DB::table('new_pro_inventory_depot')->where('id_prd_ti', $this->prd)->where('adj_inven', '<', 0)->value('tanggal_inven'));
        $this->postJson("/api/v1/trucking-ti/{$this->ti}/terima", $this->isi())->assertStatus(409);
    }

    public function test_received_realisasi_cukup_baris_pertama_menyisakan_selisih(): void
    {
        $this->siap(8000); // 8000 >= 6000 (selain baris pertama): baris pertama = 8000 - 6000, lainnya penuh
        $this->postJson("/api/v1/trucking-ti/{$this->ti}/terima", $this->isi())->assertOk();
        $ins = DB::table('new_pro_inventory_depot')->where('id_prd_ti', $this->prd)->where('adj_inven', '>', 0)->orderBy('id_master')->pluck('adj_inven')->map(fn ($x) => (float) $x)->all();
        $this->assertSame([2000.0, 6000.0], $ins);
    }

    public function test_received_accurate_gabungan_dan_pisah_serta_pembatalan(): void
    {
        $this->siap();
        config(['accurate.enabled' => true, 'accurate.token' => 't', 'accurate.secret' => 's']);
        Http::fake([
            '*item-adjustment/save.do' => Http::sequence()
                ->push(['s' => true, 'r' => ['id' => 11]])->push(['s' => true, 'r' => ['id' => 12]])           // skenario 1: gabungan
                ->push(['s' => true, 'r' => ['id' => 21]])->push(['s' => false, 'd' => ['Item tidak ada']])      // skenario 2: IN gagal
                ->push(['s' => true, 'r' => ['id' => 31]])->push(['s' => true, 'r' => ['id' => 32]])->push(['s' => true, 'r' => ['id' => 33]])->push(['s' => true, 'r' => ['id' => 34]]), // skenario 3: pisah
            '*item-adjustment/delete.do' => Http::response(['s' => true]),
        ]);
        $u = "/api/v1/trucking-ti/{$this->ti}/terima";
        $this->postJson($u, $this->isi())->assertStatus(422); // nomor, item, akun wajib bila Accurate aktif
        $acc = ['nomor_penyesuaian' => 'ADJ-OUT/1', 'kode_item' => 'ITM-1', 'akun_penyesuaian' => '5101', 'nomor_penyesuaian_terima' => 'ADJ-IN/1', 'kode_item_terima' => 'ITM-2'];
        $this->postJson($u, $this->isi() + $acc)->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'item-adjustment/save.do') && $r['detailItem'][0]['itemAdjustmentType'] === 'ADJUSTMENT_OUT' && $r['number'] === 'ADJ-OUT/1' && $r['detailItem'][0]['quantity'] == 16000);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'item-adjustment/save.do') && $r['detailItem'][0]['itemAdjustmentType'] === 'ADJUSTMENT_IN' && $r['detailItem'][0]['unitCost'] == 12000);
        $this->assertSame(11, (int) DB::table('new_pro_inventory_depot')->where('id_prd_ti', $this->prd)->where('adj_inven', '<', 0)->value('id_accurate'));
        $this->assertSame(12, (int) DB::table('new_pro_inventory_depot')->where('id_prd_ti', $this->prd)->where('adj_inven', '>', 0)->value('id_accurate'));

        // gagal di IN: OUT yang sudah masuk dibatalkan, DB dikembalikan
        $this->siap();
        $this->postJson($u, $this->isi() + $acc)->assertStatus(502)->assertJsonPath('message', 'Item tidak ada - Response dari Accurate (IN ADJ-IN/1)');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'item-adjustment/delete.do') && $r['id'] == 21);
        $this->assertSame(0, (int) DB::table('pro_trucking_instruction_req')->where('id_master', $this->ti)->value('is_received'));
        $this->assertSame(0, DB::table('new_pro_inventory_depot')->where('id_prd_ti', $this->prd)->where('adj_inven', '>', 0)->count());

        // pisah per PO: dua pasang transaksi
        $this->siap();
        $grup = [
            ['id_po_supplier' => 700001, 'id_po_receive' => 800001, 'nomor_penyesuaian' => 'O1', 'kode_item' => 'I1', 'nomor_penyesuaian_terima' => 'N1', 'kode_item_terima' => 'J1', 'harga' => 11000],
            ['id_po_supplier' => 700002, 'id_po_receive' => 800002, 'nomor_penyesuaian' => 'O2', 'kode_item' => 'I2', 'nomor_penyesuaian_terima' => 'N2', 'kode_item_terima' => 'J2', 'harga' => 13000],
        ];
        $this->postJson($u, $this->isi() + ['akun_penyesuaian' => '5101', 'pisah_accurate' => true, 'grup' => [$grup[0]]])->assertStatus(502); // grup PO ke-2 belum lengkap
        $this->siap();
        $this->postJson($u, $this->isi() + ['akun_penyesuaian' => '5101', 'pisah_accurate' => true, 'grup' => $grup])->assertOk();
        $ins = DB::table('new_pro_inventory_depot')->where('id_prd_ti', $this->prd)->where('adj_inven', '>', 0)->orderBy('id_po_supplier')->get();
        $this->assertEqualsWithDelta(11000, (float) $ins[0]->harga, 0.001);
        $this->assertEqualsWithDelta(13000, (float) $ins[1]->harga, 0.001);
        $this->assertSame([32, 34], $ins->pluck('id_accurate')->map(fn ($x) => (int) $x)->all());
    }

    public function test_approve_cancel(): void
    {
        $this->siap();
        $dsd = (int) DB::table('pro_po_ds_trucking_detail')->where('id_prd_ti', $this->prd)->value('id_dsd_ti');
        $u = "/api/v1/trucking-ti/{$this->ti}/ds/$dsd/approve-cancel";
        $this->postJson($u)->assertStatus(409); // tidak ada request cancel
        DB::table('pro_po_ds_trucking_detail')->where('id_dsd_ti', $dsd)->update(['is_request' => 1, 'disposisi_request' => 1, 'is_approved' => 0]);
        $b = collect($this->getJson("/api/v1/trucking-ti/{$this->ti}/terima")->json('baris'))->firstWhere('id_dsd', $dsd);
        $this->assertTrue($b['bisa_approve_cancel']);
        $this->assertSame('Request Cancel', $b['request']);
        $this->postJson($u)->assertOk();
        $this->assertSame(6, (int) DB::table('pro_trucking_instruction_req')->where('id_master', $this->ti)->value('status'));
        $this->assertSame(1, (int) DB::table('pro_po_ds_trucking_detail')->where('id_dsd_ti', $dsd)->value('is_cancel'));
        $this->assertSame(0, DB::table('new_pro_inventory_depot')->where('id_prd_ti', $this->prd)->count());
    }

    public function test_detail_accurate_dan_cetak_received(): void
    {
        $this->siap(16000);
        $u = "/api/v1/trucking-ti/{$this->ti}";
        config(['accurate.enabled' => true, 'accurate.token' => 't', 'accurate.secret' => 's']);
        Http::fake([
            '*item-adjustment/save.do' => Http::sequence()->push(['s' => true, 'r' => ['id' => 41]])->push(['s' => true, 'r' => ['id' => 42]]),
            '*item-adjustment/detail.do*' => Http::sequence()
                ->push(['s' => true, 'd' => ['number' => 'ADJ-OUT/9', 'transDate' => '10/11/2026', 'branchName' => 'Palembang', 'adjustmentAccountNo' => '5101', 'description' => 'uji', 'detailItem' => [['item' => ['no' => 'ITM-1', 'name' => 'HSD'], 'itemAdjustmentType' => 'ADJUSTMENT_OUT', 'quantity' => 16000, 'warehouseName' => 'PLB']]]])
                ->push(['s' => false, 'd' => ['Transaksi tidak ditemukan']]),
        ]);
        $acc = ['nomor_penyesuaian' => 'ADJ-OUT/9', 'kode_item' => 'ITM-1', 'akun_penyesuaian' => '5101', 'nomor_penyesuaian_terima' => 'ADJ-IN/9', 'kode_item_terima' => 'ITM-2'];
        $this->postJson("$u/terima", $this->isi() + $acc)->assertOk();
        $x = $this->getJson("$u/prd/{$this->prd}/accurate")->assertOk()->json();
        $this->assertSame(['IN', 'OUT'], collect($x['transaksi'])->pluck('tipe')->sort()->values()->all());
        $out = collect($x['transaksi'])->firstWhere('tipe', 'OUT');
        $this->assertSame('ADJ-OUT/9', $out['accurate']['nomor']);
        $this->assertSame('ITM-1', $out['items'][0]['kode']);
        $this->assertEqualsWithDelta(16000, $out['volume_syop'], 0.001);
        $in = collect($x['transaksi'])->firstWhere('tipe', 'IN');
        $this->assertStringContainsString('Transaksi tidak ditemukan', $in['pesan']);
        $this->assertNotEmpty($x['do']['nomor_ti']);

        $c = $this->getJson("$u/cetak-received")->assertOk()->json();
        $this->assertNotEmpty($c['baris']);
        $this->assertNotEmpty($c['transportir']);
        $this->assertSame(number_format((float) DB::table('pro_po_ds_trucking_detail as a')->join('pro_trucking_plan as e', 'a.id_plan', '=', 'e.id_plan')->where('e.id_trucking', $this->ti)->sum('a.realisasi_volume')), $c['total']);
        $this->getJson('/api/v1/trucking-ti/99999999/cetak-received')->assertNotFound();
    }
}
