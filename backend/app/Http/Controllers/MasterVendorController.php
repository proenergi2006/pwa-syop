<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StepUp;
use App\Services\Accurate\AccurateClient;
use App\Services\Accurate\AccurateException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Master Vendor (pro_master_vendor) untuk Purchasing/Procurement (peran 5), terhubung ke Accurate Online (vendor/save.do).
 * Sumber: master-vendor.php, master-vendor (1).php (daftar), add-master-vendor.php, master-vendor (2).php (simpan).
 * Tambah: simpan lalu buat vendor di Accurate (name, transDate, currencyCode IDR, vendorNo), id Accurate disimpan; gagal = seluruhnya dibatalkan.
 * Ubah: bila vendor sudah punya id_accurate, Accurate ikut diubah (id, name, transDate, vendorNo); gagal = dibatalkan. Tanpa hapus (di web hanya peran 1).
 * Bila ACCURATE_ENABLED=false, hanya database lokal yang berubah (seperti modul Accurate lain di PWA).
 */
class MasterVendorController extends Controller
{
    use StepUp;

    private static function esc(?string $s): string
    {
        return htmlspecialchars(trim((string) $s), ENT_QUOTES);
    }

    private static function plain(?string $s): string
    {
        return html_entity_decode((string) $s, ENT_QUOTES);
    }

    private function out($x): array
    {
        $kode = (string) $x->kode_vendor;

        return [
            'id' => (int) $x->id_master, 'nama' => self::plain($x->nama_vendor), 'kode' => str_starts_with($kode, 'V-') ? substr($kode, 2) : $kode, 'kode_vendor' => $kode ?: null,
            'inisial' => $x->inisial_vendor, 'aktif' => (int) $x->is_active === 1, 'accurate' => $x->id_accurate ? (int) $x->id_accurate : null,
        ];
    }

    public function index(Request $r): JsonResponse
    {
        $q = DB::table('pro_master_vendor');
        $st = (string) $r->query('status', '1');
        if ($st === '1' || $st === '0') {
            $q->where('is_active', (int) $st);
        }
        if (($term = trim((string) $r->query('q', ''))) !== '') {
            $q->whereRaw('upper(nama_vendor) like ?', ['%'.strtoupper(htmlspecialchars($term, ENT_QUOTES)).'%']);
        }
        $per = min(max((int) $r->query('per_page', 20), 1), (int) config('orca.per_page_max'));
        $page = $q->orderBy('is_active')->orderBy('id_master')->paginate($per);

        return response()->json([
            'data' => collect($page->items())->map(fn ($x) => $this->out($x))->all(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage()],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $x = DB::table('pro_master_vendor')->where('id_master', $id)->first();
        if (! $x) {
            return response()->json(['message' => 'Vendor tidak ditemukan.'], 404);
        }

        return response()->json($this->out($x) + [
            'inisial_terkunci' => DB::table('new_pro_inventory_vendor_po')->where('id_vendor', $id)->exists(), // sudah punya PO Supplier: inisial tidak boleh diubah (readonly di web)
            'dibuat_oleh' => $x->created_by, 'dibuat_pada' => $x->created_time, 'diubah_oleh' => $x->lastupdate_by, 'diubah_pada' => $x->lastupdate_time,
        ]);
    }

    /** "Referensi Kode Accurate Terakhir": vendorNo terbesar di Accurate (vendor/list.do), null bila Accurate nonaktif atau gagal. */
    public function lastCode(AccurateClient $api): JsonResponse
    {
        if (! $api->enabled()) {
            return response()->json(['kode' => null, 'accurate' => false]);
        }
        $res = $api->get('vendor/list.do', ['fields' => 'id,vendorNo,name', 'sp.sort' => 'vendorNo|desc', 'sp.pageSize' => 1]);
        if (empty($res['s'])) {
            return response()->json(['kode' => null, 'accurate' => true, 'message' => ($res['d'][0] ?? 'Gagal membaca vendor Accurate').' - Response dari Accurate']);
        }

        return response()->json(['kode' => $res['d'][0]['vendorNo'] ?? null, 'accurate' => true]);
    }

    private function input(Request $r): array
    {
        return $r->validate([
            'nama' => ['required', 'string', 'max:80'], 'kode' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._\-]+$/'],
            'inisial' => ['required', 'string', 'max:100'], 'aktif' => ['required', 'boolean'],
        ]);
    }

    private static function fail(string $m, int $s = 422, string $c = 'INVALID')
    {
        abort(response()->json(['message' => $m, 'code' => $c], $s));
    }

    /** Hasil Accurate yang gagal menjadi AccurateException dengan format pesan web. */
    private function accurate(AccurateClient $api, array $data): array
    {
        $res = $api->post('vendor/save.do', $data);
        if (empty($res['s'])) {
            throw new AccurateException(($res['d'][0] ?? 'Gagal menyimpan vendor di Accurate').' - Response dari Accurate');
        }

        return $res;
    }

    private function transDate(): string
    {
        return now(config('accurate.timezone'))->format('d/m/Y');
    }

    public function store(Request $r, AccurateClient $api): JsonResponse
    {
        $d = $this->input($r);
        if ($resp = $this->stepUp($r)) {
            return $resp;
        }
        $nama = self::esc($d['nama']);
        $kode = 'V-'.self::esc($d['kode']);
        try {
            return DB::transaction(function () use ($r, $d, $api, $nama, $kode) {
                if (DB::table('pro_master_vendor')->where('kode_vendor', $kode)->exists()) {
                    self::fail("Kode vendor $kode sudah dipakai.");
                }
                $id = DB::table('pro_master_vendor')->insertGetId([
                    'nama_vendor' => $nama, 'kode_vendor' => $kode, 'is_active' => $d['aktif'] ? 1 : 0, 'inisial_vendor' => self::esc($d['inisial']),
                    'created_time' => now(), 'created_ip' => (string) $r->ip(), 'created_by' => $r->user()->fullname,
                ]);
                if ($api->enabled()) {
                    $res = $this->accurate($api, ['name' => $nama, 'transDate' => $this->transDate(), 'currencyCode' => 'IDR', 'vendorNo' => $kode]);
                    DB::table('pro_master_vendor')->where('id_master', $id)->update(['id_accurate' => $res['r']['id'] ?? null, 'kode_vendor' => $kode]);
                }

                return response()->json(['id' => $id], 201);
            });
        } catch (AccurateException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'ACCURATE'], 502);
        }
    }

    public function update(Request $r, int $id, AccurateClient $api): JsonResponse
    {
        $x = DB::table('pro_master_vendor')->where('id_master', $id)->first();
        if (! $x) {
            return response()->json(['message' => 'Vendor tidak ditemukan.'], 404);
        }
        $d = $this->input($r);
        if ($resp = $this->stepUp($r)) {
            return $resp;
        }
        $nama = self::esc($d['nama']);
        $kode = 'V-'.self::esc($d['kode']);
        $inisial = self::esc($d['inisial']);
        if ($inisial !== (string) $x->inisial_vendor && DB::table('new_pro_inventory_vendor_po')->where('id_vendor', $id)->exists()) {
            return response()->json(['message' => 'Inisial vendor tidak bisa diubah karena vendor sudah punya PO Supplier.', 'code' => 'INITIAL_LOCKED'], 422);
        }
        if ($kode !== (string) $x->kode_vendor && DB::table('pro_master_vendor')->where('kode_vendor', $kode)->where('id_master', '<>', $id)->exists()) {
            return response()->json(['message' => "Kode vendor $kode sudah dipakai.", 'code' => 'INVALID'], 422);
        }
        try {
            return DB::transaction(function () use ($r, $id, $api, $x, $d, $nama, $kode, $inisial) {
                DB::table('pro_master_vendor')->where('id_master', $id)->update([
                    'nama_vendor' => $nama, 'kode_vendor' => $kode, 'is_active' => $d['aktif'] ? 1 : 0, 'inisial_vendor' => $inisial,
                    'lastupdate_time' => now(), 'lastupdate_ip' => (string) $r->ip(), 'lastupdate_by' => $r->user()->fullname,
                ]);
                if ($x->id_accurate && $api->enabled()) {
                    $this->accurate($api, ['id' => $x->id_accurate, 'name' => $nama, 'transDate' => $this->transDate(), 'vendorNo' => $kode]);
                }

                return response()->json(['ok' => true]);
            });
        } catch (AccurateException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'ACCURATE'], 502);
        }
    }
}
