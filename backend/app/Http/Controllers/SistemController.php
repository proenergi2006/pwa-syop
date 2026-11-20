<?php

namespace App\Http\Controllers;

use App\Services\AppSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pusat Admin (Super Admin): kesehatan sistem, wajah login pengguna, log aktivitas, dan pengaturan aplikasi (mode pemeliharaan).
 */
class SistemController extends Controller
{
    private static function peran(?string $n): string
    {
        return preg_replace('/^Role /', '', (string) $n);
    }

    private function perPage(Request $r, int $def = 30): int
    {
        return min(max((int) $r->query('per_page', $def), 1), (int) config('orca.per_page_max'));
    }

    private static function meta($page): array
    {
        return ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage()];
    }

    /** Catat tindakan admin ke jejak audit (mobile_auth_log). */
    private function audit(string $event, Request $r, ?int $target = null, ?string $ket = null): void
    {
        DB::table('mobile_auth_log')->insert([
            'id_user' => $target ?? $r->user()->id_user, 'username' => mb_substr(trim(($ket ?? '').' oleh '.$r->user()->username), 0, 50),
            'event' => $event, 'ip' => $r->ip(), 'user_agent' => mb_substr((string) $r->userAgent(), 0, 255),
        ]);
    }

    // ---------- kesehatan ----------

    public function kesehatan(): JsonResponse
    {
        // scheduler
        $hb = AppSettings::get('hb_scheduler');
        $detik = $hb ? max(0, time() - strtotime($hb)) : null;
        // database
        $t0 = microtime(true);
        try {
            DB::select('select 1');
            $db = ['ok' => true, 'ms' => (int) round((microtime(true) - $t0) * 1000)];
        } catch (\Throwable) {
            $db = ['ok' => false, 'ms' => null];
        }
        // push
        $perangkat = DB::table('mobile_push_subscriptions')->count();
        $gagalTerakhir = json_decode((string) AppSettings::get('push_gagal_terakhir'), true) ?: null;
        // versi
        $rilis = AppSettings::get('app_versi');
        $aktif = DB::table('acl_user')->where('is_active', 1)->count();
        $baru = $rilis ? DB::table('mobile_client_versions as v')->join('acl_user as u', 'u.id_user', '=', 'v.id_user')->where('u.is_active', 1)->where('v.versi', $rilis)->count() : 0;
        $lapor = DB::table('mobile_client_versions as v')->join('acl_user as u', 'u.id_user', '=', 'v.id_user')->where('u.is_active', 1)->count();
        // pengumuman terjadwal yang terlambat = scheduler tidak jalan
        $terjadwal = DB::table('mobile_announcements')->where('status', 'terjadwal');
        $total = @disk_total_space(storage_path()) ?: 0;
        $bebas = @disk_free_space(storage_path()) ?: 0;

        return response()->json([
            'waktu' => now()->toDateTimeString(),
            'scheduler' => ['ok' => $detik !== null && $detik < 180, 'terakhir' => $hb, 'detik' => $detik],
            'database' => $db,
            'push' => [
                'siap' => (bool) (config('orca.push.public_key') && config('orca.push.private_key')), 'perangkat' => $perangkat,
                'terkirim_24j' => DB::table('mobile_push_subscriptions')->where('last_sent_at', '>=', now()->subDay())->count(),
                'gagal_24j' => AppSettings::pushGagal24Jam(), 'gagal_terakhir' => $gagalTerakhir,
            ],
            'accurate' => ['aktif' => (bool) config('accurate.enabled') && filled(config('accurate.token')) && filled(config('accurate.secret'))],
            'versi' => ['rilis' => $rilis, 'pengguna' => $aktif, 'terbaru' => $baru, 'lama' => $lapor - $baru, 'belum_lapor' => $aktif - $lapor],
            'pengumuman' => ['terjadwal' => (clone $terjadwal)->count(), 'terlambat' => (clone $terjadwal)->where('jadwal_pada', '<', now()->subMinutes(3))->count()],
            'penyimpanan' => ['bebas_gb' => round($bebas / 1073741824, 1), 'total_gb' => round($total / 1073741824, 1)],
            'pemeliharaan' => AppSettings::pemeliharaan(),
            'lingkungan' => ['env' => config('app.env'), 'debug' => (bool) config('app.debug'), 'php' => PHP_VERSION],
        ]);
    }

    // ---------- ringkasan Beranda Super Admin ----------

    public function ringkasan(): JsonResponse
    {
        $k = $this->kesehatan()->getData(true);
        $masalah = [];
        $merah = false;
        if (! $k['database']['ok']) { $masalah[] = ['kode' => 'db', 'berat' => 'merah']; $merah = true; }
        if (! $k['scheduler']['ok']) { $masalah[] = ['kode' => 'scheduler', 'berat' => 'merah']; $merah = true; }
        if ($k['pengumuman']['terlambat'] > 0) { $masalah[] = ['kode' => 'pengumuman_terlambat', 'berat' => 'kuning', 'n' => $k['pengumuman']['terlambat']]; }
        if (! $k['push']['siap']) { $masalah[] = ['kode' => 'push_belum_siap', 'berat' => 'kuning']; }
        elseif ($k['push']['gagal_24j'] > 0) { $masalah[] = ['kode' => 'push_gagal', 'berat' => 'kuning', 'n' => $k['push']['gagal_24j']]; }
        if (($k['pemeliharaan']['aktif'] ?? false)) { $masalah[] = ['kode' => 'pemeliharaan', 'berat' => 'kuning']; }
        if ($k['penyimpanan']['total_gb'] > 0 && $k['penyimpanan']['bebas_gb'] / $k['penyimpanan']['total_gb'] < 0.1) { $masalah[] = ['kode' => 'disk', 'berat' => 'kuning']; }
        if ($k['lingkungan']['env'] === 'production' && $k['lingkungan']['debug']) { $masalah[] = ['kode' => 'debug', 'berat' => 'kuning']; }

        $hari = fn ($d) => [$d->copy()->startOfDay()->toDateTimeString(), $d->copy()->endOfDay()->toDateTimeString()];
        $hitung = function (array $rentang) {
            [$a, $b] = $rentang;
            $q = fn () => DB::table('mobile_auth_log')->whereBetween('created_at', [$a, $b]);

            return [
                'pengguna' => (int) $q()->whereIn('event', ['login_ok', 'face_ok'])->whereNotNull('id_user')->distinct()->count('id_user'),
                'gagal' => (int) $q()->where('event', 'like', '%fail')->count(),
                'tindakan_admin' => (int) $q()->whereIn('event', ['face_reset_admin', 'pengaturan_ubah', 'akses_ubah'])->count(),
            ];
        };
        $ini = $hitung($hari(now()));
        $lalu = $hitung($hari(now()->subDay()));

        $p = DB::table('mobile_announcements as a')->where('a.status', 'terkirim')->orderByDesc('a.dikirim_pada')->orderByDesc('a.id')
            ->select(['a.id', 'a.judul', 'a.jenis', 'a.dikirim_pada', 'a.dikirim_ke', 'a.push_ke', DB::raw('(select count(*) from mobile_announcement_reads r where r.id_announcement = a.id) as dibaca_n')])->first();

        return response()->json([
            'status' => $merah ? 'merah' : ($masalah ? 'kuning' : 'hijau'),
            'masalah' => $masalah,
            'sistem' => [
                'scheduler_detik' => $k['scheduler']['detik'], 'perangkat_push' => $k['push']['perangkat'], 'gagal_push_24j' => $k['push']['gagal_24j'],
                'versi' => $k['versi']['rilis'], 'versi_terbaru' => $k['versi']['terbaru'], 'pengguna' => $k['versi']['pengguna'],
            ],
            'aktivitas' => ['hari_ini' => $ini, 'kemarin' => $lalu],
            'pengumuman' => $p ? [
                'id' => (int) $p->id, 'judul' => $p->judul, 'jenis' => $p->jenis, 'dikirim_pada' => $p->dikirim_pada, 'dikirim_ke' => (int) $p->dikirim_ke, 'push_ke' => (int) $p->push_ke,
                'dibaca' => (int) $p->dibaca_n, 'persen' => $p->dikirim_ke > 0 ? (int) round($p->dibaca_n / $p->dikirim_ke * 100) : 0,
            ] : null,
            'pengumuman_terjadwal' => (int) $k['pengumuman']['terjadwal'],
        ]);
    }

    // ---------- wajah login ----------

    public function wajah(Request $r): JsonResponse
    {
        $f = DB::raw('(select id_user, max(created_at) as dibuat, max(last_used_at) as dipakai, max(device) as device from mobile_face_templates group by id_user) as f');
        $base = fn () => DB::table('acl_user as u')->where('u.is_active', 1)->leftJoin('acl_role as ro', 'ro.id_role', '=', 'u.id_role')->leftJoin($f, 'f.id_user', '=', 'u.id_user');
        $total = $base()->count();
        $daftar = $base()->whereNotNull('f.id_user')->count();
        $q = trim((string) $r->query('q', ''));
        $page = $base()
            ->when($q !== '', fn ($w) => $w->where('u.fullname', 'like', "%$q%"))
            ->when($r->query('filter') === 'belum', fn ($w) => $w->whereNull('f.id_user'))
            ->when($r->query('filter') === 'terdaftar', fn ($w) => $w->whereNotNull('f.id_user'))
            ->when($r->query('filter') === 'bermasalah', fn ($w) => $w->whereRaw("(select count(*) from mobile_auth_log a where a.id_user = u.id_user and a.event = 'face_fail' and a.created_at >= ?) >= 3", [now()->subDays(30)]))
            ->orderBy('u.fullname')
            ->paginate($this->perPage($r), ['u.id_user', 'u.fullname', 'ro.role_name', 'f.id_user as ada', 'f.dibuat', 'f.dipakai', 'f.device',
                DB::raw("(select count(*) from mobile_auth_log a where a.id_user = u.id_user and a.event = 'face_fail' and a.created_at >= '".now()->subDays(30)->toDateTimeString()."') as gagal")]);

        return response()->json([
            'ringkasan' => ['pengguna' => $total, 'terdaftar' => $daftar, 'belum' => $total - $daftar],
            'data' => collect($page->items())->map(fn ($x) => [
                'id' => (int) $x->id_user, 'nama' => $x->fullname, 'peran' => self::peran($x->role_name), 'terdaftar' => $x->ada !== null,
                'didaftarkan' => $x->dibuat, 'terakhir_dipakai' => $x->dipakai, 'gagal_30h' => (int) $x->gagal,
                'perangkat' => $x->device ? mb_substr(preg_replace('/\s*\(.*?\)\s*/', ' ', (string) $x->device), 0, 60) : null,
            ])->all(),
            'meta' => self::meta($page),
        ]);
    }

    /** Reset wajah pengguna: templat dihapus, pengguna harus mendaftar ulang (login password tetap bisa). */
    public function resetWajah(Request $r, int $id): JsonResponse
    {
        $u = DB::table('acl_user')->where('id_user', $id)->first(['id_user', 'username', 'fullname']);
        if (! $u) {
            return response()->json(['message' => 'Pengguna tidak ditemukan.'], 404);
        }
        $n = DB::table('mobile_face_templates')->where('id_user', $id)->delete();
        if ($n === 0) {
            return response()->json(['message' => 'Pengguna ini belum mendaftarkan wajah.', 'code' => 'BELUM_DAFTAR'], 409);
        }
        $this->audit('face_reset_admin', $r, $id, $u->username);

        return response()->json(['message' => 'Wajah '.$u->fullname.' direset. Pengguna perlu mendaftar ulang.']);
    }

    // ---------- log aktivitas ----------

    private const LABEL_AUTH = [
        'login_ok' => 'Login berhasil', 'login_fail' => 'Login gagal', 'face_ok' => 'Login wajah berhasil', 'face_fail' => 'Login wajah gagal',
        'face_enroll' => 'Daftar wajah', 'face_delete' => 'Hapus wajah', 'face_reset_admin' => 'Wajah direset admin', 'pengaturan_ubah' => 'Pengaturan diubah', 'akses_ubah' => 'Hak akses diubah',
    ];

    private const LABEL_AKSI = ['approve' => 'Menyetujui', 'reject' => 'Menolak', 'escalate' => 'Eskalasi'];

    public function log(Request $r): JsonResponse
    {
        $jenis = in_array($r->query('jenis'), ['login', 'persetujuan', 'notifikasi'], true) ? $r->query('jenis') : 'login';
        $q = trim((string) $r->query('q', ''));
        $dari = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r->query('dari')) ? $r->query('dari').' 00:00:00' : null;
        $sampai = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r->query('sampai')) ? $r->query('sampai').' 23:59:59' : null;
        $per = $this->perPage($r);

        try {
            return $this->logQuery($jenis, $q, $dari, $sampai, $per, $r);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e); // skema tabel log lama di server berbeda: jangan 500

            return $this->logKosong($jenis);
        }
    }

    private function logQuery(string $jenis, string $q, ?string $dari, ?string $sampai, int $per, Request $r): JsonResponse
    {
        if ($jenis === 'login') {
            $page = DB::table('mobile_auth_log as a')->leftJoin('acl_user as u', 'u.id_user', '=', 'a.id_user')
                ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('u.fullname', 'like', "%$q%")->orWhere('a.username', 'like', "%$q%")))
                ->when($r->query('status') === 'gagal', fn ($w) => $w->where('a.event', 'like', '%fail'))
                ->when($dari, fn ($w) => $w->where('a.created_at', '>=', $dari))->when($sampai, fn ($w) => $w->where('a.created_at', '<=', $sampai))
                ->orderByDesc('a.id')->paginate($per, ['a.*', 'u.fullname']);
            $rows = collect($page->items())->map(fn ($x) => [
                'waktu' => $x->created_at, 'pengguna' => $x->fullname ?: ($x->username ?: '—'), 'judul' => self::LABEL_AUTH[$x->event] ?? $x->event,
                'detail' => trim('IP '.$x->ip.($x->distance !== null ? ' · jarak '.round((float) $x->distance, 3) : '')), 'gagal' => str_ends_with($x->event, 'fail'),
            ]);
        } elseif ($jenis === 'persetujuan') {
            // Tabel ini peninggalan backend lama dan tidak ditulis aplikasi sekarang; di server bisa tidak ada / skemanya berbeda.
            if (! Schema::hasTable('mobile_approval_log')) {
                return $this->logKosong($jenis);
            }
            $page = DB::table('mobile_approval_log as l')->leftJoin('acl_user as u', 'u.id_user', '=', 'l.user_id')
                ->when($q !== '', fn ($w) => $w->where('u.fullname', 'like', "%$q%"))
                ->when($dari, fn ($w) => $w->where('l.created_at', '>=', $dari))->when($sampai, fn ($w) => $w->where('l.created_at', '<=', $sampai))
                ->orderByDesc('l.id')->paginate($per, ['l.*', 'u.fullname']);
            $rows = collect($page->items())->map(fn ($x) => [
                'waktu' => $x->created_at, 'pengguna' => $x->fullname ?: '#'.$x->user_id,
                'judul' => (self::LABEL_AKSI[$x->action] ?? $x->action).' '.str_replace('_', ' ', (string) $x->document_type).' #'.($x->document_id ?: $x->penawaran_id),
                'detail' => 'Status '.$x->before_status.' → '.$x->after_status.($x->notes ? ' · '.mb_substr(trim(strip_tags((string) $x->notes)), 0, 120) : ''), 'gagal' => $x->action === 'reject',
            ]);
        } else {
            if (! Schema::hasTable('mobile_notification_log')) {
                return $this->logKosong($jenis);
            }
            $page = DB::table('mobile_notification_log as n')->leftJoin('acl_user as u', 'u.id_user', '=', 'n.user_id')
                ->when($q !== '', fn ($w) => $w->where('u.fullname', 'like', "%$q%"))
                ->when($dari, fn ($w) => $w->where('n.created_at', '>=', $dari))->when($sampai, fn ($w) => $w->where('n.created_at', '<=', $sampai))
                ->orderByDesc('n.id')->paginate($per, ['n.*', 'u.fullname']);
            $rows = collect($page->items())->map(fn ($x) => [
                'waktu' => $x->created_at, 'pengguna' => $x->fullname ?: '#'.$x->user_id, 'judul' => $x->title,
                'detail' => mb_substr(trim(strip_tags((string) $x->body)), 0, 140).($x->is_read ? ' · dibaca' : ''), 'gagal' => false,
            ]);
        }

        return response()->json(['jenis' => $jenis, 'data' => $rows->values()->all(), 'meta' => self::meta($page)]);
    }

    private function logKosong(string $jenis): JsonResponse
    {
        return response()->json(['jenis' => $jenis, 'data' => [], 'tersedia' => false, 'meta' => ['page' => 1, 'per_page' => 30, 'total' => 0, 'last' => 1]]);
    }

    // ---------- pengaturan ----------

    public function pengaturan(): JsonResponse
    {
        return response()->json(['pemeliharaan' => AppSettings::pemeliharaan()]);
    }

    public function simpanPengaturan(Request $r): JsonResponse
    {
        $d = $r->validate([
            'aktif' => ['required', 'boolean'], 'pesan' => ['nullable', 'string', 'max:300'], 'sampai' => ['nullable', 'date', 'after:now'],
        ], ['sampai.after' => 'Waktu berakhir harus di masa depan.']);
        AppSettings::simpanPemeliharaan((bool) $d['aktif'], isset($d['pesan']) ? trim($d['pesan']) : null, $d['sampai'] ?? null);
        $this->audit('pengaturan_ubah', $r, null, $d['aktif'] ? 'pemeliharaan on' : 'pemeliharaan off');

        return response()->json(['message' => $d['aktif'] ? 'Mode pemeliharaan diaktifkan.' : 'Mode pemeliharaan dimatikan.', 'pemeliharaan' => AppSettings::pemeliharaan()]);
    }

    /** Status publik (tanpa login): dipakai aplikasi untuk menampilkan layar pemeliharaan dan pulih otomatis. */
    public function status(): JsonResponse
    {
        $m = AppSettings::pemeliharaan();

        return response()->json(['pemeliharaan' => $m['aktif'], 'pesan' => $m['pesan'], 'sampai' => $m['sampai']]);
    }
}
