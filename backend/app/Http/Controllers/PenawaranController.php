<?php

namespace App\Http\Controllers;

use App\Services\ApprovalException;
use App\Services\PenawaranApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PenawaranController extends Controller
{
    public function __construct(private readonly PenawaranApprovalService $svc) {}

    /** Daftar penawaran yang menunggu keputusan user. */
    public function pending(Request $request): JsonResponse
    {
        $dir = $request->query('sort') === 'asc' ? 'asc' : 'desc'; // asc = terlama dulu

        return $this->paginate(
            $request,
            $this->svc->pendingQuery($request->user())->orderBy('a.created_time', $dir),
            function ($row) {
                // Penanda peringatan batas harga untuk kartu daftar; detail rincian tidak dikirim.
                $row->peringatan = count($this->svc->warnings($row));
                unset($row->detail_rincian, $row->metode, $row->oa_kirim, $row->other_cost, $row->refund_tawar);

                return $row;
            },
        );
    }

    public function ringkasan(Request $request): JsonResponse
    {
        return response()->json($this->svc->ringkasan($request->user()));
    }

    /** Riwayat keputusan user. */
    public function history(Request $request): JsonResponse
    {
        $decision = $request->query('keputusan'); // 1 = disetujui, 2 = ditolak

        $q = $this->svc->historyQuery($request->user());
        if (in_array($decision, ['1', '2'], true)) {
            $q->where('h.result', $decision);
        }

        return $this->paginate($request, $q);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            return response()->json($this->svc->detail($request->user(), $id));
        } catch (ApprovalException $e) {
            return $this->fail($e);
        }
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'keputusan' => ['required', 'in:approve,reject'],
            'catatan'   => ['nullable', 'string', 'max:2000'],
            'teruskan'  => ['nullable', 'boolean'],
        ]);

        // Sesi hasil face login hanya boleh membaca. Memutuskan penawaran (menyangkut uang)
        // wajib konfirmasi password; kamera di browser belum setara Face ID.
        $user = $request->user();
        try {
            $result = $this->svc->decide(
                $request->user(),
                $id,
                $data['keputusan'],
                $data['catatan'] ?? null,
                array_key_exists('teruskan', $data) ? (bool) $data['teruskan'] : null,
            );
        } catch (ApprovalException $e) {
            return $this->fail($e);
        }

        return response()->json($result);
    }

    // ---------------------------------------------------------------- helpers

    private function paginate(Request $request, $query, ?callable $map = null): JsonResponse
    {
        $per = min(
            max((int) $request->query('per_page', 20), 1),
            (int) config('orca.per_page_max'),
        );

        if ($term = trim((string) $request->query('q', ''))) {
            $query->where(fn ($w) => $w
                ->where('a.nomor_surat', 'like', "%{$term}%")
                ->orWhere('b.nama_customer', 'like', "%{$term}%")
                ->orWhere('c.fullname', 'like', "%{$term}%"));
        }

        $page = $query->paginate($per);

        return response()->json([
            'data' => $map ? array_map($map, $page->items()) : $page->items(),
            'meta' => [
                'page'     => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total'    => $page->total(),
                'last'     => $page->lastPage(),
            ],
        ]);
    }

    private function fail(ApprovalException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], $e->status);
    }
}
