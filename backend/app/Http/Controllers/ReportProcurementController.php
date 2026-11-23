<?php

namespace App\Http\Controllers;

use App\Support\Xlsx;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Report Procurement (peran 5): Rekap Loaded Truck, Rekap Loaded Kapal, Cancel Trip, History Pemotongan Stock Truck, History Pemotongan Stock Operasional (BPUJ).
 * Sumber: c-rekap-loaded*.php, c-rekap-loaded-kapal*.php, e-rekap-loaded*-xls.php, c-history-stock*.php, c-history-stock-action.php,
 * c-history-stock-operational.php, c-history-stock-data-operational.php.
 */
class ReportProcurementController extends Controller
{
    private function perPage(Request $r): int
    {
        return min(max((int) $r->query('per_page', 10), 1), 100);
    }

    public function opsi(): JsonResponse
    {
        return response()->json([
            'cabang' => DB::table('pro_master_cabang')->where('is_active', 1)->where('id_master', '!=', 1)->orderBy('id_master')->get(['id_master', 'nama_cabang'])
                ->map(fn ($c) => ['id' => (int) $c->id_master, 'label' => $c->nama_cabang])->values(),
            'terminal' => DB::table('pro_master_terminal')->where('is_active', 1)->orderBy('id_master')->get(['id_master', 'nama_terminal', 'tanki_terminal'])
                ->map(fn ($t) => ['id' => (int) $t->id_master, 'label' => trim($t->nama_terminal.' '.$t->tanki_terminal)])->values(),
        ]);
    }

    // ------------------------------------------------------------------ Rekap Loaded (truck dan kapal)

    private static function statusLoaded(object $a): array
    {
        $tgl = fn ($t) => $t ? PoSupplierController::tglIndo($t) : '';
        $isLoaded = (int) $a->is_loaded;
        $isDel = (int) $a->is_delivered;
        $isCancel = (int) $a->is_cancel;
        if ($isLoaded === 0 && $isDel === 0 && $isCancel === 0) {
            return ['status' => 'Belum Loading', 'tgl_loading' => null, 'jam_loading' => null, 'tgl_cancel' => null];
        }
        if ($isLoaded === 1 && $isDel === 0 && $isCancel === 0) {
            return ['status' => 'Loading', 'tgl_loading' => $tgl($a->tanggal_loaded), 'jam_loading' => $a->jam_loaded, 'tgl_cancel' => null];
        }
        if ($isLoaded === 1 && $isDel === 1 && $isCancel === 0) {
            return ['status' => 'Delivered', 'tgl_loading' => $tgl($a->tanggal_loaded), 'jam_loading' => $a->jam_loaded, 'tgl_cancel' => null];
        }
        if ($isLoaded === 1 && $isDel === 0 && $isCancel === 1) {
            return ['status' => 'Cancel', 'tgl_loading' => null, 'jam_loading' => null, 'tgl_cancel' => $tgl($a->tanggal_cancel)];
        }

        return ['status' => '', 'tgl_loading' => null, 'jam_loading' => null, 'tgl_cancel' => null];
    }

    private function loadedTruck()
    {
        return DB::table('pro_po_ds_detail as a')
            ->join('pro_pr as b', 'a.id_pr', '=', 'b.id_pr')->join('pro_pr_detail as c', 'a.id_prd', '=', 'c.id_prd')->join('pro_po_ds as d', 'a.id_ds', '=', 'd.id_ds')
            ->join('pro_po as f', 'a.id_po', '=', 'f.id_po')->join('pro_po_detail as g', 'a.id_pod', '=', 'g.id_pod')->join('pro_master_terminal as h', 'c.pr_terminal', '=', 'h.id_master')
            ->join('pro_master_cabang as i', 'f.id_wilayah', '=', 'i.id_master')->join('pro_po_customer as j', 'a.id_poc', '=', 'j.id_poc')->join('pro_customer as k', 'j.id_customer', '=', 'k.id_customer')
            ->leftJoin('pro_master_transportir as l', 'f.id_transportir', '=', 'l.id_master')
            ->where('b.tanggal_pr', '>', '2024-01-01');
    }

    private function loadedKapal()
    {
        return DB::table('pro_po_ds_kapal as a')
            ->join('pro_pr as b', 'a.id_pr', '=', 'b.id_pr')->join('pro_pr_detail as c', 'a.id_prd', '=', 'c.id_prd')->join('pro_master_terminal as h', 'c.pr_terminal', '=', 'h.id_master')
            ->join('pro_master_cabang as i', 'a.id_wilayah', '=', 'i.id_master')->join('pro_po_customer as j', 'a.id_poc', '=', 'j.id_poc')->join('pro_customer as k', 'j.id_customer', '=', 'k.id_customer')
            ->where('b.tanggal_pr', '>', '2024-01-01');
    }

    /** Filter bersama: kata kunci, tanggal loaded (satu tanggal atau rentang), cabang. */
    private function filterLoaded($q, Request $r, string $kolomCabang, bool $truck)
    {
        if (($t = trim((string) $r->query('q', ''))) !== '') {
            $u = '%'.strtoupper($t).'%';
            $q->where(function ($w) use ($u, $truck) {
                $w->whereRaw('UPPER(b.nomor_pr) LIKE ?', [$u])->orWhereRaw('UPPER(c.nomor_lo_pr) LIKE ?', [$u])->orWhereRaw('UPPER(k.nama_customer) LIKE ?', [$u])
                    ->orWhereRaw('UPPER(h.nama_terminal) LIKE ?', [$u])->orWhereRaw('UPPER(h.tanki_terminal) LIKE ?', [$u]);
            });
        }
        $dari = $r->query('dari');
        $sampai = $r->query('sampai');
        if ($dari && ! $sampai) {
            $q->where('a.tanggal_loaded', $dari);
        } elseif ($dari && $sampai) {
            $q->whereBetween('a.tanggal_loaded', [$dari, $sampai]);
        }
        if ($r->filled('cabang')) {
            $q->where($kolomCabang, (int) $r->query('cabang'));
        }

        return $q;
    }

    private function barisLoaded(object $a, bool $truck): array
    {
        return [
            'id' => (int) ($truck ? $a->id_dsd : $a->id_dsk), 'cabang' => $a->nama_cabang, 'customer' => $a->nama_customer, 'nomor_dr' => $a->nomor_pr, 'nomor_lo' => $a->nomor_lo_pr,
            'transportir' => $truck ? $a->nama_suplier : null, 'spj' => $truck ? $a->no_spj : null, 'qty' => (float) $a->volume, 'tgl_dr' => $a->tanggal_pr, 'tgl_loaded' => $a->tanggal_loaded,
            'terminal' => $a->nama_terminal.'-'.$a->tanki_terminal,
        ] + self::statusLoaded($a);
    }

    private function listLoaded(Request $r, bool $truck): JsonResponse
    {
        $q = $this->filterLoaded($truck ? $this->loadedTruck() : $this->loadedKapal(), $r, $truck ? 'f.id_wilayah' : 'a.id_wilayah', $truck);
        $sel = ['a.*', 'b.nomor_pr', 'c.nomor_lo_pr', 'b.tanggal_pr', 'c.volume', 'h.nama_terminal', 'h.tanki_terminal', 'i.nama_cabang', 'k.nama_customer'];
        if ($truck) {
            $sel = array_merge($sel, ['g.no_spj', 'l.nama_suplier']);
        }
        $page = $q->orderByDesc('a.tanggal_loaded')->orderByDesc($truck ? 'a.id_dsd' : 'a.id_dsk')->select($sel)->paginate($this->perPage($r));
        $data = collect($page->items())->map(fn ($a) => $this->barisLoaded($a, $truck))->values();

        return response()->json([
            'data' => $data, 'total_halaman' => (float) $data->sum('qty'),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage()],
        ]);
    }

    public function loadedTruckList(Request $r): JsonResponse
    {
        return $this->listLoaded($r, true);
    }

    public function loadedKapalList(Request $r): JsonResponse
    {
        return $this->listLoaded($r, false);
    }

    private function unduh(Xlsx $x, string $nama): Response
    {
        return response($x->build(), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$nama.'"', 'Cache-Control' => 'private, no-store',
        ]);
    }

    private static function statusTeks(array $b): string
    {
        return match ($b['status']) {
            'Loading' => 'Loading Tgl Loading '.$b['tgl_loading'].' Jam Loading '.$b['jam_loading'],
            'Delivered' => 'Delivered Tgl Loading '.$b['tgl_loading'].' Jam Loading '.$b['jam_loading'],
            'Cancel' => 'CancelTgl Cancel '.$b['tgl_cancel'],
            default => $b['status'],
        };
    }

    public function loadedTruckExport(Request $r): Response
    {
        @set_time_limit(120);
        $q = $this->filterLoaded($this->loadedTruck(), $r, 'f.id_wilayah', true)
            ->leftJoin('pro_po_customer_plan as m', 'c.id_plan', '=', 'm.id_plan')->leftJoin('pro_customer_lcr as n', 'm.id_lcr', '=', 'n.id_lcr')
            ->leftJoin('pro_master_provinsi as o', 'n.prov_survey', '=', 'o.id_prov')->leftJoin('pro_master_kabupaten as p', 'n.kab_survey', '=', 'p.id_kab');
        if ($r->filled('terminal')) {
            $q->where('d.id_terminal', (int) $r->query('terminal'));
        }
        $rows = $q->orderByDesc('a.tanggal_loaded')->limit(50000)->get(['a.*', 'b.nomor_pr', 'c.nomor_lo_pr', 'b.tanggal_pr', 'c.volume', 'h.nama_terminal', 'h.tanki_terminal', 'i.nama_cabang', 'k.nama_customer',
            'c.nomor_po_supplier', 'g.no_spj', 'l.nama_suplier', DB::raw("CONCAT(n.alamat_survey, ' ', p.nama_kab, ' ', o.nama_prov) AS alamat")]);
        $x = new Xlsx('Rekap Loaded');
        $x->row([['Rekap Loaded', Xlsx::S_TITLE]]);
        $x->merge('A1:N1');
        $x->row([]);
        $x->row(array_map(fn ($h) => [$h, Xlsx::S_HEAD], ['No', 'Cabang', 'Customer', 'Alamat', 'Nomor DR', 'Nomor LO', 'Transportir', 'Spj', 'Nomor PO', 'QTY', 'Tanggal DR', 'Tanggal Loaded', 'Terminal', 'Status']));
        if ($rows->isEmpty()) {
            $x->row(['Data tidak ada']);
        }
        foreach ($rows as $i => $a) {
            $b = $this->barisLoaded($a, true);
            $x->row([$i + 1, $a->nama_cabang, $a->nama_customer, $a->alamat, $a->nomor_pr, $a->nomor_lo_pr, $a->nama_suplier, $a->no_spj, $a->nomor_po_supplier, number_format((float) $a->volume),
                $a->tanggal_pr ? date('d-m-Y', strtotime($a->tanggal_pr)) : '', $a->tanggal_loaded ? date('d-m-Y', strtotime($a->tanggal_loaded)) : '', $a->nama_terminal.' '.$a->tanki_terminal.' ', self::statusTeks($b)]);
        }

        return $this->unduh($x, 'Rekap-Loaded-'.date('dmYHis').'.xlsx');
    }

    public function loadedKapalExport(Request $r): Response
    {
        @set_time_limit(120);
        $rows = $this->filterLoaded($this->loadedKapal(), $r, 'a.id_wilayah', false)->orderByDesc('a.tanggal_loaded')->limit(50000)
            ->get(['a.*', 'b.nomor_pr', 'c.nomor_lo_pr', 'b.tanggal_pr', 'c.volume', 'h.nama_terminal', 'h.tanki_terminal', 'i.nama_cabang', 'k.nama_customer', 'c.nomor_po_supplier']);
        $x = new Xlsx('Rekap Loaded');
        $x->row([['Rekap Loaded Kapal', Xlsx::S_TITLE]]);
        $x->merge('A1:K1');
        $x->row([]);
        $x->row(array_map(fn ($h) => [$h, Xlsx::S_HEAD], ['No', 'Cabang', 'Customer', 'Nomor DR', 'Nomor LO', 'Nomor PO', 'QTY', 'Tanggal DR', 'Tanggal Loaded', 'Terminal', 'Status']));
        if ($rows->isEmpty()) {
            $x->row(['Data tidak ada']);
        }
        foreach ($rows as $i => $a) {
            $b = $this->barisLoaded($a, false);
            $x->row([$i + 1, $a->nama_cabang, $a->nama_customer, $a->nomor_pr, $a->nomor_lo_pr, $a->nomor_po_supplier, number_format((float) $a->volume),
                $a->tanggal_pr ? date('d-m-Y', strtotime($a->tanggal_pr)) : '', $a->tanggal_loaded ? date('d-m-Y', strtotime($a->tanggal_loaded)) : '', $a->nama_terminal.' '.$a->tanki_terminal.' ', self::statusTeks($b)]);
        }

        return $this->unduh($x, 'Rekap-Loaded-Kapal-'.date('dmYHis').'.xlsx');
    }

    // ------------------------------------------------------------------ Cancel Trip (pengiriman yang dibatalkan; verifikasi-request-pr.php, is_request = 3)

    private function cancelTrip(Request $r)
    {
        $q = DB::table('pro_po_ds_detail as a')
            ->join('pro_po_ds as b', 'a.id_ds', '=', 'b.id_ds')->join('pro_po_detail as c', 'a.id_pod', '=', 'c.id_pod')->join('pro_po as d', 'a.id_po', '=', 'd.id_po')
            ->join('pro_pr_detail as e', 'a.id_prd', '=', 'e.id_prd')->join('pro_pr as f', 'a.id_pr', '=', 'f.id_pr')
            ->join('pro_po_customer_plan as g', 'a.id_plan', '=', 'g.id_plan')->join('pro_po_customer as h', 'g.id_poc', '=', 'h.id_poc')
            ->join('pro_customer as i', 'h.id_customer', '=', 'i.id_customer')->join('pro_master_cabang as j', 'b.id_wilayah', '=', 'j.id_master')
            ->where('a.is_request', 3);
        if (($t = trim((string) $r->query('q', ''))) !== '') {
            $u = strtoupper($t).'%';
            $q->where(fn ($w) => $w->whereRaw('UPPER(i.nama_customer) LIKE ?', [$u])->orWhereRaw('UPPER(f.nomor_pr) LIKE ?', [$u])->orWhereRaw('UPPER(j.nama_cabang) LIKE ?', [$u]));
        }
        if ($r->filled('cabang')) {
            $q->where('b.id_wilayah', (int) $r->query('cabang'));
        }
        if ($r->filled('dari') && $r->filled('sampai')) {
            $q->whereBetween('f.tanggal_pr', [$r->query('dari'), $r->query('sampai')]);
        }

        return $q->orderByDesc('a.tanggal_request')->orderByDesc('a.id_dsd');
    }

    private const CANCEL_SELECT = ['a.id_dsd', 'a.nomor_do', 'a.request', 'a.tanggal_request', 'a.disposisi_request', 'a.is_revert', 'a.tanggal_approved', 'a.tanggal_revert',
        'f.nomor_pr', 'f.tanggal_pr', 'i.nama_customer', 'h.nomor_poc', 'e.volume', 'd.nomor_po', 'j.nama_cabang'];

    private function barisCancel(object $a): array
    {
        $dt = fn ($t) => $t ? date('d/m/Y H:i:s', strtotime($t)).' WIB' : null;
        if ((int) $a->disposisi_request === 1) {
            $status = 'Menunggu Verifikasi';
            $tglStatus = null;
        } elseif ((int) $a->disposisi_request === 2 && (int) $a->is_revert === 1) {
            $status = 'Terverifikasi';
            $tglStatus = $dt($a->tanggal_approved);
        } elseif ((int) $a->disposisi_request === 2 && (int) $a->is_revert === 2) {
            $status = 'Ditolak';
            $tglStatus = $dt($a->tanggal_revert);
        } else {
            $status = '';
            $tglStatus = null;
        }

        return [
            'id' => (int) $a->id_dsd, 'nomor_dr' => $a->nomor_pr, 'tgl_dr' => $a->tanggal_pr, 'customer' => $a->nama_customer, 'nomor_po' => $a->nomor_poc,
            'qty' => (float) $a->volume, 'nomor_dn' => $a->nomor_do, 'cabang' => $a->nama_cabang, 'alasan' => $a->request, 'tgl_request' => $a->tanggal_request,
            'status' => $status, 'tgl_status' => $tglStatus,
        ];
    }

    public function cancelTripList(Request $r): JsonResponse
    {
        $page = $this->cancelTrip($r)->select(self::CANCEL_SELECT)->paginate($this->perPage($r));
        $data = collect($page->items())->map(fn ($a) => $this->barisCancel($a))->values();

        return response()->json([
            'data' => $data, 'total_halaman' => (float) $data->sum('qty'),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage()],
        ]);
    }

    public function cancelTripExport(Request $r): Response
    {
        @set_time_limit(120);
        $rows = $this->cancelTrip($r)->limit(50000)->get(self::CANCEL_SELECT);
        $x = new Xlsx('Cancel Trip');
        $x->row([['Report Cancel Trip', Xlsx::S_TITLE]]);
        $x->merge('A1:K1');
        $x->row([]);
        $x->row(array_map(fn ($h) => [$h, Xlsx::S_HEAD], ['No', 'Kode DR', 'Tanggal DR', 'Customer', 'Nomor PO', 'Volume', 'Nomor DN', 'Cabang', 'Tanggal Request', 'Alasan', 'Status']));
        if ($rows->isEmpty()) {
            $x->row(['Data tidak ada']);
        }
        foreach ($rows as $i => $a) {
            $b = $this->barisCancel($a);
            $x->row([$i + 1, $b['nomor_dr'], $a->tanggal_pr ? date('d-m-Y', strtotime($a->tanggal_pr)) : '', $b['customer'], $b['nomor_po'], number_format($b['qty']), $b['nomor_dn'], $b['cabang'],
                $a->tanggal_request ? date('d-m-Y H:i', strtotime($a->tanggal_request)) : '', $b['alasan'], trim($b['status'].' '.$b['tgl_status'])]);
        }

        return $this->unduh($x, 'Cancel-Trip-'.date('dmYHis').'.xlsx');
    }

    // ------------------------------------------------------------------ History Pemotongan Stock Truck (depot jenis 6 dan 7)

    private function stokTruck()
    {
        return DB::table('new_pro_inventory_depot as a')
            ->leftJoin('pro_pr as b', 'a.id_pr', '=', 'b.id_pr')->leftJoin('pro_pr_detail as c', 'a.id_prd', '=', 'c.id_prd')->leftJoin('pro_master_terminal as e', 'a.id_terminal', '=', 'e.id_master')
            ->leftJoin('pro_master_cabang as f', 'b.id_wilayah', '=', 'f.id_master')->leftJoin('pro_po_customer_plan as g', 'c.id_plan', '=', 'g.id_plan')
            ->leftJoin('pro_po_customer as h', 'g.id_poc', '=', 'h.id_poc')->leftJoin('pro_customer as i', 'h.id_customer', '=', 'i.id_customer')
            ->where('a.tanggal_inven', '>', '2024-09-01')->whereIn('a.id_jenis', [6, 7]);
    }

    public function stokTruckList(Request $r): JsonResponse
    {
        $q = $this->stokTruck();
        if (($t = trim((string) $r->query('q', ''))) !== '') {
            $u = '%'.strtoupper($t).'%';
            $q->where(fn ($w) => $w->whereRaw('UPPER(b.nomor_pr) LIKE ?', [$u])->orWhereRaw('UPPER(c.nomor_lo_pr) LIKE ?', [$u])->orWhereRaw('UPPER(e.nama_terminal) LIKE ?', [$u])
                ->orWhereRaw('UPPER(e.tanki_terminal) LIKE ?', [$u])->orWhereRaw('UPPER(i.nama_customer) LIKE ?', [$u])->orWhereRaw('UPPER(c.nomor_po_supplier) LIKE ?', [$u]));
        }
        $dari = $r->query('dari');
        $sampai = $r->query('sampai');
        if ($dari && ! $sampai) {
            $q->where('a.tanggal_inven', $dari);
        } elseif ($dari && $sampai) {
            $q->whereBetween('a.tanggal_inven', [$dari, $sampai]);
        }
        if ($r->filled('cabang')) {
            $q->where('b.id_wilayah', (int) $r->query('cabang'));
        }
        if ($r->filled('terminal')) {
            $q->where('a.id_terminal', (int) $r->query('terminal'));
        }
        $page = $q->orderByDesc('a.tanggal_inven')->orderByDesc('a.id_master')
            ->select(['a.id_master', 'a.id_jenis', 'a.tanggal_inven', 'b.nomor_pr', 'c.nomor_lo_pr', 'c.nomor_po_supplier', 'b.tanggal_pr', 'c.volume', 'e.nama_terminal', 'e.tanki_terminal', 'f.nama_cabang', 'i.nama_customer'])
            ->paginate($this->perPage($r));
        $data = collect($page->items())->map(fn ($a) => [
            'id' => (int) $a->id_master, 'cabang' => $a->nama_cabang, 'customer' => $a->nama_customer, 'nomor_dr' => $a->nomor_pr, 'nomor_po' => $a->nomor_po_supplier, 'nomor_lo' => $a->nomor_lo_pr,
            'qty' => (float) $a->volume, 'tgl_dr' => $a->tanggal_pr, 'tgl_potong' => $a->tanggal_inven, 'jenis' => (int) $a->id_jenis === 6 ? 'Out Stock Virtual' : ((int) $a->id_jenis === 7 ? 'Loaded' : ''),
            'terminal' => $a->nama_terminal.'-'.$a->tanki_terminal, 'bisa_ubah' => (int) $a->id_jenis === 7,
        ])->values();

        return response()->json(['data' => $data, 'total_halaman' => (float) $data->sum('qty'), 'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage()]]);
    }

    /** Ubah tanggal potong (c-history-stock-action.php): depot, lalu tanggal loading di DS truck/kapal yang tertaut. Hanya baris jenis 7 (Loaded). */
    public function stokTruckUbahTanggal(Request $r, int $id): JsonResponse
    {
        $d = $r->validate(['tanggal' => ['required', 'date_format:Y-m-d']]);
        $row = DB::table('new_pro_inventory_depot')->where('id_master', $id)->whereIn('id_jenis', [6, 7])->first();
        if (! $row) {
            return response()->json(['message' => 'Data tidak ditemukan.'], 404);
        }
        if ((int) $row->id_jenis !== 7) {
            return response()->json(['message' => 'Tanggal potong hanya dapat diubah untuk jenis Loaded.', 'code' => 'BUKAN_LOADED'], 409);
        }
        DB::transaction(function () use ($row, $d, $id) {
            DB::table('new_pro_inventory_depot')->where('id_master', $id)->update(['tanggal_inven' => $d['tanggal']]);
            if ($row->id_dsd) {
                DB::table('pro_po_ds_detail')->where('id_dsd', $row->id_dsd)->update(['tanggal_loading' => $d['tanggal']]);
            }
            if ($row->id_dsk) {
                DB::table('pro_po_ds_kapal')->where('id_dsk', $row->id_dsk)->update(['tanggal_loading' => $d['tanggal']]);
            }
        });

        return response()->json(['message' => 'Data berhasil diedit!']);
    }

    // ------------------------------------------------------------------ History Pemotongan Stock Operasional (BPUJ dan pengisian mobil operasional)

    private const SQL_OPERASIONAL = <<<'SQLEOT'
WITH filtered_inventory_raw AS (
    SELECT *
    FROM new_pro_inventory_depot
    WHERE tanggal_inven > '2025-07-18'
      AND id_jenis IN (10, 11)
),

bpuj_inventory_one AS (
    SELECT fi.*
    FROM filtered_inventory_raw fi
    INNER JOIN (
        SELECT
            id_dsd,
            MAX(id_master) AS id_master_keep
        FROM filtered_inventory_raw
        WHERE id_jenis = 10
          AND id_dsd IS NOT NULL
        GROUP BY id_dsd
    ) selected
        ON selected.id_master_keep = fi.id_master
),

filtered_inventory AS (
    SELECT *
    FROM filtered_inventory_raw
    WHERE id_jenis <> 10

    UNION ALL

    SELECT *
    FROM bpuj_inventory_one
),

bpuj_po_list AS (
    SELECT
        fi.id_dsd,
        GROUP_CONCAT(
            DISTINCT po.nomor_po
            ORDER BY po.nomor_po
            SEPARATOR ', '
        ) AS nomor_po
    FROM filtered_inventory_raw fi
    LEFT JOIN new_pro_inventory_vendor_po po
        ON fi.id_po_supplier = po.id_master
    WHERE fi.id_jenis = 10
      AND fi.id_dsd IS NOT NULL
    GROUP BY fi.id_dsd
),

depot_ids AS (
    SELECT DISTINCT id_dsd
    FROM filtered_inventory
    WHERE id_dsd IS NOT NULL
      AND id_jenis = 10
),

bp_ranked AS (
    SELECT 
        aa.id_bpuj,
        aa.id_dsd,
        aa.cabang AS cabang_bpuj,
        aa.nomor_bpuj,
        aa.created_by,
        aa.created_at,
        aa.tanggal_bpuj,
        aa.no_unit,
        aa.nama_driver,
        aa.nama_customer,

        aa.pengisian_bbm,
        aa.dispenser,
        aa.tgl_pengisian,
        aa.liter_bbm,

        aa.pengisian_bbm_tambahan,
        aa.dispenser_tambahan,
        aa.tgl_pengisian_tambahan,
        aa.liter_bbm_tambahan,

        aa.pengisian_bbm_tambahan2,
        aa.dispenser_tambahan2,
        aa.tgl_pengisian_tambahan2,
        aa.liter_bbm_tambahan2,

        bb.id_bpuj AS id_bpuj_realisasi,
        bb.no_unit AS no_unit_realisasi_bpuj,
        bb.nama_driver AS nama_driver_realisasi_bpuj,
        bb.tanggal_realisasi,

        bb.pengisian_bbm AS pengisian_bbm_realisasi,
        bb.dispenser AS dispenser_realisasi,
        bb.tgl_pengisian AS tgl_pengisian_realisasi,
        bb.liter_bbm AS liter_bbm_realisasi,

        bb.pengisian_bbm_tambahan AS pengisian_bbm_tambah_realisasi,
        bb.dispenser_tambahan AS dispenser_tambahan_realisasi,
        bb.tgl_pengisian_tambahan AS tgl_pengisian_tambahan_realisasi,
        bb.liter_bbm_tambahan AS liter_bbm_tambahan_realisasi,

        bb.pengisian_bbm_tambahan2 AS pengisian_bbm_tambahan2_realisasi,
        bb.dispenser_tambahan2 AS dispenser_tambahan2_realisasi,
        bb.tgl_pengisian_tambahan2 AS tgl_pengisian_tambahan2_realisasi,
        bb.liter_bbm_tambahan2 AS liter_bbm_tambahan2_realisasi,

        ROW_NUMBER() OVER (
            PARTITION BY aa.id_dsd 
            ORDER BY aa.created_at DESC, aa.id_bpuj DESC
        ) AS rn
    FROM pro_bpuj aa
    INNER JOIN depot_ids di 
        ON aa.id_dsd = di.id_dsd
    LEFT JOIN pro_bpuj_realisasi bb 
        ON aa.id_bpuj = bb.id_bpuj
    WHERE aa.is_active = '1'
),

bp_latest AS (
    SELECT *
    FROM bp_ranked
    WHERE rn = 1
),

bp_sources AS (
    SELECT
        id_bpuj,
        id_dsd,
        cabang_bpuj,
        nomor_bpuj,
        created_by,
        tanggal_bpuj,
        nama_customer,
        no_unit,
        nama_driver,

        pengisian_bbm,
        dispenser,
        tgl_pengisian,
        liter_bbm,

        pengisian_bbm_tambahan,
        dispenser_tambahan,
        tgl_pengisian_tambahan,
        liter_bbm_tambahan,

        pengisian_bbm_tambahan2,
        dispenser_tambahan2,
        tgl_pengisian_tambahan2,
        liter_bbm_tambahan2,

        id_bpuj_realisasi,
        no_unit_realisasi_bpuj,
        nama_driver_realisasi_bpuj,
        tanggal_realisasi,

        pengisian_bbm_realisasi,
        dispenser_realisasi,
        tgl_pengisian_realisasi,
        liter_bbm_realisasi,

        pengisian_bbm_tambah_realisasi,
        dispenser_tambahan_realisasi,
        tgl_pengisian_tambahan_realisasi,
        liter_bbm_tambahan_realisasi,

        pengisian_bbm_tambahan2_realisasi,
        dispenser_tambahan2_realisasi,
        tgl_pengisian_tambahan2_realisasi,
        liter_bbm_tambahan2_realisasi,

        'UTAMA' AS tipe_pengisian,

        CASE 
            WHEN id_bpuj_realisasi IS NOT NULL THEN NULLIF(dispenser_realisasi, '0')
            ELSE NULLIF(dispenser, '0')
        END AS id_terminal_source,

        CASE 
            WHEN id_bpuj_realisasi IS NOT NULL THEN COALESCE(tgl_pengisian_realisasi, tanggal_realisasi)
            ELSE tgl_pengisian
        END AS tanggal_potong_source,

        CASE 
            WHEN id_bpuj_realisasi IS NOT NULL THEN NULLIF(liter_bbm_realisasi, 0)
            ELSE NULLIF(liter_bbm, 0)
        END AS qty_source
    FROM bp_latest

    UNION ALL

    SELECT
        id_bpuj,
        id_dsd,
        cabang_bpuj,
        nomor_bpuj,
        created_by,
        tanggal_bpuj,
        nama_customer,
        no_unit,
        nama_driver,

        pengisian_bbm,
        dispenser,
        tgl_pengisian,
        liter_bbm,

        pengisian_bbm_tambahan,
        dispenser_tambahan,
        tgl_pengisian_tambahan,
        liter_bbm_tambahan,

        pengisian_bbm_tambahan2,
        dispenser_tambahan2,
        tgl_pengisian_tambahan2,
        liter_bbm_tambahan2,

        id_bpuj_realisasi,
        no_unit_realisasi_bpuj,
        nama_driver_realisasi_bpuj,
        tanggal_realisasi,

        pengisian_bbm_realisasi,
        dispenser_realisasi,
        tgl_pengisian_realisasi,
        liter_bbm_realisasi,

        pengisian_bbm_tambah_realisasi,
        dispenser_tambahan_realisasi,
        tgl_pengisian_tambahan_realisasi,
        liter_bbm_tambahan_realisasi,

        pengisian_bbm_tambahan2_realisasi,
        dispenser_tambahan2_realisasi,
        tgl_pengisian_tambahan2_realisasi,
        liter_bbm_tambahan2_realisasi,

        'TAMBAHAN_1' AS tipe_pengisian,

        CASE 
            WHEN id_bpuj_realisasi IS NOT NULL THEN NULLIF(dispenser_tambahan_realisasi, '0')
            ELSE NULLIF(dispenser_tambahan, '0')
        END AS id_terminal_source,

        CASE 
            WHEN id_bpuj_realisasi IS NOT NULL THEN COALESCE(tgl_pengisian_tambahan_realisasi, tanggal_realisasi)
            ELSE tgl_pengisian_tambahan
        END AS tanggal_potong_source,

        CASE 
            WHEN id_bpuj_realisasi IS NOT NULL THEN NULLIF(liter_bbm_tambahan_realisasi, 0)
            ELSE NULLIF(liter_bbm_tambahan, 0)
        END AS qty_source
    FROM bp_latest

    UNION ALL

    SELECT
        id_bpuj,
        id_dsd,
        cabang_bpuj,
        nomor_bpuj,
        created_by,
        tanggal_bpuj,
        nama_customer,
        no_unit,
        nama_driver,

        pengisian_bbm,
        dispenser,
        tgl_pengisian,
        liter_bbm,

        pengisian_bbm_tambahan,
        dispenser_tambahan,
        tgl_pengisian_tambahan,
        liter_bbm_tambahan,

        pengisian_bbm_tambahan2,
        dispenser_tambahan2,
        tgl_pengisian_tambahan2,
        liter_bbm_tambahan2,

        id_bpuj_realisasi,
        no_unit_realisasi_bpuj,
        nama_driver_realisasi_bpuj,
        tanggal_realisasi,

        pengisian_bbm_realisasi,
        dispenser_realisasi,
        tgl_pengisian_realisasi,
        liter_bbm_realisasi,

        pengisian_bbm_tambah_realisasi,
        dispenser_tambahan_realisasi,
        tgl_pengisian_tambahan_realisasi,
        liter_bbm_tambahan_realisasi,

        pengisian_bbm_tambahan2_realisasi,
        dispenser_tambahan2_realisasi,
        tgl_pengisian_tambahan2_realisasi,
        liter_bbm_tambahan2_realisasi,

        'TAMBAHAN_2' AS tipe_pengisian,

        CASE 
            WHEN id_bpuj_realisasi IS NOT NULL THEN NULLIF(dispenser_tambahan2_realisasi, '0')
            ELSE NULLIF(dispenser_tambahan2, '0')
        END AS id_terminal_source,

        CASE 
            WHEN id_bpuj_realisasi IS NOT NULL THEN COALESCE(tgl_pengisian_tambahan2_realisasi, tanggal_realisasi)
            ELSE tgl_pengisian_tambahan2
        END AS tanggal_potong_source,

        CASE 
            WHEN id_bpuj_realisasi IS NOT NULL THEN NULLIF(liter_bbm_tambahan2_realisasi, 0)
            ELSE NULLIF(liter_bbm_tambahan2, 0)
        END AS qty_source
    FROM bp_latest
)

SELECT 
    a.*,
    b.nomor_po,

    CASE 
        WHEN a.id_jenis = 10 THEN e_bpuj.nama_cabang
        ELSE e.nama_cabang
    END AS nama_cabang,

    CASE 
        WHEN a.id_jenis = 10 THEN bp.cabang_bpuj
        ELSE c.id_wilayah
    END AS id_cabang_list,

    CASE 
        WHEN a.id_jenis = 10 THEN bp.nomor_bpuj
        ELSE c.nomor
    END AS nomor_list,

    CASE 
        WHEN a.id_jenis = 10 THEN d_bpuj.nama_terminal
        ELSE d.nama_terminal
    END AS terminal_list,

    CASE 
        WHEN a.id_jenis = 10 THEN d_bpuj.tanki_terminal
        ELSE d.tanki_terminal
    END AS tanki_terminal_list,

    CASE 
        WHEN a.id_jenis = 10 THEN bp.qty_source
        ELSE 
            CASE 
                WHEN COALESCE(a.out_inven, 0) > 0 THEN a.out_inven
                WHEN COALESCE(a.out_inven_virtual, 0) > 0 THEN a.out_inven_virtual
                ELSE 0
            END
    END AS qty_list,

    CASE 
        WHEN a.id_jenis = 10 THEN bp.tanggal_potong_source
        ELSE a.tanggal_inven
    END AS tanggal_potong_list,

    CASE 
        WHEN a.id_jenis = 10 THEN bp.nama_customer
        ELSE c.tujuan
    END AS tujuan_list,

    CASE 
        WHEN a.id_jenis = 10 THEN 'Pengurangan Stock dari BPUJ'
        ELSE c.keterangan
    END AS keterangan_list,

    CASE 
        WHEN a.id_jenis = 10 THEN bp.created_by
        ELSE c.admin_pic
    END AS admin_pic_list,

    CASE 
        WHEN a.id_jenis = 10 THEN bp.created_by
        ELSE COALESCE(c.createdby, a.created_by)
    END AS created_by_list,

    bp.id_bpuj,
    bp.nomor_bpuj,
    bp.tanggal_bpuj,
    bp.nama_customer AS customer_bpuj,
    bp.no_unit AS plat_mobil_bpuj,
    bp.nama_driver AS driver_bpuj,

    bp.pengisian_bbm AS detail_pengisian_bbm,
    bp.dispenser AS detail_dispenser,
    bp.tgl_pengisian AS detail_tgl_pengisian,
    bp.liter_bbm AS detail_liter_bbm,

    bp.pengisian_bbm_tambahan AS detail_pengisian_bbm_tambah,
    bp.dispenser_tambahan AS detail_dispenser_tambahan,
    bp.tgl_pengisian_tambahan AS detail_tgl_pengisian_tambahan,
    bp.liter_bbm_tambahan AS detail_liter_bbm_tambahan,

    bp.pengisian_bbm_tambahan2 AS detail_pengisian_bbm_tambahan2,
    bp.dispenser_tambahan2 AS detail_dispenser_tambahan2,
    bp.tgl_pengisian_tambahan2 AS detail_tgl_pengisian_tambahan2,
    bp.liter_bbm_tambahan2 AS detail_liter_bbm_tambahan2,

    bp.id_bpuj_realisasi,
    bp.no_unit_realisasi_bpuj,
    bp.nama_driver_realisasi_bpuj,
    bp.tanggal_realisasi,

    bp.pengisian_bbm_realisasi AS detail_pengisian_bbm_realisasi,
    bp.dispenser_realisasi AS detail_dispenser_realisasi,
    bp.tgl_pengisian_realisasi AS detail_tgl_pengisian_realisasi,
    bp.liter_bbm_realisasi AS detail_liter_bbm_realisasi,

    bp.pengisian_bbm_tambah_realisasi AS detail_pengisian_bbm_tambah_realisasi,
    bp.dispenser_tambahan_realisasi AS detail_dispenser_tambahan_realisasi,
    bp.tgl_pengisian_tambahan_realisasi AS detail_tgl_pengisian_tambahan_realisasi,
    bp.liter_bbm_tambahan_realisasi AS detail_liter_bbm_tambahan_realisasi,

    bp.pengisian_bbm_tambahan2_realisasi AS detail_pengisian_bbm_tambahan2_realisasi,
    bp.dispenser_tambahan2_realisasi AS detail_dispenser_tambahan2_realisasi,
    bp.tgl_pengisian_tambahan2_realisasi AS detail_tgl_pengisian_tambahan2_realisasi,
    bp.liter_bbm_tambahan2_realisasi AS detail_liter_bbm_tambahan2_realisasi,

    bp.tipe_pengisian,
    bp.id_terminal_source,
    bp.tanggal_potong_source,
    bp.qty_source AS qty_source_bpuj,

    c.nomor AS nomor_voucher,
    bp.created_by AS created_by_bpuj,
    c.tujuan,
    c.keterangan AS ket_pengisian,
    c.keterangan_realisasi AS keterangan_realisasi_pengisian,
    c.admin_pic,
    c.volume AS volume_mobil_opr,
    c1.nama_mobil AS nama_mobil_opr,
    c1.plat_mobil AS plat_mobil_opr,
    c.date_admin_realisasi AS date_realisasi_pengisian,
    c.volume_realisasi AS volume_realisasi_mobil_opr,
    c.driver AS driver_mobil_opr,
    c.driver_realisasi AS driver_realisasi_mobil_opr

FROM filtered_inventory a

LEFT JOIN new_pro_inventory_vendor_po b 
    ON a.id_po_supplier = b.id_master

LEFT JOIN pro_pengisian_solar_mobil_opr c 
    ON a.id_pengisian_solar = c.id

LEFT JOIN pro_master_mobil c1 
    ON c.id_mobil = c1.id_mobil

LEFT JOIN pro_master_terminal d 
    ON a.id_terminal = d.id_master

LEFT JOIN pro_master_cabang e 
    ON c.id_wilayah = e.id_master

LEFT JOIN bp_sources bp 
    ON a.id_jenis = 10
   AND a.id_dsd = bp.id_dsd

LEFT JOIN pro_master_cabang e_bpuj
    ON e_bpuj.id_master = CAST(bp.cabang_bpuj AS UNSIGNED)

LEFT JOIN pro_master_terminal d_bpuj
    ON d_bpuj.id_master = CAST(bp.id_terminal_source AS UNSIGNED)

WHERE 
    a.id_jenis IN (10, 11)

    AND (
        a.id_jenis <> 10
        OR (
            bp.id_terminal_source IS NOT NULL
            AND bp.id_terminal_source <> '0'
            AND bp.qty_source IS NOT NULL
            AND bp.qty_source > 0
        )
    )

    AND (
        a.id_jenis = 10
        OR (
            c.id IS NOT NULL
            AND COALESCE(c.disposisi, 0) <> 2
            AND COALESCE(c.is_admin, 0) <> 2
            AND COALESCE(c.is_admin_realisasi, 0) <> 2
        )
    )
SQLEOT;

    private static function angka($v): string
    {
        $n = (float) $v;

        return fmod($n, 1) != 0.0 ? number_format($n, 4, '.', ',') : number_format($n, 0);
    }

    private static function sumber($sumber, $dispenser): string
    {
        $sumber = trim((string) $sumber);
        $dispenser = trim((string) $dispenser);
        if ($sumber !== '') {
            return str_replace('||', ' - ', $sumber);
        }

        return $dispenser !== '' && $dispenser !== '0' ? 'Dispenser '.$dispenser : 'SPBU / Non Dispenser';
    }

    private static function pengisian(array &$rows, string $tipe, string $sumber, $tanggal, $driver, $mobil, $volume): void
    {
        if ((float) $volume <= 0) {
            return;
        }
        $rows[] = ['tipe' => $tipe, 'sumber' => $sumber, 'tanggal' => $tanggal, 'driver' => $driver, 'mobil' => $mobil, 'volume' => self::angka($volume)];
    }

    /** Syarat dan filter dari c-history-stock-data-operational.php, dengan parameter terikat. */
    private function kondisiOperasional(Request $r): array
    {
        $sql = '';
        $b = [];
        if (($t = trim((string) $r->query('q', ''))) !== '') {
            $k = '%'.strtoupper($t).'%';
            $kolom = ['b.nomor_po', 'c.nomor', 'bp.nomor_bpuj', 'd.nama_terminal', 'd_bpuj.nama_terminal', 'c.tujuan', 'c.keterangan', 'bp.nama_customer', 'bp.no_unit', 'bp.nama_driver', 'a.created_by',
                'c.createdby', 'bp.created_by', 'e.nama_cabang', 'e_bpuj.nama_cabang', 'bp.cabang_bpuj'];
            $sql .= ' AND ('.implode(' OR ', array_map(fn ($c) => "UPPER(COALESCE($c, '')) LIKE ?", $kolom)).')';
            $b = array_merge($b, array_fill(0, count($kolom), $k));
        }
        $dari = $r->query('dari');
        $sampai = $r->query('sampai');
        if ($dari && ! $sampai) {
            $sql .= ' AND ((a.id_jenis = 10 AND bp.tanggal_potong_source = ?) OR (a.id_jenis <> 10 AND a.tanggal_inven = ?))';
            array_push($b, $dari, $dari);
        } elseif ($dari && $sampai) {
            $sql .= ' AND ((a.id_jenis = 10 AND bp.tanggal_potong_source BETWEEN ? AND ?) OR (a.id_jenis <> 10 AND a.tanggal_inven BETWEEN ? AND ?))';
            array_push($b, $dari, $sampai, $dari, $sampai);
        }
        if ($r->filled('cabang')) {
            $sql .= ' AND ((a.id_jenis = 10 AND bp.cabang_bpuj = ?) OR (a.id_jenis <> 10 AND c.id_wilayah = ?))';
            array_push($b, (string) $r->query('cabang'), (string) $r->query('cabang'));
        }
        if ($r->filled('terminal')) {
            $sql .= ' AND ((a.id_jenis = 10 AND bp.id_terminal_source = ?) OR (a.id_jenis <> 10 AND a.id_terminal = ?))';
            array_push($b, (string) $r->query('terminal'), (string) $r->query('terminal'));
        }

        return [$sql, $b];
    }

    private function barisOperasional(array $d): array
    {
        $bpuj = (int) $d['id_jenis'] === 10;
        $volume = (float) ($d['qty_list'] ?? 0);
        $terminal = (string) ($d['terminal_list'] ?? '');
        if (! $bpuj && ($d['tanki_terminal_list'] ?? '') !== '' && $d['tanki_terminal_list'] !== null) {
            $terminal .= '-'.$d['tanki_terminal_list'];
        }
        $tgl = ($d['tanggal_potong_list'] ?? null) && $d['tanggal_potong_list'] !== '0000-00-00' ? $d['tanggal_potong_list'] : null;
        $out = [
            'tipe' => $bpuj ? 'bpuj' : 'operasional', 'nomor' => $d['nomor_list'] ?? '', 'terminal' => $terminal, 'nomor_po' => $d['nomor_po'] ?? '', 'tujuan' => $d['tujuan_list'] ?? '',
            'keterangan' => $d['keterangan_list'] ?? '', 'qty' => $volume, 'tgl_potong' => $tgl, 'admin_pic' => $d['admin_pic_list'] ?? '', 'created_by' => $d['created_by_list'] ?? '',
        ];
        if (! $bpuj) {
            $out['detail'] = [
                'nomor_voucher' => $d['nomor_voucher'] ?? '', 'keterangan' => $d['ket_pengisian'] ?? '', 'keterangan_realisasi' => $d['keterangan_realisasi_pengisian'] ?? '', 'volume' => self::angka($d['volume_mobil_opr'] ?? 0),
                'volume_realisasi' => self::angka($d['volume_realisasi_mobil_opr'] ?? 0), 'mobil' => trim(($d['nama_mobil_opr'] ?? '').' '.($d['plat_mobil_opr'] ?? '')), 'tanggal_realisasi' => $d['date_realisasi_pengisian'] ?? '',
                'driver' => $d['driver_mobil_opr'] ?? '', 'driver_realisasi' => $d['driver_realisasi_mobil_opr'] ?? '',
            ];

            return $out;
        }
        $v = fn (string $k) => $d[$k] ?? '';
        $aju = [];
        $real = [];
        self::pengisian($aju, 'Utama', self::sumber($v('detail_pengisian_bbm'), $v('detail_dispenser')), $v('detail_tgl_pengisian'), $v('driver_bpuj'), $v('plat_mobil_bpuj'), $v('detail_liter_bbm'));
        self::pengisian($aju, 'Tambahan 1', self::sumber($v('detail_pengisian_bbm_tambah'), $v('detail_dispenser_tambahan')), $v('detail_tgl_pengisian_tambahan'), $v('driver_bpuj'), $v('plat_mobil_bpuj'), $v('detail_liter_bbm_tambahan'));
        self::pengisian($aju, 'Tambahan 2', self::sumber($v('detail_pengisian_bbm_tambahan2'), $v('detail_dispenser_tambahan2')), $v('detail_tgl_pengisian_tambahan2'), $v('driver_bpuj'), $v('plat_mobil_bpuj'), $v('detail_liter_bbm_tambahan2'));
        self::pengisian($real, 'Utama', self::sumber($v('detail_pengisian_bbm_realisasi'), $v('detail_dispenser_realisasi')), $v('detail_tgl_pengisian_realisasi'), $v('nama_driver_realisasi_bpuj'), $v('no_unit_realisasi_bpuj'), $v('detail_liter_bbm_realisasi'));
        self::pengisian($real, 'Tambahan 1', self::sumber($v('detail_pengisian_bbm_tambah_realisasi'), $v('detail_dispenser_tambahan_realisasi')), $v('detail_tgl_pengisian_tambahan_realisasi'), $v('nama_driver_realisasi_bpuj'), $v('no_unit_realisasi_bpuj'), $v('detail_liter_bbm_tambahan_realisasi'));
        self::pengisian($real, 'Tambahan 2', self::sumber($v('detail_pengisian_bbm_tambahan2_realisasi'), $v('detail_dispenser_tambahan2_realisasi')), $v('detail_tgl_pengisian_tambahan2_realisasi'), $v('nama_driver_realisasi_bpuj'), $v('no_unit_realisasi_bpuj'), $v('detail_liter_bbm_tambahan2_realisasi'));
        $out['detail'] = ['nomor_bpuj' => $v('nomor_bpuj'), 'customer' => $v('customer_bpuj'), 'tanggal_bpuj' => $v('tanggal_bpuj'), 'tanggal_realisasi' => $v('tanggal_realisasi'), 'pengajuan' => $aju, 'realisasi' => $real];

        return $out;
    }

    /**
     * Kueri CTE ini berat (puluhan ribu baris depot + BPUJ). Web menjalankannya dua kali per halaman (hitung dan ambil); di sini dijalankan sekali
     * per kombinasi filter, hasilnya disimpan 2 menit supaya berpindah halaman tidak mengulang kueri.
     */
    public function stokOperasionalList(Request $r): JsonResponse
    {
        @set_time_limit(180);
        [$kondisi, $bind] = $this->kondisiOperasional($r);
        $semua = \Illuminate\Support\Facades\Cache::remember('rpt_stok_opr:'.md5($kondisi.json_encode($bind)), 120, function () use ($kondisi, $bind) {
            $rows = DB::select(self::SQL_OPERASIONAL.$kondisi.' ORDER BY tanggal_potong_list DESC, nomor_list DESC', $bind);

            return array_map(fn ($x) => $this->barisOperasional((array) $x), $rows);
        });
        $per = $this->perPage($r);
        $page = max(1, (int) $r->query('page', 1));
        $total = count($semua);
        $data = collect(array_slice($semua, ($page - 1) * $per, $per))->values();

        return response()->json([
            'data' => $data, 'total_halaman' => (float) $data->sum('qty'),
            'meta' => ['page' => $page, 'per_page' => $per, 'total' => $total, 'last' => max(1, (int) ceil($total / $per))],
        ]);
    }
}
