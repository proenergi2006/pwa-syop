<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TruckingTest extends TestCase
{
    use DatabaseTransactions;

    private const T = 'pro_trucking_instruction_req';

    private function as(int $role, array $abilities = ['*']): AclUser
    {
        $u = AclUser::where('id_role', $role)->where('is_active', 1)->firstOrFail();
        Sanctum::actingAs($u, $abilities);

        return $u;
    }

    /** Salin satu TI menjadi TI yang menunggu CEO (dibatalkan otomatis oleh rollback). */
    private function pending(): int
    {
        $row = (array) DB::table(self::T)->where('status', 7)->whereNotNull('id_transportir')->orderByDesc('id_master')->first();
        unset($row['id_master']);
        foreach (['ceo_pic', 'ceo_summary', 'ceo_tanggal', 'ket_cancel', 'tanggal_cancel', 'received_tanggal', 'received_pic'] as $k) {
            $row[$k] = null;
        }
        $row['status'] = 0;
        $row['ceo_result'] = 0;
        $row['is_cancel'] = 0;
        $row['is_received'] = 0;
        $row['nomor_ti'] = 'UJI/TI/'.uniqid();

        return DB::table(self::T)->insertGetId($row);
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

    /** Email akun aktif pada peran (dan wilayah) yang diharapkan menerima. */
    private function expected(array $roles, ?array $wil = null): array
    {
        $q = DB::table('acl_user')->whereIn('id_role', $roles)->where('is_active', 1)->whereNotNull('email_user')->where('email_user', '!=', '');
        if ($wil !== null) {
            $q->whereIn('id_wilayah', $wil);
        }

        return array_values(array_unique($q->pluck('email_user')->all()));
    }

    public function test_email_setuju_ke_purchasing_dan_logistik_cabang_tolak_tanpa_email(): void
    {
        $this->as(21);
        $id = $this->pending();
        $t = DB::table(self::T)->where('id_master', $id)->first();
        $this->postJson("/api/v1/trucking/$id/keputusan", ['keputusan' => 'approve'])->assertOk();
        $m = $this->mails();
        $this->assertStringStartsWith("Persetujuan Trucking Instruction [{$t->nomor_ti} - ", $m[0]->getSubject());
        $this->assertEqualsCanonicalizing($this->expected([5]), $this->recipients($m[0]));
        $logistik = $this->expected([9, 10], [(int) $t->id_wilayah]);
        $this->assertCount($logistik ? 2 : 1, $m);
        if ($logistik) {
            $this->assertStringStartsWith("Trucking Instruction Telah Disetujui [{$t->nomor_ti} - ", $m[1]->getSubject());
            $this->assertEqualsCanonicalizing($logistik, $this->recipients($m[1]));
            $this->assertStringContainsString('Delivery Plan', $m[1]->getHtmlBody());
        }

        $before = count($this->mails());
        $id2 = $this->pending();
        $this->postJson("/api/v1/trucking/$id2/keputusan", ['keputusan' => 'reject', 'catatan' => 'Ongkos tinggi'])->assertOk();
        $this->assertCount($before, $this->mails());
    }

    public function test_hanya_ceo(): void
    {
        $this->as(7);
        $this->getJson('/api/v1/trucking')->assertStatus(403);
        $this->as(21);
        $this->getJson('/api/v1/trucking?tab=all')->assertOk()->assertJsonStructure(['data' => [['nomor_ti', 'load_port', 'discharge_port', 'nama_transportir']], 'waiting', 'meta']);
    }

    public function test_daftar_menunggu_dan_detail(): void
    {
        $this->as(21);
        $id = $this->pending();
        $r = $this->getJson('/api/v1/trucking')->assertOk();
        $this->assertContains($id, array_column($r->json('data'), 'id'));
        $d = $this->getJson("/api/v1/trucking/$id")->assertOk();
        $this->assertTrue($d->json('can_decide'));
        $this->assertNotEmpty($d->json('nama_transportir'));
        $this->getJson('/api/v1/trucking/999999999')->assertStatus(404);
    }

    public function test_setujui_lalu_tidak_bisa_diputuskan_lagi(): void
    {
        $u = $this->as(21);
        $id = $this->pending();
        $this->postJson("/api/v1/trucking/$id/keputusan", ['keputusan' => 'approve', 'catatan' => 'OK <b>"siap"</b>'])->assertOk();
        $row = DB::table(self::T)->where('id_master', $id)->first();
        $this->assertSame(1, (int) $row->ceo_result);
        $this->assertSame(1, (int) $row->status);
        $this->assertSame($u->fullname, $row->ceo_pic);
        $this->assertStringNotContainsString('<b>', $row->ceo_summary); // aman untuk web (di-escape)
        $this->getJson("/api/v1/trucking/$id")->assertJsonPath('ceo_summary', 'OK <b>"siap"</b>')->assertJsonPath('can_decide', false);
        $this->postJson("/api/v1/trucking/$id/keputusan", ['keputusan' => 'approve'])->assertStatus(409);
    }

    public function test_tolak_wajib_alasan(): void
    {
        $this->as(21);
        $id = $this->pending();
        $this->postJson("/api/v1/trucking/$id/keputusan", ['keputusan' => 'reject'])->assertStatus(422);
        $this->postJson("/api/v1/trucking/$id/keputusan", ['keputusan' => 'reject', 'catatan' => 'Ongkos terlalu tinggi'])->assertOk();
        $row = DB::table(self::T)->where('id_master', $id)->first();
        $this->assertSame(2, (int) $row->ceo_result);
        $this->assertSame(1, (int) $row->status);
    }

    public function test_sesi_wajah_butuh_password_dan_yang_dibatalkan_ditolak(): void
    {
        $this->as(21, ['face']);
        $id = $this->pending();
        $this->postJson("/api/v1/trucking/$id/keputusan", ['keputusan' => 'approve'])->assertStatus(403)->assertJsonPath('code', 'STEP_UP_REQUIRED');

        $this->as(21);
        DB::table(self::T)->where('id_master', $id)->update(['is_cancel' => 1]);
        $this->postJson("/api/v1/trucking/$id/keputusan", ['keputusan' => 'approve'])->assertStatus(409);
    }
}
