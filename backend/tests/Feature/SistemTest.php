<?php

namespace Tests\Feature;

use App\Models\AclUser;
use App\Models\FaceTemplate;
use App\Services\AppSettings;
use App\Services\ModuleRoles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SistemTest extends TestCase
{
    use DatabaseTransactions;

    private function as(int $role): AclUser
    {
        $u = AclUser::where('id_role', $role)->where('is_active', 1)->firstOrFail();
        Sanctum::actingAs($u, ['*']);

        return $u;
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_hanya_super_admin(): void
    {
        foreach ([21, 7, 6, 5] as $role) {
            $this->as($role);
            foreach (['kesehatan', 'wajah', 'log', 'pengaturan', 'akses'] as $p) {
                $this->getJson("/api/v1/sistem/$p")->assertForbidden();
            }
            $this->putJson('/api/v1/sistem/pengaturan', ['aktif' => true])->assertForbidden();
            $this->putJson('/api/v1/sistem/akses/po', ['roles' => [5]])->assertForbidden();
        }
        $this->as(1);
        foreach (['kesehatan', 'wajah', 'log', 'pengaturan', 'akses'] as $p) {
            $this->getJson("/api/v1/sistem/$p")->assertOk();
        }
        $this->assertContains('sistem', $this->getJson('/api/v1/auth/me')->json('user.modules'));
    }

    public function test_kesehatan_scheduler_dan_push(): void
    {
        $this->as(1);
        DB::table('mobile_settings')->where('nama', 'hb_scheduler')->delete();
        $r = $this->getJson('/api/v1/sistem/kesehatan')->assertOk()->json();
        $this->assertFalse($r['scheduler']['ok']);
        $this->assertTrue($r['database']['ok']);
        $this->assertArrayHasKey('bebas_gb', $r['penyimpanan']);

        AppSettings::detak();
        $this->assertTrue($this->getJson('/api/v1/sistem/kesehatan')->json('scheduler.ok'));
        DB::table('mobile_settings')->where('nama', 'hb_scheduler')->update(['nilai' => now()->subMinutes(10)->toDateTimeString()]);
        $this->assertFalse($this->getJson('/api/v1/sistem/kesehatan')->json('scheduler.ok'));

        AppSettings::catatPushGagal('endpoint ditolak');
        $p = $this->getJson('/api/v1/sistem/kesehatan')->json('push');
        $this->assertSame(1, $p['gagal_24j']);
        $this->assertSame('endpoint ditolak', $p['gagal_terakhir']['pesan']);

        DB::table('mobile_announcements')->insert(['judul' => 'T', 'isi' => 'x', 'jenis' => 'info', 'status' => 'terjadwal', 'jadwal_pada' => now()->subMinutes(10), 'dibuat_oleh' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertGreaterThanOrEqual(1, $this->getJson('/api/v1/sistem/kesehatan')->json('pengumuman.terlambat'));
    }

    public function test_wajah_daftar_filter_dan_reset(): void
    {
        $this->as(1);
        DB::table('mobile_face_templates')->delete();
        $u = AclUser::where('id_role', 21)->where('is_active', 1)->firstOrFail();
        FaceTemplate::create(['id_user' => $u->id_user, 'template' => array_fill(0, 128, 0.1), 'device' => 'Mozilla/5.0 (iPhone; CPU) Safari']);
        $r = $this->getJson('/api/v1/sistem/wajah')->assertOk()->json();
        $this->assertSame(1, $r['ringkasan']['terdaftar']);
        $this->assertSame($r['ringkasan']['pengguna'] - 1, $r['ringkasan']['belum']);

        $cari = fn ($f) => collect($this->getJson('/api/v1/sistem/wajah?per_page=50&q='.urlencode($u->fullname).$f)->json('data'))->firstWhere('id', $u->id_user);
        $this->assertTrue($cari('')['terdaftar']);
        $this->assertNotNull($cari('&filter=terdaftar'));
        $this->assertNull($cari('&filter=belum'));

        $this->deleteJson("/api/v1/sistem/wajah/{$u->id_user}")->assertOk();
        $this->assertSame(0, DB::table('mobile_face_templates')->where('id_user', $u->id_user)->count());
        $this->assertSame('face_reset_admin', DB::table('mobile_auth_log')->where('id_user', $u->id_user)->orderByDesc('id')->value('event'));
        $this->deleteJson("/api/v1/sistem/wajah/{$u->id_user}")->assertStatus(409);
        $this->deleteJson('/api/v1/sistem/wajah/99999999')->assertNotFound();
        $this->assertFalse($cari('')['terdaftar']);
    }

    public function test_wajah_bermasalah_gagal_berulang(): void
    {
        $this->as(1);
        $u = AclUser::where('id_role', 7)->where('is_active', 1)->firstOrFail();
        for ($i = 0; $i < 3; $i++) {
            DB::table('mobile_auth_log')->insert(['id_user' => $u->id_user, 'username' => $u->username, 'event' => 'face_fail', 'ip' => '1.1.1.1']);
        }
        $row = collect($this->getJson('/api/v1/sistem/wajah?per_page=50&filter=bermasalah&q='.urlencode($u->fullname))->json('data'))->firstWhere('id', $u->id_user);
        $this->assertNotNull($row);
        $this->assertGreaterThanOrEqual(3, $row['gagal_30h']);
    }

    public function test_log_tiga_jenis_dengan_filter(): void
    {
        $this->as(1);
        $u = AclUser::where('id_role', 7)->where('is_active', 1)->firstOrFail();
        DB::table('mobile_auth_log')->insert(['id_user' => $u->id_user, 'username' => $u->username, 'event' => 'login_fail', 'ip' => '9.9.9.9']);
        $r = $this->getJson('/api/v1/sistem/log?jenis=login&status=gagal&q='.urlencode($u->fullname))->assertOk()->json();
        $this->assertSame('login', $r['jenis']);
        $this->assertSame('Login gagal', $r['data'][0]['judul']);
        $this->assertTrue($r['data'][0]['gagal']);
        $this->assertStringContainsString('9.9.9.9', $r['data'][0]['detail']);
        foreach ($r['data'] as $x) {
            $this->assertTrue($x['gagal']);
        }
        // rentang tanggal di masa depan = kosong
        $this->assertSame([], $this->getJson('/api/v1/sistem/log?jenis=login&dari='.now()->addDays(5)->toDateString())->json('data'));

        $p = $this->getJson('/api/v1/sistem/log?jenis=persetujuan')->assertOk()->json();
        $this->assertSame('persetujuan', $p['jenis']);
        $this->assertNotEmpty($p['data']);
        $this->assertArrayHasKey('judul', $p['data'][0]);
        $n = $this->getJson('/api/v1/sistem/log?jenis=notifikasi')->assertOk()->json();
        $this->assertNotEmpty($n['data']);
        $this->assertSame('login', $this->getJson('/api/v1/sistem/log?jenis=ngawur')->json('jenis'));
    }

    public function test_pemeliharaan_memblokir_pengguna_biasa_tetapi_tidak_super_admin(): void
    {
        $this->as(1);
        $this->putJson('/api/v1/sistem/pengaturan', ['aktif' => true, 'pesan' => 'Sedang upgrade server'])->assertOk()->assertJsonPath('pemeliharaan.aktif', true);
        $this->assertSame('pengaturan_ubah', DB::table('mobile_auth_log')->orderByDesc('id')->value('event'));
        $this->getJson('/api/v1/badges')->assertOk(); // Super Admin tetap bisa
        $s = $this->getJson('/api/v1/status')->assertOk()->json();
        $this->assertTrue($s['pemeliharaan']);
        $this->assertSame('Sedang upgrade server', $s['pesan']);

        $this->as(21);
        $r = $this->getJson('/api/v1/badges')->assertStatus(503);
        $this->assertSame('MAINTENANCE', $r->json('code'));
        $this->assertSame('Sedang upgrade server', $r->json('message'));
        $this->getJson('/api/v1/status')->assertOk(); // status publik selalu terbuka
        $this->postJson('/api/v1/auth/login', ['username' => 'tidak-ada', 'password' => 'x'])->assertStatus(422); // login tetap dilayani saat pemeliharaan (salah kredensial, bukan 503)

        $this->as(1);
        $this->putJson('/api/v1/sistem/pengaturan', ['aktif' => false])->assertOk();
        $this->as(21);
        $this->getJson('/api/v1/badges')->assertOk();
    }

    public function test_pemeliharaan_berakhir_otomatis_dan_validasi(): void
    {
        $this->as(1);
        $this->putJson('/api/v1/sistem/pengaturan', ['aktif' => true, 'sampai' => now()->subHour()->toDateTimeString()])->assertStatus(422);
        $this->putJson('/api/v1/sistem/pengaturan', ['aktif' => true, 'sampai' => now()->addHour()->toDateTimeString()])->assertOk();
        $this->as(21);
        $this->getJson('/api/v1/badges')->assertStatus(503);
        // waktu berakhir lewat: aplikasi pulih sendiri
        AppSettings::simpanPemeliharaan(true, 'x', now()->subMinute()->toDateTimeString());
        $this->getJson('/api/v1/badges')->assertOk();
        $this->assertFalse($this->getJson('/api/v1/status')->json('pemeliharaan'));
        $this->as(1);
        $this->putJson('/api/v1/sistem/pengaturan', [])->assertStatus(422);
    }

    public function test_akses_ubah_menimpa_bawaan_dan_bisa_dikembalikan(): void
    {
        $this->as(1);
        $r = $this->getJson('/api/v1/sistem/akses')->assertOk()->json();
        $po = collect($r['modul'])->firstWhere('kunci', 'po');
        $this->assertSame([21], $po['roles']);
        $this->assertFalse($po['diubah']);
        $this->assertSame('CEO', collect($r['peran'])->firstWhere('id', 21)['nama']);

        $this->as(7);
        $this->getJson('/api/v1/po')->assertForbidden();
        $this->as(1);
        $this->putJson('/api/v1/sistem/akses/po', ['roles' => [21, 7]])->assertOk();
        $this->assertSame([7, 21], collect($this->getJson('/api/v1/sistem/akses')->json('modul'))->firstWhere('kunci', 'po')['roles']);
        $this->assertTrue(collect($this->getJson('/api/v1/sistem/akses')->json('modul'))->firstWhere('kunci', 'po')['diubah']);
        $this->assertSame('akses_ubah', DB::table('mobile_auth_log')->orderByDesc('id')->value('event'));
        $this->assertTrue(ModuleRoles::overrides()['po']['roles'] === [7, 21]);
        $this->as(7);
        $this->assertContains('po', $this->getJson('/api/v1/auth/me')->json('user.modules'));

        $this->as(1);
        $this->deleteJson('/api/v1/sistem/akses/po')->assertOk();
        $this->assertSame([21], collect($this->getJson('/api/v1/sistem/akses')->json('modul'))->firstWhere('kunci', 'po')['roles']);
        $this->as(7);
        $this->assertNotContains('po', $this->getJson('/api/v1/auth/me')->json('user.modules'));
    }

    public function test_akses_menyamai_bawaan_menghapus_override_dan_validasi(): void
    {
        $this->as(1);
        $this->putJson('/api/v1/sistem/akses/po', ['roles' => [21, 7]])->assertOk();
        $this->putJson('/api/v1/sistem/akses/po', ['roles' => [21]])->assertOk();
        $this->assertSame(0, DB::table('mobile_module_overrides')->where('modul', 'po')->count());
        $this->putJson('/api/v1/sistem/akses/tidak_ada', ['roles' => [1]])->assertNotFound();
        $this->putJson('/api/v1/sistem/akses/po', ['roles' => []])->assertStatus(422);
        $this->putJson('/api/v1/sistem/akses/po', ['roles' => [987654]])->assertStatus(422);
        $this->putJson('/api/v1/sistem/akses/po', ['roles' => ['abc']])->assertStatus(422);
        $this->putJson('/api/v1/sistem/akses/po', ['roles' => [21, '*']])->assertOk()->assertJsonPath('roles', ['*']);
        $this->assertSame(['*'], collect($this->getJson('/api/v1/sistem/akses')->json('modul'))->firstWhere('kunci', 'po')['roles']);
        $this->as(5);
        $this->assertContains('po', $this->getJson('/api/v1/auth/me')->json('user.modules'));
    }

    public function test_super_admin_tidak_bisa_mengunci_diri_dari_menu_admin(): void
    {
        $this->as(1);
        $this->putJson('/api/v1/sistem/akses/sistem', ['roles' => [21]])->assertOk()->assertJsonPath('roles', [1, 21]);
        $this->putJson('/api/v1/sistem/akses/pengumuman_admin', ['roles' => [7]])->assertOk()->assertJsonPath('roles', [1, 7]);
        $this->getJson('/api/v1/sistem/kesehatan')->assertOk();
        $this->getJson('/api/v1/pengumuman-admin')->assertOk();
        $this->as(21); // peran lain yang diberi akses ikut bisa masuk
        $this->getJson('/api/v1/sistem/kesehatan')->assertOk();
        $this->as(1);
        $m = collect($this->getJson('/api/v1/sistem/akses')->json('modul'))->firstWhere('kunci', 'sistem');
        $this->assertTrue($m['terkunci']);
    }
}
