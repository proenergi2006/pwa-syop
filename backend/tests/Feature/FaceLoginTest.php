<?php

namespace Tests\Feature;

use App\Support\LegacyPassword;
use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FaceLoginTest extends TestCase
{
    use DatabaseTransactions;

    private const BM  = 384;
    private const PWD = 'Rahasia123';
    private const PNW = 59970;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('acl_user')->where('id_user', self::BM)->update([
            'password' => LegacyPassword::make(self::PWD),
        ]);
        RateLimiter::clear('face:' . sha1(mb_strtolower($this->username())));
        RateLimiter::clear('pwgate:' . self::BM);
    }

    private function username(): string
    {
        return AclUser::find(self::BM)->username;
    }

    /** Vektor 128 angka deterministik (meniru embedding face-api) per "orang". */
    private function face(int $seed): array
    {
        mt_srand($seed);

        return array_map(fn () => (mt_rand(-3000, 3000) / 10000), range(1, 128));
    }

    private function noisy(array $v, float $amp = 0.01): array
    {
        return array_map(fn ($x) => $x + (mt_rand(-100, 100) / 100) * $amp, $v);
    }

    private function enroll(int $seed = 1)
    {
        Sanctum::actingAs(AclUser::find(self::BM), ['*']);

        return $this->postJson('/api/v1/face', [
            'password' => self::PWD, 'consent' => true, 'descriptor' => $this->face($seed),
        ]);
    }

    private function faceLogin(array $descriptor, ?string $username = null)
    {
        return $this->postJson('/api/v1/auth/face-login', [
            'username' => $username ?? $this->username(), 'descriptor' => $descriptor,
        ]);
    }

    // -------------------------------------------------------------- pendaftaran

    public function test_daftar_wajah_wajib_password_benar(): void
    {
        Sanctum::actingAs(AclUser::find(self::BM), ['*']);
        $this->postJson('/api/v1/face', ['password' => 'salah', 'consent' => true, 'descriptor' => $this->face(1)])
            ->assertStatus(422)->assertJsonPath('code', 'PASSWORD_WRONG');
        $this->assertSame(0, DB::table('mobile_face_templates')->where('id_user', self::BM)->count());
    }

    public function test_daftar_wajah_wajib_persetujuan(): void
    {
        Sanctum::actingAs(AclUser::find(self::BM), ['*']);
        $this->postJson('/api/v1/face', ['password' => self::PWD, 'descriptor' => $this->face(1)])
            ->assertStatus(422)->assertJsonValidationErrors('consent');
    }

    public function test_daftar_wajah_tersimpan_terenkripsi_dan_menggantikan_yang_lama(): void
    {
        $this->enroll(1)->assertOk()->assertJsonPath('enrolled', true);
        $this->enroll(2)->assertOk();

        $rows = DB::table('mobile_face_templates')->where('id_user', self::BM)->get();
        $this->assertCount(1, $rows);                                   // diganti, bukan ditambah
        $raw = $rows[0]->template;
        $this->assertNull(json_decode($raw, true));                     // bukan JSON polos
        $this->assertStringNotContainsString((string) $this->face(2)[0], $raw);

        $this->getJson('/api/v1/face')->assertOk()->assertJsonPath('enrolled', true)->assertJsonMissing(['template']);
    }

    #[DataProvider('badDescriptors')]
    public function test_embedding_tidak_masuk_akal_ditolak(array $d): void
    {
        Sanctum::actingAs(AclUser::find(self::BM), ['*']);
        $this->postJson('/api/v1/face', ['password' => self::PWD, 'consent' => true, 'descriptor' => $d])
            ->assertStatus(422)->assertJsonPath('code', 'FACE_INVALID');
    }

    public static function badDescriptors(): array
    {
        return [
            'terlalu pendek' => [array_fill(0, 127, 0.1)],
            'semua nol'      => [array_fill(0, 128, 0.0)],
            'konstan'        => [array_fill(0, 128, 0.2)],
            'di luar rentang' => [array_merge([99.0], array_fill(0, 127, 0.1))],
            'bukan angka'    => [array_merge(['x'], array_fill(0, 127, 0.1))],
        ];
    }

    // -------------------------------------------------------------------- login

    public function test_login_wajah_cocok_memberi_token_terbatas(): void
    {
        $this->enroll(1);
        $this->app['auth']->forgetGuards();

        $res = $this->faceLogin($this->noisy($this->face(1)))->assertOk()
            ->assertJsonPath('auth_method', 'face')
            ->assertJsonStructure(['token', 'user' => ['id', 'fullname', 'face_enrolled']])
            ->assertJsonMissing(['template']);

        $tok = DB::table('personal_access_tokens')->orderByDesc('id')->first();
        $this->assertSame('["face"]', $tok->abilities);                 // bukan '*'
        $this->assertNotNull($tok->expires_at);
        $this->assertNotEmpty($res->json('token'));
    }

    public function test_wajah_orang_lain_ditolak(): void
    {
        $this->enroll(1);
        $before = DB::table('personal_access_tokens')->where('name', 'orca-x-mobile-face')->count(); // bisa ada token nyata hasil uji manual
        $this->faceLogin($this->face(2))->assertStatus(422)->assertJsonPath('code', 'FACE_MISMATCH');
        $this->assertSame($before, DB::table('personal_access_tokens')->where('name', 'orca-x-mobile-face')->count());
    }

    public function test_pesan_gagal_seragam_tidak_membocorkan_user(): void
    {
        $this->enroll(1);
        $unknown   = $this->faceLogin($this->face(1), 'user-tidak-ada-xyz')->assertStatus(422);
        $mismatch  = $this->faceLogin($this->face(2))->assertStatus(422);
        $this->assertSame($unknown->json(), $mismatch->json());

        // user ada tapi belum daftar wajah
        DB::table('mobile_face_templates')->where('id_user', self::BM)->delete();
        $unenrolled = $this->faceLogin($this->face(1))->assertStatus(422);
        $this->assertSame($unknown->json(), $unenrolled->json());
    }

    public function test_dikunci_setelah_5_gagal_walau_wajah_benar(): void
    {
        $this->enroll(1);
        for ($i = 0; $i < 5; $i++) {
            $this->faceLogin($this->face(2))->assertStatus(422);
        }
        $this->faceLogin($this->face(1))->assertStatus(429)->assertJsonPath('code', 'FACE_LOCKED');
    }

    public function test_user_nonaktif_tidak_bisa_login_wajah(): void
    {
        $this->enroll(1);
        DB::table('acl_user')->where('id_user', self::BM)->update(['is_active' => 0]);
        $this->faceLogin($this->face(1))->assertStatus(422)->assertJsonPath('code', 'FACE_MISMATCH');
    }

    public function test_audit_tercatat_tanpa_vektor(): void
    {
        $this->enroll(1);
        $this->faceLogin($this->face(2));
        $this->faceLogin($this->noisy($this->face(1)));

        $events = DB::table('mobile_auth_log')->where('id_user', self::BM)->pluck('event')->all();
        $this->assertContains('face_enroll', $events);
        $this->assertContains('face_fail', $events);
        $this->assertContains('face_ok', $events);
        $this->assertSame(['id', 'id_user', 'username', 'event', 'distance', 'ip', 'user_agent', 'created_at'],
            array_keys((array) DB::table('mobile_auth_log')->first()));
    }

    // ------------------------------------------- sesi face login tidak boleh memutuskan

    private function faceToken(): string
    {
        $this->enroll(1);
        $this->app['auth']->forgetGuards();

        return $this->faceLogin($this->noisy($this->face(1)))->assertOk()->json('token');
    }

    public function test_sesi_face_bisa_membaca_tapi_keputusan_butuh_password(): void
    {
        $t = $this->faceToken();
        $this->app['auth']->forgetGuards();

        $this->withToken($t)->getJson('/api/v1/penawaran')->assertOk();
        $this->withToken($t)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('auth_method', 'face');

        $url = '/api/v1/penawaran/' . self::PNW . '/keputusan';
        $this->withToken($t)->postJson($url, ['keputusan' => 'approve'])
            ->assertStatus(403)->assertJsonPath('code', 'STEP_UP_REQUIRED');
        $this->withToken($t)->postJson($url, ['keputusan' => 'approve', 'password' => 'salah'])
            ->assertStatus(422)->assertJsonPath('code', 'STEP_UP_FAILED');
        $this->assertSame(0, (int) DB::table('pro_penawaran')->where('id_penawaran', self::PNW)->value('flag_approval'));

        $this->withToken($t)->postJson($url, ['keputusan' => 'approve', 'password' => self::PWD])
            ->assertOk()->assertJsonPath('final', true);
        $this->assertSame(1, (int) DB::table('pro_penawaran')->where('id_penawaran', self::PNW)->value('flag_approval'));
    }

    public function test_password_gate_terkunci_setelah_5_salah(): void
    {
        $t = $this->faceToken();
        $this->app['auth']->forgetGuards();
        $url = '/api/v1/penawaran/' . self::PNW . '/keputusan';

        for ($i = 0; $i < 4; $i++) {
            $this->withToken($t)->postJson($url, ['keputusan' => 'approve', 'password' => 'salah'])->assertStatus(422);
        }
        // percobaan salah ke-5 langsung memberi tahu bahwa akun terkunci
        $this->withToken($t)->postJson($url, ['keputusan' => 'approve', 'password' => 'salah'])
            ->assertStatus(429)->assertJsonPath('code', 'PASSWORD_LOCKED');
        // setelah terkunci, password benar pun ditolak sampai masa tunggu habis
        $this->withToken($t)->postJson($url, ['keputusan' => 'approve', 'password' => self::PWD])
            ->assertStatus(429)->assertJsonPath('code', 'PASSWORD_LOCKED');
    }

    public function test_sesi_password_biasa_tidak_butuh_langkah_tambahan(): void
    {
        $login = $this->postJson('/api/v1/auth/login', ['username' => AclUser::find(self::BM)->username, 'password' => self::PWD])
            ->assertOk()->assertJsonPath('auth_method', 'password');
        $this->app['auth']->forgetGuards();

        $this->withToken($login->json('token'))
            ->postJson('/api/v1/penawaran/' . self::PNW . '/keputusan', ['keputusan' => 'approve'])->assertOk();
    }

    // -------------------------------------------------------------------- hapus

    public function test_hapus_wajah_wajib_password_lalu_login_wajah_gagal(): void
    {
        $this->enroll(1);
        $this->deleteJson('/api/v1/face', ['password' => 'salah'])->assertStatus(422);
        $this->assertSame(1, DB::table('mobile_face_templates')->where('id_user', self::BM)->count());

        $this->deleteJson('/api/v1/face', ['password' => self::PWD])->assertOk()->assertJsonPath('enrolled', false);
        $this->assertSame(0, DB::table('mobile_face_templates')->where('id_user', self::BM)->count());

        $this->app['auth']->forgetGuards();
        $this->faceLogin($this->face(1))->assertStatus(422);
    }
}
