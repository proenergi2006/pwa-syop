<?php

namespace App\Http\Controllers;

use App\Services\PasswordGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Shipping Instruction (tabel new_pro_inventory_vendor_po_ship_req), tahap akhir persetujuan CEO.
 * Alur web: Request Procurement (0) -> Verifikasi CFO (1) -> Verifikasi CEO (2) -> selesai (3).
 * ceo_result: 1 = disetujui, 2 = ditolak.
 */
class ShippingController extends Controller
{
    private function base()
    {
        return DB::table('new_pro_inventory_vendor_po_ship_req as s')
            ->leftJoin('new_pro_inventory_vendor_po as po', 's.id_vendor_po', '=', 'po.id_master')
            ->leftJoin('pro_master_vendor as v', 'po.id_vendor', '=', 'v.id_master')
            ->leftJoin('pro_master_transportir as tr', 's.id_transportir', '=', 'tr.id_master')
            ->leftJoin('pro_master_oa_kapal as k', 's.id_vessel', '=', 'k.id_master')
            ->leftJoin('pro_master_oa_kapal as tb', 's.id_vessel_tb', '=', 'tb.id_master')
            ->leftJoin('pro_master_transportir as tl', 'k.id_transportir', '=', 'tl.id_master')
            ->leftJoin('pro_master_terminal as lp', 's.loading_port', '=', 'lp.id_master')
            ->leftJoin('pro_master_terminal as dp', 's.id_terminal_discharging', '=', 'dp.id_master')
            ->where('s.is_cancel', 0);
    }

    public function waitingCount(): int
    {
        return self::waiting($this->base())->count();
    }

    private function cols(bool $detail = false): array
    {
        $c = ['s.id_master as id', 's.nomor_req', 's.nomor_si', 's.status', 's.cfo_result', 's.ceo_result', 's.quantity', 's.cargo_name',
            's.etl_date_first', 's.etl_date_last', 's.created_at', 's.ceo_tanggal', 's.cfo_tanggal',
            'po.nomor_po', 'v.nama_vendor', 'tr.nama_transportir', 'k.nama_kapal as vessel', 'tb.nama_kapal as vessel_tb',
            'lp.nama_terminal as loading_nama', 'dp.nama_terminal as discharging_nama'];
        if ($detail) {
            $c = array_merge($c, ['s.tipe_kapal', 's.flag', 's.losstype', 's.loss_tolerance', 's.freight', 's.demurrage', 's.leadtime',
                's.country_origin', 's.shipper', 's.consignee', 's.bl_ship', 's.ket_ship', 's.ket_log', 's.log_pic', 's.log_tanggal',
                's.cfo_pic', 's.cfo_summary', 's.ceo_pic', 's.ceo_summary', 'po.volume_po', 'po.harga_po',
                'lp.tanki_terminal as loading_tanki', 'lp.lokasi_terminal as loading_lokasi', 'dp.tanki_terminal as discharging_tanki', 'dp.lokasi_terminal as discharging_lokasi',
                'k.tipe_kapal as vessel_tipe', 'tb.tipe_kapal as vessel_tb_tipe', 'k.max_kapal as vessel_max',
                'k.asal_angkut', 'k.tujuan_angkut', 'tl.nama_suplier as shipping_line']);
        }

        return $c;
    }

    private static function waiting($q)
    {
        return $q->where('s.status', 2)->where('s.cfo_result', 1)->where(fn ($w) => $w->whereNull('s.ceo_result')->orWhere('s.ceo_result', 0));
    }

    /** Catatan di web disimpan ter-escape (ENT_QUOTES, baris baru <br />); dibaca kembali apa adanya. */
    private static function plain(?string $s): ?string
    {
        return $s === null ? null : html_entity_decode(str_replace(['<br />', '<br>'], "\n", $s), ENT_QUOTES);
    }

    public function index(Request $r): JsonResponse
    {
        $q = $this->base();
        $tab = $r->query('tab', 'wait');
        if ($tab === 'wait') {
            self::waiting($q);
        } elseif ($tab === 'done') {
            $q->where('s.status', 3);
        }
        if ($term = trim((string) $r->query('q', ''))) {
            $q->where(fn ($w) => $w->where('s.nomor_req', 'like', "%{$term}%")->orWhere('s.nomor_si', 'like', "%{$term}%")
                ->orWhere('po.nomor_po', 'like', "%{$term}%")->orWhere('v.nama_vendor', 'like', "%{$term}%"));
        }
        $per = min(max((int) $r->query('per_page', 20), 1), (int) config('orca.per_page_max'));
        $page = $q->orderByDesc('s.created_at')->select($this->cols())->paginate($per);
        $waiting = self::waiting($this->base())->count();

        return response()->json([
            'data' => $page->items(),
            'waiting' => $waiting,
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage()],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $row = $this->base()->where('s.id_master', $id)->select($this->cols(true))->first();
        if (! $row) {
            return response()->json(['message' => 'Shipping Instruction tidak ditemukan.'], 404);
        }
        foreach (['ket_ship', 'ket_log', 'cfo_summary', 'ceo_summary', 'bl_ship'] as $k) {
            $row->$k = self::plain($row->$k ?? null);
        }
        $row->can_decide = $this->canDecide($row);

        return response()->json($row);
    }

    private function canDecide($row): bool
    {
        return (int) $row->status === 2 && (int) $row->cfo_result === 1 && (int) ($row->ceo_result ?? 0) === 0;
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'keputusan' => ['required', 'in:approve,reject'],
            'catatan'   => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();
        if (! $user->tokenCan('*')) { // login wajah hanya baca; memutuskan wajib konfirmasi password
            $password = (string) $request->input('password', '');
            if ($password === '') {
                return response()->json(['message' => 'Masukkan kata sandi untuk memutuskan.', 'code' => 'STEP_UP_REQUIRED'], 403);
            }
            if (! PasswordGate::check($user, $password, $request)) {
                return PasswordGate::locked($user)
                    ? response()->json(['message' => 'Terlalu banyak salah kata sandi. Coba lagi 15 menit lagi.', 'code' => 'PASSWORD_LOCKED'], 429)
                    : response()->json(['message' => 'Kata sandi salah.', 'code' => 'STEP_UP_FAILED'], 422);
            }
        }
        if ($data['keputusan'] === 'reject' && trim((string) ($data['catatan'] ?? '')) === '') {
            return response()->json(['message' => 'Alasan penolakan wajib diisi.', 'code' => 'NOTE_REQUIRED'], 422);
        }

        return DB::transaction(function () use ($id, $data, $user) {
            $row = $this->base()->where('s.id_master', $id)->lockForUpdate()->select($this->cols())->first();
            if (! $row) {
                return response()->json(['message' => 'Shipping Instruction tidak ditemukan.'], 404);
            }
            if (! $this->canDecide($row)) {
                return response()->json(['message' => 'Shipping Instruction ini tidak sedang menunggu keputusan CEO.', 'code' => 'NOT_PENDING'], 409);
            }
            DB::table('new_pro_inventory_vendor_po_ship_req')->where('id_master', $id)->update([
                'ceo_result'  => $data['keputusan'] === 'approve' ? 1 : 2,
                'ceo_summary' => htmlspecialchars(trim((string) ($data['catatan'] ?? '')), ENT_QUOTES),
                'ceo_pic'     => $user->fullname,
                'ceo_tanggal' => now(),
                'status'      => 3,
                'updated_at'  => now(),
                'updated_by'  => $user->fullname,
            ]);

            return response()->json(['message' => $data['keputusan'] === 'approve' ? 'Shipping Instruction disetujui.' : 'Shipping Instruction ditolak.']);
        });
    }
}
