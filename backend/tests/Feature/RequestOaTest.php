<?php

namespace Tests\Feature;

use App\Models\AclUser;
use App\Services\Accurate\AccurateOaSync;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RequestOaTest extends TestCase
{
    use DatabaseTransactions;

    private const BASE = 'https://zeus.accurate.id/accurate/api';

    private function as(int $role, array $abilities = ['*']): AclUser
    {
        $u = AclUser::where('id_role', $role)->where('is_active', 1)->firstOrFail();
        Sanctum::actingAs($u, $abilities);

        return $u;
    }

    /**
     * Buat request yang menunggu CEO di atas Delivery Request nyata (rantai PO > DR > plan > customer harus utuh supaya daftar dan detail terbaca).
     * Source lama dan baru dibuat di dua terminal berbeda; stok virtual DR dipasang seperti yang dibuat saat DR disimpan. Semua dibatalkan oleh rollback.
     */
    private function pending(bool $split = false): int
    {
        $c = DB::table('pro_po_detail as pod')
            ->join('pro_po as b', 'b.id_po', '=', 'pod.id_po')
            ->join('pro_pr_detail as c', 'c.id_prd', '=', 'pod.id_prd')
            ->join('pro_po_customer_plan as pl', 'pl.id_plan', '=', 'c.id_plan')
            ->join('pro_po_customer as poc', 'poc.id_poc', '=', 'pl.id_poc')
            ->join('pro_customer as d', 'd.id_customer', '=', 'poc.id_customer')
            ->join('pro_penawaran as pen', 'pen.id_penawaran', '=', 'poc.id_penawaran')
            ->whereNotNull('c.no_do_syop')->where('c.no_do_syop', '!=', '')
            ->orderByDesc('pod.id_pod')->first(['pod.id_pod', 'pod.id_po', 'pod.id_prd', 'c.id_pr', 'c.nomor_po_supplier', 'c.id_po_supplier', 'c.id_po_receive', 'c.pr_vendor']);
        $id = DB::table('pro_po_request_oa')->insertGetId([
            'id_pod' => $c->id_pod, 'id_prd' => $c->id_prd, 'id_pr' => $c->id_pr, 'id_po' => $c->id_po, 'status' => 3, 'alasan_request' => "Alasan uji\nbaris dua",
            'oa_lama' => 100, 'oa_baru' => 125, 'created_time' => now(), 'created_ip' => '127.0.0.1', 'created_by' => 'Uji Marketing',
            'procurement_pic' => 'Uji Procurement', 'procurement_tanggal' => now(), 'catatan_logistik' => 'OA baru dari logistik', 'logistik_pic' => 'Uji Logistik', 'logistik_tanggal' => now(),
        ]);
        [$t1, $t2] = DB::table('pro_master_terminal')->orderBy('id_master')->limit(2)->pluck('id_master')->all();
        $base = ['id_request' => $id, 'id_po_supplier' => (int) $c->id_po_supplier, 'id_po_receive' => (int) $c->id_po_receive, 'id_vendor' => (int) $c->pr_vendor, 'harga_beli' => 9000, 'created_time' => now(), 'created_by' => 'Uji'];
        DB::table('pro_po_request_oa_detail')->insert([
            $base + ['jenis' => 1, 'urutan' => 1, 'id_terminal' => $t1, 'nomor_po_supplier' => $c->nomor_po_supplier ?: 'PO/LAMA/UJI', 'volume' => 16000],
            $base + ['jenis' => 2, 'urutan' => 1, 'id_terminal' => $t2, 'nomor_po_supplier' => 'PO/BARU/UJI-1', 'volume' => $split ? 10000 : 16000],
        ]);
        if ($split) {
            DB::table('pro_po_request_oa_detail')->insert($base + ['jenis' => 2, 'urutan' => 2, 'id_terminal' => $t1, 'nomor_po_supplier' => 'PO/BARU/UJI-2', 'volume' => 6000]);
        }
        DB::table('new_pro_inventory_depot')->insert(['id_datanya' => 'generate DR', 'id_jenis' => 6, 'id_produk' => 6, 'id_terminal' => $t1, 'id_vendor' => (int) $c->pr_vendor, 'id_po_supplier' => (int) $c->id_po_supplier, 'id_po_receive' => (int) $c->id_po_receive,
            'id_prd' => $c->id_prd, 'id_pr' => $c->id_pr, 'out_inven_virtual' => 16000, 'tanggal_inven' => now(), 'created_time' => now(), 'created_ip' => '127.0.0.1', 'created_by' => 'Uji']);

        return $id;
    }

    private function mails(): array
    {
        return Mail::mailer()->getSymfonyTransport()->messages()->map(fn ($m) => $m->getOriginalMessage())->all();
    }

    private function recipients($mail): array
    {
        return array_map(fn ($a) => $a->getAddress(), $mail->getTo());
    }

    private function expected(array $roles, ?array $wil = null): array
    {
        $q = DB::table('acl_user')->whereIn('id_role', $roles)->where('is_active', 1)->whereNotNull('email_user')->where('email_user', '!=', '');
        if ($wil !== null) {
            $q->whereIn('id_wilayah', $wil);
        }

        return array_values(array_unique($q->pluck('email_user')->all()));
    }

    public function test_hanya_ceo_yang_boleh(): void
    {
        foreach ([7, 6, 5, 3] as $role) {
            $this->as($role);
            $this->getJson('/api/v1/request-oa')->assertForbidden();
        }
        $this->as(21);
        $this->getJson('/api/v1/request-oa')->assertOk();
        $me = $this->getJson('/api/v1/auth/me')->assertOk()->json('user.modules');
        $this->assertContains('oa', $me);
        $this->assertContains('forecast', $me);
    }

    public function test_daftar_menunggu_dan_riwayat(): void
    {
        $this->as(21);
        $id = $this->pending();
        $wait = $this->getJson('/api/v1/request-oa?tab=wait')->assertOk();
        $this->assertContains($id, array_column($wait->json('data'), 'id'));
        $this->assertSame(3, $wait->json('data.0.status'));
        $this->assertGreaterThanOrEqual(1, $wait->json('waiting'));
        $row = collect($wait->json('data'))->firstWhere('id', $id);
        $this->assertSame(25, $row['selisih']);
        $this->assertNotEmpty($row['depot_baru']);

        $done = $this->getJson('/api/v1/request-oa?tab=done')->assertOk();
        $this->assertNotContains($id, array_column($done->json('data'), 'id'));
        foreach ($done->json('data') as $x) {
            $this->assertContains($x['status'], [4, 5]);
        }
        $this->getJson('/api/v1/request-oa?tab=all&q='.urlencode($row['nomor_po']))->assertOk()->assertJsonFragment(['id' => $id]);
    }

    public function test_detail_memuat_source_lama_baru_dan_selisih(): void
    {
        $this->as(21);
        $id = $this->pending();
        $d = $this->getJson("/api/v1/request-oa/$id")->assertOk()->json();
        $this->assertTrue($d['can_decide']);
        $this->assertSame(25, $d['selisih']);
        $this->assertNotEmpty($d['sebelum']['items']);
        $this->assertNotEmpty($d['sesudah']['depot']);
        $this->assertNotNull($d['nomor_po']);
        $this->getJson('/api/v1/request-oa/99999999')->assertNotFound();
        DB::table('pro_po_request_oa')->where('id_master', $id)->update(['status' => 4]);
        $this->assertFalse($this->getJson("/api/v1/request-oa/$id")->json('can_decide'));
    }

    public function test_setuju_menerapkan_source_baru_dan_mengirim_email_ke_logistik_cabang(): void
    {
        $this->as(21);
        $id = $this->pending();
        $r = DB::table('pro_po_request_oa')->where('id_master', $id)->first();
        $this->postJson("/api/v1/request-oa/$id/keputusan", ['keputusan' => 'approve', 'catatan' => "Setuju\ndengan catatan"])->assertOk();

        $after = DB::table('pro_po_request_oa')->where('id_master', $id)->first();
        $this->assertSame(4, (int) $after->status);
        $this->assertSame(1, (int) $after->ceo_result);
        $this->assertSame('Setuju<br />dengan catatan', $after->ceo_catatan);
        $this->assertNotNull($after->ceo_tanggal);
        $this->assertSame((int) $r->oa_baru, (int) DB::table('pro_po_detail')->where('id_pod', $r->id_pod)->value('ongkos_po_real'));
        $utama = DB::table('pro_po_request_oa_detail')->where('id_request', $id)->where('jenis', 2)->orderBy('urutan')->first();
        $prd = DB::table('pro_pr_detail')->where('id_prd', $r->id_prd)->first();
        $this->assertSame((int) $utama->id_terminal, (int) $prd->pr_terminal);
        $this->assertSame($utama->nomor_po_supplier, $prd->nomor_po_supplier);
        $this->assertSame(1, DB::table('new_pro_inventory_depot')->where('id_prd', $r->id_prd)->where('id_jenis', 6)->count());
        $this->assertSame(0, (int) $prd->is_split);
        $this->assertSame(0, DB::table('new_pro_inventory_potongan_stock')->where('id_prd', $r->id_prd)->count());

        $m = $this->mails();
        $this->assertStringStartsWith('Perubahan OA Disetujui - DO ', $m[0]->getSubject());
        $wil = DB::table('pro_po')->where('id_po', $r->id_po)->value('id_wilayah');
        $this->assertEqualsCanonicalizing($this->expected([9], [(int) $wil]), $this->recipients($m[0]));
        $this->assertStringContainsString('Ongkos Angkut', $m[0]->getHtmlBody());
    }

    public function test_setuju_dengan_split_menulis_dua_porsi(): void
    {
        $this->as(21);
        $id = $this->pending(true);
        $r = DB::table('pro_po_request_oa')->where('id_master', $id)->first();
        $this->postJson("/api/v1/request-oa/$id/keputusan", ['keputusan' => 'approve'])->assertOk();
        $prd = DB::table('pro_pr_detail')->where('id_prd', $r->id_prd)->first();
        $this->assertSame(1, (int) $prd->is_split);
        $this->assertSame(10000, (int) $prd->vol_potongan);
        $this->assertSame('PO/BARU/UJI-1', $prd->nomor_po_supplier);
        $pot = DB::table('new_pro_inventory_potongan_stock')->where('id_prd', $r->id_prd)->get();
        $this->assertCount(1, $pot);
        $this->assertSame(6000, (int) $pot[0]->volume);
        $this->assertSame('PO/BARU/UJI-2', $pot[0]->nomor_po_supplier);
        $inv = DB::table('new_pro_inventory_depot')->where('id_prd', $r->id_prd)->where('id_jenis', 6)->orderBy('out_inven_virtual')->pluck('out_inven_virtual')->map(fn ($v) => (int) $v)->all();
        $this->assertSame([6000, 10000], $inv);
    }

    public function test_tolak_mengembalikan_ke_procurement_dan_email_purchasing(): void
    {
        $this->as(21);
        $id = $this->pending();
        $this->postJson("/api/v1/request-oa/$id/keputusan", ['keputusan' => 'reject'])->assertStatus(422)->assertJsonPath('code', 'NOTE_REQUIRED');
        $this->postJson("/api/v1/request-oa/$id/keputusan", ['keputusan' => 'reject', 'catatan' => 'OA terlalu tinggi'])->assertOk();
        $after = DB::table('pro_po_request_oa')->where('id_master', $id)->first();
        $this->assertSame(1, (int) $after->status);
        $this->assertSame(2, (int) $after->ceo_result);
        $this->assertSame('OA terlalu tinggi', $after->ceo_catatan);
        $lama = DB::table('pro_po_request_oa_detail')->where('id_request', $id)->where('jenis', 1)->orderBy('urutan')->value('id_terminal');
        $this->assertSame((int) $lama, (int) DB::table('pro_po_detail')->where('id_pod', $after->id_pod)->value('terminal_po'));
        $m = $this->mails();
        $this->assertStringStartsWith('Perubahan OA Ditolak - DO ', $m[0]->getSubject());
        $this->assertEqualsCanonicalizing($this->expected([5]), $this->recipients($m[0]));
        $this->assertStringContainsString('OA terlalu tinggi', $m[0]->getHtmlBody());
    }

    public function test_tidak_bisa_diputuskan_dua_kali_dan_yang_bukan_status_3(): void
    {
        $this->as(21);
        $id = $this->pending();
        $this->postJson("/api/v1/request-oa/$id/keputusan", ['keputusan' => 'approve'])->assertOk();
        $this->postJson("/api/v1/request-oa/$id/keputusan", ['keputusan' => 'approve'])->assertStatus(409)->assertJsonPath('code', 'NOT_PENDING');
        $this->postJson('/api/v1/request-oa/99999999/keputusan', ['keputusan' => 'approve'])->assertNotFound();
        $this->postJson("/api/v1/request-oa/$id/keputusan", ['keputusan' => 'x'])->assertStatus(422);
    }

    public function test_sesi_wajah_wajib_konfirmasi_password(): void
    {
        $this->as(21, ['read']);
        $id = $this->pending();
        $this->postJson("/api/v1/request-oa/$id/keputusan", ['keputusan' => 'approve'])->assertForbidden()->assertJsonPath('code', 'STEP_UP_REQUIRED');
        $this->postJson("/api/v1/request-oa/$id/keputusan", ['keputusan' => 'approve', 'password' => 'salah-total'])->assertStatus(422)->assertJsonPath('code', 'STEP_UP_FAILED');
        $this->assertSame(3, (int) DB::table('pro_po_request_oa')->where('id_master', $id)->value('status'));
    }

    public function test_badge_dan_item_menunggu(): void
    {
        $this->as(21);
        $before = $this->getJson('/api/v1/badges')->assertOk()->json('oa');
        $id = $this->pending();
        $this->assertSame($before + 1, $this->getJson('/api/v1/badges')->json('oa'));
        $items = \App\Services\WaitingCounts::items(AclUser::where('id_role', 21)->where('is_active', 1)->first());
        $this->assertArrayHasKey($id, $items['oa']);
        $this->as(7);
        $this->assertSame(0, $this->getJson('/api/v1/badges')->json('oa'));
    }

    /** Pasang data Accurate pada request uji: DR tersambung (DO + SO) dan PO Supplier baru yang punya id Accurate dan gudang. */
    private function sambungAccurate(int $id): void
    {
        $po = DB::table('vw_terminal_inventory_receive as v')->join('new_pro_inventory_vendor_po as p', 'p.nomor_po', '=', 'v.nomor_po_supplier')
            ->whereNotNull('p.id_accurate')->first(['v.nomor_po_supplier']);
        DB::table('pro_po_request_oa_detail')->where('id_request', $id)->where('jenis', 2)->update(['nomor_po_supplier' => $po->nomor_po_supplier]);
        $r = DB::table('pro_po_request_oa')->where('id_master', $id)->first();
        $plan = DB::table('pro_pr_detail')->where('id_prd', $r->id_prd)->value('id_plan');
        DB::table('pro_pr_detail')->where('id_prd', $r->id_prd)->update(['id_do_accurate' => 5001]);
        DB::table('pro_po_customer_plan')->where('id_plan', $plan)->update(['id_accurate' => 4001, 'no_so' => 'SO-UJI']);
        config(['accurate.enabled' => true, 'accurate.token' => 'tok-uji', 'accurate.secret' => 'rahasia-uji', 'accurate.rate_window' => 0]);
    }

    private function fakeAccurate(bool $soGagal = false): void
    {
        $detail = fn () => ['s' => true, 'd' => ['transDate' => '01/11/2026', 'detailItem' => [
            ['id' => 1, 'item' => ['no' => 'BBM-LAMA', 'itemType' => 'INVENTORY'], 'quantity' => 16000, 'unitPrice' => 11000, 'department' => ['name' => 'Samarinda']],
            ['id' => 2, 'item' => ['no' => 'NS-009', 'itemType' => 'SERVICE'], 'quantity' => 16000, 'unitPrice' => 500, 'department' => ['name' => 'Samarinda']],
        ]]];
        Http::fake([
            self::BASE.'/purchase-order/detail.do*' => Http::response(['s' => true, 'd' => ['detailItem' => [['item' => ['no' => 'BBM-BARU', 'itemType' => 'INVENTORY']]]]]),
            self::BASE.'/delivery-order/detail.do*' => Http::response($detail()),
            self::BASE.'/delivery-order/save.do' => Http::response(['s' => true]),
            self::BASE.'/sales-order/detail.do*' => Http::response($detail()),
            self::BASE.'/sales-order/save.do' => $soGagal ? Http::response(['s' => false, 'd' => ['Barang tidak dapat diubah']]) : Http::response(['s' => true]),
        ]);
    }

    public function test_setuju_menyinkronkan_do_lalu_so_ke_accurate(): void
    {
        $this->as(21);
        $id = $this->pending();
        $this->sambungAccurate($id);
        $this->fakeAccurate();
        $this->postJson("/api/v1/request-oa/$id/keputusan", ['keputusan' => 'approve'])->assertOk();

        $paths = [];
        Http::assertSent(function ($req) use (&$paths) {
            $paths[] = parse_url($req->url(), PHP_URL_PATH);

            return true;
        });
        $save = array_values(array_filter($paths, fn ($p) => str_ends_with($p, 'save.do')));
        $this->assertSame(['/accurate/api/delivery-order/save.do', '/accurate/api/sales-order/save.do'], $save); // DO dulu, baru SO
        Http::assertSent(function ($req) {
            if (! str_ends_with($req->url(), 'delivery-order/save.do')) {
                return false;
            }
            $items = $req->data()['detailItem'];
            $hapus = array_values(array_filter($items, fn ($i) => ($i['_status'] ?? null) === 'delete'));
            $baru = array_values(array_filter($items, fn ($i) => ($i['itemNo'] ?? null) === 'BBM-BARU'));

            return count($hapus) === 1 && $hapus[0]['itemNo'] === 'BBM-LAMA' && count($baru) >= 1 && $baru[0]['unitPrice'] == 11000
                && $baru[0]['quantity'] == 16000 && ! empty($baru[0]['warehouseName']) && $req->data()['number'] !== null;
        });
        $this->assertSame(4, (int) DB::table('pro_po_request_oa')->where('id_master', $id)->value('status'));
    }

    public function test_so_gagal_di_accurate_membatalkan_perubahan_database(): void
    {
        $this->as(21);
        $id = $this->pending();
        $this->sambungAccurate($id);
        $this->fakeAccurate(true);
        $r = DB::table('pro_po_request_oa')->where('id_master', $id)->first();
        $prdSebelum = (array) DB::table('pro_pr_detail')->where('id_prd', $r->id_prd)->first();
        $this->postJson("/api/v1/request-oa/$id/keputusan", ['keputusan' => 'approve'])->assertStatus(422)->assertJsonPath('code', 'ACCURATE')
            ->assertJsonFragment(['message' => 'Update Sales Order Accurate gagal: Barang tidak dapat diubah']);
        $this->assertSame(3, (int) DB::table('pro_po_request_oa')->where('id_master', $id)->value('status'));
        $this->assertSame($prdSebelum, (array) DB::table('pro_pr_detail')->where('id_prd', $r->id_prd)->first());
        $this->assertSame([], $this->mails());
    }

    public function test_dr_yang_belum_tersambung_accurate_dilewati(): void
    {
        $this->as(21);
        $id = $this->pending();
        config(['accurate.enabled' => true, 'accurate.token' => 'tok-uji', 'accurate.secret' => 'rahasia-uji']);
        $r = DB::table('pro_po_request_oa')->where('id_master', $id)->first();
        DB::table('pro_pr_detail')->where('id_prd', $r->id_prd)->update(['id_do_accurate' => null]);
        Http::preventStrayRequests();
        $this->postJson("/api/v1/request-oa/$id/keputusan", ['keputusan' => 'approve'])->assertOk();
    }

    public function test_susun_detail_mengganti_baris_inventory_dan_menjaga_item_lain(): void
    {
        $lama = [
            ['id' => 1, 'item' => ['no' => 'A', 'itemType' => 'INVENTORY'], 'quantity' => 100, 'unitPrice' => 10, 'department' => ['name' => 'X']],
            ['id' => 2, 'item' => ['no' => 'NS-001', 'itemType' => 'SERVICE'], 'quantity' => 100, 'unitPrice' => 3, 'department' => ['name' => 'X']],
        ];
        $baru = AccurateOaSync::susun($lama, [['itemNo' => 'B', 'volume' => 60, 'warehouseName' => 'G1'], ['itemNo' => 'C', 'volume' => 40, 'warehouseName' => 'G2']], 'Cab');
        $this->assertCount(4, $baru);
        $this->assertSame('NS-001', $baru[0]['itemNo']);
        $this->assertSame('delete', $baru[1]['_status']);
        $this->assertSame([60, 40], [$baru[2]['quantity'], $baru[3]['quantity']]);
        $this->assertSame([10, 10], [$baru[2]['unitPrice'], $baru[3]['unitPrice']]);
        $this->assertSame(['G1', 'G2'], [$baru[2]['warehouseName'], $baru[3]['warehouseName']]);
    }
}
