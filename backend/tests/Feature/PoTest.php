<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PoTest extends TestCase
{
    use DatabaseTransactions;

    private const PO = 'new_pro_inventory_vendor_po';

    private function as(int $role, array $abilities = ['*']): AclUser
    {
        $u = AclUser::where('id_role', $role)->where('is_active', 1)->firstOrFail();
        Sanctum::actingAs($u, $abilities);

        return $u;
    }

    /** Salin PO selesai menjadi PO yang menunggu CEO (di-rollback otomatis). */
    private function pending(int $id = 209901000000001, string $nomor = 'TEST/PO/CEO'): int
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


    /** Email yang tertangkap oleh mailer "array" (phpunit.xml) selama tes. */
    private function mails(): array
    {
        return \Illuminate\Support\Facades\Mail::mailer()->getSymfonyTransport()->messages()
            ->map(fn ($m) => $m->getOriginalMessage())->all();
    }

    private function recipients($mail): array
    {
        return array_map(fn ($a) => $a->getAddress(), $mail->getTo());
    }

    public function test_email_ke_purchasing_saat_setuju_dan_kembalikan(): void
    {
        $this->as(21);
        $id = $this->pending();
        $purchasing = array_values(array_unique(DB::table('acl_user')->where('id_role', 5)->where('is_active', 1)->whereNotNull('email_user')->where('email_user', '!=', '')->pluck('email_user')->all()));
        $this->assertNotEmpty($purchasing);

        $this->postJson("/api/v1/po/$id/keputusan", ['keputusan' => 'return', 'catatan' => 'Revisi harga'])->assertOk();
        $m = $this->mails();
        $this->assertCount(1, $m);
        $this->assertStringStartsWith('Pengembalian PO Supplier [', $m[0]->getSubject());
        $this->assertEqualsCanonicalizing($purchasing, $this->recipients($m[0]));
        $this->assertStringContainsString('meminta anda untuk merevisi ulang PO', $m[0]->getHtmlBody());
        $this->assertStringContainsString('TEST/PO/CEO', $m[0]->getHtmlBody());

        $id2 = $this->pending(209901000000002, 'TEST/PO/CEO/2');
        $this->postJson("/api/v1/po/$id2/keputusan", ['keputusan' => 'approve'])->assertOk();
        $m = $this->mails();
        $this->assertCount(2, $m);
        $this->assertStringStartsWith('Persetujuan PO Supplier[', $m[1]->getSubject());
        $this->assertStringContainsString('telah melakukan verifikasi PO Supplier', $m[1]->getHtmlBody());
    }

    public function test_email_tidak_dikirim_bila_keputusan_gagal(): void
    {
        $this->as(21);
        $this->postJson('/api/v1/po/1/keputusan', ['keputusan' => 'approve'])->assertStatus(404);
        $this->assertCount(0, $this->mails());
    }

    public function test_hanya_ceo(): void
    {
        $this->as(7);
        $this->getJson('/api/v1/po')->assertStatus(403);
        $this->as(21);
        $this->getJson('/api/v1/po?tab=done')->assertOk()->assertJsonStructure(['data', 'waiting', 'meta']);
    }

    public function test_daftar_menunggu_dan_detail(): void
    {
        $this->as(21);
        $id = $this->pending();
        $r = $this->getJson('/api/v1/po')->assertOk();
        $this->assertContains($id, array_column($r->json('data'), 'id'));
        $d = $this->getJson("/api/v1/po/$id")->assertOk();
        $this->assertTrue($d->json('can_decide'));
        $this->assertNotEmpty($d->json('nama_vendor'));
        $this->assertArrayHasKey('sebelumnya', $d->json());
    }

    public function test_setujui_menulis_seperti_web(): void
    {
        $u = $this->as(21);
        $id = $this->pending();
        $this->postJson("/api/v1/po/$id/keputusan", ['keputusan' => 'approve', 'catatan' => "OK <b>\nlanjut"])->assertOk();
        $po = DB::table(self::PO)->where('id_master', $id)->first();
        $this->assertSame(4, (int) $po->disposisi_po);
        $this->assertSame(1, (int) $po->ceo_result);
        $this->assertSame($u->fullname, $po->ceo_pic);
        $this->assertSame('OK &lt;b&gt;<br />lanjut', $po->ceo_summary);
        $this->postJson("/api/v1/po/$id/keputusan", ['keputusan' => 'approve'])->assertStatus(409);
    }

    public function test_kembalikan_wajib_catatan(): void
    {
        $this->as(21);
        $id = $this->pending();
        $this->postJson("/api/v1/po/$id/keputusan", ['keputusan' => 'return'])->assertStatus(422);
        $this->postJson("/api/v1/po/$id/keputusan", ['keputusan' => 'return', 'catatan' => 'Harga belum sesuai'])->assertOk();
        $po = DB::table(self::PO)->where('id_master', $id)->first();
        $this->assertSame(5, (int) $po->disposisi_po);
        $this->assertSame(1, (int) $po->revert_ceo);
    }

    public function test_sesi_wajah_butuh_password(): void
    {
        $this->as(21, ['face']);
        $id = $this->pending();
        $this->postJson("/api/v1/po/$id/keputusan", ['keputusan' => 'approve'])->assertStatus(403)->assertJsonPath('code', 'STEP_UP_REQUIRED');
    }

    public function test_po_yang_belum_lewat_cfo_tidak_bisa_diputuskan(): void
    {
        $this->as(21);
        $id = $this->pending();
        DB::table(self::PO)->where('id_master', $id)->update(['disposisi_po' => 1, 'cfo_result' => 0]);
        $this->postJson("/api/v1/po/$id/keputusan", ['keputusan' => 'approve'])->assertStatus(409);
    }

    public function test_bisa_ditolak_bila_accurate_diwajibkan(): void
    {
        $this->as(21);
        $id = $this->pending();
        DB::table(self::PO)->where('id_master', $id)->update(['id_accurate' => 123]);
        config(['orca.po_allow_without_accurate' => false]);
        $this->postJson("/api/v1/po/$id/keputusan", ['keputusan' => 'approve'])->assertStatus(409)->assertJsonPath('code', 'ACCURATE_UNSUPPORTED');
    }
}
