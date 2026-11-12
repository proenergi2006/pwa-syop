<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DrTest extends TestCase
{
    use DatabaseTransactions;

    private function as(int $role): AclUser
    {
        $u = AclUser::where('id_role', $role)->where('is_active', 1)->firstOrFail();
        Sanctum::actingAs($u, ['*']);

        return $u;
    }

    private function pending(): int
    {
        return (int) DB::table('pro_pr')->where('disposisi_pr', 3)->where('purchasing_result', 0)->orderBy('id_pr')->value('id_pr');
    }

    private function pos(?int $id = null): array
    {
        return $this->getJson('/api/v1/dr/'.($id ?? $this->pending()).'/po-supplier')->assertOk()->json('data');
    }

    /** @return array<int,array> baris payload memakai satu PO besar */
    private function payload(int $id, array $po, ?int $splitOf = null): array
    {
        $d = $this->getJson("/api/v1/dr/$id")->assertOk();
        $lines = [];
        foreach ($d->json('lines') as $l) {
            $row = ['id_prd' => $l['id_prd'], 'volume' => $l['vol_ori'], 'po' => ['id_po_supplier' => $po['id_po_supplier'], 'id_po_receive' => $po['id_po_receive'], 'id_terminal' => $po['id_terminal']]];
            $lines[] = $row;
        }

        return ['catatan' => 'OK', 'lines' => $lines];
    }

    public function test_hanya_purchasing(): void
    {
        $this->as(21);
        $this->getJson('/api/v1/dr')->assertStatus(403);
        $this->as(5);
        $this->getJson('/api/v1/dr')->assertOk()->assertJsonStructure(['data' => [['id', 'nomor_pr', 'status', 'customer', 'volume']], 'waiting', 'cabang', 'meta']);
        $this->assertGreaterThan(0, $this->getJson('/api/v1/badges')->json('dr'));
    }

    public function test_daftar_detail_dan_daftar_po(): void
    {
        $this->as(5);
        $id = $this->pending();
        $this->assertContains($id, array_column($this->getJson('/api/v1/dr?status=3')->json('data'), 'id'));
        $d = $this->getJson("/api/v1/dr/$id")->assertOk()->assertJsonPath('disposisi', 3)->assertJsonPath('can_save', true);
        $this->assertNotEmpty($d->json('lines'));
        $this->assertArrayHasKey('harga_dasar_nett', $d->json('lines.0'));
        $this->assertNotEmpty($this->pos());
        $this->getJson('/api/v1/dr/999999999')->assertStatus(404);
    }

    public function test_simpan_volume_salah_ditolak_dan_stok_virtual(): void
    {
        $this->as(5);
        $id = $this->pending();
        $po = collect($this->pos())->sortByDesc('sisa')->first();
        $p = $this->payload($id, $po);
        $bad = $p;
        $bad['lines'][0]['volume'] += 1;
        $this->postJson("/api/v1/dr/$id/verifikasi", $bad)->assertStatus(422);
        $bad['lines'][0]['volume'] -= 2;
        $this->postJson("/api/v1/dr/$id/verifikasi", $bad)->assertStatus(422);

        $this->postJson("/api/v1/dr/$id/verifikasi", $p)->assertOk();
        $pr = DB::table('pro_pr')->where('id_pr', $id)->first();
        $this->assertSame(6, (int) $pr->disposisi_pr);
        $this->assertSame(1, (int) $pr->purchasing_result);
        $rows = DB::table('pro_pr_detail')->where('id_pr', $id)->where('is_approved', 1)->get();
        foreach ($rows as $r) {
            $this->assertStringStartsWith('LO/', $r->nomor_lo_pr);
            $this->assertNotEmpty($r->no_do_syop);
            $this->assertSame((int) $po['id_po_receive'], (int) $r->id_po_receive);
            $this->assertSame($po['harga_tebus'], (int) $r->pr_harga_beli);
        }
        $this->assertSame($rows->count(), DB::table('new_pro_inventory_depot')->where('id_pr', $id)->where('id_jenis', 6)->count());
        $this->postJson("/api/v1/dr/$id/verifikasi", $p)->assertStatus(409);
    }

    public function test_split_dan_revisi(): void
    {
        $this->as(5);
        $id = $this->pending();
        $po = collect($this->pos())->sortByDesc('sisa')->first();
        $p = $this->payload($id, $po);
        $l = $p['lines'][0];
        $half = intdiv($l['volume'], 2);
        $p['lines'][0]['volume'] = $l['volume'] - $half;
        $p['lines'][0]['split'] = ['volume' => $half, 'po' => $l['po']];
        $this->postJson("/api/v1/dr/$id/verifikasi", $p)->assertOk();
        $this->assertSame(1, DB::table('new_pro_inventory_potongan_stock')->where('id_pr', $id)->count());
        $this->assertSame(1, (int) DB::table('pro_pr_detail')->where('id_prd', $l['id_prd'])->value('is_split'));
        $det = $this->getJson("/api/v1/dr/$id")->assertOk();
        $this->assertSame($half, $det->json('lines.0.volume_potong'));

        $this->postJson("/api/v1/dr/$id/revisi")->assertStatus(409); // DR lama (Closed) tidak bisa direvisi
        DB::table('pro_pr')->where('id_pr', $id)->update(['tanggal_pr' => now()->toDateString()]);
        $this->postJson("/api/v1/dr/$id/revisi")->assertOk();
        $this->assertSame(3, (int) DB::table('pro_pr')->where('id_pr', $id)->value('disposisi_pr'));
        $this->assertSame(0, DB::table('new_pro_inventory_depot')->where('id_pr', $id)->count());
        $this->assertSame(0, DB::table('new_pro_inventory_potongan_stock')->where('id_pr', $id)->count());
    }

    public function test_stok_tidak_cukup_dan_depot_beda_ditolak(): void
    {
        $this->as(5);
        $id = $this->pending();
        $pos = collect($this->pos());
        $kecil = $pos->sortBy('sisa')->first();
        $p = $this->payload($id, $kecil);
        $p['lines'][0]['volume'] = $kecil['sisa'] + 1000; // jelas melebihi stok (volume asli tidak sama, tertolak oleh salah satu aturan)
        $this->postJson("/api/v1/dr/$id/verifikasi", $p)->assertStatus(422);

        $a = $pos->first();
        $b = $pos->first(fn ($x) => $x['id_terminal'] !== $a['id_terminal']);
        if ($b) {
            $p = $this->payload($id, $a);
            $l = $p['lines'][0];
            $half = intdiv($l['volume'], 2);
            $p['lines'][0]['volume'] = $l['volume'] - $half;
            $p['lines'][0]['split'] = ['volume' => $half, 'po' => ['id_po_supplier' => $b['id_po_supplier'], 'id_po_receive' => $b['id_po_receive'], 'id_terminal' => $b['id_terminal']]];
            $this->postJson("/api/v1/dr/$id/verifikasi", $p)->assertStatus(422)->assertSee('Depot harus sama');
        }
    }

    public function test_kembalikan_ke_dp_menghapus_dr_dan_reset_plan(): void
    {
        $this->as(5);
        $id = $this->pending();
        $plans = DB::table('pro_pr_detail')->where('id_pr', $id)->where('is_approved', 1)->pluck('id_plan');
        $this->postJson("/api/v1/dr/$id/kembali-dp")->assertOk();
        $this->assertFalse(DB::table('pro_pr')->where('id_pr', $id)->exists());
        $this->assertSame(0, DB::table('pro_pr_detail')->where('id_pr', $id)->where('is_approved', 1)->count());
        $this->assertSame(0, DB::table('pro_po_customer_plan')->whereIn('id_plan', $plans)->where('status_plan', '<>', 0)->count());
        $this->postJson("/api/v1/dr/$id/kembali-dp")->assertStatus(404);
    }

    public function test_history_approve_dan_reset_split_tanpa_split(): void
    {
        $this->as(5);
        $id = $this->pending();
        $l = $this->getJson("/api/v1/dr/$id")->json('lines.0');
        $this->getJson("/api/v1/dr/$id/history/{$l['id_penawaran']}")->assertOk()->assertJsonStructure(['data']);
        $this->postJson("/api/v1/dr/$id/reset-split", ['id_prd' => $l['id_prd']])->assertStatus(409);
    }

    public function test_sesi_wajah_butuh_password(): void
    {
        $u = AclUser::where('id_role', 5)->where('is_active', 1)->firstOrFail();
        Sanctum::actingAs($u, ['face']);
        $id = $this->pending();
        $this->postJson("/api/v1/dr/$id/verifikasi", ['lines' => [['id_prd' => 1, 'volume' => 1, 'po' => ['id_po_supplier' => 1, 'id_po_receive' => 1, 'id_terminal' => 1]]]])
            ->assertStatus(403)->assertJsonPath('code', 'STEP_UP_REQUIRED');
        $this->postJson("/api/v1/dr/$id/kembali-dp")->assertStatus(403)->assertJsonPath('code', 'STEP_UP_REQUIRED');
    }
}
