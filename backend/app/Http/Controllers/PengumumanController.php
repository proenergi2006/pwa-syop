<?php

namespace App\Http\Controllers;

use App\Models\AclUser;
use App\Services\AnnouncementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $page = DB::table('mobile_announcements as a')->orderByRaw("case when a.status = 'terkirim' then 1 else 0 end")->orderByDesc('a.id')
            ->select(['a.*', DB::raw('(select count(*) from mobile_announcement_reads r where r.id_announcement = a.id) as dibaca_n')])->paginate($per);
        $peran = DB::table('acl_role')->pluck('role_name', 'id_role');

        return response()->json([
            'data' => collect($page->items())->map(function ($a) use ($peran) {
                $roles = self::sasaranArray($a->sasaran);

                return [
                    'id' => (int) $a->id, 'judul' => $a->judul, 'isi' => $a->isi, 'jenis' => $a->jenis, 'status' => $a->status, 'dikirim_pada' => $a->dikirim_pada, 'jadwal_pada' => $a->jadwal_pada,
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
        [$ids, $perangkat] = AnnouncementService::penerima($roles ?: null);

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
            'jadwal'   => ['nullable', 'date', 'after:now'],
        ], ['judul.required' => 'Judul wajib diisi.', 'isi.required' => 'Isi pengumuman wajib diisi.']);
    }

    public function store(Request $r): JsonResponse
    {
        $d = $this->validasi($r);
        $id = DB::table('mobile_announcements')->insertGetId([
            'judul' => trim($d['judul']), 'isi' => trim($d['isi']), 'jenis' => $d['jenis'], 'sasaran' => self::sasaranString($d['sasaran'] ?? null), 'status' => ! empty($d['jadwal']) ? 'terjadwal' : 'draft', 'jadwal_pada' => $d['jadwal'] ?? null,
            'dibuat_oleh' => $r->user()->id_user, 'dibuat_nama' => $r->user()->fullname, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! empty($d['kirim'])) {
            return $this->rilis($id, 201);
        }

        return response()->json(['message' => ! empty($d['jadwal']) ? 'Pengumuman dijadwalkan.' : 'Draf tersimpan.', 'id' => $id], 201);
    }

    public function update(Request $r, int $id): JsonResponse
    {
        $d = $this->validasi($r);
        $a = DB::table('mobile_announcements')->where('id', $id)->first(['status']);
        if (! $a) {
            return response()->json(['message' => 'Pengumuman tidak ditemukan.'], 404);
        }
        if ($a->status === 'terkirim') {
            return response()->json(['message' => 'Pengumuman yang sudah dirilis tidak bisa diubah.', 'code' => 'SUDAH_DIRILIS'], 409);
        }
        DB::table('mobile_announcements')->where('id', $id)->update([
            'judul' => trim($d['judul']), 'isi' => trim($d['isi']), 'jenis' => $d['jenis'], 'sasaran' => self::sasaranString($d['sasaran'] ?? null),
            'status' => ! empty($d['jadwal']) ? 'terjadwal' : 'draft', 'jadwal_pada' => $d['jadwal'] ?? null, 'updated_at' => now(),
        ]);
        if (! empty($d['kirim'])) {
            return $this->rilis($id);
        }

        return response()->json(['message' => ! empty($d['jadwal']) ? 'Pengumuman dijadwalkan.' : 'Draf tersimpan.', 'id' => $id]);
    }

    /** Salin pengumuman (mis. yang sudah terkirim) menjadi draf baru. */
    public function gandakan(Request $r, int $id): JsonResponse
    {
        $a = DB::table('mobile_announcements')->where('id', $id)->first();
        if (! $a) {
            return response()->json(['message' => 'Pengumuman tidak ditemukan.'], 404);
        }
        $baru = DB::table('mobile_announcements')->insertGetId([
            'judul' => $a->judul, 'isi' => $a->isi, 'jenis' => $a->jenis, 'sasaran' => $a->sasaran, 'status' => 'draft',
            'dibuat_oleh' => $r->user()->id_user, 'dibuat_nama' => $r->user()->fullname, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Disalin sebagai draf baru.', 'id' => $baru], 201);
    }

    /** Daftar pengguna sasaran yang sudah / belum membaca satu pengumuman terkirim. */
    public function pembaca(Request $r, int $id): JsonResponse
    {
        $a = DB::table('mobile_announcements')->where('id', $id)->first(['id', 'status', 'sasaran']);
        if (! $a) {
            return response()->json(['message' => 'Pengumuman tidak ditemukan.'], 404);
        }
        if ($a->status !== 'terkirim') {
            return response()->json(['message' => 'Pengumuman belum dirilis.', 'code' => 'BELUM_DIRILIS'], 409);
        }
        $sudah = $r->query('status') === 'sudah';
        $roles = AnnouncementService::sasaranArray($a->sasaran);
        $q = DB::table('acl_user as u')->leftJoin('acl_role as ro', 'ro.id_role', '=', 'u.id_role')->where('u.is_active', 1)
            ->when($roles, fn ($w) => $w->whereIn('u.id_role', $roles))
            ->when(true, fn ($w) => $sudah
                ? $w->whereExists(fn ($e) => $e->select(DB::raw(1))->from('mobile_announcement_reads as r')->whereColumn('r.id_user', 'u.id_user')->where('r.id_announcement', $id))
                : $w->whereNotExists(fn ($e) => $e->select(DB::raw(1))->from('mobile_announcement_reads as r')->whereColumn('r.id_user', 'u.id_user')->where('r.id_announcement', $id)));
        $total = (clone $q)->count();
        $rows = $q->orderBy('u.fullname')->limit(300)->get(['u.id_user', 'u.fullname', 'ro.role_name']);

        return response()->json([
            'total' => $total, 'dipotong' => $total > 300,
            'data' => $rows->map(fn ($x) => ['id' => (int) $x->id_user, 'nama' => $x->fullname, 'peran' => preg_replace('/^Role /', '', (string) $x->role_name)])->all(),
        ]);
    }

    /** Status notifikasi tiap pengguna aktif: sudah mengaktifkan push atau belum, versi aplikasi, terakhir aktif. */
    public function perangkat(Request $r): JsonResponse
    {
        $per = min(max((int) $r->query('per_page', 30), 1), (int) config('orca.per_page_max'));
        $base = fn () => DB::table('acl_user as u')->where('u.is_active', 1)
            ->leftJoin('acl_role as ro', 'ro.id_role', '=', 'u.id_role')
            ->leftJoin('mobile_client_versions as v', 'v.id_user', '=', 'u.id_user')
            ->leftJoin(DB::raw('(select id_user, count(*) as n from mobile_push_subscriptions group by id_user) as p'), 'p.id_user', '=', 'u.id_user');
        $total = $base()->count();
        $aktif = $base()->where('p.n', '>', 0)->count();
        $versi = DB::table('mobile_settings')->where('nama', 'app_versi')->value('nilai');
        $q = $base()->when(trim((string) $r->query('q', '')) !== '', fn ($w) => $w->where('u.fullname', 'like', '%'.trim((string) $r->query('q')).'%'))
            ->when($r->query('filter') === 'belum', fn ($w) => $w->whereRaw('coalesce(p.n, 0) = 0'))
            ->when($r->query('filter') === 'lama' && $versi, fn ($w) => $w->where(fn ($x) => $x->whereNull('v.versi')->orWhere('v.versi', '!=', $versi)));
        $page = $q->orderBy('u.fullname')->paginate($per, ['u.id_user', 'u.fullname', 'ro.role_name', DB::raw('coalesce(p.n, 0) as perangkat'), 'v.versi', 'v.dilihat_pada']);

        return response()->json([
            'ringkasan' => ['pengguna' => $total, 'push_aktif' => $aktif, 'push_belum' => $total - $aktif, 'versi_terbaru' => $versi],
            'data' => collect($page->items())->map(fn ($x) => [
                'id' => (int) $x->id_user, 'nama' => $x->fullname, 'peran' => preg_replace('/^Role /', '', (string) $x->role_name),
                'perangkat' => (int) $x->perangkat, 'versi' => $x->versi, 'terbaru' => $versi && $x->versi === $versi, 'terakhir_aktif' => $x->dilihat_pada,
            ])->all(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage()],
        ]);
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

    /** Rilis sekarang (tanpa menunggu jadwal). */
    private function rilis(int $id, int $status = 200): JsonResponse
    {
        $r = app(AnnouncementService::class)->rilis($id);
        if ($r === null) {
            return response()->json(['message' => 'Pengumuman tidak ditemukan.'], 404);
        }
        if ($r === 'sudah') {
            return response()->json(['message' => 'Pengumuman ini sudah pernah dirilis.', 'code' => 'SUDAH_DIRILIS'], 409);
        }

        return response()->json(['message' => 'Pengumuman dirilis ke '.$r['dikirim_ke'].' pengguna.', 'id' => $id] + $r, $status);
    }
}
