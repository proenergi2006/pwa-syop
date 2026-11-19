<?php

namespace App\Http\Controllers;

use App\Services\ApprovalMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Trucking Instruction (pro_trucking_instruction_req), persetujuan CEO.
 * Sumber: trucking-instruction (1).php (daftar), trucking-instruction-detail.php, trucking-instruction.php (aksi approve).
 * status: 0 Request Procurement, 1 diputuskan CEO, 2 Delivery Plan, 3 Delivery Request, 5 Loaded, 6 Cancel, 7 Received, 8 Request Logistik.
 * ceo_result: 1 = disetujui, 2 = ditolak. Hanya status 0 yang menunggu keputusan CEO.
 * Email notifikasi yang dikirim web saat setuju belum dibuat di sini.
 */
class TruckingController extends Controller
{
    private const T = 'pro_trucking_instruction_req';

    private function base()
    {
        return DB::table(self::T.' as a')
            ->leftJoin('pro_master_terminal as b', 'a.discharging_port', '=', 'b.id_master')
            ->leftJoin('pro_master_terminal as c', 'a.loading_port', '=', 'c.id_master')
            ->leftJoin('pro_master_transportir as d', 'a.id_transportir', '=', 'd.id_master')
            ->leftJoin('pro_master_cabang as e', 'a.id_wilayah', '=', 'e.id_master');
    }

    private function cols(bool $detail = false): array
    {
        $c = ['a.id_master as id', 'a.nomor_ti', 'a.status', 'a.ceo_result', 'a.is_cancel', 'a.is_received', 'a.volume', 'a.tanggal_pengajuan', 'a.etl_awal',
            'a.created_at', 'a.ceo_tanggal', 'e.nama_cabang',
            DB::raw("concat_ws(' - ', c.nama_terminal, nullif(c.tanki_terminal, ''), nullif(c.lokasi_terminal, '')) as load_port"),
            DB::raw("concat_ws(' - ', b.nama_terminal, nullif(b.tanki_terminal, ''), nullif(b.lokasi_terminal, '')) as discharge_port"),
            'c.nama_terminal as loading_nama', 'b.nama_terminal as discharging_nama'];
        if ($detail) {
            $c = array_merge($c, ['a.ongkos_angkut', 'a.remarks', 'a.etl_akhir', 'a.realisasi_volume', 'a.ket_cancel', 'a.tanggal_cancel', 'a.ceo_pic', 'a.ceo_summary', 'a.created_by',
                DB::raw("concat_ws(', ', d.nama_suplier, d.nama_transportir) as nama_transportir")]);
        } else {
            $c[] = DB::raw("concat_ws(', ', d.nama_suplier, d.nama_transportir) as nama_transportir");
        }

        return $c;
    }

    private static function waiting($q)
    {
        return $q->where('a.status', 0)->where(fn ($w) => $w->whereNull('a.is_cancel')->orWhere('a.is_cancel', 0));
    }

    public function waitingCount(): int
    {
        return self::waiting($this->base())->count();
    }

    /** @return array<int,string> id => nomor TI */
    public function waitingItems(): array
    {
        return self::waiting($this->base())->orderByDesc('a.id_master')->limit(500)
            ->get(['a.id_master as id', 'a.nomor_ti as label'])->pluck('label', 'id')->all();
    }

    /** Catatan disimpan seperti web: di-escape HTML (ENT_QUOTES); dibaca kembali apa adanya. */
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
            $q->where('a.status', '>', 0);
        }
        if ($term = trim((string) $r->query('q', ''))) {
            $q->where(fn ($w) => $w->where('a.nomor_ti', 'like', "%{$term}%")->orWhere('b.nama_terminal', 'like', "%{$term}%")
                ->orWhere('c.nama_terminal', 'like', "%{$term}%")->orWhere('d.nama_transportir', 'like', "%{$term}%"));
        }
        $per = min(max((int) $r->query('per_page', 20), 1), (int) config('orca.per_page_max'));
        $page = $q->orderBy('a.status')->orderByDesc('a.id_master')->select($this->cols())->paginate($per);

        return response()->json([
            'data' => $page->items(),
            'waiting' => $this->waitingCount(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage()],
        ]);
    }

    private function canDecide($row): bool
    {
        return (int) $row->status === 0 && (int) ($row->is_cancel ?? 0) === 0 && (int) ($row->ceo_result ?? 0) === 0;
    }

    public function show(int $id): JsonResponse
    {
        $row = $this->base()->where('a.id_master', $id)->select($this->cols(true))->first();
        if (! $row) {
            return response()->json(['message' => 'Trucking Instruction tidak ditemukan.'], 404);
        }
        $row->ceo_summary = self::plain($row->ceo_summary);
        $row->can_decide = $this->canDecide($row);

        return response()->json($row);
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'keputusan' => ['required', 'in:approve,reject'],
            'catatan'   => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();

        if ($data['keputusan'] === 'reject' && trim((string) ($data['catatan'] ?? '')) === '') {
            return response()->json(['message' => 'Alasan penolakan wajib diisi.', 'code' => 'NOTE_REQUIRED'], 422);
        }

        return DB::transaction(function () use ($id, $data, $user) {
            $row = $this->base()->where('a.id_master', $id)->lockForUpdate()->select($this->cols())->first();
            if (! $row) {
                return response()->json(['message' => 'Trucking Instruction tidak ditemukan.'], 404);
            }
            if (! $this->canDecide($row)) {
                return response()->json(['message' => 'Trucking Instruction ini tidak sedang menunggu keputusan CEO.', 'code' => 'NOT_PENDING'], 409);
            }
            $note = trim((string) ($data['catatan'] ?? ''));
            DB::table(self::T)->where('id_master', $id)->update([
                'ceo_result'  => $data['keputusan'] === 'approve' ? 1 : 2,
                'ceo_summary' => $note === '' ? '' : htmlspecialchars($note, ENT_QUOTES),
                'ceo_pic'     => $user->fullname,
                'ceo_tanggal' => now(),
                'status'      => 1,
                'updated_at'  => now(),
                'updated_by'  => $user->fullname,
            ]);

            if ($data['keputusan'] === 'approve') {
                $tag = $row->nomor_ti . ' - ' . ApprovalMail::stamp();
                ApprovalMail::toRoles([5], null, "Persetujuan Trucking Instruction [{$tag}]", e($user->fullname) . ' telah menyetujui Trucking Instruction');
                // Logistik cabang diminta membuat Delivery Plan (di web lama pesan ini salah alamat ke Purchasing; di sini ke Logistik/Admin Finance cabang TI).
                ApprovalMail::toRoles([9, 10], [(int) DB::table(self::T)->where('id_master', $id)->value('id_wilayah')], "Trucking Instruction Telah Disetujui [{$tag}]",
                    'Trucking Instruction dengan nomor <b>' . e($row->nomor_ti) . '</b> telah dibuat dan disetujui.<br>Silakan membuat <b>Delivery Plan</b> untuk proses pengiriman selanjutnya.');
            }

            return response()->json(['message' => $data['keputusan'] === 'approve' ? 'Trucking Instruction disetujui.' : 'Trucking Instruction ditolak.']);
        });
    }
}
