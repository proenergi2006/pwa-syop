<?php

namespace App\Http\Controllers;

use App\Services\ApprovalMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Trucking Delivery Request (pro_pr_trucking + pro_pr_trucking_detail), hanya untuk Purchasing/Procurement (peran 5).
 * Sumber: trucking-delivery-request.php (halaman daftar), trucking-delivery-request (1).php (datatable), trucking-delivery-request-detail.php,
 * __get_trucking_dr_purchasing.php (detail peran 5), trucking-delivery-request (2).php (aksi simpan / revisi peran 5).
 * disposisi_dr: 0 Pending Procurement, 1 Terverifikasi, 2 Purchase Order (diisi Logistik di web).
 * Tidak ada Accurate di jalur ini (web lama juga tidak memanggil Accurate untuk Trucking DR).
 */
class TruckingDrController extends Controller
{
    public function waitingCount(): int
    {
        return (int) DB::table('pro_pr_trucking')->where(fn ($w) => $w->whereNull('purchasing_result')->orWhere('purchasing_result', 0))->where('disposisi_dr', 0)->count();
    }

    /** @return array<int,string> id_pr_ti => nomor DR */
    public function waitingItems(): array
    {
        return DB::table('pro_pr_trucking')->where(fn ($w) => $w->whereNull('purchasing_result')->orWhere('purchasing_result', 0))->where('disposisi_dr', 0)
            ->orderByDesc('id_pr_ti')->limit(500)->get(['id_pr_ti as id', 'nomor_pr_ti as label'])->pluck('label', 'id')->all();
    }

    private static function statusLabel($r): string
    {
        return [0 => 'Pending Procurement', 1 => 'Terverifikasi', 2 => 'Purchase Order'][(int) $r->disposisi_dr] ?? '';
    }

    public function index(Request $r): JsonResponse
    {
        $q = DB::table('pro_pr_trucking as a')->join('pro_master_cabang as b', 'a.id_wilayah', '=', 'b.id_master');

        if (($term = trim((string) $r->query('q', ''))) !== '') {
            $q->where(function ($w) use ($term) {
                $like = "%{$term}%";
                $w->where('a.nomor_pr_ti', 'like', $like)
                    ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('pro_pr_trucking_detail as c')->whereColumn('c.id_pr_ti', 'a.id_pr_ti')->where('c.nomor_lo_pr_ti', 'like', $like))
                    ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('pro_pr_trucking_detail as c')
                        ->join('pro_trucking_plan as d', 'd.id_plan', '=', 'c.id_plan')->join('pro_trucking_instruction_req as e', 'e.id_master', '=', 'd.id_trucking')
                        ->whereColumn('c.id_pr_ti', 'a.id_pr_ti')->where('e.nomor_ti', 'like', $like));
            });
        }
        if ($r->filled('cabang')) {
            $q->where('a.id_wilayah', (int) $r->query('cabang'));
        }
        $st = (string) $r->query('status', '');
        if ($st !== '' && ctype_digit($st)) {
            $q->where('a.disposisi_dr', (int) $st);
        }
        $from = $r->query('from');
        $to = $r->query('to');
        if ($from && $to) {
            $q->whereBetween('a.tanggal_pr_ti', [$from, $to]);
        } elseif ($from || $to) {
            $q->where('a.tanggal_pr_ti', $from ?: $to);
        }

        $per = min(max((int) $r->query('per_page', 20), 1), (int) config('orca.per_page_max'));
        $page = $q->orderByDesc('a.tanggal_pr_ti')->orderByRaw('IF(a.disposisi_dr = 0, 1, 0) desc')->orderByDesc('a.id_pr_ti')
            ->select('a.id_pr_ti', 'a.nomor_pr_ti', 'a.tanggal_pr_ti', 'a.disposisi_dr', 'a.purchasing_result', 'b.nama_cabang')
            ->paginate($per);

        $ids = collect($page->items())->pluck('id_pr_ti');
        $det = $ids->isEmpty() ? collect() : DB::table('pro_pr_trucking_detail as a')
            ->join('pro_trucking_plan as b', 'a.id_plan', '=', 'b.id_plan')->join('pro_trucking_instruction_req as c', 'b.id_trucking', '=', 'c.id_master')
            ->whereIn('a.id_pr_ti', $ids)->orderBy('a.id_prd_ti')
            ->get(['a.id_pr_ti', 'a.volume', 'c.nomor_ti', 'c.etl_awal'])->groupBy('id_pr_ti');

        $data = collect($page->items())->map(function ($p) use ($det) {
            $d = $det[$p->id_pr_ti] ?? collect();

            return [
                'id' => (int) $p->id_pr_ti, 'nomor_dr' => $p->nomor_pr_ti, 'tanggal' => $p->tanggal_pr_ti, 'cabang' => $p->nama_cabang,
                'nomor_ti' => $d->pluck('nomor_ti')->values(), 'volume' => $d->pluck('volume')->map(fn ($v) => (int) $v)->values(),
                'tgl_loading' => $d->pluck('etl_awal')->values(),
                'status' => self::statusLabel($p), 'disposisi' => (int) $p->disposisi_dr,
                'menunggu' => ! (int) $p->purchasing_result, // baris diwarnai untuk Purchasing
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
        return DB::table('pro_pr_trucking as a')->join('pro_master_cabang as b', 'a.id_wilayah', '=', 'b.id_master')
            ->where('a.id_pr_ti', $id)->first(['a.*', 'b.nama_cabang', 'b.inisial_cabang']);
    }

    private static function plain(?string $s): ?string
    {
        return $s === null ? null : html_entity_decode(str_replace(['<br />', '<br>'], "\n", $s), ENT_QUOTES);
    }

    /** Baris DR: query sama dengan __get_trucking_dr_purchasing.php (tanpa kolom yang tidak dipakai). */
    private function lines(int $id): array
    {
        return DB::select("
            select a.id_prd_ti, a.id_pr_ti, a.id_plan, a.volume, a.vol_ori, a.vol_ori_pr, a.vol_potongan, a.nomor_lo_pr_ti, a.nomor_po_supplier,
                a.id_po_supplier, a.id_po_receive, a.is_split, a.splitted_from_pr,
                b.purchasing_result,
                d.nomor_ti, d.id_master as id_trucking, d.etl_awal, d.etl_akhir,
                concat(f.nama_terminal,' - ',f.tanki_terminal,' - ',f.lokasi_terminal) as loading_port,
                concat(e.nama_terminal,' - ',e.tanki_terminal,' - ',e.lokasi_terminal) as discharging_port,
                f.id_cabang as wilayah, c.catatan_log,
                l.nama_terminal, l.tanki_terminal, l.lokasi_terminal,
                t.volume as volume_potong, t.nomor_po_supplier as nomor_potong,
                pt.nama_terminal as terminal_potong, pt.tanki_terminal as tanki_potong, pt.lokasi_terminal as lokasi_potong,
                g.is_loaded, g.is_delivered, g.is_cancel, g.tanggal_loaded, g.jam_loaded, g.tanggal_cancel
            from pro_pr_trucking_detail a
                join pro_pr_trucking b on a.id_pr_ti = b.id_pr_ti
                join pro_trucking_plan c on a.id_plan = c.id_plan
                join pro_trucking_instruction_req d on c.id_trucking = d.id_master
                join pro_master_terminal e on d.discharging_port = e.id_master
                join pro_master_terminal f on d.loading_port = f.id_master
                left join new_pro_inventory_vendor_po k on a.id_po_supplier = k.id_master
                left join pro_master_terminal l on k.id_terminal = l.id_master
                left join new_pro_inventory_potongan_stock t on a.id_prd_ti = t.id_prd_ti
                left join pro_master_terminal pt on pt.id_master = t.pr_terminal
                left join pro_po_ds_trucking_detail g on a.id_prd_ti = g.id_prd_ti
            where a.id_pr_ti = ?
            order by a.id_prd_ti", [$id]);
    }

    private function line($x, bool $verified): array
    {
        $term = fn ($n, $tk, $lk) => $n ? trim($n.($tk ? ' - '.$tk : '').($lk ? ', '.$lk : '')) : '';
        if ((int) $x->is_loaded === 0 && (int) $x->is_delivered === 0 && (int) $x->is_cancel === 1) {
            $status = ['kode' => 'cancel', 'tanggal' => $x->tanggal_cancel];
        } elseif ((int) $x->is_loaded === 1 && (int) $x->is_delivered === 0 && (int) $x->is_cancel === 1) {
            $status = ['kode' => 'cancel', 'tanggal' => $x->tanggal_cancel];
        } elseif ((int) $x->is_loaded === 1 && (int) $x->is_delivered === 1 && (int) $x->is_cancel === 0) {
            $status = ['kode' => 'delivered', 'tanggal' => $x->tanggal_loaded, 'jam' => $x->jam_loaded];
        } elseif ((int) $x->is_loaded === 1) {
            $status = ['kode' => 'loading', 'tanggal' => $x->tanggal_loaded, 'jam' => $x->jam_loaded];
        } else {
            $status = ['kode' => 'belum'];
        }

        return [
            'id_prd' => (int) $x->id_prd_ti, 'id_plan' => (int) $x->id_plan, 'nomor_ti' => $x->nomor_ti,
            'loading_port' => $x->loading_port, 'discharging_port' => $x->discharging_port,
            'volume_awal' => (int) ($x->vol_ori_pr ?: $x->volume), 'volume' => (int) $x->volume, 'vol_ori' => (int) ($x->vol_ori_pr ?: $x->volume),
            'vol_potongan' => (int) $x->vol_potongan, 'volume_potong' => $x->volume_potong === null ? null : (int) $x->volume_potong,
            'po_supplier' => $x->nomor_po_supplier ?: null, 'po_potong' => $x->nomor_potong ?: null,
            'depot' => $term($x->nama_terminal, $x->tanki_terminal, $x->lokasi_terminal), 'depot_potong' => $term($x->terminal_potong, $x->tanki_potong, $x->lokasi_potong),
            'tgl_loading' => $x->etl_awal, 'catatan_logistik' => $x->catatan_log, 'nomor_lo' => $x->nomor_lo_pr_ti, 'status' => $status,
            'sudah_split' => (int) $x->is_split === 1 || $x->volume_potong !== null,
            'wilayah' => (int) $x->wilayah,
        ];
    }

    public function show(int $id): JsonResponse
    {
        $h = $this->header($id);
        if (! $h) {
            return response()->json(['message' => 'Trucking Delivery Request tidak ditemukan.'], 404);
        }
        $lines = $this->lines($id);
        $verified = (bool) ((int) $h->purchasing_result);
        $out = array_map(fn ($x) => $this->line($x, $verified), $lines);
        $d = (int) $h->disposisi_dr;
        $canSave = $out && ! $verified && $d === 0;

        return response()->json([
            'id' => (int) $h->id_pr_ti, 'nomor_dr' => $h->nomor_pr_ti, 'tanggal' => $h->tanggal_pr_ti, 'cabang' => $h->nama_cabang,
            'dr_submit' => $h->logistik_tanggal ?? null, 'dr_release' => $h->purchasing_tanggal,
            'status' => self::statusLabel($h), 'disposisi' => $d, 'terverifikasi' => $verified,
            'catatan_purchasing' => self::plain($h->purchasing_summary), 'purchasing_pic' => $h->purchasing_pic, 'purchasing_tanggal' => $h->purchasing_tanggal,
            'can_save' => $canSave, 'can_return' => $canSave, 'can_revise' => $d === 1,
            'total_volume' => array_sum(array_column($out, 'volume')),
            'lines' => $out,
        ]);
    }

    /** Cabang inventory yang boleh dipilih (sama dengan __get_po_by_terminal.php): 2, 3, 6 saling terbuka; selain itu cabang yang sama. */
    private static function cabangInventory(int $idw): array
    {
        return in_array($idw, [2, 3, 6], true) ? [2, 3, 6] : [$idw];
    }

    /** Cabang dasar daftar PO Supplier: cabang terminal loading TI pada DR ini (data-wilayah di web). */
    private function wilayah(int $id): ?int
    {
        $w = DB::table('pro_pr_trucking_detail as a')->join('pro_trucking_plan as c', 'a.id_plan', '=', 'c.id_plan')
            ->join('pro_trucking_instruction_req as d', 'c.id_trucking', '=', 'd.id_master')->join('pro_master_terminal as f', 'd.loading_port', '=', 'f.id_master')
            ->where('a.id_pr_ti', $id)->orderBy('a.id_prd_ti')->value('f.id_cabang');

        return $w === null ? null : (int) $w;
    }

    public function poSupplier(Request $r, int $id): JsonResponse
    {
        $h = $this->header($id);
        $w = $h ? $this->wilayah($id) : null;
        if (! $h || $w === null) {
            return response()->json(['message' => 'Trucking Delivery Request tidak ditemukan.'], 404);
        }
        $q = DB::table('vw_terminal_inventory_receive')->where('sisa_inven', '>', 0)->whereIn('id_cabang', self::cabangInventory($w));
        if (($t = trim((string) $r->query('q', ''))) !== '') {
            $q->where(fn ($x) => $x->where('nama_terminal', 'like', "%{$t}%")->orWhere('nomor_po_supplier', 'like', "%{$t}%"));
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

    /** Simpan (verifikasi) Purchasing: aksi peran 5 pada trucking-delivery-request (2).php. */
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

        return DB::transaction(function () use ($request, $id, $data) {
            $h = DB::table('pro_pr_trucking as a')->join('pro_master_cabang as b', 'a.id_wilayah', '=', 'b.id_master')->where('a.id_pr_ti', $id)
                ->lockForUpdate()->first(['a.*', 'b.inisial_cabang']);
            if (! $h) {
                return response()->json(['message' => 'Trucking Delivery Request tidak ditemukan.'], 404);
            }
            if ((int) $h->disposisi_dr !== 0 || (int) $h->purchasing_result !== 0) {
                return response()->json(['message' => 'Trucking Delivery Request ini tidak sedang menunggu verifikasi Purchasing.', 'code' => 'NOT_PENDING'], 409);
            }

            $db = $this->lines($id);
            if (! $db) {
                self::fail('Data DR tidak ditemukan.');
            }
            $byId = [];
            foreach ($db as $x) {
                $byId[(int) $x->id_prd_ti] = $x;
            }
            $posted = [];
            foreach ($data['lines'] as $l) {
                $posted[(int) $l['id_prd']] = $l;
            }
            if (array_diff_key($byId, $posted) || array_diff_key($posted, $byId)) {
                self::fail('Data baris DR tidak sama dengan yang tersimpan. Muat ulang halaman.');
            }

            // Stok lama milik DR ini dilepas dulu (DR yang direvisi lalu disimpan ulang), supaya stok tidak terhitung ganda.
            DB::table('new_pro_inventory_depot')->where('id_pr_ti', $id)->delete();
            DB::table('new_pro_inventory_potongan_stock')->where('id_pr_ti', $id)->delete();

            $inisial = $h->inisial_cabang;
            $pic = $request->user()->fullname;
            $stock = [];   // id_po_receive|id_terminal => volume dipakai
            $po = function (array $p, int $wilayah) {
                $v = DB::table('vw_terminal_inventory_receive')->where('id_po_supplier', $p['id_po_supplier'])->where('id_po_receive', $p['id_po_receive'])
                    ->where('id_terminal', $p['id_terminal'])->whereIn('id_cabang', self::cabangInventory($wilayah))->first();
                if (! $v) {
                    self::fail('PO Supplier yang dipilih tidak ditemukan atau bukan dari cabang TI ini.');
                }

                return $v;
            };

            $plan = [];
            foreach ($byId as $idp => $x) {
                $l = $posted[$idp];
                $wil = (int) $x->wilayah;
                $p = $po($l['po'], $wil);
                $volori = (int) ($x->vol_ori_pr ?: $x->volume);
                $vol = (int) $l['volume'];
                $split = $l['split'] ?? null;
                $sp = $split ? $po($split['po'], $wil) : null;
                if (! $p->id_terminal || ! $p->id_po_supplier || ! $p->id_po_receive || ! $p->nomor_po_supplier) {
                    self::fail('Depot dan PO Supplier harus diisi semua');
                }
                if ($sp && (int) $sp->id_terminal !== (int) $p->id_terminal) {
                    self::fail('Depot harus sama antara yang di-split dan yang tidak di-split');
                }
                $total = $vol + ($split ? (int) $split['volume'] : 0);
                if ($total !== $volori) {
                    self::fail('Perubahan volume delivery ['.number_format($total).'] '.($total > $volori ? 'lebih besar' : 'lebih kecil').' daripada volume awal ['.number_format($volori).']');
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

            foreach ($plan as $idp => $c) {
                $x = $c['x'];
                $p = $c['p'];
                DB::table('pro_pr_trucking_detail')->where('id_prd_ti', $idp)->update([
                    'vol_ori' => $c['volori'], 'vol_ori_pr' => $c['volori'], 'volume' => $c['vol'], 'vol_potongan' => $c['vol'],
                    'nomor_lo_pr_ti' => 'LO/TI/'.$inisial.'/'.str_pad((string) mt_rand(1, 99999999), 6, '0', STR_PAD_LEFT),
                    'nomor_po_supplier' => $p->nomor_po_supplier, 'id_po_supplier' => $p->id_po_supplier, 'id_po_receive' => $p->id_po_receive,
                    'is_approved' => 1, 'cabang' => $inisial,
                ]);
                $this->outStock($p, $c['vol'], $id, $idp, 'Trucking Instruction : '.$x->nomor_ti, $pic, $request->ip());

                if ($c['split']) {
                    $sp = $c['sp'];
                    $sv = (int) $c['split']['volume'];
                    DB::table('new_pro_inventory_potongan_stock')->insert([
                        'id_pr_ti' => $id, 'id_prd_ti' => $idp, 'volume' => $sv, 'pr_terminal' => $sp->id_terminal,
                        'nomor_po_supplier' => $sp->nomor_po_supplier, 'id_po_supplier' => $sp->id_po_supplier, 'id_po_receive' => $sp->id_po_receive,
                    ]);
                    $this->outStock($sp, $sv, $id, $idp, 'Trucking Instruction : '.$x->nomor_ti.' (SPLIT)', $pic, $request->ip());
                    DB::table('pro_pr_trucking_detail')->where('id_prd_ti', $idp)->update(['is_split' => 1]);
                }
            }

            DB::table('pro_pr_trucking')->where('id_pr_ti', $id)->update([
                'purchasing_summary' => str_replace(["\r\n", "\r", "\n"], '<br />', htmlspecialchars(trim((string) ($data['catatan'] ?? '')), ENT_QUOTES)),
                'purchasing_result' => 1, 'purchasing_pic' => $pic, 'purchasing_tanggal' => now(), 'disposisi_dr' => 1,
            ]);

            ApprovalMail::toRoles([9, 10], [(int) $h->id_wilayah], 'Verifikasi Trucking Instruction DR [' . ApprovalMail::stamp() . ']',
                e($pic) . ' telah melakukan verifikasi untuk Trucking Instruction DR <b>' . e($h->nomor_pr_ti) . '</b>');

            return response()->json(['message' => 'Trucking Delivery Request diverifikasi.']);
        });
    }

    /** Pengurangan stok depot (id_jenis 4, id_produk dari config orca.trucking_dr_produk, bawaan 15) sama dengan web: adj_inven negatif, terkait id_pr_ti/id_prd_ti. */
    private function outStock($p, int $volume, int $idr, int $idp, string $ket, string $pic, ?string $ip): void
    {
        DB::table('new_pro_inventory_depot')->insert([
            'id_datanya' => md5(uniqid('1089', true).'-'.$idp.'-'.microtime(true)), 'id_jenis' => 4, 'id_produk' => (int) config('orca.trucking_dr_produk'), 'id_terminal' => $p->id_terminal,
            'id_po_supplier' => $p->id_po_supplier, 'id_po_receive' => $p->id_po_receive, 'tanggal_inven' => now()->toDateString(), 'adj_inven' => -$volume,
            'keterangan' => $ket, 'created_time' => now(), 'created_ip' => (string) $ip, 'created_by' => $pic, 'id_pr_ti' => $idr, 'id_prd_ti' => $idp,
        ]);
    }

    /** Revisi DR: DR terverifikasi dikembalikan ke Pending Procurement (aksi revisiDR di web); stok dan split dilepas. */
    public function revise(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->stepUp($request)) {
            return $resp;
        }

        return DB::transaction(function () use ($id) {
            $h = DB::table('pro_pr_trucking')->where('id_pr_ti', $id)->lockForUpdate()->first();
            if (! $h) {
                return response()->json(['message' => 'Trucking Delivery Request tidak ditemukan.'], 404);
            }
            if ((int) $h->disposisi_dr !== 1) {
                return response()->json(['message' => 'Hanya DR berstatus Terverifikasi yang dapat direvisi.', 'code' => 'NOT_REVISABLE'], 409);
            }
            $busy = DB::table('pro_pr_trucking_detail as a')->join('pro_po_ds_trucking_detail as s', 'a.id_prd_ti', '=', 's.id_prd_ti')->where('a.id_pr_ti', $id)
                ->where(fn ($w) => $w->where('s.is_loaded', 1)->orWhere('s.is_delivered', 1))->exists();
            if ($busy) {
                return response()->json(['message' => 'DR ini sudah loading atau terkirim, tidak bisa direvisi.', 'code' => 'ALREADY_LOADED'], 409);
            }
            DB::table('pro_pr_trucking')->where('id_pr_ti', $id)->update(['disposisi_dr' => 0, 'purchasing_result' => 0]);
            DB::table('new_pro_inventory_depot')->where('id_pr_ti', $id)->delete();
            DB::table('new_pro_inventory_potongan_stock')->where('id_pr_ti', $id)->delete();
            DB::table('pro_pr_trucking_detail')->where('id_pr_ti', $id)->update(['vol_potongan' => null, 'nomor_lo_pr_ti' => null]);

            return response()->json(['message' => 'DR dikembalikan ke Pending Procurement.']);
        });
    }

    /** "Kembalikan Ke DP" (action/cek_status_dr_ti.php): detail dan DR dihapus, TI kembali status 1, baris rencana (pro_trucking_plan) dihapus agar Logistik membuat ulang. */
    public function returnToDp(Request $request, int $id): JsonResponse
    {
        if ($resp = $this->stepUp($request)) {
            return $resp;
        }

        return DB::transaction(function () use ($id, $request) {
            $h = $this->header($id);
            if (! $h) {
                return response()->json(['message' => 'Trucking Delivery Request tidak ditemukan.'], 404);
            }
            $rows = DB::table('pro_pr_trucking_detail as a')->leftJoin('pro_trucking_plan as p', 'a.id_plan', '=', 'p.id_plan')
                ->where('a.id_pr_ti', $id)->get(['a.id_prd_ti', 'a.id_plan', 'p.id_trucking']);
            if ((int) $h->disposisi_dr !== 0 || (int) $h->purchasing_result !== 0 || $rows->isEmpty()) {
                return response()->json(['message' => 'DR ini tidak bisa dikembalikan ke DP.', 'code' => 'NOT_PENDING'], 409);
            }
            DB::table('pro_pr_trucking_detail')->whereIn('id_prd_ti', $rows->pluck('id_prd_ti'))->delete();
            DB::table('pro_pr_trucking')->where('id_pr_ti', $id)->delete();
            DB::table('pro_trucking_instruction_req')->whereIn('id_master', $rows->pluck('id_trucking')->filter()->unique())->update(['status' => 1]);
            DB::table('pro_trucking_plan')->whereIn('id_plan', $rows->pluck('id_plan')->unique())->delete();

            ApprovalMail::toRoles([5], null, 'Revisi DR Trucking Instruction ke DP  [' . ApprovalMail::stamp() . ']',
                e($request->user()->fullname) . ' kembalikan status Trucking DR ke Trucking Delivery Plan logistik');

            return response()->json(['message' => 'DR dikembalikan ke delivery plan logistik.']);
        });
    }

    /** Konfirmasi password untuk sesi hasil login wajah sebelum menulis (menyangkut stok). Null = lolos. */
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
}
