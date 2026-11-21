<?php

namespace App\Http\Controllers;

use App\Services\ApprovalMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trucking Instruction Request, sisi Procurement (peran 5). Sumber: trucking-instruction.php (daftar), trucking-instruction-list.php (datatable),
 * trucking-instruction-add.php (form), web/action/trucking-instruction.php (add, update, cancel), get_data_trucking.php (pilihan),
 * trucking-instruction-cetak.php dan template (cetakan). Persetujuan CEO ada di TruckingController.
 * status: 0 Request Procurement, 1 diputuskan CEO, 2 Delivery Plan, 3 Delivery Request, 6 Cancel, 7 Received, 8 Request Logistik.
 */
class TruckingTiController extends Controller
{
    private const T = 'pro_trucking_instruction_req';

    private static function fail(string $m, string $c = 'INVALID', int $s = 422)
    {
        abort(response()->json(['message' => $m, 'code' => $c], $s));
    }

    private static function terminal(string $a = 'b'): string
    {
        return "concat({$a}.nama_terminal,' - ',{$a}.tanki_terminal,' - ',{$a}.lokasi_terminal)";
    }

    private function base()
    {
        return DB::table(self::T.' as a')
            ->join('pro_master_terminal as b', 'a.discharging_port', '=', 'b.id_master')
            ->join('pro_master_terminal as c', 'a.loading_port', '=', 'c.id_master')
            ->leftJoin('pro_master_transportir as d', 'a.id_transportir', '=', 'd.id_master');
    }

    /** Jumlah surat jalan/DS aktif dan yang belum direalisasi per TI (datatable web: loop per baris). */
    private function dsRingkas(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $rows = DB::table('pro_po_ds_trucking_detail as x')->join('pro_trucking_plan as y', 'x.id_plan', '=', 'y.id_plan')->whereIn('y.id_trucking', $ids)
            ->groupBy('y.id_trucking')
            ->selectRaw('y.id_trucking as id, count(*) as jumlah, sum(case when ifnull(x.is_cancel,0) <> 1 and (x.realisasi_volume is null or x.realisasi_volume = 0) then 1 else 0 end) as belum')->get();

        return $rows->keyBy('id')->map(fn ($r) => ['jumlah' => (int) $r->jumlah, 'belum' => (int) $r->belum])->all();
    }

    private static function statusTeks(object $a): array
    {
        $tgl = fn ($t) => $t ? date('d/m/Y H:i:s', strtotime($t)).' WIB' : '';
        $status = '';
        if ((int) $a->status === 0) {
            $status = 'Request Procurement';
        }
        if ((int) $a->status > 0) {
            $status = ((int) $a->ceo_result === 1 ? 'Terverifikasi CEO ' : 'Ditolak CEO ').$tgl($a->ceo_tanggal);
        }

        return [(int) $a->status === 6 ? 'Cancel' : '', $status];
    }

    public function index(Request $r): JsonResponse
    {
        $q = $this->base();
        if (($t = trim((string) $r->query('q', ''))) !== '') {
            $q->where(fn ($w) => $w->where('a.nomor_ti', 'like', "%{$t}%")->orWhere('b.nama_terminal', 'like', "%{$t}%")->orWhere('c.nama_terminal', 'like', "%{$t}%"));
        }
        $st = (string) $r->query('status', '');
        if ($st === 'belum_realisasi') {
            $q->where(fn ($w) => $w->whereNull('a.is_received')->orWhere('a.is_received', '<>', 1))->where('a.status', '<>', 6)
                ->whereExists(fn ($e) => $e->select(DB::raw(1))->from('pro_po_ds_trucking_detail as x')->join('pro_trucking_plan as y', 'x.id_plan', '=', 'y.id_plan')
                    ->whereColumn('y.id_trucking', 'a.id_master')->whereRaw('ifnull(x.is_cancel,0) <> 1')->where(fn ($z) => $z->whereNull('x.realisasi_volume')->orWhere('x.realisasi_volume', 0)));
        } elseif ($st === '1') {
            $q->where('a.status', '>', 0);
        } elseif ($st !== '') {
            $q->where('a.status', (int) $st);
        }
        $per = min(max((int) $r->query('per_page', 25), 1), (int) config('orca.per_page_max', 100));
        $page = $q->orderByDesc('a.tanggal_pengajuan')->orderByDesc('a.id_master')
            ->select(['a.*', DB::raw(self::terminal('b').' as discharge_port'), DB::raw(self::terminal('c').' as load_port'), DB::raw("concat(d.nama_suplier,', ',d.nama_transportir) as nama_transportir")])->paginate($per);
        $ds = $this->dsRingkas(collect($page->items())->pluck('id_master')->all());

        $data = collect($page->items())->map(function ($a) use ($ds) {
            $s = $ds[$a->id_master] ?? ['jumlah' => 0, 'belum' => 0];
            [$cancel, $status] = self::statusTeks($a);
            $badge = [];
            $terima = false;
            $ikon = 'ubah';
            if ((int) $a->status !== 0) {
                $ikon = 'lihat';
                if ((int) $a->ceo_result === 2) {
                    $ikon = 'ubah';
                }
                if ((int) $a->status === 8) {
                    $badge[] = ['teks' => 'Request Logistik', 'warna' => 'warning'];
                    $ikon = 'ubah';
                }
            }
            if ((int) $a->status === 2) {
                $badge[] = ['teks' => 'Delivery Plan', 'warna' => 'primary'];
            } elseif ((int) $a->status === 3) {
                $badge[] = ['teks' => 'Delivery Request', 'warna' => 'primary'];
            }
            if ($s['jumlah'] > 0) {
                $badge = array_values(array_filter($badge, fn ($b) => $b['teks'] === 'Request Logistik'));
                $badge[] = ['teks' => 'Loaded', 'warna' => 'primary'];
                $terima = true;
                if ($s['belum'] > 0 && (int) $a->is_received !== 1 && (int) $a->status !== 6) {
                    $badge[] = ['teks' => 'Belum Realisasi', 'warna' => 'warning'];
                }
            }
            if ((int) $a->is_received === 1) {
                $badge = array_values(array_filter($badge, fn ($b) => $b['teks'] !== 'Belum Realisasi'));
                $badge[] = ['teks' => 'Received', 'warna' => 'primary'];
                $terima = true;
            }

            return [
                'id' => (int) $a->id_master, 'nomor_ti' => $a->nomor_ti, 'tanggal' => $a->created_at, 'etl' => $a->etl_awal, 'load_port' => $a->load_port, 'discharge_port' => $a->discharge_port,
                'volume' => (int) $a->volume, 'cancel' => $cancel, 'is_cancel' => (int) $a->is_cancel === 1, 'status' => $status, 'badge' => $badge, 'ikon' => $ikon,
                'bisa_cetak' => (int) $a->status > 0 && (int) $a->ceo_result === 1, 'bisa_terima' => $terima, 'nama_transportir' => $a->nama_transportir,
            ];
        })->values();

        return response()->json(['data' => $data, 'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage()]]);
    }

    // ---- pilihan form (get_data_trucking.php) ----

    public function opsi(): JsonResponse
    {
        return response()->json([
            'cabang' => DB::table('pro_master_cabang')->orderBy('id_master')->get(['id_master', 'nama_cabang'])->map(fn ($c) => ['id' => (int) $c->id_master, 'label' => $c->nama_cabang])->values(),
            'terminal' => DB::table('pro_master_terminal as a')->join('pro_master_cabang as b', 'a.id_cabang', '=', 'b.id_master')->where('a.is_active', 1)->orderBy('a.id_master')
                ->get(['a.id_master', 'a.nama_terminal', 'a.tanki_terminal', 'a.lokasi_terminal'])
                ->map(fn ($t) => ['id' => (int) $t->id_master, 'label' => $t->nama_terminal.' - '.$t->tanki_terminal.' - '.$t->lokasi_terminal])->values(),
        ]);
    }

    /** Transporter menurut region: lokasi_suplier sama dengan nama cabang. */
    public function transportir(Request $r): JsonResponse
    {
        $nama = DB::table('pro_master_cabang')->where('id_master', (int) $r->query('id_wilayah'))->value('nama_cabang');
        $rows = $nama === null ? collect() : DB::table('pro_master_transportir')->where('lokasi_suplier', $nama)->orderBy('id_master')->get(['id_master', 'nama_suplier', 'nama_transportir', 'lokasi_suplier']);

        return response()->json(['data' => $rows->map(fn ($t) => ['id' => (int) $t->id_master, 'label' => $t->nama_suplier.' - '.$t->nama_transportir.' - '.$t->lokasi_suplier])->values()]);
    }

    /** Ongkos angkut menurut transporter dan volume yang persis sama dengan volume angkut master. Nilai: "id_wil,id_vol,ongkos". */
    public function ongkos(Request $r): JsonResponse
    {
        $rows = DB::table('pro_master_ongkos_angkut as a')
            ->join('pro_master_wilayah_angkut as b', 'a.id_wil_angkut', '=', 'b.id_master')->join('pro_master_provinsi as c', 'a.id_prov_angkut', '=', 'c.id_prov')
            ->join('pro_master_kabupaten as d', 'a.id_kab_angkut', '=', 'd.id_kab')->join('pro_master_volume_angkut as e', 'a.id_vol_angkut', '=', 'e.id_master')
            ->where('a.id_transportir', (int) $r->query('transportir'))->where('e.volume_angkut', (int) $r->query('volume'))
            ->get(['a.id_wil_angkut', 'a.id_vol_angkut', 'a.ongkos_angkut']);

        return response()->json(['data' => $rows->map(fn ($x) => ['value' => $x->id_wil_angkut.','.$x->id_vol_angkut.','.$x->ongkos_angkut, 'label' => number_format((float) $x->ongkos_angkut)])->values()]);
    }

    /** Nomor TI: NNN/PE-Purch/250/BULAN-ROMAWI/TAHUN, urut terbesar yang memuat tahun ini + 1. */
    private function nomorBaru(): string
    {
        $tahun = date('Y');
        $maks = (int) DB::table(self::T)->where('nomor_ti', 'like', '%'.$tahun.'%')->selectRaw("coalesce(max(cast(substring_index(nomor_ti, '/', 1) as unsigned)), 0) as n")->value('n');
        $urut = $maks + 1;
        $romawi = [1 => 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][(int) date('n')];

        return ($urut < 1000 ? str_pad((string) $urut, 3, '0', STR_PAD_LEFT) : (string) $urut).'/PE-Purch/250/'.$romawi.'/'.$tahun;
    }

    private function bentuk(object $a): array
    {
        $dapatUbah = (int) $a->is_cancel === 0 && (((int) $a->status <= 1 && (int) $a->ceo_result !== 1) || ((int) $a->status === 8 && (int) $a->is_reschedule === 1));

        return [
            'id' => (int) $a->id_master, 'nomor_ti' => $a->nomor_ti, 'status' => (int) $a->status, 'id_wilayah' => (int) $a->id_wilayah, 'tanggal' => $a->tanggal_pengajuan, 'volume' => (int) $a->volume,
            'load_port' => (int) $a->loading_port, 'discharge_port' => (int) $a->discharging_port, 'tipe_oa' => $a->tipe_oa, 'id_transportir' => (int) $a->id_transportir,
            'tgl_awal' => $a->etl_awal, 'tgl_akhir' => $a->etl_akhir, 'ongkos' => $a->id_wil_angkut.','.$a->id_vol_angkut.','.$a->ongkos_angkut, 'remarks' => $a->remarks,
            'ceo_result' => (int) $a->ceo_result, 'ceo_summary' => $a->ceo_summary, 'ceo_pic' => $a->ceo_pic, 'ceo_tanggal' => $a->ceo_tanggal,
            'is_cancel' => (int) $a->is_cancel === 1, 'ket_cancel' => $a->ket_cancel, 'tanggal_cancel' => $a->tanggal_cancel, 'is_reschedule' => (int) $a->is_reschedule === 1,
            'ket_reschedule' => $a->ket_reschedule, 'reschedule_tanggal' => $a->reschedule_tanggal, 'is_received' => (int) $a->is_received === 1,
            'bisa_ubah' => $dapatUbah, 'bisa_cancel' => (int) $a->is_cancel === 0 && (int) $a->status !== 7,
            'bisa_cetak' => (int) $a->status > 0 && (int) $a->ceo_result === 1,
        ];
    }

    public function show(int $id): JsonResponse
    {
        $a = DB::table(self::T)->where('id_master', $id)->first();
        if (! $a) {
            return response()->json(['message' => 'Trucking Instruction tidak ditemukan.'], 404);
        }

        return response()->json($this->bentuk($a));
    }

    public function baru(): JsonResponse
    {
        return response()->json(['nomor_ti' => $this->nomorBaru(), 'tanggal' => date('Y-m-d')]);
    }

    private function validasi(Request $r): array
    {
        $d = $r->validate([
            'id_wilayah' => ['required', 'integer', 'exists:pro_master_cabang,id_master'], 'tanggal' => ['required', 'date_format:Y-m-d'], 'volume' => ['required', 'integer', 'min:1'],
            'load_port' => ['required', 'integer', 'exists:pro_master_terminal,id_master'], 'discharge_port' => ['required', 'integer', 'exists:pro_master_terminal,id_master'],
            'tipe_oa' => ['required', 'in:Pro Energi,Third Party'], 'transportir' => ['required', 'integer', 'exists:pro_master_transportir,id_master'],
            'tgl_awal' => ['required', 'date_format:Y-m-d'], 'tgl_akhir' => ['required', 'date_format:Y-m-d', 'after_or_equal:tgl_awal'],
            'ongkos_angkut' => ['required', 'regex:/^\d+,\d+,\d+$/'], 'remarks' => ['required', 'string', 'max:255'],
        ]);
        [$d['id_wil_angkut'], $d['id_vol_angkut'], $d['ongkos']] = array_map('intval', explode(',', $d['ongkos_angkut']));

        return $d;
    }

    public function store(Request $request): JsonResponse
    {
        $d = $this->validasi($request);
        $user = $request->user();
        $nomor = $this->nomorBaru();
        $id = DB::table(self::T)->insertGetId([
            'id_wilayah' => $d['id_wilayah'], 'nomor_ti' => $nomor, 'tanggal_pengajuan' => $d['tanggal'], 'loading_port' => $d['load_port'], 'discharging_port' => $d['discharge_port'],
            'volume' => $d['volume'], 'etl_awal' => $d['tgl_awal'], 'etl_akhir' => $d['tgl_akhir'], 'tipe_oa' => $d['tipe_oa'], 'remarks' => $d['remarks'], 'id_transportir' => $d['transportir'],
            'id_wil_angkut' => $d['id_wil_angkut'], 'id_vol_angkut' => $d['id_vol_angkut'], 'ongkos_angkut' => $d['ongkos'], 'created_at' => now(), 'created_by' => $user->fullname,
        ]);
        ApprovalMail::toRoles([16], null, 'Persetujuan Trucking Instruction ['.$nomor.' - '.ApprovalMail::stamp().']',
            '<b>'.e($user->fullname).'</b> telah mengajukan <b>Trucking Instruction</b> dan meminta untuk melanjutkan proses persetujuan <b>Trucking Instruction</b>.<br><br>Nomor Trucking Instruction: '
            .e($nomor).'<br>Tanggal Pengajuan: '.PoSupplierController::tglIndo(date('Y-m-d')).'<br>Diajukan Oleh: '.e($user->fullname));

        return response()->json(['id' => (int) $id, 'nomor_ti' => $nomor, 'message' => 'Trucking Instruction tersimpan.'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $a = DB::table(self::T)->where('id_master', $id)->first();
        if (! $a) {
            self::fail('Trucking Instruction tidak ditemukan.', 'NOT_FOUND', 404);
        }
        if (! $this->bentuk($a)['bisa_ubah']) {
            self::fail('Trucking Instruction ini tidak dapat diubah.', 'TIDAK_BISA_UBAH', 409);
        }
        $d = $this->validasi($request);
        // web: ubah mengembalikan ke Request Procurement (status 0), persetujuan CEO dikosongkan; etl_akhir tidak ikut diubah
        DB::table(self::T)->where('id_master', $id)->update([
            'tanggal_pengajuan' => $d['tanggal'], 'id_wilayah' => $d['id_wilayah'], 'status' => 0, 'loading_port' => $d['load_port'], 'discharging_port' => $d['discharge_port'],
            'id_transportir' => $d['transportir'], 'id_wil_angkut' => $d['id_wil_angkut'], 'id_vol_angkut' => $d['id_vol_angkut'], 'remarks' => $d['remarks'], 'ongkos_angkut' => $d['ongkos'],
            'volume' => $d['volume'], 'etl_awal' => $d['tgl_awal'], 'tipe_oa' => $d['tipe_oa'], 'updated_at' => now(), 'updated_by' => $request->user()->fullname,
            'ceo_result' => 0, 'ceo_pic' => null, 'ceo_summary' => null,
        ]);

        return response()->json(['message' => 'Trucking Instruction diubah.']);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $a = DB::table(self::T)->where('id_master', $id)->first();
        if (! $a) {
            self::fail('Trucking Instruction tidak ditemukan.', 'NOT_FOUND', 404);
        }
        if (! $this->bentuk($a)['bisa_cancel']) {
            self::fail('Trucking Instruction ini tidak dapat di-cancel.', 'TIDAK_BISA_CANCEL', 409);
        }
        $d = $request->validate(['ket_cancel' => ['required', 'string', 'max:2000']]);
        DB::table(self::T)->where('id_master', $id)->update(['is_cancel' => 1, 'ket_cancel' => $d['ket_cancel'], 'status' => 6, 'tanggal_cancel' => now()]);

        return response()->json(['message' => 'Trucking Instruction di-cancel.']);
    }

    /** Data cetakan Trucking Instruction (template trucking-instruction.php). Tombol cetak web: status > 0 dan disetujui CEO. */
    public function cetak(Request $request, int $id): JsonResponse
    {
        $a = DB::table(self::T.' as a')
            ->join('pro_master_terminal as b', 'a.discharging_port', '=', 'b.id_master')->join('pro_master_terminal as c', 'a.loading_port', '=', 'c.id_master')
            ->join('pro_master_cabang as d', 'b.id_cabang', '=', 'd.id_master')->join('pro_master_cabang as e', 'c.id_cabang', '=', 'e.id_master')
            ->join('pro_master_transportir as f', 'a.id_transportir', '=', 'f.id_master')->join('pro_master_volume_angkut as g', 'a.id_vol_angkut', '=', 'g.id_master')
            ->where('a.id_master', $id)
            ->first(['a.*', DB::raw("concat(b.nama_terminal,' - ',d.nama_cabang) as discharge_port"), DB::raw("concat(c.nama_terminal,' - ',e.nama_cabang) as load_port"),
                DB::raw("concat(f.nama_suplier,', ',f.nama_transportir) as nama_transportir"), 'g.volume_angkut']);
        if (! $a) {
            return response()->json(['message' => 'Trucking Instruction tidak ditemukan.'], 404);
        }
        if (! ((int) $a->status > 0 && (int) $a->ceo_result === 1)) {
            return response()->json(['message' => 'Trucking Instruction baru dapat dicetak setelah disetujui CEO.', 'code' => 'BELUM_DISETUJUI'], 409);
        }
        $spj = ! Schema::hasTable('pro_po_trucking_detail') ? collect() : DB::table('pro_po_trucking_detail as c')
            ->join('pro_trucking_plan as d', 'd.id_plan', '=', 'c.id_plan')
            ->join('pro_po_ds_trucking_detail as f', fn ($j) => $j->on('f.id_plan', '=', 'd.id_plan')->where(fn ($w) => $w->where('f.is_cancel', 0)->orWhereNull('f.is_cancel')))
            ->where('d.id_trucking', $id)->pluck('c.no_spj_ti')->filter()->unique()->values();
        $tgl = PoSupplierController::class;

        return response()->json([
            'nomor' => $a->nomor_ti, 'tanggal' => $tgl::tglIndo($a->created_at), 'kapal_truk' => $a->nama_transportir.' / '.number_format((float) $a->volume_angkut).' L',
            'transporter' => $a->nama_transportir, 'volume_angkut' => number_format((float) $a->volume_angkut), 'load_port' => $a->load_port, 'discharge_port' => $a->discharge_port,
            'loading_date' => $tgl::tglIndo($a->etl_awal), 'qty' => number_format((float) $a->volume), 'no_sj' => $spj, 'remarks' => $a->remarks, 'dicetak' => $request->user()->fullname.' '.date('d/m/Y H:i:s').' WIB',
        ]);
    }
}
