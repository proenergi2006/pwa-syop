<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Sisa stok inventory per PO supplier (sumber: view vw_terminal_inventory_receive, sama dengan sisa-stock.php). */
class InventoryController extends Controller
{
    private function base(Request $r)
    {
        $q = DB::table('vw_terminal_inventory_receive as a')
            ->join('pro_master_terminal as e', 'a.id_terminal', '=', 'e.id_master')
            ->where('a.sisa_inven', '>', 0);

        foreach (['produk' => 'a.id_produk', 'cabang' => 'a.id_cabang', 'vendor' => 'a.id_vendor', 'terminal' => 'a.id_terminal', 'kategori' => 'e.kategori_terminal'] as $key => $col) {
            $v = $r->query($key);
            if ($v !== null && $v !== '' && ctype_digit((string) $v)) {
                $q->where($col, (int) $v);
            }
        }
        if ($term = trim((string) $r->query('q', ''))) {
            $q->where(fn ($w) => $w
                ->where('a.nomor_po_supplier', 'like', "%{$term}%")
                ->orWhere('a.nama_vendor', 'like', "%{$term}%")
                ->orWhere('a.nama_terminal', 'like', "%{$term}%"));
        }

        return $q;
    }

    public function index(Request $r): JsonResponse
    {
        $per = min(max((int) $r->query('per_page', 20), 1), (int) config('orca.per_page_max'));

        $sum = (clone $this->base($r))->selectRaw('coalesce(sum(a.sisa_inven),0) as liter, count(*) as po')->first();
        $perProduk = (clone $this->base($r))
            ->groupBy('a.id_produk', 'a.jenis_produk', 'a.merk_dagang')
            ->orderByRaw('sum(a.sisa_inven) desc')
            ->selectRaw("a.id_produk, concat(a.jenis_produk,' - ',a.merk_dagang) as produk, sum(a.sisa_inven) as liter")
            ->get();

        $page = $this->base($r)
            ->orderBy('a.tgl_po_supplier')->orderBy('a.id_terminal')->orderBy('a.id_produk')->orderBy('a.id_cabang')->orderBy('a.id_vendor')
            ->select('a.id_po_receive', 'a.id_po_supplier', 'a.nomor_po_supplier as nomor_po', 'a.tgl_po_supplier as tgl_po',
                'a.sisa_inven', 'a.harga_tebus', 'a.jenis_produk', 'a.merk_dagang', 'a.nama_cabang', 'a.nama_vendor',
                'a.nama_terminal', 'a.tanki_terminal', 'a.lokasi_terminal', 'e.kategori_terminal')
            ->paginate($per);

        return response()->json([
            'data'    => $page->items(),
            'summary' => ['liter' => (float) $sum->liter, 'po' => (int) $sum->po, 'per_produk' => $perProduk],
            'meta'    => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage()],
        ]);
    }

    public function filters(): JsonResponse
    {
        return response()->json([
            'produk'   => DB::table('pro_master_produk')->where('is_active', 1)->orderBy('jenis_produk')->get(['id_master as id', DB::raw("concat(jenis_produk,' - ',merk_dagang) as label")]),
            'cabang'   => DB::table('pro_master_cabang')->where('is_active', 1)->orderBy('nama_cabang')->get(['id_master as id', 'nama_cabang as label']),
            'vendor'   => DB::table('pro_master_vendor')->where('is_active', 1)->orderBy('nama_vendor')->get(['id_master as id', 'nama_vendor as label']),
            'terminal' => DB::table('pro_master_terminal')->where('is_active', 1)->orderBy('nama_terminal')->get(['id_master as id', DB::raw("trim(concat(nama_terminal,' ',coalesce(tanki_terminal,''),', ',coalesce(lokasi_terminal,''))) as label")]),
            'kategori' => [['id' => 1, 'label' => 'Terminal'], ['id' => 2, 'label' => 'Dispenser'], ['id' => 3, 'label' => 'Truck']],
        ]);
    }

    /**
     * Stok per terminal + harga rata-rata tertimbang (COGS), untuk Beranda CEO.
     * wavg = SUM(harga_tebus x vol_terima) / SUM(vol_terima); harga_tebus > 100.000 dianggap salah input dan dikeluarkan.
     */
    public function terminals(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = (int) $user->id_role;
        $showCogs = in_array($role, [(int) config('orca.roles.ceo'), (int) config('orca.roles.purchasing')], true); // COGS: CEO dan Purchasing
        $bm = $role === (int) config('orca.roles.bm');

        $rows = DB::table('vw_terminal_inventory_receive as a')
            ->join('pro_master_terminal as e', 'a.id_terminal', '=', 'e.id_master')
            ->when($bm, fn ($q) => $q->where('e.id_cabang', (int) $user->id_wilayah)) // BM hanya cabangnya
            ->groupBy('a.id_terminal')
            ->selectRaw('a.id_terminal, max(a.nama_terminal) as nama, max(a.tanki_terminal) as tanki, max(a.nama_cabang) as cabang,
                max(a.jenis_produk) as produk, max(e.batas_atas) as batas_atas, max(e.batas_bawah) as batas_bawah, max(e.kategori_terminal) as kategori,
                coalesce(sum(a.sisa_inven),0) as sisa,
                sum(case when a.harga_tebus <= 100000 then a.harga_tebus * a.vol_terima_barang else 0 end)
                  / nullif(sum(case when a.harga_tebus <= 100000 then a.vol_terima_barang else 0 end),0) as wavg')
            ->havingRaw('coalesce(sum(a.sisa_inven),0) > 0')
            ->orderByDesc('sisa')
            ->get()
            ->map(fn ($r) => [
                'id'          => (int) $r->id_terminal,
                'nama'        => trim($r->nama),
                'tanki'       => $r->tanki,
                'cabang'      => $r->cabang,
                'produk'      => $r->produk,
                'kategori'    => $r->kategori,
                'sisa'        => round((float) $r->sisa, 2),
                'wavg'        => round((float) $r->wavg, 2),
                'nilai'       => round((float) $r->wavg * (float) $r->sisa, 2),
                'batas_atas'  => (int) $r->batas_atas,
                'batas_bawah' => (int) $r->batas_bawah,
            ])->map(function ($r) use ($showCogs) {
                if (! $showCogs) {
                    unset($r['wavg'], $r['nilai']); // COGS hanya untuk CEO dan Purchasing
                }

                return $r;
            })->all();

        $stok = array_sum(array_column($rows, 'sisa'));
        $summary = ['stok' => $stok, 'terminal' => count($rows)];
        if ($showCogs) {
            $nilai = array_sum(array_column($rows, 'nilai'));
            $summary += ['nilai' => $nilai, 'wavg' => $stok > 0 ? round($nilai / $stok, 2) : 0];
        }

        return response()->json(['data' => $rows, 'summary' => $summary, 'cogs' => $showCogs, 'scope' => $bm ? 'cabang' : 'semua']);
    }
}
