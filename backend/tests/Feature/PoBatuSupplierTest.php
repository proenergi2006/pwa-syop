<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PoBatuSupplierTest extends TestCase
{
    use DatabaseTransactions;

    private const PO = 'new_pro_inventory_vendor_po_crushed_stone';
    private const U = '/api/v1/po-batu-supplier';

    private function as(int $role): AclUser
    {
        $u = AclUser::where('id_role', $role)->where('is_active', 1)->firstOrFail();
        Sanctum::actingAs($u, ['*']);

        return $u;
    }

    private function payload(array $o = []): array
    {
        $c = DB::table(self::PO)->orderByDesc('id_master')->first();

        return $o + [
            'tanggal' => '2026-11-24', 'id_produk' => (int) $c->id_produk, 'id_terminal' => (int) $c->id_terminal, 'id_vendor' => (int) $c->id_vendor,
            'terms' => 'NET', 'terms_day' => 30, 'kd_tax' => 'E', 'volume_po' => 1000, 'harga_tebus' => 250000.5,
            'deskripsi' => 'Batu Pecah 1-2', 'ket' => 'Loading & QC', 'terms_condition' => '',
        ];
    }

    public function test_hanya_procurement(): void
    {
        foreach ([21, 7, 6] as $role) {
            $this->as($role);
            $this->getJson(self::U)->assertForbidden();
            $this->postJson(self::U, $this->payload())->assertForbidden();
        }
        $this->as(5);
        $this->getJson(self::U)->assertOk();
        $this->assertContains('po_supplier_batu', $this->getJson('/api/v1/auth/me')->json('user.modules') ?? []);
    }

    public function test_daftar_filter_dan_detail(): void
    {
        $this->as(5);
        $opsi = $this->getJson(self::U.'/opsi')->assertOk()->json();
        $this->assertNotEmpty($opsi['produk']);
        $this->assertNotEmpty($opsi['terminal']);
        $this->assertNotEmpty($opsi['vendor']);

        $r = $this->getJson(self::U.'?per_page=5')->assertOk()->json();
        $this->assertNotEmpty($r['data']);
        $x = $r['data'][0];
        $this->assertSame($r['meta']['total'], DB::table(self::PO)->where('harga_tebus', '>', 0)->count());
        $c = $this->getJson(self::U.'?q='.urlencode($x['nomor_po']))->assertOk()->json('data');
        $this->assertContains($x['nomor_po'], array_column($c, 'nomor_po'));
        $v = $this->getJson(self::U.'?vendor=0')->json('data');
        $this->assertSame([], $v);
        $d = $this->getJson(self::U.'/'.$x['id'])->assertOk()->json();
        $this->assertSame($x['nomor_po'], $d['nomor_po']);
        $this->assertContains('cetak', $x['aksi']); // PO yang sudah disetujui CEO bisa dicetak
        $this->getJson(self::U.'/999')->assertNotFound();
    }

    public function test_tambah_po_batu(): void
    {
        $u = $this->as(5);
        $r = $this->postJson(self::U, $this->payload())->assertCreated()->json();
        $po = DB::table(self::PO)->where('id_master', $r['id'])->first();
        $this->assertSame($r['nomor_po'], $po->nomor_po);
        $this->assertMatchesRegularExpression('#^\d{3}/[A-Z0-9]*/[A-Z0-9]*/(I|II|III|IV|V|VI|VII|VIII|IX|X|XI|XII)/26$#', $po->nomor_po);
        $this->assertSame(0, (int) $po->disposisi_po);
        $this->assertSame($u->fullname, $po->created_by);
        // 1000 x 250.000,5 = 250.000.500; PPN 11% dibulatkan; total = subtotal + PPN
        $this->assertEqualsWithDelta(250000500.0, (float) $po->subtotal, 0.01);
        $this->assertEqualsWithDelta(27500055.0, (float) $po->ppn_11, 0.01);
        $this->assertEqualsWithDelta(277500555.0, (float) $po->total_order, 0.01);
        $this->assertSame('Loading &amp; QC', $po->keterangan);
        $this->assertSame('30', (string) $po->terms_day);

        // nomor berikutnya (vendor, cabang, tahun sama) naik satu
        $r2 = $this->postJson(self::U, $this->payload(['kd_tax' => 'EC', 'terms' => 'COD', 'terms_day' => null, 'terms_condition' => "A\nB"]))->assertCreated()->json();
        $n1 = (int) substr($r['nomor_po'], 0, 3);
        $this->assertSame($n1 + 1, (int) substr($r2['nomor_po'], 0, 3));
        $po2 = DB::table(self::PO)->where('id_master', $r2['id'])->first();
        $this->assertEquals(0, $po2->ppn_11); // kode tax EC: tanpa PPN
        $this->assertEqualsWithDelta(250000500.0, (float) $po2->total_order, 0.01);
        $this->assertNull($po2->terms_day);
        $this->assertSame('A<br />B', $po2->terms_condition);
    }

    public function test_validasi(): void
    {
        $this->as(5);
        $this->postJson(self::U, $this->payload(['terms_day' => null]))->assertStatus(422)->assertJsonValidationErrors('terms_day'); // NET wajib hari
        $this->postJson(self::U, $this->payload(['volume_po' => 0]))->assertStatus(422);
        $this->postJson(self::U, $this->payload(['harga_tebus' => -1]))->assertStatus(422);
        $this->postJson(self::U, $this->payload(['kd_tax' => 'X']))->assertStatus(422);
        $this->postJson(self::U, $this->payload(['ket' => '']))->assertStatus(422);
        $this->postJson(self::U, $this->payload(['id_vendor' => 999999]))->assertStatus(422);
    }

    public function test_ubah_mengulang_persetujuan_dan_ajukan(): void
    {
        $u = $this->as(5);
        $id = $this->postJson(self::U, $this->payload())->assertCreated()->json('id');
        $cfo = array_values(array_unique(DB::table('acl_user')->where('id_role', 4)->where('is_active', 1)->whereNotNull('email_user')->where('email_user', '!=', '')->pluck('email_user')->all()));
        $this->postJson(self::U."/$id/persetujuan")->assertOk()->assertJsonPath('message', 'Persetujuan untuk PO crushed stone '.DB::table(self::PO)->where('id_master', $id)->value('nomor_po').' sudah diajukan');
        $m = \Illuminate\Support\Facades\Mail::mailer()->getSymfonyTransport()->messages()->map(fn ($x) => $x->getOriginalMessage())->all();
        $this->assertCount(1, $m);
        $this->assertStringStartsWith('Persetujuan PO crushed stone [', $m[0]->getSubject());
        $this->assertStringContainsString('Meminta Persetujuan Po Supplier Crushed Stone', $m[0]->getHtmlBody());
        if ($cfo) {
            $this->assertEqualsCanonicalizing($cfo, array_map(fn ($a) => $a->getAddress(), $m[0]->getTo()));
        }
        $this->assertSame(1, (int) DB::table(self::PO)->where('id_master', $id)->value('disposisi_po'));
        $this->postJson(self::U."/$id/persetujuan")->assertStatus(409); // sudah diajukan

        DB::table(self::PO)->where('id_master', $id)->update(['disposisi_po' => 4, 'cfo_result' => 1, 'ceo_result' => 1]);
        $this->putJson(self::U."/$id", $this->payload(['volume_po' => 2000, 'harga_tebus' => 100000, 'id_vendor' => null, 'id_produk' => null, 'id_terminal' => null]))->assertOk();
        $po = DB::table(self::PO)->where('id_master', $id)->first();
        $this->assertSame(0, (int) $po->disposisi_po);
        $this->assertSame(0, (int) $po->cfo_result);
        $this->assertSame(0, (int) $po->ceo_result);
        $this->assertEqualsWithDelta(200000000.0, (float) $po->subtotal, 0.01);
        $this->assertSame($u->fullname, $po->lastupdate_by);
        $this->assertNotNull($this->getJson(self::U."/$id/ubah")->assertOk()->json('nomor_po'));

        DB::table(self::PO)->where('id_master', $id)->update(['is_cancel' => 1]);
        $this->putJson(self::U."/$id", $this->payload())->assertStatus(409)->assertJsonPath('code', 'LOCKED');
    }

    public function test_cetak_untuk_procurement(): void
    {
        $this->as(5);
        $id = DB::table(self::PO)->where('disposisi_po', 4)->value('id_master');
        $this->getJson(self::U."/$id/cetak")->assertOk()->assertJsonPath('kepala.penandatangan', 'Vica Krisdianatha');
    }
}
