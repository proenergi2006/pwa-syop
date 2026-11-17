<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Semua panggilan Accurate di sini DITIRUKAN (Http::fake); tidak ada yang menyentuh server Accurate sungguhan. */
class MasterDataTest extends TestCase
{
    use DatabaseTransactions;

    private const BASE = 'https://zeus.accurate.id/accurate/api';

    private function as(int $role): AclUser
    {
        $u = AclUser::where('id_role', $role)->where('is_active', 1)->firstOrFail();
        Sanctum::actingAs($u, ['*']);

        return $u;
    }

    private function aol(): void
    {
        config(['accurate.enabled' => true, 'accurate.token' => 'tok-uji', 'accurate.secret' => 'rahasia-uji']);
        Http::preventStrayRequests();
    }

    private function terminal(array $o = []): array
    {
        return $o + ['nama' => 'Terminal Uji', 'tanki' => 'T-01', 'initial' => 'TU', 'id_cabang' => 2, 'kategori' => 1, 'batas_atas' => 100000, 'batas_bawah' => 5000,
            'lokasi' => 'Jl. Uji "1"', 'telp' => '021', 'alamat' => 'Alamat', 'fax' => '021', 'pic' => 'Budi', 'latitude' => '-6.1', 'longitude' => '106.8', 'catatan' => "a\nb", 'aktif' => true,
            'attention' => [['nama' => 'Ani', 'posisi' => 'Manager', 'hp' => '08', 'email' => 'a@x.id'], ['nama' => '', 'posisi' => 'kosong']],
            'dokumen_baru' => [['dokumen' => 'Izin Usaha', 'masa_berlaku' => '2030-01-31']]];
    }

    public function test_hanya_purchasing(): void
    {
        foreach ([21, 7, 9] as $role) {
            $this->as($role);
            $this->getJson('/api/v1/master/terminal')->assertForbidden();
            $this->getJson('/api/v1/master/vendor')->assertForbidden();
        }
        $this->as(5);
        $this->getJson('/api/v1/master/terminal')->assertOk()->assertJsonStructure(['data', 'meta']);
        $this->getJson('/api/v1/master/vendor')->assertOk()->assertJsonStructure(['data', 'meta']);
        $this->getJson('/api/v1/master/terminal/options')->assertOk()->assertJsonCount(3, 'kategori');
    }

    public function test_terminal_tambah_detail_ubah(): void
    {
        $this->as(5);
        $id = $this->postJson('/api/v1/master/terminal', $this->terminal())->assertCreated()->json('id');
        $d = $this->getJson("/api/v1/master/terminal/$id")->assertOk();
        $d->assertJsonPath('nama', 'Terminal Uji')->assertJsonPath('lokasi', 'Jl. Uji "1"')->assertJsonPath('kategori_nama', 'Depo')->assertJsonPath('aktif', true);
        $this->assertCount(1, $d->json('attention'));          // baris attention tanpa nama dilewati
        $this->assertSame('Izin Usaha', $d->json('dokumen.0.dokumen'));
        $this->assertStringContainsString('&quot;', DB::table('pro_master_terminal')->where('id_master', $id)->value('lokasi_terminal'));

        $doc = $d->json('dokumen.0.id');
        $this->putJson("/api/v1/master/terminal/$id", $this->terminal(['nama' => 'Terminal Baru', 'aktif' => false, 'dokumen_baru' => [], 'hapus_dokumen' => [$doc]]))->assertOk();
        $this->getJson("/api/v1/master/terminal/$id")->assertJsonPath('nama', 'Terminal Baru')->assertJsonPath('aktif', false)->assertJsonCount(0, 'dokumen');
        $this->assertContains($id, collect($this->getJson('/api/v1/master/terminal?status=all&q=baru')->json('data'))->pluck('id')->all());
        $this->assertNotContains($id, collect($this->getJson('/api/v1/master/terminal?status=1&q=baru')->json('data'))->pluck('id')->all());
    }

    public function test_terminal_validasi(): void
    {
        $this->as(5);
        $this->postJson('/api/v1/master/terminal', $this->terminal(['nama' => '']))->assertStatus(422);
        $this->postJson('/api/v1/master/terminal', $this->terminal(['kategori' => 9]))->assertStatus(422);
        $this->postJson('/api/v1/master/terminal', $this->terminal(['id_cabang' => 99999]))->assertStatus(422);
        $this->putJson('/api/v1/master/terminal/999999', $this->terminal())->assertNotFound();
    }

    public function test_vendor_tambah_ke_accurate(): void
    {
        $this->as(5);
        $this->aol();
        Http::fake([self::BASE.'/vendor/save.do' => Http::response(['s' => true, 'r' => ['id' => 777]])]);
        $id = $this->postJson('/api/v1/master/vendor', ['nama' => 'PT Uji "A"', 'kode' => '99991', 'inisial' => 'ujia', 'aktif' => true])->assertCreated()->json('id');
        $row = DB::table('pro_master_vendor')->where('id_master', $id)->first();
        $this->assertSame('V-99991', $row->kode_vendor);
        $this->assertSame(777, (int) $row->id_accurate);
        Http::assertSent(function ($r) {
            $b = json_decode($r->body(), true);

            return str_ends_with($r->url(), '/vendor/save.do') && $b['name'] === 'PT Uji &quot;A&quot;' && $b['vendorNo'] === 'V-99991' && $b['currencyCode'] === 'IDR' && $b['transDate'] === now('Asia/Jakarta')->format('d/m/Y') && ! isset($b['id']);
        });
    }

    public function test_vendor_tambah_gagal_di_accurate_dibatalkan(): void
    {
        $this->as(5);
        $this->aol();
        Http::fake([self::BASE.'/vendor/save.do' => Http::response(['s' => false, 'd' => ['vendorNo sudah ada']])]);
        $this->postJson('/api/v1/master/vendor', ['nama' => 'PT Gagal', 'kode' => '99992', 'inisial' => 'GG', 'aktif' => true])
            ->assertStatus(502)->assertJsonPath('message', 'vendorNo sudah ada - Response dari Accurate');
        $this->assertSame(0, DB::table('pro_master_vendor')->where('kode_vendor', 'V-99992')->count());
    }

    public function test_vendor_ubah_dan_inisial_terkunci(): void
    {
        $this->as(5);
        $this->aol();
        $id = DB::table('pro_master_vendor')->insertGetId(['nama_vendor' => 'Lama', 'kode_vendor' => 'V-99993', 'id_accurate' => 555, 'is_active' => 1, 'inisial_vendor' => 'LM', 'created_time' => now(), 'created_ip' => '-', 'created_by' => 'uji']);
        Http::fake([self::BASE.'/vendor/save.do' => Http::sequence()->push(['s' => true, 'r' => ['id' => 555]])->push(['s' => false, 'd' => ['ditolak']])]);
        $this->putJson("/api/v1/master/vendor/$id", ['nama' => 'Baru', 'kode' => '99994', 'inisial' => 'LM', 'aktif' => false])->assertOk();
        $this->assertSame('V-99994', DB::table('pro_master_vendor')->where('id_master', $id)->value('kode_vendor'));
        Http::assertSent(fn ($r) => ($b = json_decode($r->body(), true)) && $b['id'] == 555 && $b['name'] === 'Baru' && $b['vendorNo'] === 'V-99994' && ! isset($b['currencyCode']));

        // gagal di Accurate: perubahan dibatalkan
        $this->putJson("/api/v1/master/vendor/$id", ['nama' => 'Lagi', 'kode' => '99994', 'inisial' => 'LM', 'aktif' => true])->assertStatus(502);
        $this->assertSame('Baru', DB::table('pro_master_vendor')->where('id_master', $id)->value('nama_vendor'));

        // inisial terkunci bila vendor sudah punya PO Supplier
        $po = (array) DB::table('new_pro_inventory_vendor_po')->first();
        if ($po) {
            DB::table('new_pro_inventory_vendor_po')->where('id_master', $po['id_master'])->update(['id_vendor' => $id]);
            $this->putJson("/api/v1/master/vendor/$id", ['nama' => 'Baru', 'kode' => '99994', 'inisial' => 'XX', 'aktif' => true])->assertStatus(422)->assertJsonPath('code', 'INITIAL_LOCKED');
            $this->assertTrue($this->getJson("/api/v1/master/vendor/$id")->json('inisial_terkunci'));
        }
    }

    public function test_vendor_tanpa_accurate_aktif_hanya_lokal(): void
    {
        $this->as(5);
        config(['accurate.enabled' => false]);
        Http::preventStrayRequests();
        $id = $this->postJson('/api/v1/master/vendor', ['nama' => 'Lokal', 'kode' => '99995', 'inisial' => 'LK', 'aktif' => true])->assertCreated()->json('id');
        $this->assertNull(DB::table('pro_master_vendor')->where('id_master', $id)->value('id_accurate'));
        $this->postJson('/api/v1/master/vendor', ['nama' => 'Dobel', 'kode' => '99995', 'inisial' => 'LK', 'aktif' => true])->assertStatus(422);
        $this->getJson('/api/v1/master/vendor/kode-terakhir')->assertOk()->assertJsonPath('kode', null);
    }

    public function test_kode_terakhir_dari_accurate(): void
    {
        $this->as(5);
        $this->aol();
        Http::fake([self::BASE.'/vendor/list.do*' => Http::response(['s' => true, 'd' => [['id' => 1, 'vendorNo' => 'V-00123', 'name' => 'X']]])]);
        $this->getJson('/api/v1/master/vendor/kode-terakhir')->assertOk()->assertJsonPath('kode', 'V-00123');
    }
}
