<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Master Harga Jual (pro_master_harga_minyak), sumber: master-approval-harga*.php, add-master-harga-minyak.php, detil-master-harga-minyak.php.
 * Satu harga = periode + area + produk (pajak = 1, seperti form web). Tier: Harga Dasar (pricelist), BM, OM, COO, CEO.
 * Catatan: file action web (action/master-harga-minyak.php) tidak ada di folder, jadi nilai bawaan penyimpanan
 * (is_evaluated = 1, is_approved = 1, loco/skp = '0', is_edited = 1 saat ubah) disimpulkan dari data yang ada.
 */
class HargaController extends Controller
{
    private const T = 'pro_master_harga_minyak';
    private const PAJAK = 1;

    private function key(Request $r): array
    {
        $v = validator($r->all(), [
            'awal' => ['required', 'date_format:Y-m-d'], 'akhir' => ['required', 'date_format:Y-m-d'],
            'area' => ['required', 'integer'], 'produk' => ['required', 'integer'],
        ])->validate();

        return [$v['awal'], $v['akhir'], (int) $v['area'], (int) $v['produk']];
    }

    private function row(string $awal, string $akhir, int $area, int $produk)
    {
        return DB::table(self::T.' as a')
            ->join('pro_master_area as b', 'a.id_area', '=', 'b.id_master')
            ->join('pro_master_produk as c', 'a.produk', '=', 'c.id_master')
            ->where('a.periode_awal', $awal)->where('a.periode_akhir', $akhir)->where('a.id_area', $area)->where('a.produk', $produk)
            ->where('a.pajak', self::PAJAK);
    }

    private static function dipakai(string $awal, string $akhir, int $area, int $produk): int
    {
        return (int) DB::table('pro_penawaran')
            ->whereRaw('DATE(masa_awal) = ?', [$awal])->whereRaw('DATE(masa_akhir) = ?', [$akhir])
            ->whereRaw('CAST(id_area AS UNSIGNED) = ?', [$area])->whereRaw('CAST(produk_tawar AS UNSIGNED) = ?', [$produk])->count();
    }

    public function options(): JsonResponse
    {
        return response()->json([
            'area'   => DB::table('pro_master_area')->where('is_active', 1)->orderBy('nama_area')->get(['id_master as id', 'nama_area as label']),
            'produk' => DB::table('pro_master_produk')->where('is_active', 1)->orderBy('jenis_produk')->get(['id_master as id', DB::raw("concat(jenis_produk,' - ',merk_dagang) as label")]),
        ]);
    }

    public function index(Request $r): JsonResponse
    {
        $q = DB::table(self::T.' as a')
            ->join('pro_master_area as b', 'a.id_area', '=', 'b.id_master')
            ->join('pro_master_produk as c', 'a.produk', '=', 'c.id_master')
            ->where('a.pajak', self::PAJAK)->where('a.is_evaluated', 1)->where('a.is_approved', 1);
        if (($d = $r->query('periode')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            $q->where('a.periode_awal', $d);
        }
        if (ctype_digit((string) $r->query('area'))) {
            $q->where('a.id_area', (int) $r->query('area'));
        }
        if (ctype_digit((string) $r->query('produk'))) {
            $q->where('a.produk', (int) $r->query('produk'));
        }
        $per = min(max((int) $r->query('per_page', 20), 1), (int) config('orca.per_page_max'));
        $page = $q->orderByDesc('a.periode_awal')->orderBy('a.id_area')->orderBy('a.produk')
            ->select('a.periode_awal', 'a.periode_akhir', 'a.id_area as area', 'a.produk', 'b.nama_area', 'c.jenis_produk', 'c.merk_dagang',
                'a.harga_normal', 'a.harga_sm', 'a.harga_om', 'a.harga_coo', 'a.harga_ceo')
            ->paginate($per);
        $items = array_map(function ($x) {
            $x->dipakai = self::dipakai($x->periode_awal, $x->periode_akhir, (int) $x->area, (int) $x->produk) > 0;

            return $x;
        }, $page->items());

        return response()->json([
            'data' => $items,
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage()],
        ]);
    }

    public function show(Request $r): JsonResponse
    {
        [$awal, $akhir, $area, $produk] = $this->key($r);
        $row = $this->row($awal, $akhir, $area, $produk)
            ->select('a.*', 'b.nama_area', 'c.jenis_produk', 'c.merk_dagang')->first();
        if (! $row) {
            return response()->json(['message' => 'Harga tidak ditemukan.'], 404);
        }
        $out = (array) $row;
        $out['dipakai'] = self::dipakai($awal, $akhir, $area, $produk) > 0;

        return response()->json($out);
    }

    private static function priceRules(string $p): array
    {
        $max = 'max:2000000000';

        return [
            "$p.normal" => ['required', 'integer', 'min:1', $max],
            "$p.sm"     => ['required', 'integer', 'min:1', $max],
            "$p.om"     => ['required', 'integer', 'min:1', $max],
            "$p.coo"    => ['nullable', 'integer', 'min:0', $max],
            "$p.ceo"    => ['nullable', 'integer', 'min:0', $max],
            "$p.note"   => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'periode_awal'  => ['required', 'date_format:Y-m-d'],
            'periode_akhir' => ['required', 'date_format:Y-m-d', 'after_or_equal:periode_awal'],
            'items'         => ['required', 'array', 'min:1', 'max:50'],
            'items.*.area'   => ['required', 'integer'],
            'items.*.produk' => ['required', 'integer'],
        ] + self::priceRules('items.*'));

        $seen = [];
        foreach ($data['items'] as $i => $it) {
            $k = $it['area'].'-'.$it['produk'];
            if (isset($seen[$k])) {
                return response()->json(['message' => 'Baris '.($i + 1).': area dan produk yang sama diisi lebih dari sekali.', 'code' => 'DUPLICATE_ROW'], 422);
            }
            $seen[$k] = true;
        }
        $areas = DB::table('pro_master_area')->where('is_active', 1)->pluck('nama_area', 'id_master');
        $produks = DB::table('pro_master_produk')->where('is_active', 1)->pluck('merk_dagang', 'id_master');
        foreach ($data['items'] as $i => $it) {
            if (! isset($areas[$it['area']]) || ! isset($produks[$it['produk']])) {
                return response()->json(['message' => 'Baris '.($i + 1).': area atau produk tidak valid.', 'code' => 'INVALID_REF'], 422);
            }
        }

        $user = $request->user();

        return DB::transaction(function () use ($data, $user, $request, $areas, $produks) {
            foreach ($data['items'] as $i => $it) {
                $exists = DB::table(self::T)->where('periode_awal', $data['periode_awal'])->where('periode_akhir', $data['periode_akhir'])
                    ->where('id_area', $it['area'])->where('produk', $it['produk'])->where('pajak', self::PAJAK)->exists();
                if ($exists) {
                    return response()->json(['message' => 'Baris '.($i + 1).': harga untuk '.$areas[$it['area']].' - '.$produks[$it['produk']].' pada periode ini sudah ada. Gunakan Ubah.', 'code' => 'EXISTS'], 409);
                }
                DB::table(self::T)->insert([
                    'periode_awal' => $data['periode_awal'], 'periode_akhir' => $data['periode_akhir'], 'id_area' => $it['area'], 'pajak' => self::PAJAK, 'produk' => $it['produk'],
                    'harga_normal' => $it['normal'], 'harga_sm' => $it['sm'], 'harga_om' => $it['om'], 'harga_coo' => $it['coo'] ?? 0, 'harga_ceo' => $it['ceo'] ?? 0,
                    'loco' => '0', 'skp' => '0', 'note_jual' => (string) ($it['note'] ?? ''),
                    'is_evaluated' => 1, 'is_approved' => 1, 'tanggal_persetujuan' => now(), 'is_edited' => 0,
                    'created_time' => now(), 'created_ip' => $request->ip(), 'created_by' => $user->fullname,
                ]);
            }

            return response()->json(['message' => count($data['items']).' harga jual disimpan.', 'jumlah' => count($data['items'])], 201);
        });
    }

    public function update(Request $request): JsonResponse
    {
        [$awal, $akhir, $area, $produk] = $this->key($request);
        $h = $request->validate(['h' => ['required', 'array']] + self::priceRules('h'))['h'];
        $n = DB::table(self::T)->where('periode_awal', $awal)->where('periode_akhir', $akhir)->where('id_area', $area)->where('produk', $produk)->where('pajak', self::PAJAK)
            ->update([
                'harga_normal' => $h['normal'], 'harga_sm' => $h['sm'], 'harga_om' => $h['om'], 'harga_coo' => $h['coo'] ?? 0, 'harga_ceo' => $h['ceo'] ?? 0,
                'note_jual' => (string) ($h['note'] ?? ''), 'is_edited' => 1,
                'lastupdate_time' => now(), 'lastupdate_ip' => $request->ip(), 'lastupdate_by' => $request->user()->fullname,
            ]);
        if (! $n) {
            return response()->json(['message' => 'Harga tidak ditemukan.'], 404);
        }

        return response()->json(['message' => 'Harga jual diperbarui.']);
    }

    public function destroy(Request $request): JsonResponse
    {
        [$awal, $akhir, $area, $produk] = $this->key($request);
        if (self::dipakai($awal, $akhir, $area, $produk) > 0) {
            return response()->json(['message' => 'Harga ini sudah dipakai pada penawaran dan tidak bisa dihapus.', 'code' => 'IN_USE'], 409);
        }
        $n = DB::table(self::T)->where('periode_awal', $awal)->where('periode_akhir', $akhir)->where('id_area', $area)->where('produk', $produk)->delete();

        return $n ? response()->json(['message' => 'Harga jual dihapus.']) : response()->json(['message' => 'Harga tidak ditemukan.'], 404);
    }
}
