<?php

namespace App\Http\Controllers;

use App\Models\AclUser;
use App\Models\PushSubscription;
use App\Services\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pengumuman dari Super Admin ke pengguna aplikasi.
 * Super Admin (peran 1, modul pengumuman_admin) menulis pengumuman lalu merilisnya: saat itu juga dikirim SATU KALI sebagai Web Push ke perangkat
 * pengguna sasaran (seperti notifikasi WhatsApp) dan muncul di menu Pengumuman semua pengguna sasaran sampai dibaca.
 * Sasaran: semua pengguna aktif, atau pengguna dengan peran tertentu. Draf bisa disimpan dan diubah; yang sudah dirilis tidak bisa diubah (hanya dihapus).
 */
class PengumumanController extends Controller
{
    private const JENIS = ['info', 'penting', 'pembaruan'];
    private const UNREAD_HARI = 30; // pengumuman lebih lama dari ini tidak lagi dihitung "belum dibaca"

    // ---------- bantu ----------

    /** Query pengumuman terkirim yang menyasar user ini. */
    private static function untuk(AclUser $u)
    {
        return DB::table('mobile_announcements as a')->where('a.status', 'terkirim')
            ->where(fn ($w) => $w->whereNull('a.sasaran')->orWhere('a.sasaran', 'like', '%,'.(int) $u->id_role.',%'));
    }

    public static function belumDibaca(AclUser $u): int
    {
        return self::untuk($u)->where('a.dikirim_pada', '>=', now()->subDays(self::UNREAD_HARI))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('mobile_announcement_reads as r')->whereColumn('r.id_announcement', 'a.id')->where('r.id_user', $u->id_user))
            ->count();
    }

    private static function sasaranString(?array $roles): ?string
    {
        $roles = array_values(array_unique(array_filter(array_map('intval', $roles ?? []))));

        return $roles ? ','.implode(',', $roles).',' : null;
    }

    private static function sasaranArray(?string $s): array
    {
        return $s ? array_values(array_filter(array_map('intval', explode(',', $s)))) : [];
    }

    private static function bentuk(object $a, ?bool $dibaca = null): array
    {
        return [
            'id' => (int) $a->id, 'judul' => $a->judul, 'isi' => $a->isi, 'jenis' => $a->jenis, 'dikirim_pada' => $a->dikirim_pada,
            'dibaca' => $dibaca,
        ];
    }

    /** Pengguna aktif yang menjadi sasaran (id) dan yang punya perangkat terdaftar notifikasi. */
    private static function penerima(?array $roles): array
    {
        $q = DB::table('acl_user')->where('is_active', 1);
        if ($roles) {
            $q->whereIn('id_role', $roles);
        }
        $ids = $q->pluck('id_user')->map(fn ($v) => (int) $v)->all();
        $perangkat = $ids ? PushSubscription::whereIn('id_user', $ids)->count() : 0;

        return [$ids, $perangkat];
    }

    // ---------- pengguna ----------

    public function index(Request $r): JsonResponse
    {
        $u = $r->user();
        $per = min(max((int) $r->query('per_page', 20), 1), (int) config('orca.per_page_max'));
        $page = self::untuk($u)->leftJoin('mobile_announcement_reads as r', fn ($j) => $j->on('r.id_announcement', '=', 'a.id')->where('r.id_user', $u->id_user))
            ->orderByDesc('a.dikirim_pada')->orderByDesc('a.id')->select(['a.*', DB::raw('r.id_user is not null as dibaca')])->paginate($per);

        return response()->json([
            'data' => collect($page->items())->map(fn ($a) => self::bentuk($a, (bool) $a->dibaca))->all(),
            'belum_dibaca' => self::belumDibaca($u),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage()],
        ]);
    }

    private function tandaiDibaca(AclUser $u, int $id): void
    {
        DB::table('mobile_announcement_reads')->insertOrIgnore(['id_announcement' => $id, 'id_user' => $u->id_user, 'dibaca_pada' => now()]);
    }

    /** Buka satu pengumuman: sekaligus ditandai sudah dibaca. */
    public function show(Request $r, int $id): JsonResponse
    {
        $a = self::untuk($r->user())->where('a.id', $id)->first();
        if (! $a) {
            return response()->json(['message' => 'Pengumuman tidak ditemukan.'], 404);
        }
        $this->tandaiDibaca($r->user(), $id);

        return response()->json(self::bentuk($a, true) + ['belum_dibaca' => self::belumDibaca($r->user())]);
    }

    public function bacaSemua(Request $r): JsonResponse
    {
        $u = $r->user();
        $ids = self::untuk($u)->pluck('a.id')->all();
        foreach (array_chunk($ids, 200) as $chunk) {
            DB::table('mobile_announcement_reads')->insertOrIgnore(array_map(fn ($id) => ['id_announcement' => $id, 'id_user' => $u->id_user, 'dibaca_pada' => now()], $chunk));
        }

        return response()->json(['message' => 'Semua pengumuman ditandai sudah dibaca.', 'belum_dibaca' => 0]);
    }

    // ---------- Super Admin ----------

    /** Daftar semua pengumuman beserta jumlah penerima dan yang sudah membaca. */
    public function adminIndex(Request $r): JsonResponse
    {
        $per = min(max((int) $r->query('per_page', 20), 1), (int) config('orca.per_page_max'));
        $page = DB::table('mobile_announcements as a')->orderByRaw("case when a.status = 'draft' then 0 else 1 end")->orderByDesc('a.id')
            ->select(['a.*', DB::raw('(select count(*) from mobile_announcement_reads r where r.id_announcement = a.id) as dibaca_n')])->paginate($per);
        $peran = DB::table('acl_role')->pluck('role_name', 'id_role');

        return response()->json([
            'data' => collect($page->items())->map(function ($a) use ($peran) {
                $roles = self::sasaranArray($a->sasaran);

                return [
                    'id' => (int) $a->id, 'judul' => $a->judul, 'isi' => $a->isi, 'jenis' => $a->jenis, 'status' => $a->status, 'dikirim_pada' => $a->dikirim_pada,
                    'dikirim_ke' => (int) $a->dikirim_ke, 'push_ke' => (int) $a->push_ke, 'dibaca' => (int) $a->dibaca_n, 'dibuat_nama' => $a->dibuat_nama,
                    'sasaran' => $roles, 'sasaran_nama' => $roles ? array_map(fn ($id) => preg_replace('/^Role /', '', (string) ($peran[$id] ?? $id)), $roles) : null,
                    'dibuat_pada' => $a->created_at,
                ];
            })->all(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage()],
        ]);
    }

    /** Pilihan peran untuk sasaran, dengan jumlah pengguna aktif. */
    public function peran(): JsonResponse
    {
        $rows = DB::table('acl_role as r')->leftJoin('acl_user as u', fn ($j) => $j->on('u.id_role', '=', 'r.id_role')->where('u.is_active', 1))
            ->groupBy('r.id_role', 'r.role_name')->orderBy('r.role_name')->get(['r.id_role as id', 'r.role_name as nama', DB::raw('count(u.id_user) as pengguna')]);

        return response()->json(['data' => $rows->filter(fn ($x) => (int) $x->pengguna > 0)->map(fn ($x) => ['id' => (int) $x->id, 'nama' => preg_replace('/^Role /', '', (string) $x->nama), 'pengguna' => (int) $x->pengguna])->values()->all()]);
    }

    /** Perkiraan penerima sebelum merilis: pengguna sasaran dan perangkat yang akan menerima push. */
    public function perkiraan(Request $r): JsonResponse
    {
        $roles = array_filter(array_map('intval', (array) $r->query('sasaran', [])));
        [$ids, $perangkat] = self::penerima($roles ?: null);

        return response()->json(['pengguna' => count($ids), 'perangkat' => $perangkat]);
    }

    private function validasi(Request $r): array
    {
        return $r->validate([
            'judul'    => ['required', 'string', 'max:150'],
            'isi'      => ['required', 'string', 'max:3000'],
            'jenis'    => ['required', 'in:'.implode(',', self::JENIS)],
            'sasaran'  => ['nullable', 'array', 'max:30'],
            'sasaran.*' => ['integer'],
            'kirim'    => ['nullable', 'boolean'],
        ], ['judul.required' => 'Judul wajib diisi.', 'isi.required' => 'Isi pengumuman wajib diisi.']);
    }

    public function store(Request $r): JsonResponse
    {
        $d = $this->validasi($r);
        $id = DB::table('mobile_announcements')->insertGetId([
            'judul' => trim($d['judul']), 'isi' => trim($d['isi']), 'jenis' => $d['jenis'], 'sasaran' => self::sasaranString($d['sasaran'] ?? null), 'status' => 'draft',
            'dibuat_oleh' => $r->user()->id_user, 'dibuat_nama' => $r->user()->fullname, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! empty($d['kirim'])) {
            return $this->rilis($id, 201);
        }

        return response()->json(['message' => 'Draf tersimpan.', 'id' => $id], 201);
    }

    public function update(Request $r, int $id): JsonResponse
    {
        $d = $this->validasi($r);
        $a = DB::table('mobile_announcements')->where('id', $id)->first(['status']);
        if (! $a) {
            return response()->json(['message' => 'Pengumuman tidak ditemukan.'], 404);
        }
        if ($a->status !== 'draft') {
            return response()->json(['message' => 'Pengumuman yang sudah dirilis tidak bisa diubah.', 'code' => 'SUDAH_DIRILIS'], 409);
        }
        DB::table('mobile_announcements')->where('id', $id)->update([
            'judul' => trim($d['judul']), 'isi' => trim($d['isi']), 'jenis' => $d['jenis'], 'sasaran' => self::sasaranString($d['sasaran'] ?? null), 'updated_at' => now(),
        ]);
        if (! empty($d['kirim'])) {
            return $this->rilis($id);
        }

        return response()->json(['message' => 'Draf tersimpan.', 'id' => $id]);
    }

    public function kirim(int $id): JsonResponse
    {
        return $this->rilis($id);
    }

    public function destroy(int $id): JsonResponse
    {
        if (! DB::table('mobile_announcements')->where('id', $id)->exists()) {
            return response()->json(['message' => 'Pengumuman tidak ditemukan.'], 404);
        }
        DB::table('mobile_announcement_reads')->where('id_announcement', $id)->delete();
        DB::table('mobile_announcements')->where('id', $id)->delete();

        return response()->json(['message' => 'Pengumuman dihapus.']);
    }

    /** Rilis: tandai terkirim (sekali; yang sudah terkirim ditolak) lalu kirim push satu kali ke perangkat pengguna sasaran. */
    private function rilis(int $id, int $status = 200): JsonResponse
    {
        $a = DB::transaction(function () use ($id) {
            $a = DB::table('mobile_announcements')->where('id', $id)->lockForUpdate()->first();
            if (! $a || $a->status !== 'draft') {
                return $a ? 'sudah' : null;
            }
            $roles = self::sasaranArray($a->sasaran);
            [$ids] = self::penerima($roles ?: null);
            DB::table('mobile_announcements')->where('id', $id)->update(['status' => 'terkirim', 'dikirim_pada' => now(), 'dikirim_ke' => count($ids), 'updated_at' => now()]);
            $a->ids = $ids;

            return $a;
        });
        if ($a === null) {
            return response()->json(['message' => 'Pengumuman tidak ditemukan.'], 404);
        }
        if ($a === 'sudah') {
            return response()->json(['message' => 'Pengumuman ini sudah pernah dirilis.', 'code' => 'SUDAH_DIRILIS'], 409);
        }

        $isi = Str::limit(trim(preg_replace('/\s+/', ' ', (string) $a->isi)), 140);
        $push = app(PushService::class)->send($a->ids, [
            'title' => $a->judul, 'body' => $isi, 'url' => '/pengumuman?buka='.$id, 'tag' => 'pengumuman-'.$id,
            'en' => ['title' => $a->judul, 'body' => $isi],
        ]);
        DB::table('mobile_announcements')->where('id', $id)->update(['push_ke' => $push]);

        return response()->json(['message' => 'Pengumuman dirilis ke '.count($a->ids).' pengguna.', 'id' => $id, 'dikirim_ke' => count($a->ids), 'push_ke' => $push], $status);
    }
}
