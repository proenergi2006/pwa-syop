<?php

namespace App\Http\Controllers;

use App\Services\Accurate\AccurateDrSync;
use App\Services\Accurate\AccurateException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Delivery Request (pro_pr + pro_pr_detail) untuk Purchasing (peran 5).
 * Sumber: purchase-request.php, purchase-request (1).php (daftar), purchase-request-detail.php, __get_data_pr_purchasing.php (detail),
 * purchase-request (2).php (aksi peran 5), __get_po_by_terminal.php, __cek_pr_customer_purchasing.php.
 * disposisi_pr: 1 Admin Finance, 2 Verifikasi BM, 3 Verifikasi Purchasing, 4 COO, 5 CEO, 6 Terverifikasi, 7 Purchase Order, 8 Cancel.
 * Simpan Purchasing mengikuti web (LO/DO, harga beli, stok virtual, split, disposisi 6) KECUALI pembuatan Sales Order/Delivery Order di Accurate,
 * email pemberitahuan, permintaan ubah OA, kembalikan ke DP, dan batal split (file pendukungnya tidak ada / Accurate belum dipindahkan).
 */
class DrController extends Controller
{
    private const LEGACY = '2023-09-01';

    private function rowsPerm($q)
    {
        return $q->where('a.disposisi_pr', '>', 2); // peran 5: disposisi_pr > 2
    }

    public function waitingCount(): int
    {
        return (int) DB::table('pro_pr')->where('disposisi_pr', 3)->where(fn ($w) => $w->whereNull('purchasing_result')->orWhere('purchasing_result', 0))->count();
    }

    private static function statusLabel($r): string
    {
        $d = (int) $r->disposisi_pr;
        if ($d === 1) {
            return $r->ada_ar ? 'Pending Due AR' : 'Admin Finance';
        }

        return [2 => 'Verifikasi BM', 3 => 'Verifikasi Purchasing', 4 => 'Verifikasi COO', 5 => 'Verifikasi CEO', 7 => 'Purchase Order', 8 => 'Cancel'][$d]
            ?? ($d === 6 ? ($r->tanggal_pr < self::LEGACY ? 'Closed' : 'Terverifikasi') : '');
    }

    public function index(Request $r): JsonResponse
    {
        $q = $this->rowsPerm(DB::table('pro_pr as a')->join('pro_master_cabang as b', 'a.id_wilayah', '=', 'b.id_master'));

        if (($term = trim((string) $r->query('q', ''))) !== '') {
            $q->where(function ($w) use ($term) {
                $like = "%{$term}%";
                $w->where('a.nomor_pr', 'like', $like)
                    ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('pro_pr_detail as c')->whereColumn('c.id_pr', 'a.id_pr')->where('c.nomor_lo_pr', 'like', $like))
                    ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('pro_pr_detail as c')
                        ->join('pro_po_customer_plan as d', 'd.id_plan', '=', 'c.id_plan')->join('pro_po_customer as e', 'e.id_poc', '=', 'd.id_poc')
                        ->join('pro_customer as f', 'f.id_customer', '=', 'e.id_customer')->whereColumn('c.id_pr', 'a.id_pr')->where('f.nama_customer', 'like', $like));
            });
        }
        if ($r->filled('cabang')) {
            $q->where('a.id_wilayah', (int) $r->query('cabang'));
        }
        $st = (string) $r->query('status', '');
        if ($st === '1') {
            $q->where('a.disposisi_pr', 1)->where('a.ada_ar', 0);
        } elseif ($st === '1.5') {
            $q->where('a.disposisi_pr', 1)->where('a.ada_ar', 1);
        } elseif ($st === '4') {
            $q->where('a.disposisi_pr', 4)->where('a.is_ceo', 0);
        } elseif ($st === '4.5') {
            $q->where('a.disposisi_pr', 5)->where('a.is_ceo', 1);
        } elseif ($st === '8') {
            $q->where('a.tanggal_pr', '<', self::LEGACY)->where('a.disposisi_pr', 6);
        } elseif ($st !== '' && ctype_digit($st)) {
            $q->where('a.disposisi_pr', (int) $st);
        }
        $from = $r->query('from');
        $to = $r->query('to');
        if ($from && $to) {
            $q->whereBetween('a.tanggal_pr', [$from, $to]);
        } elseif ($from || $to) {
            $q->where('a.tanggal_pr', $from ?: $to);
        }

        $per = min(max((int) $r->query('per_page', 20), 1), (int) config('orca.per_page_max'));
        $page = $q->orderByRaw("
            CASE WHEN dps = 6 AND a.tanggal_pr >= '".self::LEGACY."' THEN dps END DESC,
            CASE WHEN dps < 6 AND a.tanggal_pr >= '".self::LEGACY."' THEN a.tanggal_pr END DESC,
            CASE WHEN a.tanggal_pr < '".self::LEGACY."' THEN a.tanggal_pr END DESC,
            dps DESC, a.tanggal_pr DESC, a.id_pr DESC")
            ->select('a.id_pr', 'a.nomor_pr', 'a.tanggal_pr', 'a.disposisi_pr', 'a.ada_ar', 'a.purchasing_result', 'b.nama_cabang', DB::raw('IF(a.disposisi_pr = 3, 1, 0) as dps'))
            ->paginate($per);

        $ids = collect($page->items())->pluck('id_pr');
        $det = $ids->isEmpty() ? collect() : DB::table('pro_pr_detail as a')
            ->join('pro_po_customer_plan as b', 'a.id_plan', '=', 'b.id_plan')->join('pro_po_customer as c', 'b.id_poc', '=', 'c.id_poc')
            ->leftJoin('pro_customer as f', 'f.id_customer', '=', 'c.id_customer')
            ->whereIn('a.id_pr', $ids)->orderBy('a.id_prd')
            ->get(['a.id_pr', 'a.volume', 'b.tanggal_kirim', 'b.tanggal_loading', 'c.nomor_poc', 'f.nama_customer'])->groupBy('id_pr');

        $data = collect($page->items())->map(function ($p) use ($det) {
            $d = $det[$p->id_pr] ?? collect();

            return [
                'id' => (int) $p->id_pr, 'nomor_pr' => $p->nomor_pr, 'tanggal_pr' => $p->tanggal_pr, 'cabang' => $p->nama_cabang,
                'customer' => $d->pluck('nama_customer')->filter()->unique()->values(),
                'nomor_po' => $d->pluck('nomor_poc')->values(), 'volume' => $d->pluck('volume')->map(fn ($v) => (int) $v)->values(),
                'tgl_loading' => $d->pluck('tanggal_loading')->values(), 'tgl_kirim' => $d->pluck('tanggal_kirim')->values(),
                'status' => self::statusLabel($p), 'disposisi' => (int) $p->disposisi_pr,
                'menunggu' => (int) $p->disposisi_pr === 3 && ! (int) $p->purchasing_result, // baris diwarnai untuk Purchasing
            ];
        })->all();

        return response()->json([
            'data' => $data, 'waiting' => $this->waitingCount(),
            'cabang' => DB::table('pro_master_cabang')->where('is_active', 1)->where('id_master', '<>', 1)->orderBy('nama_cabang')->get(['id_master as id', 'nama_cabang as nama']),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage()],
        ]);
    }

    private function header(int $id)
    {
        return $this->rowsPerm(DB::table('pro_pr as a')->join('pro_master_cabang as b', 'a.id_wilayah', '=', 'b.id_master'))
            ->where('a.id_pr', $id)->first(['a.*', 'b.nama_cabang', 'b.inisial_cabang']);
    }

    private static function plain(?string $s): ?string
    {
        return $s === null ? null : html_entity_decode(str_replace(['<br />', '<br>'], "\n", $s), ENT_QUOTES);
    }

    /** Baris DR yang disetujui (a.is_approved = 1), query sama dengan __get_data_pr_purchasing.php. */
    private function lines(int $id): array
    {
        return DB::select("
            select a.*,
                b.sm_result, b.purchasing_result, b.revert_cfo, b.revert_ceo,
                c.tanggal_kirim, c.status_plan, c.catatan_reschedule, c.status_jadwal, c.no_so, c.catatan_log, c.tanggal_loading as tgl_etl_plan, c.top_plan,
                e.alamat_survey, f.nama_prov, g.nama_kab, n.nilai_pbbkb,
                k.id_penawaran, k.masa_awal, k.masa_akhir, k.id_area, k.refund_tawar, k.other_cost, k.detail_rincian, k.harga_dasar, k.gabung_oa, k.pembulatan,
                o1.harga_normal, o2.harga_normal as harga_normal_new,
                h.nama_customer, h.id_customer, h.kode_pelanggan, h.jenis_payment, h.top_payment, h.credit_limit,
                i.fullname, l.nama_area, d.harga_poc, m.jenis_produk, m.merk_dagang, m.id_master as id_produk, e.jenis_usaha,
                d.nomor_poc, d.id_poc, p.nama_terminal, p.tanki_terminal, p.lokasi_terminal, r.wilayah_angkut,
                s.is_loaded, s.is_delivered, s.is_cancel, s.tanggal_loaded, s.jam_loaded, s.tanggal_cancel,
                t.volume as volume_potong, t.nomor_po_supplier as nomor_potong, t.pr_harga_beli as harga_potong,
                pt.nama_terminal as terminal_potong, pt.tanki_terminal as tanki_potong, pt.lokasi_terminal as lokasi_potong
            from pro_pr_detail a
                join pro_pr b on a.id_pr = b.id_pr
                join pro_po_customer_plan c on a.id_plan = c.id_plan
                join pro_po_customer d on c.id_poc = d.id_poc
                join pro_customer_lcr e on c.id_lcr = e.id_lcr
                join pro_master_provinsi f on e.prov_survey = f.id_prov
                join pro_master_kabupaten g on e.kab_survey = g.id_kab
                join pro_customer h on d.id_customer = h.id_customer
                join acl_user i on h.id_marketing = i.id_user
                join pro_master_cabang j on h.id_wilayah = j.id_master
                join pro_penawaran k on d.id_penawaran = k.id_penawaran
                join pro_master_area l on k.id_area = l.id_master
                join pro_master_produk m on d.produk_poc = m.id_master
                join pro_master_pbbkb n on k.pbbkb_tawar = n.id_master
                left join pro_master_harga_minyak o1 on k.masa_awal = o1.periode_awal and k.masa_akhir = o1.periode_akhir and k.id_area = o1.id_area and k.produk_tawar = o1.produk
                    and k.pbbkb_tawar = o1.pajak and o1.is_approved = 1
                left join pro_master_harga_minyak o2 on k.masa_awal = o2.periode_awal and k.masa_akhir = o2.periode_akhir and k.id_area = o2.id_area and k.produk_tawar = o2.produk
                    and o2.pajak = 1 and o2.is_approved = 1
                left join pro_master_terminal p on a.pr_terminal = p.id_master
                left join pro_master_wilayah_angkut r on e.id_wil_oa = r.id_master
                left join pro_po_ds_detail s on a.id_prd = s.id_prd
                left join new_pro_inventory_potongan_stock t on a.id_prd = t.id_prd
                left join pro_master_terminal pt on pt.id_master = t.pr_terminal
            where a.id_pr = ? and a.is_approved = 1
            order by a.is_approved desc, c.tanggal_kirim, k.id_cabang, k.id_area, a.id_plan, a.id_prd", [$id]);
    }

    private function line($x, bool $verified): array
    {
        $tempal = strtolower(str_replace(['KABUPATEN ', 'KOTA '], '', (string) $x->nama_kab));
        $alamat = trim($x->alamat_survey.' '.ucwords($tempal).' '.$x->nama_prov);
        $hargaNormal = $x->harga_normal ?: $x->harga_normal_new;
        $tmp = (float) $x->refund_tawar + (float) $x->other_cost;
        $rincian = json_decode((string) $x->detail_rincian, true) ?: [];
        $hdNew = 0.0;
        $rows = [];
        foreach ($rincian as $i => $r) {
            $biaya = round((float) ($r['biaya'] ?? 0), 2); // web: number_format(biaya, 2)
            if ($i === 0) {
                $hdNew = $biaya;
            }
            $rows[] = ['nama' => $r['rincian'] ?? '', 'nilai' => $r['nilai'] ?? null, 'biaya' => $biaya];
        }
        $volume = (int) $x->volume;
        $volPotongan = (int) $x->vol_potongan;
        $hargaBeli = (int) $x->pr_harga_beli;
        $nett = $hdNew - $tmp;
        $netprt = ($nett - $hargaBeli) * ($volPotongan === 0 ? $volume : $volPotongan);
        $netprt1 = ((float) $x->harga_potong > 0) ? ($nett - (float) $x->harga_potong) * (float) $x->volume_potong : 0;
        $term = fn ($n, $tk, $lk) => $n ? trim($n.($tk ? ' - '.$tk : '').($lk ? ', '.$lk : '')) : '';

        $jp = $x->jenis_payment;
        $termPay = ['CREDIT' => 'NET '.$x->top_payment, 'COD' => 'COD', 'CDB' => 'CBD'][$jp] ?? '';

        if ((int) $x->is_cancel === 1) {
            $status = ['kode' => 'cancel', 'tanggal' => $x->tanggal_cancel];
        } elseif ((int) $x->is_loaded === 1 && (int) $x->is_delivered === 1) {
            $status = ['kode' => 'delivered', 'tanggal' => $x->tanggal_loaded, 'jam' => $x->jam_loaded];
        } elseif ((int) $x->is_loaded === 1) {
            $status = ['kode' => 'loading', 'tanggal' => $x->tanggal_loaded, 'jam' => $x->jam_loaded];
        } else {
            $status = ['kode' => 'belum'];
        }

        return [
            'id_prd' => (int) $x->id_prd, 'id_plan' => (int) $x->id_plan, 'id_poc' => (int) $x->id_poc, 'id_produk' => (int) $x->id_produk,
            'id_customer' => (int) $x->id_customer, 'id_penawaran' => (int) $x->id_penawaran, 'splitted_from_pr' => $x->splitted_from_pr ? (int) $x->splitted_from_pr : null,
            'customer' => ['kode' => $x->kode_pelanggan, 'nama' => $x->nama_customer, 'usaha' => $x->jenis_usaha, 'marketing' => $x->fullname],
            'area' => ['nama' => $x->nama_area, 'alamat' => $alamat, 'wilayah_oa' => $x->wilayah_angkut],
            'po_customer' => ['nomor' => $x->nomor_poc, 'merk' => $x->merk_dagang, 'tgl_kirim' => $x->tanggal_kirim],
            'volume_awal' => (int) ($verified ? $x->vol_ori_pr : $x->volume),
            'volume' => $volume, 'vol_ori' => (int) ($x->vol_ori_pr ?: $x->volume),
            'vol_potongan' => $volPotongan, 'volume_potong' => $x->volume_potong === null ? null : (int) $x->volume_potong,
            'po_supplier' => $x->nomor_po_supplier ?: null, 'po_potong' => $x->nomor_potong ?: null,
            'depot' => $term($x->nama_terminal, $x->tanki_terminal, $x->lokasi_terminal), 'depot_potong' => $term($x->terminal_potong, $x->tanki_potong, $x->lokasi_potong),
            'harga_beli' => $hargaBeli ?: null, 'harga_potong' => $x->harga_potong ? (int) $x->harga_potong : null,
            'harga_dasar' => (float) $x->harga_dasar, 'pembulatan' => (int) $x->pembulatan, 'gabung_oa' => (bool) $x->gabung_oa,
            'rincian' => $rows, 'harga_dasar_nett' => $hdNew, 'refund' => (float) $x->refund_tawar, 'other_cost' => (float) $x->other_cost,
            'nett_profit' => round($netprt, 2), 'nett_profit_potong' => round($netprt1, 2),
            'price_list' => $hargaNormal === null ? null : (float) $hargaNormal,
            'catatan' => (int) $x->status_plan === 2 ? $x->catatan_reschedule : $x->status_jadwal, 'catatan_logistik' => $x->catatan_log,
            'no_do_syop' => $x->no_do_syop, 'nomor_lo' => $x->nomor_lo_pr, 'tgl_loading' => $x->tgl_etl_plan, 'status' => $status,
            'top_plan' => $x->top_plan ?: $termPay, 'credit_limit' => $x->credit_limit,
            'sudah_split' => (int) $x->is_split === 1 || $x->volume_potong !== null,
            'accurate_do' => $x->id_do_accurate ? (int) $x->id_do_accurate : null,
        ];
    }

    /** Penguncian Simpan: setelah submit BM, nonaktif dari 16:31 hari itu sampai 08:00 hari berikutnya (sama dengan tombol web). */
    private function lockedUntil($h): ?string
    {
        if (! $h->submit_bm) {
            return null;
        }
        $t = strtotime($h->submit_bm);
        $from = strtotime(date('Y-m-d', $t).' 16:31:00');
        $to = strtotime(date('Y-m-d', strtotime('+1 day', $t)).' 08:00:00');
        $now = time();

        return ($now >= $from && $now < $to) ? date('c', $to) : null;
    }

    public function show(int $id): JsonResponse
    {
        $h = $this->header($id);
        if (! $h) {
            return response()->json(['message' => 'Delivery Request tidak ditemukan.'], 404);
        }
        $lines = $this->lines($id);
        $first = $lines[0] ?? null;
        $fnr = $first ? (int) $first->purchasing_result : 0;
        $verified = (bool) $fnr;
        $out = array_map(fn ($x) => $this->line($x, $verified), $lines);
        $disLo = $first && ($first->revert_cfo || $first->revert_ceo);
        $locked = $this->lockedUntil($h);
        $canSave = $first && ! $fnr && (int) $first->sm_result === 1 && (int) $h->disposisi_pr === 3 && ! $locked;
        $canRevise = (int) $h->disposisi_pr === 6 && $h->tanggal_pr >= self::LEGACY;

        return response()->json([
            'id' => (int) $h->id_pr, 'nomor_pr' => $h->nomor_pr, 'tanggal_pr' => $h->tanggal_pr, 'cabang' => $h->nama_cabang,
            'dr_submit' => $h->logistik_tanggal, 'dr_release' => $h->purchasing_tanggal,
            'status' => self::statusLabel($h), 'disposisi' => (int) $h->disposisi_pr, 'terverifikasi' => $verified,
            'catatan_cfo' => $first && $first->revert_cfo ? self::plain($h->revert_cfo_summary) : null,
            'catatan_ceo' => $first && $first->revert_ceo ? self::plain($h->revert_ceo_summary) : null,
            'catatan_purchasing' => self::plain($h->purchasing_summary), 'purchasing_pic' => $h->purchasing_pic, 'purchasing_tanggal' => $h->purchasing_tanggal,
            'dis_lo' => (bool) $disLo,
            'can_save' => $canSave, 'can_return' => $canSave, 'cabang_inventory' => self::cabangInventory((int) $h->id_wilayah), 'locked_until' => $locked, 'can_revise' => $canRevise,
            'total_volume' => array_sum(array_column($out, 'volume')), 'total_nett_profit' => round(array_sum(array_map(fn ($l) => $l['nett_profit'] + $l['nett_profit_potong'], $out)), 2),
            'lines' => $out,
        ]);
    }

    /** Cabang inventory yang boleh dipilih untuk DR di wilayah ini (versi baru __get_po_by_terminal.php): 2, 3, 6 saling terbuka; selain itu cabang yang sama. */
    private static function cabangInventory(int $idw): array
    {
        return in_array($idw, [2, 3, 6], true) ? [2, 3, 6] : [$idw];
    }

    /** Daftar PO Supplier yang masih bersaldo (vw_terminal_inventory_receive, sisa_inven > 0) pada cabang DR ini. */
    public function poSupplier(Request $r, int $id): JsonResponse
    {
        $h = $this->header($id);
        if (! $h) {
            return response()->json(['message' => 'Delivery Request tidak ditemukan.'], 404);
        }
        $q = DB::table('vw_terminal_inventory_receive')->where('sisa_inven', '>', 0)->whereIn('id_cabang', self::cabangInventory((int) $h->id_wilayah));
        if (($t = trim((string) $r->query('q', ''))) !== '') {
            $q->where(fn ($w) => $w->where('nama_terminal', 'like', "%{$t}%")->orWhere('nomor_po_supplier', 'like', "%{$t}%"));
        }

        return response()->json(['data' => $q->orderBy('nama_terminal')->orderBy('nomor_po_supplier')->limit(300)->get()->map(fn ($x) => [
            'id_terminal' => (int) $x->id_terminal, 'terminal' => trim($x->nama_terminal.' '.$x->tanki_terminal), 'lokasi' => $x->lokasi_terminal,
            'sisa' => round((float) $x->sisa_inven, 2), 'nomor_po' => $x->nomor_po_supplier, 'vol_po' => round((float) $x->vol_po_supplier, 2),
            'vol_terima' => round((float) $x->vol_terima_barang, 2), 'harga_tebus' => (int) round((float) $x->harga_tebus),
            'vendor' => $x->nama_vendor, 'id_vendor' => (int) $x->id_vendor, 'id_po_supplier' => (int) $x->id_po_supplier, 'id_po_receive' => (int) $x->id_po_receive,
        ])]);
    }

    private static function fail(string $m, string $c = 'INVALID', int $s = 422)
    {
        abort(response()->json(['message' => $m, 'code' => $c], $s));
    }

    /** Simpan (verifikasi) Purchasing: aksi peran 5 pada purchase-request (2).php, tanpa Accurate. */
    public function verify(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'catatan' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id_prd' => ['required', 'integer'],
            'lines.*.volume' => ['required', 'integer', 'min:0'],
            'lines.*.po' => ['required', 'array'],
            'lines.*.po.id_po_supplier' => ['required', 'integer'],
            'lines.*.po.id_po_receive' => ['required', 'integer'],
            'lines.*.po.id_terminal' => ['required', 'integer'],
            'lines.*.split' => ['nullable', 'array'],
            'lines.*.split.volume' => ['required_with:lines.*.split', 'integer', 'min:1'],
            'lines.*.split.po' => ['required_with:lines.*.split', 'array'],
            'lines.*.split.po.id_po_supplier' => ['required_with:lines.*.split', 'integer'],
            'lines.*.split.po.id_po_receive' => ['required_with:lines.*.split', 'integer'],
            'lines.*.split.po.id_terminal' => ['required_with:lines.*.split', 'integer'],
        ]);

        if ($resp = $this->stepUp($request)) {
            return $resp;
        }

        try {
            return DB::transaction(function () use ($request, $id, $data) {
            $h = DB::table('pro_pr as a')->join('pro_master_cabang as b', 'a.id_wilayah', '=', 'b.id_master')->where('a.id_pr', $id)->where('a.disposisi_pr', '>', 2)
                ->lockForUpdate()->first(['a.*', 'b.inisial_cabang']);
            if (! $h) {
                return response()->json(['message' => 'Delivery Request tidak ditemukan.'], 404);
            }
            if ((int) $h->disposisi_pr !== 3 || (int) $h->purchasing_result !== 0 || (int) $h->sm_result !== 1) {
                return response()->json(['message' => 'Delivery Request ini tidak sedang menunggu verifikasi Purchasing.', 'code' => 'NOT_PENDING'], 409);
            }
            if ($until = $this->lockedUntil($h)) {
                return response()->json(['message' => 'Simpan dinonaktifkan sampai 08:00 setelah jam 16:30 pada hari submit BM.', 'code' => 'LOCKED', 'until' => $until], 409);
            }

            $db = $this->lines($id);
            if (! $db) {
                self::fail('Data DR tidak ditemukan.');
            }
            $byId = [];
            foreach ($db as $x) {
                $byId[(int) $x->id_prd] = $x;
            }
            $posted = [];
            foreach ($data['lines'] as $l) {
                $posted[(int) $l['id_prd']] = $l;
            }
            if (array_diff_key($byId, $posted) || array_diff_key($posted, $byId)) {
                self::fail('Data baris DR tidak sama dengan yang tersimpan. Muat ulang halaman.');
            }
            $disLo = (bool) ($db[0]->revert_cfo || $db[0]->revert_ceo);

            // Stok virtual lama milik DR ini dilepas dulu (kasus DR yang dikembalikan lalu disimpan ulang), supaya stok tidak terhitung ganda.
            DB::table('new_pro_inventory_depot')->where('id_pr', $id)->where('id_datanya', 'generate DR')->where('id_jenis', 6)->delete();
            DB::table('new_pro_inventory_potongan_stock')->where('id_pr', $id)->delete();

            $inisial = $h->inisial_cabang;
            $pic = $request->user()->fullname;
            $stock = [];   // id_po_receive => volume dipakai
            $cabs = self::cabangInventory((int) $h->id_wilayah);
            $po = function (array $p) use ($cabs) {
                $v = DB::table('vw_terminal_inventory_receive')->where('id_po_supplier', $p['id_po_supplier'])->where('id_po_receive', $p['id_po_receive'])->where('id_terminal', $p['id_terminal'])->whereIn('id_cabang', $cabs)->first();
                if (! $v) {
                    self::fail('PO Supplier yang dipilih tidak ditemukan atau bukan dari cabang DR ini.');
                }

                return $v;
            };

            $plan = [];
            foreach ($byId as $idp => $x) {
                $l = $posted[$idp];
                $p = $po($l['po']);
                $volori = (int) ($x->vol_ori_pr ?: $x->volume);
                $vol = (int) $l['volume'];
                $split = $l['split'] ?? null;
                $sp = $split ? $po($split['po']) : null;
                if (! $p->id_vendor || ! (int) round((float) $p->harga_tebus) || ! $p->id_terminal || ! $p->id_po_supplier || ! $p->id_po_receive || ! $p->nomor_po_supplier) {
                    self::fail('Depot, PO Supplier dan Harga Beli harus diisi semua');
                }
                if ($sp && (int) $sp->id_terminal !== (int) $p->id_terminal) {
                    self::fail('Depot harus sama antara yang di-split dan yang tidak di-split');
                }
                $total = $vol + ($split ? (int) $split['volume'] : 0);
                if ($total > $volori) {
                    self::fail('Perubahan volume delivery ['.number_format($total).'] lebih besar daripada volume awal ['.number_format($volori).']');
                }
                if ($total < $volori) {
                    self::fail('Perubahan volume delivery ['.number_format($total).'] lebih kecil daripada volume awal ['.number_format($volori).']');
                }
                $k = $p->id_po_receive.'|'.$p->id_terminal;
                $stock[$k] = ($stock[$k] ?? 0) + $vol;
                if ($sp) {
                    $k2 = $sp->id_po_receive.'|'.$sp->id_terminal;
                    $stock[$k2] = ($stock[$k2] ?? 0) + (int) $split['volume'];
                }
                $plan[$idp] = compact('x', 'p', 'sp', 'volori', 'vol', 'split');
            }
            foreach ($stock as $key => $used) {
                [$rec, $term] = explode('|', (string) $key);
                $sisa = (float) DB::table('vw_terminal_inventory_receive')->where('id_po_receive', $rec)->where('id_terminal', $term)->value('sisa_inven');
                if ($used > $sisa) {
                    self::fail('Stock tidak cukup: dibutuhkan '.number_format($used).' L, sisa '.number_format($sisa).' L pada PO Supplier yang dipilih.', 'STOCK');
                }
            }

            // Nomor DO berikutnya (sama dengan web: terakhir per cabang/tahun/bulan Romawi).
            $romawi = [1 => 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
            $tglKirim = end($byId)->tanggal_kirim;
            $month = date('m-Y');
            if (date('m-Y', strtotime($tglKirim)) > $month) { // perbandingan teks, persis web
                $bulanRomawi = $romawi[(int) date('m', strtotime($tglKirim))];
                $year = date('y', strtotime($tglKirim));
            } else {
                $bulanRomawi = $romawi[(int) date('m')];
                $year = date('y');
            }
            $last = DB::table('pro_pr_detail')->where('cabang', $inisial)->whereNotNull('no_do_syop')->where('no_do_syop', 'like', "%/{$year}/{$bulanRomawi}/%")->orderByDesc('no_do_syop')->value('no_do_syop');
            $urut = 0;
            if ($last) {
                $e = explode('/', $last);
                $urut = ($e[3] ?? null) == $year ? (int) ($e[5] ?? 0) : 0;
                if (($e[4] ?? null) != $bulanRomawi) {
                    $urut = 0;
                }
            }

            foreach ($plan as $idp => $c) {
                $x = $c['x'];
                $p = $c['p'];
                $upd = [
                    'vol_ori' => $c['volori'], 'vol_ori_pr' => $c['volori'], 'volume' => $c['vol'],
                    'pr_top' => $x->top_plan ?: ['CREDIT' => 'NET '.$x->top_payment, 'COD' => 'COD', 'CDB' => 'CBD'][$x->jenis_payment] ?? null,
                    'pr_kredit_limit' => $x->credit_limit, 'pr_vendor' => $p->id_vendor, 'pr_harga_beli' => (int) round((float) $p->harga_tebus),
                    'pr_price_list' => (int) ($x->harga_normal ?: $x->harga_normal_new), 'pr_po' => null, 'pr_terminal' => $p->id_terminal, 'cabang' => $inisial,
                    'nomor_po_supplier' => $p->nomor_po_supplier, 'id_po_supplier' => $p->id_po_supplier, 'id_po_receive' => $p->id_po_receive,
                ];
                if (! $disLo) {
                    $upd['vol_potongan'] = $c['vol'];
                    $upd['nomor_lo_pr'] = 'LO/'.$inisial.'/'.str_pad((string) mt_rand(1, 99999999), 6, '0', STR_PAD_LEFT);
                    $urut++;
                    if (! $x->no_do_syop) {
                        $upd['no_do_syop'] = 'DO/PE/'.$inisial.'/'.$year.'/'.$bulanRomawi.'/'.sprintf('%04s', $urut);
                    }
                }
                DB::table('pro_pr_detail')->where('id_prd', $idp)->update($upd);
                $this->outVirtual((int) $x->id_produk, $p, $c['vol'], $id, $idp, $pic, $request->ip());

                if ($c['split']) {
                    $sp = $c['sp'];
                    $sv = (int) $c['split']['volume'];
                    DB::table('new_pro_inventory_potongan_stock')->insert([
                        'id_pr' => $id, 'id_prd' => $idp, 'volume' => $sv, 'pr_terminal' => $sp->id_terminal, 'pr_harga_beli' => (int) round((float) $sp->harga_tebus),
                        'nomor_po_supplier' => $sp->nomor_po_supplier, 'id_po_supplier' => $sp->id_po_supplier, 'id_po_receive' => $sp->id_po_receive, 'created_time' => now(),
                    ]);
                    $this->outVirtual((int) $x->id_produk, $sp, $sv, $id, $idp, $pic, $request->ip());
                    DB::table('pro_pr_detail')->where('id_prd', $idp)->update(['is_split' => 1]);
                }
            }

            DB::table('pro_pr')->where('id_pr', $id)->update([
                'purchasing_summary' => str_replace(["\r\n", "\r", "\n"], '<br />', htmlspecialchars(trim((string) ($data['catatan'] ?? '')), ENT_QUOTES)),
                'purchasing_result' => 1, 'purchasing_pic' => $pic, 'purchasing_tanggal' => now(), 'disposisi_pr' => 6, 'is_ceo' => 0, 'coo_result' => 1,
            ]);

            // Accurate (bila aktif): SO lalu DO. Gagal = seluruh penyimpanan dibatalkan, sama dengan web.
            app(AccurateDrSync::class)->create($id, trim((string) ($data['catatan'] ?? '')));

            return response()->json(['message' => 'Delivery Request diverifikasi.']);
            });
        } catch (AccurateException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'ACCURATE'], 502);
        }
    }

    private function outVirtual(int $produk, $p, int $volume, int $idr, int $idp, string $pic, ?string $ip): void
    {
        DB::table('new_pro_inventory_depot')->insert([
            'id_datanya' => 'generate DR', 'id_jenis' => 6, 'id_produk' => $produk, 'id_terminal' => $p->id_terminal, 'id_vendor' => $p->id_vendor,
            'id_po_supplier' => $p->id_po_supplier, 'id_po_receive' => $p->id_po_receive, 'tanggal_inven' => now()->toDateString(), 'out_inven_virtual' => $volume,
            'keterangan' => 'Out Stock Virtual', 'created_time' => now(), 'created_ip' => (string) $ip, 'created_by' => $pic, 'id_pr' => $idr, 'id_prd' => $idp,
        ]);
    }

    /** Revisi DR: DR terverifikasi dikembalikan ke Verifikasi Purchasing (aksi revisiDR di web), stok virtual dan split dilepas. */
    public function revise(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->stepUp($request)) {
            return $resp;
        }

        try {
            return DB::transaction(function () use ($id) {
            $h = $this->header($id);
            if (! $h) {
                return response()->json(['message' => 'Delivery Request tidak ditemukan.'], 404);
            }
            if ((int) $h->disposisi_pr !== 6 || $h->tanggal_pr < self::LEGACY) {
                return response()->json(['message' => 'Hanya DR berstatus Terverifikasi yang dapat direvisi.', 'code' => 'NOT_REVISABLE'], 409);
            }
            $busy = DB::table('pro_pr_detail as a')->join('pro_po_ds_detail as s', 'a.id_prd', '=', 's.id_prd')->where('a.id_pr', $id)
                ->where(fn ($w) => $w->where('s.is_loaded', 1)->orWhere('s.is_delivered', 1))->exists();
            if ($busy) {
                return response()->json(['message' => 'DR ini sudah loading atau terkirim, tidak bisa direvisi.', 'code' => 'ALREADY_LOADED'], 409);
            }
            // Accurate (bila aktif): hapus DO lalu SO dulu; bila ditolak, tidak ada perubahan di database.
            app(AccurateDrSync::class)->delete($id);
            DB::table('pro_pr')->where('id_pr', $id)->update(['disposisi_pr' => 3, 'purchasing_result' => 0]);
            DB::table('new_pro_inventory_depot')->where('id_pr', $id)->delete();
            DB::table('new_pro_inventory_potongan_stock')->where('id_pr', $id)->delete();
            DB::table('pro_pr_detail')->where('id_pr', $id)->update(['vol_potongan' => null, 'nomor_lo_pr' => null]);

            return response()->json(['message' => 'DR dikembalikan ke Verifikasi Purchasing.']);
            });
        } catch (AccurateException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'ACCURATE'], 502);
        }
    }

    /** Konfirmasi password untuk sesi hasil login wajah sebelum menulis (data DR menyangkut stok dan harga beli). Null = lolos. */
    private function stepUp(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user->tokenCan('*')) {
            return null;
        }
        $password = (string) $request->input('password', '');
        if ($password === '') {
            return response()->json(['message' => 'Masukkan kata sandi untuk melanjutkan.', 'code' => 'STEP_UP_REQUIRED'], 403);
        }
        if (! \App\Services\PasswordGate::check($user, $password, $request)) {
            return \App\Services\PasswordGate::locked($user)
                ? response()->json(['message' => 'Terlalu banyak salah kata sandi. Coba lagi 15 menit lagi.', 'code' => 'PASSWORD_LOCKED'], 429)
                : response()->json(['message' => 'Kata sandi salah.', 'code' => 'STEP_UP_FAILED'], 422);
        }

        return null;
    }

    /** "Kembalikan Ke DP" (action/cek_status_dr_pr.php): DR dihapus dan rencana kirim (DP) kembali ke Logistik (status_plan = 0). */
    public function returnToDp(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->stepUp($request)) {
            return $resp;
        }

        return DB::transaction(function () use ($id) {
            $h = $this->header($id);
            if (! $h) {
                return response()->json(['message' => 'Delivery Request tidak ditemukan.'], 404);
            }
            $first = DB::table('pro_pr_detail')->where('id_pr', $id)->where('is_approved', 1)->exists();
            if ((int) $h->disposisi_pr !== 3 || (int) $h->purchasing_result !== 0 || (int) $h->sm_result !== 1 || ! $first) {
                return response()->json(['message' => 'DR ini tidak bisa dikembalikan ke DP.', 'code' => 'NOT_PENDING'], 409);
            }
            if ($this->lockedUntil($h)) {
                return response()->json(['message' => 'Pengembalian dinonaktifkan sampai 08:00 setelah jam 16:30 pada hari submit BM.', 'code' => 'LOCKED'], 409);
            }
            $rows = DB::table('pro_pr_detail')->where('id_pr', $id)->where('is_approved', 1)->get(['id_prd', 'id_plan']);
            DB::table('pro_pr_detail')->whereIn('id_prd', $rows->pluck('id_prd'))->delete();
            DB::table('pro_pr')->where('id_pr', $id)->delete();
            DB::table('pro_po_customer_plan')->whereIn('id_plan', $rows->pluck('id_plan')->unique())->update(['status_plan' => 0]);

            return response()->json(['message' => 'DR dikembalikan ke delivery plan logistik.']);
        });
    }

    /** Batal split model lama (action/reset_split_pr.php, peran 5): baris anak dihapus, volume induk kembali ke volume awal. */
    public function resetSplit(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['id_prd' => ['required', 'integer']]);
        if ($resp = $this->stepUp($request)) {
            return $resp;
        }

        return DB::transaction(function () use ($id, $data) {
            $h = $this->header($id);
            $row = DB::table('pro_pr_detail')->where('id_pr', $id)->where('id_prd', $data['id_prd'])->first();
            if (! $h || ! $row || ! $row->splitted_from_pr || (int) $h->disposisi_pr !== 3 || (int) $h->purchasing_result !== 0) {
                return response()->json(['message' => 'Split tidak dapat dibatalkan.', 'code' => 'NOT_SPLIT'], 409);
            }
            $parent = (int) $row->splitted_from_pr;
            DB::table('pro_pr_detail')->where('id_pr', $id)->where('splitted_from_pr', $parent)->delete();
            DB::table('pro_pr_detail')->where('id_pr', $id)->where('id_prd', $parent)->update(['volume' => DB::raw('vol_ori_pr'), 'splitted_from_pr' => null]);

            return response()->json(['message' => 'Split dibatalkan.']);
        });
    }

    /** History Approve penawaran (__get_history_approve.php): pro_approval_hist kd_approval P001. */
    public function historyApprove(Request $request, int $id, int $penawaran): JsonResponse
    {
        if (! $this->header($id)) {
            return response()->json(['message' => 'Delivery Request tidak ditemukan.'], 404);
        }
        $rows = DB::table('pro_approval_hist as a')->where('a.kd_approval', 'P001')->where('a.id_penawaran', $penawaran)
            ->leftJoin('acl_user as u', 'u.id_user', '=', 'a.id_user')->leftJoin('acl_role as r', 'r.id_role', '=', 'a.id_role')
            ->orderBy('a.tgl_approval')
            ->get(['u.fullname', 'r.role_name', 'a.volume', 'a.harga_dasar', 'a.oa_kirim', 'a.ppn', 'a.pbbkb', 'a.tgl_approval', 'a.result', 'a.summary', 'a.keterangan_pengajuan']);

        return response()->json(['data' => $rows->map(fn ($x) => [
            'user' => $x->fullname, 'role' => $x->role_name, 'volume' => (int) $x->volume, 'harga_dasar' => (int) $x->harga_dasar, 'oa' => (int) $x->oa_kirim,
            'ppn' => (int) $x->ppn, 'pbbkb' => (int) $x->pbbkb, 'tanggal' => $x->tgl_approval,
            'status' => $x->result === '1' ? 'Disetujui' : ($x->result === '2' ? 'Ditolak' : ''),
            'keterangan_approval' => self::plain($x->summary), 'keterangan_pengajuan' => self::plain($x->keterangan_pengajuan),
        ])->values()]);
    }
}
