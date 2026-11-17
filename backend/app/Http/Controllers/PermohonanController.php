<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StepUp;
use App\Models\AclUser;
use App\Services\ApprovalMail;
use App\Services\LegacyFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Verifikasi Permohonan (pemutakhiran data customer, pro_customer_update) untuk Branch Manager (peran 7), per cabangnya.
 * Sumber: verifikasi-permohonan.php + datatable (daftar), verifikasi-permohonan-detail.php, __get_permohonan_om.php (form BM), evaluation-permohonan.php (aksi).
 * Alur web: Admin Finance (disposisi 1) -> BM (2) -> CFO (3) -> CEO (4). Form BM memakai extend=2, jadi BM memutuskan sendiri:
 * flag_approval = hasil, tgl_approval = sekarang. Setuju -> email ke Admin Finance cabang ("Pemutakhiran Data"); tolak -> tanpa email.
 */
class PermohonanController extends Controller
{
    use StepUp;

    private const KATEGORI = [
        1 => 'Perubahan Credit Limit', 2 => 'Perubahan TOP', 3 => 'Perubahan Data', 4 => 'Perubahan Credit Limit & Data Customer',
        5 => 'Perubahan TOP & Data Customer', 6 => 'Perubahan Credit Limit & TOP', 7 => 'Perubahan Credit Limit & TOP & Data Customer',
    ];
    private const FLAG = [1 => 'Admin Finance', 2 => 'BM', 3 => 'CFO'];

    private function base(AclUser $u)
    {
        return DB::table('pro_customer_update as a')->join('pro_customer as b', 'a.id_customer', '=', 'b.id_customer')->join('acl_user as c', 'b.id_marketing', '=', 'c.id_user')
            ->where('a.flag_disposisi', '>', 1)->where('b.id_wilayah', $u->id_wilayah);
    }

    private function waiting($q)
    {
        return $q->where('a.flag_disposisi', 2)->where('a.om_result', 0)->where('a.flag_approval', 0);
    }

    public function waitingCount(AclUser $u): int
    {
        return (int) $this->waiting($this->base($u))->count();
    }

    /** @return array<int,string> id_cu => nama customer - judul */
    public function waitingItems(AclUser $u): array
    {
        return $this->waiting($this->base($u))->orderByDesc('a.id_cu')->limit(500)->get(['a.id_cu as id', 'b.nama_customer', 'a.judul'])
            ->mapWithKeys(fn ($x) => [(int) $x->id => $x->nama_customer.' - '.$x->judul])->all();
    }

    private static function tgl($v): ?string
    {
        return $v && ! str_starts_with((string) $v, '0000') ? date('d/m/Y H:i:s', strtotime($v)).' WIB' : null;
    }

    private static function status(object $x): string
    {
        $d = (int) $x->flag_disposisi;
        if ($d === 0) {
            return 'Terdaftar';
        }
        if ((int) $x->flag_edited === 1) {
            return 'Disetujui '.(self::FLAG[$d] ?? '').' ('.self::tgl($x->tgl_approval).'); Dimutakhirkan ('.self::tgl($x->tgl_edited).')';
        }
        if ((int) $x->flag_approval === 1) {
            return 'Permohonan Disetujui '.(self::FLAG[$d] ?? '').' ('.self::tgl($x->tgl_approval).')';
        }
        if ((int) $x->flag_approval === 2) {
            return 'Permohonan Ditolak '.(self::FLAG[$d] ?? '').' ('.self::tgl($x->tgl_approval).')';
        }

        return [1 => 'Diverifikasi Admin Finance', 2 => 'Diverifikasi BM', 3 => 'Diverifikasi CFO', 4 => 'Diverifikasi CEO'][$d] ?? '-';
    }

    public function index(Request $r): JsonResponse
    {
        $u = $r->user();
        $q = $this->base($u);
        if (($term = trim((string) $r->query('q', ''))) !== '') {
            $like = '%'.strtoupper($term).'%';
            $q->where(fn ($w) => $w->whereRaw('upper(b.nama_customer) like ?', [$like])->orWhereRaw('upper(a.judul) like ?', [$like])->orWhere('b.kode_pelanggan', $term));
        }
        if ($r->filled('kategori')) {
            $q->where('a.kategori', (int) $r->query('kategori'));
        }
        if ($r->query('filter') === 'menunggu') {
            $this->waiting($q);
        }
        $page = $q->orderBy('a.om_result')->orderByDesc('a.id_cu')->paginate(min(50, max(1, (int) $r->query('per_page', 20))), ['a.*', 'b.nama_customer', 'b.kode_pelanggan', 'c.fullname']);

        return response()->json([
            'data' => collect($page->items())->map(fn ($x) => [
                'id' => (int) $x->id_cu, 'tanggal' => date('d/m/Y', strtotime($x->created_time)), 'customer' => $x->nama_customer, 'marketing' => $x->fullname,
                'kategori' => $x->kategori ? (self::KATEGORI[(int) $x->kategori] ?? null) : null, 'judul' => $x->judul, 'status' => self::status($x),
                'menunggu' => (int) $x->flag_disposisi === 2 && (int) $x->om_result === 0 && (int) $x->flag_approval === 0,
                'ditolak' => (int) $x->flag_approval === 2,
            ])->all(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'kategori' => collect(self::KATEGORI)->map(fn ($n, $id) => ['id' => $id, 'nama' => $n])->values()->all(),
        ]);
    }

    private function find(Request $r, int $id): ?object
    {
        return $this->base($r->user())->where('a.id_cu', $id)->first(['a.*', 'b.nama_customer', 'b.kode_pelanggan', 'c.fullname']);
    }

    private static function tahap($ringkas, $hasil, $pic, $tgl): array
    {
        return ['ringkasan' => $ringkas, 'hasil' => $hasil ? [1 => 'Ya', 2 => 'Tidak'][(int) $hasil] ?? null : null, 'oleh' => $pic ?: null, 'tanggal' => self::tgl($tgl)];
    }

    public function show(Request $r, int $id): JsonResponse
    {
        $x = $this->find($r, $id);
        if (! $x) {
            return response()->json(['message' => 'Data permohonan tidak ditemukan.'], 404);
        }

        return response()->json(['data' => [
            'id' => $id, 'customer' => $x->nama_customer, 'kode_pelanggan' => $x->kode_pelanggan, 'marketing' => $x->fullname,
            'kategori' => $x->kategori ? (self::KATEGORI[(int) $x->kategori] ?? null) : null, 'judul' => $x->judul, 'pesan' => $x->pesan,
            'lampiran' => $x->attachment_order ? ['nama' => $x->attachment_order_ori ?: $x->attachment_order] : null,
            'status' => self::status($x), 'tanggal' => date('d/m/Y', strtotime($x->created_time)),
            'finance' => self::tahap($x->finance_summary, $x->finance_result, $x->finance_pic, $x->finance_tanggal),
            'bm' => self::tahap($x->om_summary, $x->om_result, $x->om_pic, $x->om_tanggal),
            'bisa_putus' => (int) $x->flag_disposisi === 2 && (int) $x->om_result === 0 && (int) $x->flag_approval === 0,
        ]]);
    }

    public function berkas(Request $r, int $id)
    {
        $x = $this->find($r, $id);
        if (! $x || ! $x->attachment_order) {
            return response()->json(['message' => 'Berkas tidak ditemukan.', 'code' => 'FILE_MISSING'], 404);
        }

        return LegacyFile::response('lampiran/'.basename($x->attachment_order), $x->attachment_order_ori ?: $x->attachment_order);
    }

    public function decide(Request $r, int $id): JsonResponse
    {
        if (! $this->find($r, $id)) {
            return response()->json(['message' => 'Data permohonan tidak ditemukan.'], 404);
        }
        $d = $r->validate(['result' => 'required|in:1,2', 'summary' => 'required|string|max:5000']);
        if ($resp = $this->stepUp($r)) {
            return $resp;
        }
        $u = $r->user();
        $res = (int) $d['result'];
        $summary = str_replace(["\r\n", "\r", "\n"], '<br />', htmlspecialchars($d['summary'], ENT_QUOTES));

        return DB::transaction(function () use ($id, $u, $res, $summary) {
            $x = DB::table('pro_customer_update')->where('id_cu', $id)->lockForUpdate()->first();
            if ((int) $x->flag_disposisi !== 2 || (int) $x->om_result !== 0 || (int) $x->flag_approval !== 0) {
                return response()->json(['message' => 'Permohonan ini sudah diputuskan atau belum waktunya Anda memutuskan.', 'code' => 'STATE'], 409);
            }
            DB::table('pro_customer_update')->where('id_cu', $id)->update([
                'om_summary' => $summary, 'om_result' => $res, 'om_tanggal' => now(), 'om_pic' => $u->fullname, 'flag_approval' => $res, 'tgl_approval' => now(),
            ]);
            if ($res === 1) {
                $wil = DB::table('pro_customer')->where('id_customer', $x->id_customer)->value('id_wilayah');
                ApprovalMail::toRoles([10], [(int) $wil], 'Pemutakhiran Data ['.ApprovalMail::stamp().']', 'Persetujuan untuk permohonan update data customer telah disetujui, silahkan data dimutakhirkan');
            }

            return response()->json(['ok' => true, 'status' => $res === 1 ? 'disetujui' : 'ditolak']);
        });
    }
}
