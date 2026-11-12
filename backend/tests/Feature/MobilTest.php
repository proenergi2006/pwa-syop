<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobilTest extends TestCase
{
    use DatabaseTransactions;

    private function user(int $wil, ?int $role = null, bool $notRole = false): AclUser
    {
        return AclUser::where('id_wilayah', $wil)->where('is_active', 1)
            ->when($role && ! $notRole, fn ($q) => $q->where('id_role', $role))
            ->when($role && $notRole, fn ($q) => $q->whereNotIn('id_role', [1, 6, 14]))
            ->firstOrFail();
    }

    private function car(int $cab): int
    {
        return (int) DB::table('pro_master_mobil')->where('id_cabang', $cab)->where('is_active', 1)->value('id_mobil');
    }

    private function body(int $car, array $o = []): array
    {
        return array_merge(['mobil' => $car, 'tanggal' => now()->addDays(3)->toDateString(), 'mulai' => '09:00', 'selesai' => '11:00', 'keperluan' => 'Dinas'], $o);
    }

    public function test_mobil_sesuai_cabang_dan_tanpa_pilihan_cabang(): void
    {
        Sanctum::actingAs($this->user(5, 7, true), ['*']);
        $r = $this->getJson('/api/v1/mobil')->assertOk();
        $this->assertSame(5, $r->json('cabang.id'));
        $this->assertSame([], $r->json('pilih_cabang'));
        $this->assertSame([9], collect($r->json('mobil'))->pluck('id')->all());
        // cabang lain tidak bisa dipaksa lewat query
        $this->getJson('/api/v1/mobil?cabang=6')->assertOk()->assertJsonPath('cabang.id', 5);
    }

    public function test_cabang_1_dan_2_digabung_dan_om_boleh_memilih(): void
    {
        Sanctum::actingAs($this->user(2, 7, true), ['*']);
        $ids = collect($this->getJson('/api/v1/mobil')->assertOk()->json('mobil'))->pluck('id')->sort()->values()->all();
        $this->assertSame(DB::table('pro_master_mobil')->whereIn('id_cabang', [1, 2])->where('is_active', 1)->pluck('id_mobil')->sort()->values()->all(), $ids);

        Sanctum::actingAs(AclUser::where('id_role', 6)->where('is_active', 1)->firstOrFail(), ['*']);
        $r = $this->getJson('/api/v1/mobil?cabang=5')->assertOk()->assertJsonPath('cabang.id', 5);
        $this->assertNotEmpty($r->json('pilih_cabang'));
    }

    public function test_pesan_bentrok_ubah_km_hapus(): void
    {
        $u = $this->user(5, 7, true);
        $car = $this->car(5);
        Sanctum::actingAs($u, ['*']);
        $id = $this->postJson('/api/v1/mobil', $this->body($car))->assertStatus(201)->json('id');
        $this->postJson('/api/v1/mobil', $this->body($car, ['mulai' => '10:00', 'selesai' => '12:00']))->assertStatus(409)->assertJsonPath('code', 'TAKEN');
        $this->postJson('/api/v1/mobil', $this->body($car, ['mulai' => '11:00', 'selesai' => '12:00']))->assertStatus(201);
        $this->postJson('/api/v1/mobil', $this->body($car, ['tanggal' => '2020-01-01']))->assertStatus(422);

        $this->putJson("/api/v1/mobil/$id", $this->body($car, ['km' => 12345.5, 'bensin' => 60]))->assertOk();
        $row = DB::table('pro_peminjaman_mobil')->where('id_peminjaman', $id)->first();
        $this->assertSame(60, (int) $row->bensin);
        $this->assertEquals(12345.5, (float) $row->last_km);
        $this->putJson("/api/v1/mobil/$id", $this->body($car, ['bensin' => 120]))->assertStatus(422);

        $lain = $this->user(5, 7, true);
        $x = AclUser::where('id_user', '!=', $u->id_user)->whereNotIn('id_role', [14])->where('is_active', 1)->first();
        Sanctum::actingAs($x, ['*']);
        $this->deleteJson("/api/v1/mobil/$id")->assertStatus(403);

        Sanctum::actingAs($u, ['*']);
        $this->deleteJson("/api/v1/mobil/$id")->assertOk();
    }

    public function test_mobil_cabang_lain_ditolak_dan_admin_14_boleh_hapus(): void
    {
        $u = $this->user(5, 7, true);
        Sanctum::actingAs($u, ['*']);
        $this->postJson('/api/v1/mobil', $this->body($this->car(6)))->assertStatus(422)->assertJsonPath('code', 'CAR');
        $id = $this->postJson('/api/v1/mobil', $this->body($this->car(5)))->assertStatus(201)->json('id');

        $adm = AclUser::where('id_role', 14)->where('is_active', 1)->first();
        if ($adm) {
            Sanctum::actingAs($adm, ['*']);
            $this->deleteJson("/api/v1/mobil/$id")->assertOk();
        } else {
            $this->markTestSkipped('Tidak ada user peran 14.');
        }
    }
}
