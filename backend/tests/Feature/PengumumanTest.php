<?php

namespace Tests\Feature;

use App\Models\AclUser;
use App\Models\PushSubscription;
use App\Services\PushService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PengumumanTest extends TestCase
{
    use DatabaseTransactions;

    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sent = [];
        $self = $this;
        $this->app->instance(PushService::class, new class($self) extends PushService {
            public function __construct(private $t) {}

            public function send(array $userIds, array $payload): int
            {
                $this->t->rekam($userIds, $payload);

                return count($userIds);
            }
        });
    }

    public function rekam(array $ids, array $payload): void
    {
        $this->sent[] = [$ids, $payload];
    }

    private function as(int $role): AclUser
    {
        $u = AclUser::where('id_role', $role)->where('is_active', 1)->firstOrFail();
        Sanctum::actingAs($u, ['*']);

        return $u;
    }

    private function buat(array $o = []): int
    {
        $this->as(1);

        return $this->postJson('/api/v1/pengumuman-admin', $o + ['judul' => 'Libur Natal', 'isi' => "Kantor libur 25 Desember.\nSelamat berlibur.", 'jenis' => 'info'])->assertCreated()->json('id');
    }

    public function test_kelola_hanya_super_admin_dan_semua_pengguna_bisa_membaca(): void
    {
        foreach ([21, 7, 6, 5, 3] as $role) {
            $this->as($role);
            $this->getJson('/api/v1/pengumuman-admin')->assertForbidden();
            $this->postJson('/api/v1/pengumuman-admin', ['judul' => 'x', 'isi' => 'y', 'jenis' => 'info'])->assertForbidden();
            $this->getJson('/api/v1/pengumuman')->assertOk();
            $this->assertContains('pengumuman', $this->getJson('/api/v1/auth/me')->json('user.modules'));
            $this->assertNotContains('pengumuman_admin', $this->getJson('/api/v1/auth/me')->json('user.modules'));
        }
        $this->as(1);
        $this->getJson('/api/v1/pengumuman-admin')->assertOk();
        $this->assertContains('pengumuman_admin', $this->getJson('/api/v1/auth/me')->json('user.modules'));
    }

    public function test_draf_tidak_dikirim_dan_tidak_terlihat_pengguna(): void
    {
        $id = $this->buat();
        $this->assertSame([], $this->sent);
        $this->assertSame('draft', DB::table('mobile_announcements')->where('id', $id)->value('status'));
        $this->as(21);
        $this->assertNotContains($id, array_column($this->getJson('/api/v1/pengumuman')->json('data'), 'id'));
        $this->getJson("/api/v1/pengumuman/$id")->assertNotFound();
        // draf bisa diubah
        $this->as(1);
        $this->putJson("/api/v1/pengumuman-admin/$id", ['judul' => 'Judul baru', 'isi' => 'Isi baru', 'jenis' => 'penting'])->assertOk();
        $this->assertSame('Judul baru', DB::table('mobile_announcements')->where('id', $id)->value('judul'));
    }

    public function test_rilis_mengirim_push_satu_kali_dan_tidak_bisa_diulang(): void
    {
        $id = $this->buat();
        $semua = DB::table('acl_user')->where('is_active', 1)->count();
        $r = $this->postJson("/api/v1/pengumuman-admin/$id/kirim")->assertOk();
        $this->assertSame($semua, $r->json('dikirim_ke'));
        $this->assertCount(1, $this->sent);
        [$ids, $p] = $this->sent[0];
        $this->assertCount($semua, $ids);
        $this->assertSame('Libur Natal', $p['title']);
        $this->assertSame('Kantor libur 25 Desember. Selamat berlibur.', $p['body']);
        $this->assertSame("/pengumuman?buka=$id", $p['url']);
        $this->assertSame("pengumuman-$id", $p['tag']);
        $row = DB::table('mobile_announcements')->where('id', $id)->first();
        $this->assertSame('terkirim', $row->status);
        $this->assertNotNull($row->dikirim_pada);
        $this->assertSame($semua, (int) $row->push_ke);

        // sekali saja: rilis ulang ditolak dan tidak ada push kedua; yang terkirim tidak bisa diubah
        $this->postJson("/api/v1/pengumuman-admin/$id/kirim")->assertStatus(409)->assertJsonPath('code', 'SUDAH_DIRILIS');
        $this->putJson("/api/v1/pengumuman-admin/$id", ['judul' => 'x', 'isi' => 'y', 'jenis' => 'info'])->assertStatus(409);
        $this->assertCount(1, $this->sent);
        $this->postJson('/api/v1/pengumuman-admin/999999/kirim')->assertNotFound();
    }

    public function test_buat_dan_kirim_sekaligus(): void
    {
        $this->as(1);
        $r = $this->postJson('/api/v1/pengumuman-admin', ['judul' => 'Rapat', 'isi' => 'Besok jam 9.', 'jenis' => 'penting', 'kirim' => true])->assertCreated();
        $this->assertGreaterThan(0, $r->json('dikirim_ke'));
        $this->assertCount(1, $this->sent);
        $this->assertSame('terkirim', DB::table('mobile_announcements')->where('id', $r->json('id'))->value('status'));
    }

    public function test_sasaran_peran_tertentu(): void
    {
        $id = $this->buat(['sasaran' => [21, 6]]);
        $this->postJson("/api/v1/pengumuman-admin/$id/kirim")->assertOk();
        $this->assertSame(',21,6,', DB::table('mobile_announcements')->where('id', $id)->value('sasaran'));
        [$ids] = $this->sent[0];
        $this->assertEqualsCanonicalizing(DB::table('acl_user')->where('is_active', 1)->whereIn('id_role', [21, 6])->pluck('id_user')->map(fn ($v) => (int) $v)->all(), $ids);
        foreach ([21, 6] as $role) {
            $this->as($role);
            $this->assertContains($id, array_column($this->getJson('/api/v1/pengumuman')->json('data'), 'id'));
            $this->getJson("/api/v1/pengumuman/$id")->assertOk();
        }
        foreach ([7, 5] as $role) {
            $this->as($role);
            $this->assertNotContains($id, array_column($this->getJson('/api/v1/pengumuman')->json('data'), 'id'));
            $this->getJson("/api/v1/pengumuman/$id")->assertNotFound();
            $this->assertSame(0, $this->getJson('/api/v1/badges')->json('pengumuman'));
        }
    }

    public function test_belum_dibaca_lencana_baca_dan_baca_semua(): void
    {
        $a = $this->buat(['judul' => 'A']);
        $b = $this->buat(['judul' => 'B']);
        $this->postJson("/api/v1/pengumuman-admin/$a/kirim")->assertOk();
        $this->postJson("/api/v1/pengumuman-admin/$b/kirim")->assertOk();
        $u = $this->as(21);
        $sebelum = $this->getJson('/api/v1/badges')->assertOk()->json('pengumuman');
        $this->assertGreaterThanOrEqual(2, $sebelum);
        $list = $this->getJson('/api/v1/pengumuman')->json();
        $this->assertSame($sebelum, $list['belum_dibaca']);
        $this->assertFalse(collect($list['data'])->firstWhere('id', $a)['dibaca']);

        $this->getJson("/api/v1/pengumuman/$a")->assertOk()->assertJsonPath('judul', 'A')->assertJsonPath('dibaca', true)->assertJsonPath('belum_dibaca', $sebelum - 1);
        $this->assertSame($sebelum - 1, $this->getJson('/api/v1/badges')->json('pengumuman'));
        $this->getJson("/api/v1/pengumuman/$a")->assertOk(); // membuka lagi tidak menggandakan
        $this->assertSame(1, DB::table('mobile_announcement_reads')->where('id_announcement', $a)->where('id_user', $u->id_user)->count());
        // pengguna lain tidak terpengaruh
        $this->as(7);
        $this->assertSame($sebelum, $this->getJson('/api/v1/badges')->json('pengumuman'));
        $this->as(21);
        $this->postJson('/api/v1/pengumuman/baca-semua')->assertOk()->assertJsonPath('belum_dibaca', 0);
        $this->assertSame(0, $this->getJson('/api/v1/badges')->json('pengumuman'));
        // yang sudah dibaca tidak ikut jumlah "belum dibaca" total approval
        $this->assertArrayNotHasKey('pengumuman', \App\Services\WaitingCounts::for($u));
    }

    public function test_pengumuman_lama_tidak_dihitung_belum_dibaca_tetapi_tetap_tampil(): void
    {
        $id = $this->buat();
        $this->postJson("/api/v1/pengumuman-admin/$id/kirim")->assertOk();
        DB::table('mobile_announcements')->where('id', $id)->update(['dikirim_pada' => now()->subDays(40)]);
        $this->as(21);
        $this->assertContains($id, array_column($this->getJson('/api/v1/pengumuman')->json('data'), 'id'));
        $awal = $this->getJson('/api/v1/badges')->json('pengumuman');
        DB::table('mobile_announcements')->where('id', $id)->update(['dikirim_pada' => now()]);
        $this->assertSame($awal + 1, $this->getJson('/api/v1/badges')->json('pengumuman'));
    }

    public function test_statistik_hapus_dan_validasi(): void
    {
        $id = $this->buat(['sasaran' => [21]]);
        $this->postJson("/api/v1/pengumuman-admin/$id/kirim")->assertOk();
        $ceo = $this->as(21);
        $this->getJson("/api/v1/pengumuman/$id")->assertOk();
        $this->as(1);
        $row = collect($this->getJson('/api/v1/pengumuman-admin')->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertSame('terkirim', $row['status']);
        $this->assertSame(1, $row['dibaca']);
        $this->assertSame(['CEO'], $row['sasaran_nama']);
        $this->assertGreaterThanOrEqual(1, $row['dikirim_ke']);

        $this->postJson('/api/v1/pengumuman-admin', ['judul' => '', 'isi' => 'x', 'jenis' => 'info'])->assertStatus(422);
        $this->postJson('/api/v1/pengumuman-admin', ['judul' => 'x', 'isi' => '', 'jenis' => 'info'])->assertStatus(422);
        $this->postJson('/api/v1/pengumuman-admin', ['judul' => 'x', 'isi' => 'y', 'jenis' => 'aneh'])->assertStatus(422);
        $this->postJson('/api/v1/pengumuman-admin', ['judul' => str_repeat('a', 151), 'isi' => 'y', 'jenis' => 'info'])->assertStatus(422);

        $this->deleteJson("/api/v1/pengumuman-admin/$id")->assertOk();
        $this->assertSame(0, DB::table('mobile_announcements')->where('id', $id)->count());
        $this->assertSame(0, DB::table('mobile_announcement_reads')->where('id_announcement', $id)->count());
        $this->deleteJson("/api/v1/pengumuman-admin/$id")->assertNotFound();
        $this->as(21);
        $this->getJson("/api/v1/pengumuman/$id")->assertNotFound();
    }

    public function test_peran_dan_perkiraan_penerima(): void
    {
        $this->as(1);
        $peran = $this->getJson('/api/v1/pengumuman-admin/peran')->assertOk()->json('data');
        $ceo = collect($peran)->firstWhere('id', 21);
        $this->assertSame('CEO', $ceo['nama']);
        $this->assertSame(DB::table('acl_user')->where('is_active', 1)->where('id_role', 21)->count(), $ceo['pengguna']);
        $ids = DB::table('acl_user')->where('is_active', 1)->where('id_role', 21)->pluck('id_user')->all();
        PushSubscription::create(['id_user' => $ids[0], 'endpoint_hash' => sha1('uji'.$ids[0]), 'endpoint' => 'https://example.test/x', 'p256dh' => 'a', 'auth' => 'b']);
        $k = $this->getJson('/api/v1/pengumuman-admin/perkiraan?sasaran[]=21')->assertOk()->json();
        $this->assertSame(count($ids), $k['pengguna']);
        $this->assertGreaterThanOrEqual(1, $k['perangkat']);
        $semua = $this->getJson('/api/v1/pengumuman-admin/perkiraan')->json();
        $this->assertSame(DB::table('acl_user')->where('is_active', 1)->count(), $semua['pengguna']);
    }

    public function test_jadwal_dikirim_otomatis_saat_waktunya_dan_hanya_sekali(): void
    {
        $this->as(1);
        $this->postJson('/api/v1/pengumuman-admin', ['judul' => 'Dulu', 'isi' => 'x', 'jenis' => 'info', 'jadwal' => now()->subMinute()->toDateTimeString()])->assertStatus(422); // harus di masa depan
        $id = $this->postJson('/api/v1/pengumuman-admin', ['judul' => 'Nanti', 'isi' => 'Isi', 'jenis' => 'info', 'sasaran' => [21], 'jadwal' => now()->addHour()->toDateTimeString()])->assertCreated()->json('id');
        $this->assertSame('terjadwal', DB::table('mobile_announcements')->where('id', $id)->value('status'));
        $this->artisan('orca:kirim-pengumuman')->assertSuccessful();
        $this->assertSame([], $this->sent); // belum waktunya

        DB::table('mobile_announcements')->where('id', $id)->update(['jadwal_pada' => now()->subMinute()]);
        $this->artisan('orca:kirim-pengumuman')->assertSuccessful();
        $this->assertCount(1, $this->sent);
        $row = DB::table('mobile_announcements')->where('id', $id)->first();
        $this->assertSame('terkirim', $row->status);
        $this->assertNull($row->jadwal_pada);
        $this->artisan('orca:kirim-pengumuman')->assertSuccessful();
        $this->assertCount(1, $this->sent); // tidak terkirim dua kali

        // jadwal bisa diubah/dibatalkan sebelum waktunya
        $id2 = $this->postJson('/api/v1/pengumuman-admin', ['judul' => 'B', 'isi' => 'y', 'jenis' => 'info', 'jadwal' => now()->addDay()->toDateTimeString()])->json('id');
        $this->putJson("/api/v1/pengumuman-admin/$id2", ['judul' => 'B', 'isi' => 'y', 'jenis' => 'info'])->assertOk();
        $this->assertSame('draft', DB::table('mobile_announcements')->where('id', $id2)->value('status'));
        // terjadwal juga bisa dikirim sekarang
        $id3 = $this->postJson('/api/v1/pengumuman-admin', ['judul' => 'C', 'isi' => 'z', 'jenis' => 'info', 'jadwal' => now()->addDay()->toDateTimeString()])->json('id');
        $this->postJson("/api/v1/pengumuman-admin/$id3/kirim")->assertOk();
        $this->assertSame('terkirim', DB::table('mobile_announcements')->where('id', $id3)->value('status'));
    }

    public function test_gandakan_menjadi_draf_baru(): void
    {
        $id = $this->buat(['sasaran' => [21, 7], 'jenis' => 'penting']);
        $this->postJson("/api/v1/pengumuman-admin/$id/kirim")->assertOk();
        $baru = $this->postJson("/api/v1/pengumuman-admin/$id/gandakan")->assertCreated()->json('id');
        $this->assertNotSame($id, $baru);
        $row = DB::table('mobile_announcements')->where('id', $baru)->first();
        $this->assertSame('draft', $row->status);
        $this->assertSame('Libur Natal', $row->judul);
        $this->assertSame('penting', $row->jenis);
        $this->assertSame(',21,7,', $row->sasaran);
        $this->assertNull($row->dikirim_pada);
        $this->postJson('/api/v1/pengumuman-admin/999999/gandakan')->assertNotFound();
    }

    public function test_daftar_pembaca_sudah_dan_belum(): void
    {
        $id = $this->buat(['sasaran' => [21]]);
        $this->getJson("/api/v1/pengumuman-admin/$id/pembaca")->assertStatus(409);
        $this->postJson("/api/v1/pengumuman-admin/$id/kirim")->assertOk();
        $ceo = DB::table('acl_user')->where('is_active', 1)->where('id_role', 21)->pluck('id_user')->map(fn ($v) => (int) $v)->all();
        $this->as(1);
        $belum = $this->getJson("/api/v1/pengumuman-admin/$id/pembaca")->assertOk();
        $this->assertSame(count($ceo), $belum->json('total'));
        $this->assertEqualsCanonicalizing($ceo, array_column($belum->json('data'), 'id'));
        $this->assertSame('CEO', $belum->json('data.0.peran'));

        Sanctum::actingAs(AclUser::find($ceo[0]), ['*']);
        $this->getJson("/api/v1/pengumuman/$id")->assertOk();
        $this->as(1);
        $this->assertSame(count($ceo) - 1, $this->getJson("/api/v1/pengumuman-admin/$id/pembaca")->json('total'));
        $sudah = $this->getJson("/api/v1/pengumuman-admin/$id/pembaca?status=sudah")->json();
        $this->assertSame([$ceo[0]], array_column($sudah['data'], 'id'));
        $this->assertSame(404, $this->getJson('/api/v1/pengumuman-admin/999999/pembaca')->status());
        $this->as(21);
        $this->getJson("/api/v1/pengumuman-admin/$id/pembaca")->assertForbidden();
    }

    public function test_status_notifikasi_pengguna(): void
    {
        $this->as(1);
        DB::table('mobile_push_subscriptions')->delete();
        DB::table('mobile_client_versions')->delete();
        DB::table('mobile_settings')->updateOrInsert(['nama' => 'app_versi'], ['nilai' => 'v9', 'diubah_pada' => now()]);
        $a = AclUser::where('id_role', 21)->where('is_active', 1)->firstOrFail();
        $b = AclUser::where('id_role', 7)->where('is_active', 1)->firstOrFail();
        PushSubscription::create(['id_user' => $a->id_user, 'endpoint_hash' => sha1('s1'), 'endpoint' => 'https://example.test/1', 'p256dh' => 'a', 'auth' => 'b']);
        PushSubscription::create(['id_user' => $a->id_user, 'endpoint_hash' => sha1('s2'), 'endpoint' => 'https://example.test/2', 'p256dh' => 'a', 'auth' => 'b']);
        DB::table('mobile_client_versions')->insert([['id_user' => $a->id_user, 'versi' => 'v9', 'dilihat_pada' => now()], ['id_user' => $b->id_user, 'versi' => 'v1', 'dilihat_pada' => now()]]);

        $cari = fn ($u, $extra = '') => collect($this->getJson('/api/v1/pengumuman-admin/perangkat?per_page=100&q='.urlencode($u->fullname).$extra)->assertOk()->json('data'))->firstWhere('id', $u->id_user);
        $r = $this->getJson('/api/v1/pengumuman-admin/perangkat')->assertOk()->json();
        $total = DB::table('acl_user')->where('is_active', 1)->count();
        $this->assertSame($total, $r['ringkasan']['pengguna']);
        $this->assertSame(1, $r['ringkasan']['push_aktif']);
        $this->assertSame($total - 1, $r['ringkasan']['push_belum']);
        $this->assertSame('v9', $r['ringkasan']['versi_terbaru']);
        $ra = $cari($a);
        $this->assertSame(2, $ra['perangkat']);
        $this->assertTrue($ra['terbaru']);
        $rb = $cari($b);
        $this->assertSame(0, $rb['perangkat']);
        $this->assertFalse($rb['terbaru']);
        $this->assertSame('v1', $rb['versi']);

        $belum = $this->getJson('/api/v1/pengumuman-admin/perangkat?filter=belum')->json();
        $this->assertSame($total - 1, $belum['meta']['total']);
        $this->assertNull($cari($a, '&filter=belum'));
        $this->assertNotNull($cari($b, '&filter=belum'));
        $this->assertNull($cari($a, '&filter=lama'));
        $this->assertNotNull($cari($b, '&filter=lama'));
        $this->as(21);
        $this->getJson('/api/v1/pengumuman-admin/perangkat')->assertForbidden();
    }
}
