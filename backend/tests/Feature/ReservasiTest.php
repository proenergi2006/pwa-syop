<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReservasiTest extends TestCase
{
    use DatabaseTransactions;

    private function userCabang(int $cab, ?int $role = null): AclUser
    {
        return AclUser::where('id_wilayah', $cab)->where('is_active', 1)->when($role, fn ($q) => $q->where('id_role', $role))->firstOrFail();
    }

    private function body(int $room, array $o = []): array
    {
        return array_merge(['ruangan' => $room, 'tanggal' => now()->addDays(3)->toDateString(), 'mulai' => '09:00', 'selesai' => '10:00', 'personel' => 'Tim', 'keperluan' => 'Rapat'], $o);
    }

    public function test_ruangan_sesuai_cabang_dan_semua_peran(): void
    {
        $u = $this->userCabang(4);
        Sanctum::actingAs($u, ['*']);
        $r = $this->getJson('/api/v1/reservasi')->assertOk()->assertJsonPath('cabang.id', 4);
        $ids = collect($r->json('ruangan'))->pluck('id')->all();
        $this->assertSame(DB::table('pro_master_ruangan')->where('id_cabang', 4)->where('is_active', 1)->pluck('id_ruangan')->sort()->values()->all(), collect($ids)->sort()->values()->all());
        $this->assertNotEmpty($ids);
    }

    public function test_pesan_bentrok_ubah_hapus(): void
    {
        $u = $this->userCabang(4);
        $room = (int) DB::table('pro_master_ruangan')->where('id_cabang', 4)->where('is_active', 1)->value('id_ruangan');
        Sanctum::actingAs($u, ['*']);
        $id = $this->postJson('/api/v1/reservasi', $this->body($room))->assertStatus(201)->json('id');
        $this->postJson('/api/v1/reservasi', $this->body($room, ['mulai' => '09:30', 'selesai' => '10:30']))->assertStatus(409)->assertJsonPath('code', 'TAKEN');
        $this->postJson('/api/v1/reservasi', $this->body($room, ['mulai' => '08:00', 'selesai' => '11:00']))->assertStatus(409); // membungkus
        $this->postJson('/api/v1/reservasi', $this->body($room, ['mulai' => '10:00', 'selesai' => '11:00']))->assertStatus(201); // berimpitan boleh
        $this->postJson('/api/v1/reservasi', $this->body($room, ['mulai' => '12:00', 'selesai' => '11:00']))->assertStatus(422);
        $this->postJson('/api/v1/reservasi', $this->body($room, ['tanggal' => '2020-01-01']))->assertStatus(422);

        $this->putJson("/api/v1/reservasi/$id", $this->body($room, ['keperluan' => 'Ubah']))->assertOk();
        $this->assertSame('Ubah', DB::table('pro_reservasi_ruangan')->where('id_reservasi', $id)->value('keperluan'));

        $lain = AclUser::where('id_user', '!=', $u->id_user)->where('is_active', 1)->first();
        Sanctum::actingAs($lain, ['*']);
        $this->deleteJson("/api/v1/reservasi/$id")->assertStatus(403)->assertJsonPath('code', 'NOT_OWNER');

        Sanctum::actingAs($u, ['*']);
        $this->deleteJson("/api/v1/reservasi/$id")->assertOk();
        $this->assertFalse(DB::table('pro_reservasi_ruangan')->where('id_reservasi', $id)->exists());
    }

    public function test_ruangan_cabang_lain_ditolak(): void
    {
        $u = $this->userCabang(4);
        $lain = (int) DB::table('pro_master_ruangan')->where('id_cabang', '!=', 4)->where('is_active', 1)->value('id_ruangan');
        Sanctum::actingAs($u, ['*']);
        $this->postJson('/api/v1/reservasi', $this->body($lain))->assertStatus(422)->assertJsonPath('code', 'ROOM');
    }
}
