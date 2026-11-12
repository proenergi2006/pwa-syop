<?php

namespace App\Http\Controllers;

use App\Services\PasswordGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Master Produk (pro_master_produk), sumber: master-produk*.php, add-master-produk.php, detil-master-produk.php.
 * Daftar, detail, tambah, ubah (tanpa hapus, seperti web). Teks disimpan ter-escape HTML (ENT_QUOTES), catatan dengan <br />.
 */
class ProdukController extends Controller
{
    private const T = 'pro_master_produk';

    private static function esc(string $s): string
    {
        return htmlspecialchars(trim($s), ENT_QUOTES);
    }

    private static function plain(?string $s): string
    {
        return html_entity_decode(str_replace(['<br />', '<br>'], "\n", (string) $s), ENT_QUOTES);
    }

    private function out($r, bool $detail = false): array
    {
        $o = [
            'id' => (int) $r->id_master, 'jenis' => self::plain($r->jenis_produk), 'merk' => self::plain($r->merk_dagang),
            'aktif' => (int) $r->is_active === 1, 'catatan' => self::plain($r->catatan_produk),
        ];
        if ($detail) {
            $o += ['dibuat_oleh' => $r->created_by, 'dibuat_pada' => $r->created_time, 'diubah_oleh' => $r->lastupdate_by, 'diubah_pada' => $r->lastupdate_time];
        }

        return $o;
    }

    public function index(Request $r): JsonResponse
    {
        $q = DB::table(self::T);
        $st = $r->query('status', 'all');
        if ($st === 'active') {
            $q->where('is_active', 1);
        } elseif ($st === 'inactive') {
            $q->where('is_active', 0);
        }
        if ($term = trim((string) $r->query('q', ''))) {
            $t = htmlspecialchars($term, ENT_QUOTES);
            $q->where(fn ($w) => $w->where('jenis_produk', 'like', "%{$t}%")->orWhere('merk_dagang', 'like', "%{$t}%")
                ->orWhere('jenis_produk', 'like', "%{$term}%")->orWhere('merk_dagang', 'like', "%{$term}%"));
        }
        $per = min(max((int) $r->query('per_page', 20), 1), (int) config('orca.per_page_max'));
        $page = $q->orderBy('id_master')->paginate($per);

        return response()->json([
            'data' => collect($page->items())->map(fn ($x) => $this->out($x))->all(),
            'counts' => ['all' => DB::table(self::T)->count(), 'active' => DB::table(self::T)->where('is_active', 1)->count()],
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage()],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $row = DB::table(self::T)->where('id_master', $id)->first();

        return $row ? response()->json($this->out($row, true)) : response()->json(['message' => 'Produk tidak ditemukan.'], 404);
    }

    /** Sesi hasil login wajah wajib mengonfirmasi password sebelum menulis data master. Null = lolos. */
    private function stepUp(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user->tokenCan('*')) {
            return null;
        }
        $password = (string) $request->input('password', '');
        if ($password === '') {
            return response()->json(['message' => 'Masukkan kata sandi untuk menyimpan perubahan.', 'code' => 'STEP_UP_REQUIRED'], 403);
        }
        if (! PasswordGate::check($user, $password, $request)) {
            return PasswordGate::locked($user)
                ? response()->json(['message' => 'Terlalu banyak salah kata sandi. Coba lagi 15 menit lagi.', 'code' => 'PASSWORD_LOCKED'], 429)
                : response()->json(['message' => 'Kata sandi salah.', 'code' => 'STEP_UP_FAILED'], 422);
        }

        return null;
    }

    private function input(Request $r): array
    {
        return $r->validate([
            'jenis' => ['required', 'string', 'max:25'], 'merk' => ['required', 'string', 'max:25'],
            'catatan' => ['nullable', 'string', 'max:1000'], 'aktif' => ['required', 'boolean'],
        ]);
    }

    private function cols(array $d): array
    {
        $jenis = self::esc($d['jenis']);
        $merk = self::esc($d['merk']);
        if ($jenis === '' || $merk === '' || mb_strlen($jenis) > 25 || mb_strlen($merk) > 25) {
            abort(response()->json(['message' => 'Jenis dan merk wajib diisi (maksimal 25 karakter setelah disimpan).', 'code' => 'INVALID'], 422));
        }
        $note = str_replace(["\r\n", "\r", "\n"], '<br />', self::esc((string) ($d['catatan'] ?? '')));
        if (mb_strlen($note) > 1000) {
            abort(response()->json(['message' => 'Catatan terlalu panjang.', 'code' => 'INVALID'], 422));
        }

        return ['jenis_produk' => $jenis, 'merk_dagang' => $merk, 'catatan_produk' => $note, 'is_active' => $d['aktif'] ? 1 : 0];
    }

    public function store(Request $r): JsonResponse
    {
        $d = $this->input($r);
        if ($resp = $this->stepUp($r)) {
            return $resp;
        }
        $c = $this->cols($d);
        $id = DB::table(self::T)->insertGetId($c + ['created_time' => now(), 'created_ip' => $r->ip(), 'created_by' => $r->user()->fullname]);

        return response()->json(['id' => $id], 201);
    }

    public function update(Request $r, int $id): JsonResponse
    {
        if (! DB::table(self::T)->where('id_master', $id)->exists()) {
            return response()->json(['message' => 'Produk tidak ditemukan.'], 404);
        }
        $d = $this->input($r);
        if ($resp = $this->stepUp($r)) {
            return $resp;
        }
        $c = $this->cols($d);
        DB::table(self::T)->where('id_master', $id)->update($c + ['lastupdate_time' => now(), 'lastupdate_ip' => $r->ip(), 'lastupdate_by' => $r->user()->fullname]);

        return response()->json(['ok' => true]);
    }
}
