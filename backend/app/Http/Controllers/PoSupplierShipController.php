<?php

namespace App\Http\Controllers;

use App\Services\ApprovalMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shipping Instruction Request pada PO Supplier kapal (jenis kirim Ship), Procurement.
 * Sumber: vendor-po-ship-req.php (halaman) dan web/action/vendor-po-ship-req.php (aksi add, update, cancel).
 * Setelah dibuat, permintaan diproses Logistik (nomor SI), lalu CFO dan CEO (modul Shipping Instruction CEO yang sudah ada).
 */
class PoSupplierShipController extends Controller
{
    private const SR = 'new_pro_inventory_vendor_po_ship_req';

    private static function fail(string $m, string $c = 'INVALID', int $s = 422)
    {
        abort(response()->json(['message' => $m, 'code' => $c], $s));
    }

    private function po(int $id): object
    {
        $po = DB::table('new_pro_inventory_vendor_po')->where('id_master', $id)->first();
        if (! $po) {
            self::fail('PO Supplier tidak ditemukan.', 'NOT_FOUND', 404);
        }
        if ((int) $po->jenis_kirim !== 2) {
            self::fail('Shipping request hanya untuk PO dengan jenis kirim Ship.', 'BUKAN_KAPAL', 409);
        }

        return $po;
    }

    private static function terkunci(object $po): bool
    {
        return (int) $po->is_close === 1 || (int) $po->is_cancel === 1;
    }

    private function aktif(int $idPo): ?object
    {
        return DB::table(self::SR)->where('id_vendor_po', $idPo)->where('is_cancel', 0)->orderByDesc('id_master')->first();
    }

    /** Nomor request: NNN/PE-Purch/250/BULAN-ROMAWI/TAHUN, urut per tahun (web: nomor terbesar yang memuat tahun ini + 1). */
    private function nomorReq(): string
    {
        $tahun = date('Y');
        $maks = (int) DB::table(self::SR)->where('nomor_req', 'like', '%'.$tahun.'%')
            ->selectRaw("coalesce(max(cast(substring_index(nomor_req, '/', 1) as unsigned)), 0) as n")->value('n');
        $urut = $maks + 1;
        $romawi = [1 => 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][(int) date('n')];

        return ($urut < 1000 ? str_pad((string) $urut, 3, '0', STR_PAD_LEFT) : (string) $urut).'/PE-Purch/250/'.$romawi.'/'.$tahun;
    }

    public function kapal(Request $r): JsonResponse
    {
        $q = DB::table('pro_master_oa_kapal')->where('id_transportir', (int) $r->query('transportir'))->orderBy('nama_kapal');

        return response()->json(['data' => $q->get(['id_master', 'nama_kapal', 'tipe_kapal'])->map(fn ($k) => ['id' => (int) $k->id_master, 'nama' => trim($k->nama_kapal), 'tipe' => trim((string) $k->tipe_kapal)])->values()]);
    }

    private static function langkah(object $s): array
    {
        $baris = fn ($peran, $status, $detail, $catatan, $tgl) => ['peran' => $peran, 'status' => (int) $status, 'detail' => $detail, 'catatan' => $catatan, 'tanggal' => $tgl];

        return [
            $baris($s->created_by, 1, 'Shipping Request Created', $s->ket_ship, $s->created_at),
            $baris($s->log_pic, $s->nomor_si !== null ? 1 : 0, 'PO Shipping Instruction', $s->ket_log, $s->log_tanggal),
            $baris($s->cfo_pic, $s->cfo_result, 'CFO', $s->cfo_summary, $s->cfo_tanggal),
            $baris($s->ceo_pic, $s->ceo_result, 'CEO', $s->ceo_summary, $s->ceo_tanggal),
        ];
    }

    private function bentuk(object $s): array
    {
        $satuan = Schema::hasColumn(self::SR, 'satuan');

        return [
            'id' => (int) $s->id_master, 'nomor_req' => $s->nomor_req, 'nomor_si' => $s->nomor_si, 'status' => (int) $s->status, 'cfo_result' => (int) $s->cfo_result, 'ceo_result' => (int) $s->ceo_result,
            'id_transportir' => (int) $s->id_transportir, 'tipe_kapal' => (int) $s->tipe_kapal, 'id_vessel_tb' => (int) $s->id_vessel_tb ?: null, 'id_vessel' => (int) $s->id_vessel ?: null,
            'load_port' => (int) $s->loading_port, 'discharge_terminal_id' => (int) $s->id_terminal_discharging, 'flag' => $s->flag, 'volume' => (float) $s->quantity,
            'tgl_etl_awal' => $s->etl_date_first, 'tgl_etl_akhir' => $s->etl_date_last, 'cargo' => $s->cargo_name, 'leadtime' => (int) $s->leadtime, 'country' => $s->country_origin,
            'freight' => (float) $s->freight, 'demurrage' => (int) $s->demurrage, 'losstype' => $s->losstype, 'loss' => (float) $s->loss_tolerance, 'satuan' => $satuan ? ($s->satuan ?? '') : '',
            'shipper' => $s->shipper, 'signee_name' => $s->consignee, 'bl_ship' => $s->bl_ship, 'ket_ship' => $s->ket_ship, 'langkah' => self::langkah($s),
        ];
    }

    public function show(int $id): JsonResponse
    {
        $po = $this->po($id);
        $s = $this->aktif($id);
        $terkunci = self::terkunci($po);
        $disetujui = (int) $po->ceo_result === 1 && (int) ($po->revert_ceo ?? 0) === 0;

        return response()->json([
            'po' => ['id' => (int) $po->id_master, 'nomor_po' => $po->nomor_po, 'volume_po' => (float) $po->volume_po, 'terkunci' => $terkunci],
            'ada' => (bool) $s,
            'request' => $s ? $this->bentuk($s) : null,
            'baru' => ['nomor_req' => $this->nomorReq(), 'volume' => (float) $po->volume_po, 'flag' => 'INDONESIA', 'country' => 'Indonesia'],
            'bisa_tambah' => ! $s && $disetujui && ! $terkunci,
            'bisa_ubah' => (bool) $s && ! $terkunci && ((int) $s->status === 0 || (int) $s->cfo_result === 2 || (int) $s->ceo_result === 2),
            'bisa_cetak' => (bool) $s && ((int) $s->status === 4 || (int) $s->ceo_result === 1),
            'bisa_cancel' => (bool) $s && ! $terkunci && ((int) $s->status === 4 || (int) $s->ceo_result === 1),
            'opsi' => [
                'transportir' => DB::table('pro_master_transportir')->where('tipe_angkutan', 2)->orderBy('id_master')->get(['id_master', 'nama_suplier'])->map(fn ($t) => ['id' => (int) $t->id_master, 'label' => $t->nama_suplier])->values(),
                'terminal' => DB::table('pro_master_terminal')->where('is_active', 1)->orderBy('id_master')->get(['id_master', 'nama_terminal', 'tanki_terminal', 'lokasi_terminal'])
                    ->map(fn ($t) => ['id' => (int) $t->id_master, 'label' => $t->nama_terminal.' - '.$t->tanki_terminal.' - '.$t->lokasi_terminal])->values(),
            ],
        ]);
    }

    private function validasi(Request $r): array
    {
        return $r->validate([
            'id_transportir' => ['required', 'integer', 'exists:pro_master_transportir,id_master'], 'tipe_kapal' => ['required', 'in:1,2'],
            'id_vessel' => ['required', 'integer', 'exists:pro_master_oa_kapal,id_master'], 'id_vessel_tb' => ['required_if:tipe_kapal,1', 'nullable', 'integer', 'exists:pro_master_oa_kapal,id_master'],
            'load_port' => ['required', 'integer', 'exists:pro_master_terminal,id_master'], 'discharge_terminal_id' => ['required', 'integer', 'exists:pro_master_terminal,id_master'],
            'volume' => ['required', 'integer', 'min:1'], 'flag' => ['nullable', 'string', 'max:200'], 'tgl_etl_awal' => ['required', 'date_format:Y-m-d'],
            'tgl_etl_akhir' => ['required', 'date_format:Y-m-d', 'after_or_equal:tgl_etl_awal'], 'cargo' => ['required', 'string', 'max:255'], 'leadtime' => ['required', 'integer', 'min:1'],
            'country' => ['required', 'string', 'max:200'], 'freight' => ['required', 'numeric', 'gt:0'], 'demurrage' => ['required', 'integer', 'min:1'],
            'losstype' => ['required', 'in:R1,R2,R3,R4'], 'loss' => ['required', 'numeric', 'gt:0'], 'satuan' => ['required', 'in:GOV,GSV'],
            'shipper' => ['required', 'string', 'max:200'], 'signee_name' => ['required', 'string', 'max:200'], 'bl_ship' => ['required', 'string', 'max:5000'], 'ket_ship' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    private function kolom(array $d): array
    {
        $k = [
            'id_transportir' => $d['id_transportir'], 'tipe_kapal' => $d['tipe_kapal'], 'id_vessel_tb' => (int) $d['tipe_kapal'] === 1 ? ($d['id_vessel_tb'] ?? 0) : 0, 'id_vessel' => $d['id_vessel'],
            'id_terminal_discharging' => $d['discharge_terminal_id'], 'quantity' => $d['volume'], 'loading_port' => $d['load_port'], 'etl_date_first' => $d['tgl_etl_awal'], 'etl_date_last' => $d['tgl_etl_akhir'],
            'cargo_name' => $d['cargo'], 'leadtime' => $d['leadtime'], 'losstype' => $d['losstype'], 'loss_tolerance' => $d['loss'], 'freight' => $d['freight'], 'demurrage' => $d['demurrage'],
            'ket_ship' => $d['ket_ship'] ?? '', 'country_origin' => $d['country'], 'shipper' => $d['shipper'], 'consignee' => $d['signee_name'], 'bl_ship' => $d['bl_ship'],
        ];
        if (Schema::hasColumn(self::SR, 'satuan')) {
            $k['satuan'] = $d['satuan'];
        }

        return $k;
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $po = $this->po($id);
        if (self::terkunci($po) || (int) $po->ceo_result !== 1 || (int) ($po->revert_ceo ?? 0) !== 0) {
            self::fail('PO ini belum disetujui atau sudah close/cancel.', 'PO_TERKUNCI', 409);
        }
        if ($this->aktif($id)) {
            self::fail('PO ini sudah punya shipping request yang aktif.', 'SUDAH_ADA', 409);
        }
        $d = $this->validasi($request);
        $user = $request->user();
        $nomor = $this->nomorReq();
        $idSr = DB::table(self::SR)->insertGetId($this->kolom($d) + [
            'id_vendor_po' => $id, 'flag' => $d['flag'] ?? 'INDONESIA', 'nomor_req' => $nomor, 'created_at' => now(), 'created_by' => $user->fullname,
        ]);
        ApprovalMail::toRoles([16], null, 'Shipping Instruction Request['.$nomor.' - '.ApprovalMail::stamp().']',
            '<b>'.e($user->fullname).'</b> telah mengajukan <b>Shipping Request</b> dan meminta untuk melanjutkan proses <b>Shipping Instruction</b>.<br><br>Nomor Request: '.e($nomor)
            .'<br>Tanggal Pengajuan: '.ApprovalMail::stamp().'<br>Diajukan Oleh: '.e($user->fullname));

        return response()->json(['id' => (int) $idSr, 'nomor_req' => $nomor, 'message' => 'Shipping request tersimpan.'], 201);
    }

    private function srUbah(int $id, int $sr): array
    {
        $po = $this->po($id);
        $s = DB::table(self::SR)->where('id_master', $sr)->where('id_vendor_po', $id)->where('is_cancel', 0)->first();
        if (! $s) {
            self::fail('Shipping request tidak ditemukan.', 'NOT_FOUND', 404);
        }

        return [$po, $s];
    }

    public function update(Request $request, int $id, int $sr): JsonResponse
    {
        [$po, $s] = $this->srUbah($id, $sr);
        if (self::terkunci($po) || ! ((int) $s->status === 0 || (int) $s->cfo_result === 2 || (int) $s->ceo_result === 2)) {
            self::fail('Shipping request ini sedang diproses dan tidak dapat diubah.', 'TIDAK_BISA_UBAH', 409);
        }
        $d = $this->validasi($request);
        // Setiap perubahan mengulang proses dari awal (status 0, nomor SI dan seluruh persetujuan dikosongkan), seperti web.
        DB::table(self::SR)->where('id_master', $sr)->update($this->kolom($d) + [
            'flag' => 'INDONESIA', 'nomor_si' => null, 'updated_at' => now(), 'updated_by' => $request->user()->fullname, 'status' => 0, 'ket_log' => null, 'log_pic' => null, 'log_tanggal' => null,
            'mgrfin_result' => null, 'mgrfin_pic' => null, 'mgrfin_tanggal' => null, 'mgrfin_summary' => null, 'cfo_result' => 0, 'cfo_pic' => null, 'cfo_tanggal' => null, 'cfo_summary' => null,
            'ceo_result' => 0, 'ceo_pic' => null, 'ceo_tanggal' => null, 'ceo_summary' => null,
        ]);

        return response()->json(['message' => 'Shipping request diubah.']);
    }

    public function cancel(Request $request, int $id, int $sr): JsonResponse
    {
        [$po, $s] = $this->srUbah($id, $sr);
        if (self::terkunci($po) || ! ((int) $s->status === 4 || (int) $s->ceo_result === 1)) {
            self::fail('Shipping request ini tidak dapat di-cancel.', 'TIDAK_BISA_CANCEL', 409);
        }
        $d = $request->validate(['ket_cancel' => ['required', 'string', 'max:2000']]);
        DB::table(self::SR)->where('id_master', $sr)->update(['is_cancel' => 1, 'ket_cancel' => $d['ket_cancel']]);

        return response()->json(['message' => 'Shipping request di-cancel.']);
    }

    /**
     * Data cetakan Shipping Instruction (shipping-instruction-cetak.php, tipe shipping_instruction, template/shipping-instruction.php).
     * Tombol Cetak di web hanya muncul bila status 4 atau CEO menyetujui.
     */
    public function cetak(Request $request, int $id, int $sr): JsonResponse
    {
        $this->po($id);
        $s = DB::table(self::SR.' as a')
            ->leftJoin('pro_master_oa_kapal as c', 'a.id_vessel', '=', 'c.id_master')
            ->leftJoin('pro_master_oa_kapal as h', 'a.id_vessel_tb', '=', 'h.id_master')
            ->leftJoin('pro_master_transportir as f', 'c.id_transportir', '=', 'f.id_master')
            ->where('a.id_master', $sr)->where('a.id_vendor_po', $id)
            ->first(['a.*', 'c.nama_kapal', 'c.tipe_kapal as tipe_vessel', 'c.asal_angkut', 'c.tujuan_angkut', 'h.nama_kapal as tb_nama', 'h.tipe_kapal as tb_tipe', 'f.nama_suplier', 'f.alamat_suplier']);
        if (! $s) {
            return response()->json(['message' => 'Shipping request tidak ditemukan.'], 404);
        }
        if (! ((int) $s->status === 4 || (int) $s->ceo_result === 1)) {
            return response()->json(['message' => 'Shipping Instruction baru dapat dicetak setelah disetujui.', 'code' => 'BELUM_DISETUJUI'], 409);
        }
        $tgl = PoSupplierController::class;
        $a = $s->etl_date_first;
        $b = $s->etl_date_last;
        // web: perbandingan bulan selalu benar (kurung salah), jadi: tanggal sama -> satu tanggal; selain itu "dd - tanggal akhir lengkap"
        $laycan = date('d', strtotime($a)) === date('d', strtotime($b)) ? $tgl::tglIndo($a) : date('d', strtotime($a)).' - '.$tgl::tglIndo($b);
        $kapal = trim($s->tipe_vessel.' '.$s->nama_kapal);
        if ((int) $s->tipe_kapal === 1) {
            $kapal .= ' & '.trim($s->tb_tipe.' '.$s->tb_nama);
        }

        return response()->json([
            'nomor' => $s->nomor_req, 'tanggal' => $tgl::tglIndo($s->created_at), 'kepada' => $s->nama_suplier, 'alamat' => $s->alamat_suplier, 'produk' => $s->cargo_name,
            'kapal' => $kapal, 'laycan' => $laycan, 'quantity' => number_format((float) $s->quantity).' ('.(Schema::hasColumn(self::SR, 'satuan') ? ($s->satuan ?? '') : '').')',
            'shipper' => $s->shipper, 'consignee' => $s->consignee, 'port_loading' => $s->asal_angkut, 'port_discharge' => $s->tujuan_angkut,
            'penandatangan' => 'Raniah Suhaimah', 'jabatan' => 'Management Procurement',
        ]);
    }
}
