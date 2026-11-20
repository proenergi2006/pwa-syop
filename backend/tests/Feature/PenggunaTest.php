<?php

namespace Tests\Feature;

use App\Models\AclUser;
use App\Support\LegacyPassword;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PenggunaTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): AclUser
    {
        $u = AclUser::where('id_role', 1)->where('is_active', 1)->firstOrFail();
        Sanctum::actingAs($u, ['*']);
        config(['orca.password_salt' => 'garam-uji']);

        return $u;
    }

    private function baru(array $o = []): array
    {
        return array_merge([
            'username' => 'uji.pengguna', 'fullname' => 'Uji Pengguna', 'email' => 'uji@example.com', 'telepon' => '0811',
            'password' => 'Rahasia123', 'password_confirmation' => 'Rahasia123', 'id_role' => 22, 'id_wilayah' => 1,
        ], $o);
    }

    public function test_hanya_super_admin(): void
    {
        foreach ([21, 7, 5] as $role) {
            Sanctum::actingAs(AclUser::where('id_role', $role)->where('is_active', 1)->firstOrFail(), ['*']);
            $this->getJson('/api/v1/sistem/pengguna')->assertForbidden();
            $this->postJson('/api/v1/sistem/pengguna', $this->baru())->assertForbidden();
            $this->postJson('/api/v1/sistem/pengguna/1/reset-password')->assertForbidden();
        }
    }

    public function test_daftar_dan_filter(): void
    {
        $this->admin();
        $r = $this->getJson('/api/v1/sistem/pengguna?status=2&per_page=5')->assertOk()->json();
        $this->assertCount(5, $r['data']);
        $this->assertGreaterThan(5, $r['meta']['total']);
        $this->assertArrayNotHasKey('password', $r['data'][0]);

        $ceo = $this->getJson('/api/v1/sistem/pengguna?role=21&status=1')->assertOk()->json('data');
        $this->assertNotEmpty($ceo);
        foreach ($ceo as $x) {
            $this->assertSame(21, $x['id_role']);
        }
        $nama = $ceo[0]['username'];
        $this->assertContains($nama, array_column($this->getJson('/api/v1/sistem/pengguna?status=2&q='.$nama)->json('data'), 'username'));

        $o = $this->getJson('/api/v1/sistem/pengguna/opsi')->assertOk()->json();
        $this->assertNotEmpty($o['peran']);
        $this->assertNotEmpty($o['cabang']);
    }

    public function test_tambah_pengguna_bisa_login_seperti_web(): void
    {
        $this->admin();
        $id = $this->postJson('/api/v1/sistem/pengguna', $this->baru())->assertCreated()->json('id');
        $row = DB::table('acl_user')->where('id_user', $id)->first();
        $this->assertSame(22, (int) $row->id_role);
        $this->assertTrue(LegacyPassword::check('Rahasia123', $row->password));
        $this->assertStringStartsWith('$2a$08$', $row->password);
        $this->assertSame(1, (int) $row->is_active);
        $this->assertDatabaseHas('mobile_auth_log', ['id_user' => $id, 'event' => 'pengguna_tambah']);
        $this->postJson('/api/v1/auth/login', ['username' => 'uji.pengguna', 'password' => 'Rahasia123'])->assertOk();
    }

    public function test_validasi_tambah(): void
    {
        $this->admin();
        $this->postJson('/api/v1/sistem/pengguna', $this->baru(['password_confirmation' => 'beda']))->assertStatus(422);
        $this->postJson('/api/v1/sistem/pengguna', $this->baru(['email' => 'salah']))->assertStatus(422);
        $this->postJson('/api/v1/sistem/pengguna', $this->baru(['id_role' => 9999]))->assertStatus(422);
        $this->postJson('/api/v1/sistem/pengguna', $this->baru(['id_role' => 12]))->assertStatus(422); // transportir wajib
        $this->postJson('/api/v1/sistem/pengguna', $this->baru(['id_role' => 13]))->assertStatus(422); // terminal wajib
        $this->postJson('/api/v1/sistem/pengguna', $this->baru(['id_role' => 17]))->assertStatus(422); // OM wajib
        $this->postJson('/api/v1/sistem/pengguna', $this->baru())->assertCreated();
        $this->postJson('/api/v1/sistem/pengguna', $this->baru())->assertStatus(422); // username kembar
    }

    public function test_nonaktif_mencabut_token_dan_tidak_boleh_diri_sendiri(): void
    {
        $adm = $this->admin();
        $id = $this->postJson('/api/v1/sistem/pengguna', $this->baru())->json('id');
        $tok = AclUser::find($id)->createToken('uji')->plainTextToken;
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $id)->count());

        $this->putJson("/api/v1/sistem/pengguna/$id", ['aktif' => false])->assertOk();
        $this->assertSame(0, (int) DB::table('acl_user')->where('id_user', $id)->value('is_active'));
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $id)->count());
        $this->assertNotEmpty($tok);

        $this->putJson("/api/v1/sistem/pengguna/$id", ['aktif' => true])->assertOk();
        $this->assertSame(1, (int) DB::table('acl_user')->where('id_user', $id)->value('is_active'));

        $this->putJson('/api/v1/sistem/pengguna/'.$adm->id_user, ['aktif' => false])->assertStatus(422);
        $this->putJson('/api/v1/sistem/pengguna/99999999', ['aktif' => true])->assertNotFound();
    }

    public function test_reset_password_kirim_email(): void
    {
        $this->admin();
        Mail::fake();
        config(['mail.default' => 'array']);
        $id = $this->postJson('/api/v1/sistem/pengguna', $this->baru())->json('id');
        $lama = DB::table('acl_user')->where('id_user', $id)->value('password');
        $this->postJson("/api/v1/sistem/pengguna/$id/reset-password")->assertOk();
        $this->assertNotSame($lama, DB::table('acl_user')->where('id_user', $id)->value('password'));
        $this->assertDatabaseHas('mobile_auth_log', ['id_user' => $id, 'event' => 'pengguna_reset_sandi']);
    }

    public function test_reset_password_ditolak_bila_email_tidak_valid(): void
    {
        $this->admin();
        $id = $this->postJson('/api/v1/sistem/pengguna', $this->baru())->json('id');
        DB::table('acl_user')->where('id_user', $id)->update(['email_user' => '']);
        $lama = DB::table('acl_user')->where('id_user', $id)->value('password');
        $this->postJson("/api/v1/sistem/pengguna/$id/reset-password")->assertStatus(422)->assertJsonPath('code', 'EMAIL_KOSONG');
        $this->assertSame($lama, DB::table('acl_user')->where('id_user', $id)->value('password'));
    }

    public function test_atur_password_manual(): void
    {
        $this->admin();
        $id = $this->postJson('/api/v1/sistem/pengguna', $this->baru())->json('id');
        $this->putJson("/api/v1/sistem/pengguna/$id/password", ['password' => 'Baru12345', 'password_confirmation' => 'beda'])->assertStatus(422);
        $this->putJson("/api/v1/sistem/pengguna/$id/password", ['password' => 'abc', 'password_confirmation' => 'abc'])->assertStatus(422);
        $this->putJson("/api/v1/sistem/pengguna/$id/password", ['password' => 'Baru12345', 'password_confirmation' => 'Baru12345'])->assertOk();
        $this->assertTrue(LegacyPassword::check('Baru12345', DB::table('acl_user')->where('id_user', $id)->value('password')));
        $this->assertDatabaseHas('mobile_auth_log', ['id_user' => $id, 'event' => 'pengguna_atur_sandi']);
        $this->putJson('/api/v1/sistem/pengguna/99999999/password', ['password' => 'Baru12345', 'password_confirmation' => 'Baru12345'])->assertNotFound();
    }

    public function test_ubah_peran_membangun_ulang_izin_menu(): void
    {
        $adm = $this->admin();
        $id = $this->postJson('/api/v1/sistem/pengguna', $this->baru())->json('id');
        $menuLama = (int) DB::table('acl_menu')->whereNotIn('id_menu', DB::table('acl_role_menu')->where('id_role', 21)->pluck('id_menu'))->value('id_menu');
        $this->assertGreaterThan(0, $menuLama);
        DB::table('acl_role_permission')->insert(['id_user' => $id, 'id_role' => 22, 'id_menu' => $menuLama, 'permission' => '{}']);
        $this->putJson("/api/v1/sistem/pengguna/$id/peran", ['id_role' => 22])->assertStatus(422)->assertJsonPath('code', 'SAMA');
        $this->putJson("/api/v1/sistem/pengguna/$id/peran", ['id_role' => 9999])->assertStatus(422);
        $this->putJson('/api/v1/sistem/pengguna/'.$adm->id_user.'/peran', ['id_role' => 21])->assertStatus(422);
        $this->putJson("/api/v1/sistem/pengguna/$id/peran", ['id_role' => 21])->assertOk();
        $this->assertSame(21, (int) DB::table('acl_user')->where('id_user', $id)->value('id_role'));
        $this->assertSame(0, DB::table('acl_role_permission')->where('id_user', $id)->where('id_menu', $menuLama)->count());
        $this->assertSame(
            DB::table('acl_menu as a')->join('acl_role_menu as b', 'a.id_menu', '=', 'b.id_menu')->where('b.id_role', 21)->where('a.menu_level', '<>', 0)->where('a.is_active', 1)->count(),
            DB::table('acl_role_permission')->where('id_user', $id)->where('id_role', 21)->count(),
        );
        $this->assertDatabaseHas('mobile_auth_log', ['id_user' => $id, 'event' => 'pengguna_ubah_peran']);
    }

    public function test_hapus_hanya_akun_yang_belum_pernah_login(): void
    {
        $adm = $this->admin();
        $baru = $this->postJson('/api/v1/sistem/pengguna', $this->baru())->json('id');
        $this->deleteJson('/api/v1/sistem/pengguna/'.$adm->id_user)->assertStatus(422);
        $this->deleteJson("/api/v1/sistem/pengguna/$baru")->assertOk();
        $this->assertDatabaseMissing('acl_user', ['id_user' => $baru]);

        $lama = $this->postJson('/api/v1/sistem/pengguna', $this->baru(['username' => 'uji.lama']))->json('id');
        DB::table('acl_user')->where('id_user', $lama)->update(['last_login_time' => now()]);
        $this->deleteJson("/api/v1/sistem/pengguna/$lama")->assertStatus(409)->assertJsonPath('code', 'PERNAH_LOGIN');
        $this->assertDatabaseHas('acl_user', ['id_user' => $lama]);
        $this->deleteJson('/api/v1/sistem/pengguna/99999999')->assertNotFound();
    }

    public function test_status_mencatat_lastupdate(): void
    {
        $adm = $this->admin();
        $id = $this->postJson('/api/v1/sistem/pengguna', $this->baru())->json('id');
        $this->putJson("/api/v1/sistem/pengguna/$id", ['aktif' => false])->assertOk();
        $row = DB::table('acl_user')->where('id_user', $id)->first();
        $this->assertSame($adm->fullname, $row->lastupdate_by);
        $this->assertNotNull($row->lastupdate_time);
    }
}
