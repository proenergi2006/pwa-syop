<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TruckingTiTest extends TestCase
{
    use DatabaseTransactions;

    private function purch(): void
    {
        Sanctum::actingAs(AclUser::where('id_role', 5)->where('is_active', 1)->firstOrFail(), ['*']);
    }

    private function isi(array $o = []): array
    {
        $r = DB::table('pro_master_cabang as c')->join('pro_master_transportir as t', 't.lokasi_suplier', '=', 'c.nama_cabang')->join('pro_master_ongkos_angkut as o', 'o.id_transportir', '=', 't.id_master')
            ->join('pro_master_volume_angkut as v', 'v.id_master', '=', 'o.id_vol_angkut')->first(['c.id_master as cab', 't.id_master as tr', 'o.id_wil_angkut as w', 'o.id_vol_angkut as vid', 'v.volume_angkut as vol', 'o.ongkos_angkut as ongkos']);
        $term = DB::table('pro_master_terminal')->where('is_active', 1)->pluck('id_master');

        return array_merge([
            'id_wilayah' => (int) $r->cab, 'tanggal' => '2026-11-21', 'volume' => (int) $r->vol, 'load_port' => (int) $term[0], 'discharge_port' => (int) $term[1], 'tipe_oa' => 'Pro Energi', 'transportir' => (int) $r->tr,
            'tgl_awal' => '2026-11-25', 'tgl_akhir' => '2026-11-26', 'ongkos_angkut' => $r->w.','.$r->vid.','.$r->ongkos, 'remarks' => 'catatan uji',
        ], $o);
    }

    public function test_hanya_purchasing(): void
    {
        foreach ([21, 7] as $role) {
            Sanctum::actingAs(AclUser::where('id_role', $role)->where('is_active', 1)->firstOrFail(), ['*']);
            $this->getJson('/api/v1/trucking-ti')->assertForbidden();
            $this->postJson('/api/v1/trucking-ti', [])->assertForbidden();
        }
    }

    public function test_daftar_dan_filter(): void
    {
        $this->purch();
        $r = $this->getJson('/api/v1/trucking-ti?per_page=5')->assertOk()->json();
        $this->assertNotEmpty($r['data']);
        $this->assertGreaterThan(5, $r['meta']['total']);
        $this->assertArrayHasKey('badge', $r['data'][0]);
        foreach ($this->getJson('/api/v1/trucking-ti?status=7&per_page=5')->json('data') as $x) {
            $this->assertContains('Received', array_column($x['badge'], 'teks'));
        }
        foreach ($this->getJson('/api/v1/trucking-ti?status=6&per_page=5')->json('data') as $x) {
            $this->assertSame('Cancel', $x['cancel']);
        }
        $nomor = $r['data'][0]['nomor_ti'];
        $this->assertContains($nomor, array_column($this->getJson('/api/v1/trucking-ti?q='.urlencode($nomor))->json('data'), 'nomor_ti'));
        $this->assertNotEmpty($this->getJson('/api/v1/trucking-ti/opsi')->json('terminal'));
    }

    public function test_pilihan_transporter_dan_ongkos(): void
    {
        $this->purch();
        $d = $this->isi();
        $t = $this->getJson('/api/v1/trucking-ti/transportir?id_wilayah='.$d['id_wilayah'])->assertOk()->json('data');
        $this->assertContains($d['transportir'], array_column($t, 'id'));
        $o = $this->getJson('/api/v1/trucking-ti/ongkos?transportir='.$d['transportir'].'&volume='.$d['volume'])->assertOk()->json('data');
        $this->assertContains($d['ongkos_angkut'], array_column($o, 'value'));
        $this->assertSame([], $this->getJson('/api/v1/trucking-ti/ongkos?transportir='.$d['transportir'].'&volume=1')->json('data')); // volume harus persis
    }

    public function test_tambah_ubah_cancel(): void
    {
        Mail::fake();
        $this->purch();
        $d = $this->isi();
        $this->postJson('/api/v1/trucking-ti', array_diff_key($d, ['remarks' => 1]))->assertStatus(422);
        $this->postJson('/api/v1/trucking-ti', ['ongkos_angkut' => 'abc'] + $d)->assertStatus(422);
        $this->postJson('/api/v1/trucking-ti', ['tgl_akhir' => '2026-11-01'] + $d)->assertStatus(422);
        $r = $this->postJson('/api/v1/trucking-ti', $d)->assertCreated()->json();
        $this->assertMatchesRegularExpression('#^\d{3}/PE-Purch/250/[IVX]+/\d{4}$#', $r['nomor_ti']);
        $r2 = $this->postJson('/api/v1/trucking-ti', $d)->json();
        $this->assertSame((int) substr($r['nomor_ti'], 0, 3) + 1, (int) substr($r2['nomor_ti'], 0, 3));
        $s = $this->getJson('/api/v1/trucking-ti/'.$r['id'])->assertOk()->json();
        $this->assertTrue($s['bisa_ubah']);
        $this->assertFalse($s['bisa_cetak']);

        $this->putJson('/api/v1/trucking-ti/'.$r['id'], ['remarks' => 'baru'] + $d)->assertOk();
        $this->assertSame('baru', DB::table('pro_trucking_instruction_req')->where('id_master', $r['id'])->value('remarks'));
        // disetujui CEO: tidak bisa diubah, bisa dicetak
        DB::table('pro_trucking_instruction_req')->where('id_master', $r['id'])->update(['status' => 1, 'ceo_result' => 1, 'ceo_tanggal' => now()]);
        $this->putJson('/api/v1/trucking-ti/'.$r['id'], $d)->assertStatus(409);
        $this->assertTrue($this->getJson('/api/v1/trucking-ti/'.$r['id'])->json('bisa_cetak'));
        $c = $this->getJson('/api/v1/trucking-ti/'.$r['id'].'/cetak')->assertOk()->json();
        $this->assertSame($r['nomor_ti'], $c['nomor']);
        $this->assertSame(number_format($d['volume']), $c['qty']);
        // ditolak CEO: bisa diubah lagi
        DB::table('pro_trucking_instruction_req')->where('id_master', $r['id'])->update(['ceo_result' => 2]);
        $this->putJson('/api/v1/trucking-ti/'.$r['id'], $d)->assertOk();
        $this->assertSame(0, (int) DB::table('pro_trucking_instruction_req')->where('id_master', $r['id'])->value('status'));
        // cancel
        $this->postJson('/api/v1/trucking-ti/'.$r['id'].'/cancel', [])->assertStatus(422);
        $this->postJson('/api/v1/trucking-ti/'.$r['id'].'/cancel', ['ket_cancel' => 'batal'])->assertOk();
        $row = DB::table('pro_trucking_instruction_req')->where('id_master', $r['id'])->first();
        $this->assertSame(6, (int) $row->status);
        $this->assertSame(1, (int) $row->is_cancel);
        $this->postJson('/api/v1/trucking-ti/'.$r['id'].'/cancel', ['ket_cancel' => 'lagi'])->assertStatus(409);
        $this->putJson('/api/v1/trucking-ti/'.$r['id'], $d)->assertStatus(409);
    }

    public function test_received_tidak_bisa_cancel(): void
    {
        $this->purch();
        $id = DB::table('pro_trucking_instruction_req')->where('status', 7)->value('id_master');
        $this->postJson("/api/v1/trucking-ti/$id/cancel", ['ket_cancel' => 'x'])->assertStatus(409);
    }
}
