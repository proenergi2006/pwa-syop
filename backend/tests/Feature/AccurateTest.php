<?php

namespace Tests\Feature;

use App\Models\AclUser;
use App\Services\Accurate\AccurateClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Semua panggilan Accurate di sini DITIRUKAN (Http::fake); tidak ada yang menyentuh server Accurate sungguhan. */
class AccurateTest extends TestCase
{
    use DatabaseTransactions;

    private const BASE = 'https://zeus.accurate.id/accurate/api';

    private function on(): void
    {
        config(['accurate.enabled' => true, 'accurate.token' => 'tok-uji', 'accurate.secret' => 'rahasia-uji', 'accurate.rate_window' => 0]);
        Http::preventStrayRequests();
    }

    public function test_nonaktif_secara_bawaan_dan_tanpa_token(): void
    {
        config(['accurate.enabled' => false, 'accurate.token' => 'x', 'accurate.secret' => 'y']);
        $this->assertFalse(app(AccurateClient::class)->enabled());
        config(['accurate.enabled' => true, 'accurate.token' => null]);
        $this->assertFalse(app(AccurateClient::class)->enabled());
    }

    public function test_header_tanda_tangan_dan_normalisasi(): void
    {
        $this->on();
        Http::fake([
            self::BASE.'/a.do*' => Http::response(['s' => true, 'r' => ['id' => 5]]),
            self::BASE.'/html.do*' => Http::response('<html>expired</html>', 401),
            self::BASE.'/err.do*' => Http::response(['d' => ['x']], 500),
        ]);
        $c = app(AccurateClient::class);
        $this->assertSame(['s' => true, 'r' => ['id' => 5]], $c->get('a.do', ['id' => 1]));
        Http::assertSent(function (HttpRequest $r) use ($c) {
            $ts = $r->header('X-Api-Timestamp')[0] ?? '';

            return str_contains($r->url(), 'a.do?id=1') && preg_match('#^\d{2}/\d{2}/\d{4} \d{2}:\d{2}:\d{2}$#', $ts)
                && ($r->header('X-Api-Signature')[0] ?? '') === base64_encode(hash_hmac('sha256', $ts, 'rahasia-uji', true))
                && ($r->header('Authorization')[0] ?? '') === 'Bearer tok-uji';
        });
        $bad = $c->post('html.do', ['a' => 1]);
        $this->assertFalse($bad['s']);
        $this->assertStringContainsString('bukan JSON', $bad['d'][0]);
        $e = $c->delete('err.do', ['id' => 1]);
        $this->assertFalse($e['s']);
        $this->assertSame(['x'], $e['d']);
    }

    public function test_batch_membatasi_paralel_dan_mengembalikan_per_kunci(): void
    {
        $this->on();
        Http::fake([self::BASE.'/*' => Http::response(['s' => true, 'r' => ['ok' => 1]])]);
        $reqs = [];
        foreach (range(1, 20) as $i) {
            $reqs["k$i"] = ['method' => $i % 2 ? 'POST' : 'GET', 'path' => 'x.do', 'data' => ['i' => $i], 'query' => ['i' => $i]];
        }
        $res = app(AccurateClient::class)->batch($reqs, 8);
        $this->assertCount(20, $res);
        $this->assertTrue($res['k7']['s']);
        Http::assertSentCount(20);
    }

    // ---- Delivery Request

    private function as5(): void
    {
        Sanctum::actingAs(AclUser::where('id_role', 5)->where('is_active', 1)->firstOrFail(), ['*']);
    }

    /** @return array{0:int,1:array} id DR menunggu dan PO Supplier terbesar yang dibuat "tercatat di Accurate" */
    private function siapkan(): array
    {
        $this->as5();
        $this->on();
        $id = (int) DB::table('pro_pr')->where('disposisi_pr', 3)->where('purchasing_result', 0)->orderBy('id_pr')->value('id_pr');
        $po = collect($this->getJson("/api/v1/dr/$id/po-supplier")->json('data'))->sortByDesc('sisa')->first();
        DB::table('new_pro_inventory_vendor_po')->where('nomor_po', $po['nomor_po'])->update(['id_accurate' => 777]);

        return [$id, $po];
    }

    private function payload(int $id, array $po): array
    {
        $lines = [];
        foreach ($this->getJson("/api/v1/dr/$id")->json('lines') as $l) {
            $lines[] = ['id_prd' => $l['id_prd'], 'volume' => $l['vol_ori'], 'po' => ['id_po_supplier' => $po['id_po_supplier'], 'id_po_receive' => $po['id_po_receive'], 'id_terminal' => $po['id_terminal']]];
        }

        return ['catatan' => 'Catatan uji', 'lines' => $lines];
    }

    private function fakeOk(bool $withDelete = true): void
    {
        Http::fake(array_filter([
            self::BASE.'/purchase-order/detail.do*' => Http::response(['s' => true, 'd' => ['detailItem' => [
                ['item' => ['itemType' => 'SERVICE', 'no' => 'JASA'], 'id' => 1],
                ['item' => ['itemType' => 'INVENTORY', 'no' => 'ITM-HSD'], 'id' => 2],
            ]]]),
            self::BASE.'/sales-order/save.do' => Http::response(['s' => true, 'r' => ['id' => 111]]),
            self::BASE.'/delivery-order/save.do' => Http::response(['s' => true, 'r' => ['id' => 222]]),
            $withDelete ? self::BASE.'/*/delete.do' : null => $withDelete ? Http::response(['s' => true]) : null,
        ]));
    }

    public function test_simpan_dr_membuat_so_lalu_do_di_accurate(): void
    {
        [$id, $po] = $this->siapkan();
        $this->fakeOk();
        $this->postJson("/api/v1/dr/$id/verifikasi", $this->payload($id, $po))->assertOk();

        $so = collect(Http::recorded())->map(fn ($p) => $p[0])->first(fn (HttpRequest $r) => str_ends_with($r->url(), 'sales-order/save.do'));
        $do = collect(Http::recorded())->map(fn ($p) => $p[0])->first(fn (HttpRequest $r) => str_ends_with($r->url(), 'delivery-order/save.do'));
        $this->assertNotNull($so);
        $this->assertNotNull($do);
        $sd = $so->data();
        $this->assertFalse($sd['taxable']);
        $this->assertSame(date('d/m/Y'), $sd['transDate']);
        $this->assertStringStartsWith('SO dari PO ', $sd['description']);
        $this->assertContains('ITM-HSD', array_column($sd['detailItem'], 'itemNo'));
        $this->assertContains('NS-009', array_column($sd['detailItem'], 'itemNo'));
        $dd = $do->data();
        $this->assertStringStartsWith('DO dari PO ', $dd['description']);
        $this->assertStringContainsString('Catatan uji', $dd['description']);
        $this->assertSame($sd['number'], $dd['detailItem'][0]['salesOrderNumber']);
        $this->assertSame(1, (int) DB::table('pro_pr')->where('id_pr', $id)->value('purchasing_result'));
        $this->assertSame(111, (int) DB::table('pro_po_customer_plan')->whereIn('id_plan', DB::table('pro_pr_detail')->where('id_pr', $id)->pluck('id_plan'))->value('id_accurate'));
        $this->assertSame(222, (int) DB::table('pro_pr_detail')->where('id_pr', $id)->whereNotNull('id_do_accurate')->value('id_do_accurate'));
    }

    public function test_gagal_do_membatalkan_so_dan_database(): void
    {
        [$id, $po] = $this->siapkan();
        Http::fake([
            self::BASE.'/purchase-order/detail.do*' => Http::response(['s' => true, 'd' => ['detailItem' => [['item' => ['itemType' => 'INVENTORY', 'no' => 'ITM-HSD'], 'id' => 2]]]]),
            self::BASE.'/sales-order/save.do' => Http::response(['s' => true, 'r' => ['id' => 111]]),
            self::BASE.'/delivery-order/save.do' => Http::response(['s' => false, 'd' => ['Item tidak cukup']]),
            self::BASE.'/*/delete.do' => Http::response(['s' => true]),
        ]);
        $r = $this->postJson("/api/v1/dr/$id/verifikasi", $this->payload($id, $po))->assertStatus(502)->assertJsonPath('code', 'ACCURATE');
        $this->assertStringContainsString('Item tidak cukup - Response dari Accurate DO', $r->json('message'));
        Http::assertSent(fn (HttpRequest $q) => str_ends_with($q->url(), 'sales-order/delete.do') && $q->data()['id'] === 111);
        $this->assertSame(3, (int) DB::table('pro_pr')->where('id_pr', $id)->value('disposisi_pr'));
        $this->assertSame(0, DB::table('new_pro_inventory_depot')->where('id_pr', $id)->count());
    }

    public function test_tanpa_id_accurate_pada_po_tidak_memanggil_accurate(): void
    {
        $this->as5();
        $this->on();
        Http::fake();
        $id = (int) DB::table('pro_pr')->where('disposisi_pr', 3)->where('purchasing_result', 0)->orderBy('id_pr')->value('id_pr');
        $po = collect($this->getJson("/api/v1/dr/$id/po-supplier")->json('data'))->sortByDesc('sisa')->first();
        DB::table('new_pro_inventory_vendor_po')->where('nomor_po', $po['nomor_po'])->update(['id_accurate' => null]);
        $this->postJson("/api/v1/dr/$id/verifikasi", $this->payload($id, $po))->assertOk();
        Http::assertNothingSent();
    }

    public function test_revisi_menghapus_do_lalu_so_dan_gagal_menjaga_database(): void
    {
        [$id, $po] = $this->siapkan();
        $this->fakeOk(false);
        $this->postJson("/api/v1/dr/$id/verifikasi", $this->payload($id, $po))->assertOk();
        DB::table('pro_pr')->where('id_pr', $id)->update(['tanggal_pr' => now()->toDateString()]);

        Http::fake([
            self::BASE.'/delivery-order/delete.do' => Http::sequence()->push(['s' => false, 'd' => ['DO dipakai']])->push(['s' => true]),
            self::BASE.'/sales-order/delete.do' => Http::response(['s' => true]),
        ]);
        $this->postJson("/api/v1/dr/$id/revisi")->assertStatus(502);
        $this->assertSame(6, (int) DB::table('pro_pr')->where('id_pr', $id)->value('disposisi_pr')); // tidak berubah

        $this->postJson("/api/v1/dr/$id/revisi")->assertOk();
        $this->assertSame(3, (int) DB::table('pro_pr')->where('id_pr', $id)->value('disposisi_pr'));
        $this->assertSame(0, DB::table('pro_pr_detail')->where('id_pr', $id)->whereNotNull('id_do_accurate')->count());
        $this->assertSame(0, DB::table('pro_po_customer_plan')->whereIn('id_plan', DB::table('pro_pr_detail')->where('id_pr', $id)->pluck('id_plan'))->whereNotNull('id_accurate')->count());
    }

    // ---- PO Supplier (CEO)

    private function pendingPo(): int
    {
        $row = (array) DB::table('new_pro_inventory_vendor_po')->orderByDesc('id_master')->first();
        $row['id_master'] = (int) DB::table('new_pro_inventory_vendor_po')->max('id_master') + 1;
        $row['disposisi_po'] = 2;
        $row['cfo_result'] = 1;
        $row['ceo_result'] = 0;
        $row['revert_cfo'] = 0;
        $row['is_cancel'] = 0;
        $row['nomor_po'] = 'UJI/PO/ACC/'.uniqid();
        $row['id_accurate'] = 888;

        DB::table('new_pro_inventory_vendor_po')->insert($row);

        return (int) $row['id_master'];
    }

    public function test_persetujuan_po_ceo_membuka_kembali_po_di_accurate(): void
    {
        Sanctum::actingAs(AclUser::where('id_role', 21)->where('is_active', 1)->firstOrFail(), ['*']);
        $this->on();
        $id = $this->pendingPo();
        Http::fake([
            self::BASE.'/purchase-order/detail.do*' => Http::response(['s' => true, 'd' => ['toAddress' => 'Jl. Uji', 'detailItem' => [['id' => 9, 'item' => ['no' => 'ITM-1'], 'quantity' => 100, 'unitPrice' => 5000]]]]),
            self::BASE.'/purchase-order/save.do' => Http::response(['s' => true, 'r' => ['id' => 888]]),
        ]);
        $this->postJson("/api/v1/po/$id/keputusan", ['keputusan' => 'approve'])->assertOk()->assertJsonPath('accurate_manual', false);
        Http::assertSent(function (HttpRequest $r) {
            if (! str_ends_with($r->url(), 'purchase-order/save.do')) {
                return false;
            }
            $d = $r->data();

            return $d['id'] === 888 && $d['manualClosed'] === false && $d['toAddress'] === 'Jl. Uji'
                && $d['detailItem'][0] === ['id' => 9, 'itemNo' => 'ITM-1', 'quantity' => 100, 'unitPrice' => 5000, 'manualClosed' => false];
        });
        $this->assertSame(4, (int) DB::table('new_pro_inventory_vendor_po')->where('id_master', $id)->value('disposisi_po'));
    }

    public function test_persetujuan_po_dibatalkan_bila_accurate_menolak_atau_id_kosong(): void
    {
        Sanctum::actingAs(AclUser::where('id_role', 21)->where('is_active', 1)->firstOrFail(), ['*']);
        $this->on();
        $id = $this->pendingPo();
        Http::fake([self::BASE.'/purchase-order/detail.do*' => Http::response(['s' => false, 'd' => ['Token tidak valid']])]);
        $this->postJson("/api/v1/po/$id/keputusan", ['keputusan' => 'approve'])->assertStatus(502)->assertJsonPath('code', 'ACCURATE');
        $this->assertSame(2, (int) DB::table('new_pro_inventory_vendor_po')->where('id_master', $id)->value('disposisi_po'));

        DB::table('new_pro_inventory_vendor_po')->where('id_master', $id)->update(['id_accurate' => null]);
        $this->postJson("/api/v1/po/$id/keputusan", ['keputusan' => 'approve'])->assertStatus(502)->assertSee('Id accurate tidak ditemukan');
    }
}
