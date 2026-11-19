<?php

namespace App\Http\Controllers;

use App\Models\AclUser;
use App\Services\ApprovalMail;
use App\Services\LegacyFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Verifikasi Penerima Refund (pro_master_penerima_refund): tahap BM (peran 7) lalu CEO (peran 21).
 * Sumber: master-penerima-refund-bm(.php) / -ceo(.php) (daftar dan detail) dan master-penerima-refund.php (aksi approve/reject/approve_ceo/reject_ceo).
 * is_bm / is_ceo: 0 menunggu, 1 disetujui, 2 ditolak. BM menyetujui -> email ke CEO; CEO menyetujui -> email ke pembuat data.
 * BM menolak -> berhenti (CEO tidak melihatnya). Pembuatan/ubah penerima refund (marketing) tidak ada di PWA.
 */
class RefundPenerimaController extends Controller
{
    private function base(AclUser $u)
    {
        $q = DB::table('pro_master_penerima_refund as a')->join('pro_customer as b', 'a.id_customer', '=', 'b.id_customer')->join('acl_user as c', 'b.id_marketing', '=', 'c.id_user');
        if ((int) $u->id_role === 7) {
            $q->where('b.id_wilayah', $u->id_wilayah);
        } else {
            $q->where('a.is_bm', 1); // CEO hanya melihat yang sudah disetujui BM
        }

        return $q;
    }

    private function waiting($q, AclUser $u)
    {
        return (int) $u->id_role === 7 ? $q->where('a.is_bm', 0) : $q->where('a.is_ceo', 0);
    }

    public function waitingCount(AclUser $u): int
    {
        return (int) $this->waiting($this->base($u), $u)->count();
    }

    /** @return array<int,string> id => customer - penerima */
    public function waitingItems(AclUser $u): array
    {
        return $this->waiting($this->base($u), $u)->orderByDesc('a.id')->limit(500)
            ->get(['a.id', 'b.nama_customer', 'a.nama'])->mapWithKeys(fn ($x) => [(int) $x->id => $x->nama_customer.' - '.$x->nama])->all();
    }

    private static function tahap(int $v, ?string $by, $date, ?string $catatan): array
    {
        return ['hasil' => $v, 'oleh' => $by ?: null, 'tanggal' => $date && ! str_starts_with((string) $date, '0000') ? date('d/m/Y H:i', strtotime($date)) : null, 'catatan' => $catatan ?: null];
    }

    private static function status(object $x): string
    {
        if ((int) $x->is_bm === 2) {
            return 'Ditolak BM';
        }
        if ((int) $x->is_bm === 0) {
            return 'Verifikasi BM';
        }

        return [0 => 'Verifikasi CEO', 1 => 'Disetujui CEO', 2 => 'Ditolak CEO'][(int) $x->is_ceo] ?? '';
    }

    private function row(object $x): array
    {
        return [
            'id' => (int) $x->id, 'customer' => trim($x->kode_pelanggan.' '.$x->nama_customer), 'marketing' => $x->marketingnya, 'nama' => $x->nama, 'divisi' => ucwords((string) $x->divisi),
            'aktif' => (int) $x->is_active === 1, 'status' => self::status($x), 'is_bm' => (int) $x->is_bm, 'is_ceo' => (int) $x->is_ceo,
        ];
    }

    private const SEL = ['a.*', 'b.kode_pelanggan', 'b.nama_customer', 'c.fullname as marketingnya'];

    public function index(Request $r): JsonResponse
    {
        $u = $r->user();
        $q = $this->base($u);
        if (($term = trim((string) $r->query('q', ''))) !== '') {
            $like = '%'.strtoupper($term).'%';
            $q->where(fn ($w) => $w->whereRaw('upper(b.nama_customer) like ?', [$like])->orWhereRaw('upper(b.kode_pelanggan) like ?', [$like])->orWhereRaw('upper(a.nama) like ?', [$like]));
        }
        if ($r->query('filter') === 'menunggu') {
            $this->waiting($q, $u);
        }
        $page = $q->orderBy((int) $u->id_role === 7 ? 'a.is_bm' : 'a.is_ceo')->orderByDesc('a.id')->paginate(min(50, max(1, (int) $r->query('per_page', 20))), self::SEL);

        return response()->json([
            'data' => collect($page->items())->map(fn ($x) => $this->row($x) + ['menunggu' => (int) $u->id_role === 7 ? (int) $x->is_bm === 0 : ((int) $x->is_bm === 1 && (int) $x->is_ceo === 0)])->all(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    private function find(Request $r, int $id): ?object
    {
        return $this->base($r->user())->where('a.id', $id)->first(self::SEL);
    }

    public function show(Request $r, int $id): JsonResponse
    {
        $x = $this->find($r, $id);
        if (! $x) {
            return response()->json(['message' => 'Data penerima refund tidak ditemukan.'], 404);
        }
        $role = (int) $r->user()->id_role;

        return response()->json(['data' => $this->row($x) + [
            'bank' => trim($x->bank.' - '.$x->kode_bank, ' -'), 'no_rekening' => $x->no_rekening, 'atas_nama' => $x->atas_nama, 'no_ktp' => $x->no_ktp,
            'ada_ktp' => (bool) $x->foto_ktp, 'ada_npwp' => (bool) $x->foto_npwp,
            'bm' => self::tahap((int) $x->is_bm, $x->bm_by, $x->bm_date, $x->catatan_bm), 'ceo' => self::tahap((int) $x->is_ceo, $x->ceo_by, $x->ceo_date, $x->catatan_ceo),
            'bisa_putus' => $role === 7 ? (int) $x->is_bm === 0 : ((int) $x->is_bm === 1 && (int) $x->is_ceo === 0),
        ]]);
    }

    /** jenis ktp|npwp: folder ktp_penerima_refund / npwp_penerima_refund, nama berkas = isi kolom foto_ktp / foto_npwp. */
    public function berkas(Request $r, int $id, string $jenis)
    {
        $x = $this->find($r, $id);
        if (! $x) {
            return response()->json(['message' => 'Data penerima refund tidak ditemukan.'], 404);
        }
        $nama = $jenis === 'ktp' ? $x->foto_ktp : $x->foto_npwp;

        return LegacyFile::response($nama ? ($jenis === 'ktp' ? 'ktp_penerima_refund/' : 'npwp_penerima_refund/').basename($nama) : null, $nama ?: $jenis);
    }

    public function decide(Request $r, int $id): JsonResponse
    {
        if (! $this->find($r, $id)) {
            return response()->json(['message' => 'Data penerima refund tidak ditemukan.'], 404);
        }
        $d = $r->validate(['result' => 'required|in:1,2', 'catatan' => 'nullable|string|max:1000']);
        $u = $r->user();
        $bm = (int) $u->id_role === 7;
        $res = (int) $d['result'];
        $catatan = htmlspecialchars((string) ($d['catatan'] ?? ''), ENT_QUOTES);

        return DB::transaction(function () use ($id, $u, $bm, $res, $catatan) {
            $x = DB::table('pro_master_penerima_refund')->where('id', $id)->lockForUpdate()->first();
            $boleh = $bm ? (int) $x->is_bm === 0 : ((int) $x->is_bm === 1 && (int) $x->is_ceo === 0);
            if (! $boleh) {
                return response()->json(['message' => 'Data ini sudah diputuskan atau belum waktunya Anda memutuskan.', 'code' => 'STATE'], 409);
            }
            $p = $bm ? ['is_bm' => $res, 'bm_by' => $u->fullname, 'bm_date' => now(), 'catatan_bm' => $catatan]
                : ['is_ceo' => $res, 'ceo_by' => $u->fullname, 'ceo_date' => now(), 'catatan_ceo' => $catatan];
            DB::table('pro_master_penerima_refund')->where('id', $id)->update($p);

            if ($res === 1) {
                $cust = DB::table('pro_customer')->where('id_customer', $x->id_customer)->first(['kode_pelanggan', 'nama_customer']);
                $nama = trim($cust->kode_pelanggan.' '.$cust->nama_customer);
                if ($bm) {
                    ApprovalMail::toRoles([21], null, 'Approval Penerima Refund', e($x->created_by).' mengajukan permohonan approval untuk customer '.e($nama).' atas nama '.e(ucwords((string) $x->nama)));
                } else {
                    $to = DB::table('acl_user')->where('fullname', $x->created_by)->where('is_active', 1)->pluck('email_user')->all();
                    ApprovalMail::send($to, 'Approval Penerima Refund', e($u->fullname).' telah melakukan approval untuk customer '.e($nama).' atas nama '.e((string) $x->nama));
                }
            }

            return response()->json(['ok' => true, 'status' => $res === 1 ? 'disetujui' : 'ditolak']);
        });
    }
}
