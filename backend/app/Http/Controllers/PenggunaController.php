<?php

namespace App\Http\Controllers;

use App\Support\LegacyPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Kelola pengguna (Super Admin). Mengikuti web lama: acl-user.php, add-acl-user.php, datatable/user.php, __get_wilayah.php.
 *  - daftar: cari username/nama/nama OM, status, cabang, peran
 *  - tambah: aturan wilayah/group per peran (6 OM = group, 18 CS = cabang/group/nasional), transportir (12), terminal (13), OM (17)
 *  - ubah: hanya status aktif (dan OM untuk peran 17), sama seperti web
 *  - reset password: acak 8 karakter, dikirim ke email pengguna (gagal kirim = dibatalkan)
 */
class PenggunaController extends Controller
{
    private static function peran(?string $n): string
    {
        return preg_replace('/^Role /', '', (string) $n);
    }

    private function audit(Request $r, string $event, int $target, string $ket): void
    {
        DB::table('mobile_auth_log')->insert([
            'id_user' => $target, 'username' => mb_substr(trim($ket.' oleh '.$r->user()->username), 0, 50),
            'event' => $event, 'ip' => $r->ip(), 'user_agent' => mb_substr((string) $r->userAgent(), 0, 255),
        ]);
    }

    private function dasar()
    {
        return DB::table('acl_user as a')
            ->join('acl_role as b', 'a.id_role', '=', 'b.id_role')
            ->leftJoin('pro_master_cabang as c', 'a.id_wilayah', '=', 'c.id_master')
            ->leftJoin('pro_master_group_cabang as f', 'a.id_group', '=', 'f.id_gu')
            ->leftJoin('pro_master_transportir as d', 'a.id_transportir', '=', 'd.id_master')
            ->leftJoin('pro_master_terminal as e', 'a.id_terminal', '=', 'e.id_master')
            ->leftJoin('acl_user as g', 'a.id_om', '=', 'g.id_user');
    }

    private const KOLOM = ['a.id_user', 'a.username', 'a.fullname', 'a.email_user', 'a.mobile_user', 'a.id_role', 'a.id_wilayah', 'a.id_group', 'a.id_om', 'a.is_active', 'a.last_login_time',
        'b.role_name', 'c.nama_cabang', 'f.group_wilayah', 'd.nama_transportir', 'e.nama_terminal', 'g.fullname as nama_omnya'];

    private static function wilayah(object $x): ?string
    {
        $role = (int) $x->id_role;
        $w = $x->id_wilayah === null ? null : (int) $x->id_wilayah;
        $g = $x->id_group === null ? null : (int) $x->id_group;
        if ($role === 12) {
            return $x->nama_transportir;
        }
        if ($role === 13) {
            return $x->nama_terminal;
        }
        if ($role === 17) {
            return $x->nama_omnya;
        }
        if ($role === 6) {
            return $x->group_wilayah ?: $x->nama_cabang;
        }
        if (! $w && ! $g) {
            return 'Nasional';
        }
        if (! $w && $g) {
            return $x->group_wilayah;
        }

        return $x->nama_cabang;
    }

    private function bentuk(object $x): array
    {
        return [
            'id' => (int) $x->id_user, 'username' => $x->username, 'nama' => $x->fullname, 'email' => $x->email_user, 'telepon' => $x->mobile_user,
            'id_role' => (int) $x->id_role, 'peran' => self::peran($x->role_name), 'wilayah' => self::wilayah($x),
            'aktif' => (bool) $x->is_active, 'login_terakhir' => $x->last_login_time, 'id_om' => (int) $x->id_om ?: null,
        ];
    }

    public function index(Request $r): JsonResponse
    {
        $q = trim((string) $r->query('q', ''));
        $status = (string) $r->query('status', '1'); // 1 aktif, 0 tidak aktif, 2 semua
        $page = $this->dasar()
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('a.username', 'like', "%$q%")->orWhere('a.fullname', 'like', "%$q%")->orWhere('g.fullname', 'like', "%$q%")))
            ->when(in_array($status, ['0', '1'], true), fn ($w) => $w->where('a.is_active', (int) $status))
            ->when($r->filled('cabang'), fn ($w) => $w->where('a.id_wilayah', (int) $r->query('cabang')))
            ->when($r->filled('role'), fn ($w) => $w->where('a.id_role', (int) $r->query('role')))
            ->orderByDesc('a.is_active')->orderBy('a.username')
            ->paginate(min(max((int) $r->query('per_page', 25), 1), (int) config('orca.per_page_max')), self::KOLOM);

        return response()->json([
            'data' => collect($page->items())->map(fn ($x) => $this->bentuk($x))->all(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage()],
        ]);
    }

    /** Pilihan untuk filter dan form (peran, cabang, transportir, terminal, OM). */
    public function opsi(): JsonResponse
    {
        return response()->json([
            'peran' => DB::table('acl_role')->where('is_active', 1)->orderBy('no_urut')->get(['id_role', 'role_name'])
                ->map(fn ($x) => ['id' => (int) $x->id_role, 'nama' => self::peran($x->role_name)])->all(),
            'cabang' => DB::table('pro_master_cabang')->where('is_active', 1)->orderBy('id_master')->get(['id_master', 'nama_cabang'])
                ->map(fn ($x) => ['id' => (int) $x->id_master, 'nama' => $x->nama_cabang])->all(),
            'group' => DB::table('pro_master_group_cabang')->where('id_gu', '<>', 1)->get(['id_gu', 'group_wilayah'])
                ->map(fn ($x) => ['id' => (int) $x->id_gu, 'nama' => $x->group_wilayah])->all(),
            'transportir' => DB::table('pro_master_transportir')->where('is_active', 1)->whereIn('tipe_angkutan', [1, 3])->orderBy('nama_transportir')
                ->get(['id_master', 'nama_transportir', 'lokasi_suplier'])
                ->map(fn ($x) => ['id' => (int) $x->id_master, 'nama' => trim($x->nama_transportir.' - '.$x->lokasi_suplier, ' -')])->all(),
            'terminal' => DB::table('pro_master_terminal')->where('is_active', 1)->orderBy('nama_terminal')->get(['id_master', 'nama_terminal', 'tanki_terminal', 'lokasi_terminal'])
                ->map(fn ($x) => ['id' => (int) $x->id_master, 'nama' => trim($x->nama_terminal.', '.$x->tanki_terminal.' - '.$x->lokasi_terminal, ' ,-')])->all(),
            'om' => DB::table('acl_user')->where('is_active', 1)->where('id_role', 6)->orderBy('fullname')->get(['id_user', 'fullname'])
                ->map(fn ($x) => ['id' => (int) $x->id_user, 'nama' => $x->fullname])->all(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $x = $this->dasar()->where('a.id_user', $id)->first(self::KOLOM);
        if (! $x) {
            return response()->json(['message' => 'Pengguna tidak ditemukan.'], 404);
        }

        return response()->json($this->bentuk($x));
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'username' => ['required', 'string', 'max:50'],
            'fullname' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:60'],
            'telepon' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:6', 'max:40', 'confirmed'],
            'id_role' => ['required', 'integer'],
            'id_wilayah' => ['nullable', 'integer'],
            'cakupan' => ['nullable', 'in:1,2,3'], // khusus peran 18: 1 cabang, 2 group, 3 nasional
            'id_transportir' => ['nullable', 'integer'],
            'id_terminal' => ['nullable', 'integer'],
            'id_om' => ['nullable', 'integer'],
            'aktif' => ['nullable', 'boolean'],
        ], [
            'password.confirmed' => 'Konfirmasi password tidak sesuai.',
            'password.min' => 'Password minimal 6 karakter.',
        ]);
        $role = (int) $d['id_role'];
        if (! DB::table('acl_role')->where('id_role', $role)->where('is_active', 1)->exists()) {
            return response()->json(['message' => 'Peran tidak dikenal.'], 422);
        }
        if (DB::table('acl_user')->where('username', $d['username'])->exists()) {
            return response()->json(['message' => 'Username sudah dipakai.'], 422);
        }
        $transportir = (int) ($d['id_transportir'] ?? 0);
        $terminal = (int) ($d['id_terminal'] ?? 0);
        $om = (int) ($d['id_om'] ?? 0);
        if ($role === 12 && ! $transportir) {
            return response()->json(['message' => 'Transportir belum diisi.'], 422);
        }
        if ($role === 13 && ! $terminal) {
            return response()->json(['message' => 'Terminal belum diisi.'], 422);
        }
        if ($role === 17 && ! $om) {
            return response()->json(['message' => 'Operation Manager belum diisi.'], 422);
        }

        // Aturan wilayah sama dengan acl-user.php (action add).
        $wil = (int) ($d['id_wilayah'] ?? 0);
        $cakupan = (string) ($d['cakupan'] ?? '1');
        if ($role === 6) {
            $group = $wil;
            $wil = (int) DB::table('pro_master_cabang')->where('id_group_cabang', $group)->value('id_master');
        } elseif ($role === 18 && $cakupan === '3') {
            $group = 0;
            $wil = 0;
        } elseif ($role === 18 && $cakupan === '2') {
            $group = $wil;
            $wil = 0;
        } else {
            if (! $wil && ! in_array($role, [12, 13, 17], true) && ! ($role === 18)) {
                return response()->json(['message' => 'Wilayah belum diisi.'], 422);
            }
            $group = (int) DB::table('pro_master_cabang')->where('id_master', $wil)->value('id_group_cabang');
        }

        try {
            $hash = LegacyPassword::make($d['password']);
        } catch (\InvalidArgumentException) {
            return response()->json(['message' => 'Password anda terlalu panjang.'], 422);
        }

        $id = DB::table('acl_user')->insertGetId([
            'username' => $d['username'], 'password' => $hash, 'fullname' => $d['fullname'], 'mobile_user' => $d['telepon'], 'email_user' => $d['email'],
            'id_wilayah' => $wil, 'id_group' => $group, 'id_role' => $role, 'id_transportir' => $transportir, 'id_terminal' => $terminal, 'id_om' => $om,
            'is_active' => ($d['aktif'] ?? true) ? 1 : 0, 'created_time' => now(), 'created_ip' => $r->ip(), 'created_by' => $r->user()->fullname,
        ]);
        $this->audit($r, 'pengguna_tambah', $id, $d['username']);

        return response()->json(['message' => 'Pengguna dibuat.', 'id' => $id], 201);
    }

    /** Sama dengan web: yang bisa diubah hanya status aktif, dan Operation Manager untuk peran 17. */
    public function update(Request $r, int $id): JsonResponse
    {
        $u = DB::table('acl_user')->where('id_user', $id)->first(['id_user', 'username', 'id_role', 'is_active']);
        if (! $u) {
            return response()->json(['message' => 'Pengguna tidak ditemukan.'], 404);
        }
        $d = $r->validate(['aktif' => ['required', 'boolean'], 'id_om' => ['nullable', 'integer']]);
        if ($id === (int) $r->user()->id_user && ! $d['aktif']) {
            return response()->json(['message' => 'Anda tidak bisa menonaktifkan akun sendiri.'], 422);
        }
        $set = ['is_active' => $d['aktif'] ? 1 : 0];
        if ((int) $u->id_role === 17) {
            $om = (int) ($d['id_om'] ?? 0);
            if (! $om || ! DB::table('acl_user')->where('id_user', $om)->where('id_role', 6)->exists()) {
                return response()->json(['message' => 'Operation Manager belum diisi.'], 422);
            }
            $set['id_om'] = $om;
        }
        if (Schema::hasColumn('acl_user', 'lastupdate_time')) { // sama dengan deleteTable.php (activeAcc)
            $set += ['lastupdate_time' => now(), 'lastupdate_ip' => mb_substr((string) $r->ip(), 0, 20), 'lastupdate_by' => mb_substr((string) $r->user()->fullname, 0, 50)];
        }
        DB::table('acl_user')->where('id_user', $id)->update($set);
        if (! $d['aktif']) {
            DB::table('personal_access_tokens')->where('tokenable_id', $id)->delete(); // nonaktif = keluar dari semua perangkat
        }
        $this->audit($r, 'pengguna_ubah', $id, $u->username.($d['aktif'] ? ' aktif' : ' nonaktif'));

        return response()->json(['message' => 'Pengguna diperbarui.']);
    }

    /** Reset password: acak 8 karakter, dikirim ke email pengguna; bila email gagal terkirim, password tidak diubah. */
    public function resetPassword(Request $r, int $id): JsonResponse
    {
        $u = DB::table('acl_user')->where('id_user', $id)->first(['id_user', 'username', 'email_user']);
        if (! $u) {
            return response()->json(['message' => 'Pengguna tidak ditemukan.'], 404);
        }
        if (! filter_var($u->email_user, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['message' => 'Email pengguna belum valid, password tidak bisa dikirim.', 'code' => 'EMAIL_KOSONG'], 422);
        }
        $baru = Str::password(8, true, true, false, false);
        $lama = DB::table('acl_user')->where('id_user', $id)->value('password');
        DB::table('acl_user')->where('id_user', $id)->update(['password' => LegacyPassword::make($baru)]);
        try {
            Mail::html('<p>Password : '.e($baru).'</p><p>'.e((string) config('app.url')).'</p>', fn ($m) => $m->to($u->email_user)->subject('Reset Password Aplikasi Pro Energi'));
        } catch (\Throwable $e) {
            DB::table('acl_user')->where('id_user', $id)->update(['password' => $lama]);

            return response()->json(['message' => 'Email gagal dikirim, password tidak diubah.', 'code' => 'EMAIL_GAGAL'], 502);
        }
        DB::table('personal_access_tokens')->where('tokenable_id', $id)->delete();
        $this->audit($r, 'pengguna_reset_sandi', $id, $u->username);

        return response()->json(['message' => 'Password '.$u->username.' direset dan dikirim ke emailnya.']);
    }

    /** Reset password manual (acl-change-password.php dengan idr): admin mengetik password baru, tanpa password lama. */
    public function aturPassword(Request $r, int $id): JsonResponse
    {
        $u = DB::table('acl_user')->where('id_user', $id)->first(['id_user', 'username']);
        if (! $u) {
            return response()->json(['message' => 'Pengguna tidak ditemukan.'], 404);
        }
        $d = $r->validate(['password' => ['required', 'string', 'min:6', 'max:40', 'confirmed']], [
            'password.confirmed' => 'Konfirmasi password tidak sesuai.', 'password.min' => 'Password minimal 6 karakter.',
        ]);
        try {
            $hash = LegacyPassword::make($d['password']);
        } catch (\InvalidArgumentException) {
            return response()->json(['message' => 'Password anda terlalu panjang.'], 422);
        }
        DB::table('acl_user')->where('id_user', $id)->update(['password' => $hash]);
        DB::table('personal_access_tokens')->where('tokenable_id', $id)->delete();
        $this->audit($r, 'pengguna_atur_sandi', $id, $u->username);

        return response()->json(['message' => 'Password '.$u->username.' diperbarui. Pengguna keluar dari semua perangkat.']);
    }

    /**
     * Ubah peran (acl-user-roles.php): hanya id_role yang berubah, lalu izin menu web dibangun ulang dari menu peran baru
     * (acl_role_permission). Wilayah, group, dan OM tidak disentuh, sama seperti web.
     */
    public function ubahPeran(Request $r, int $id): JsonResponse
    {
        $u = DB::table('acl_user')->where('id_user', $id)->first(['id_user', 'username', 'id_role']);
        if (! $u) {
            return response()->json(['message' => 'Pengguna tidak ditemukan.'], 404);
        }
        $d = $r->validate(['id_role' => ['required', 'integer']]);
        $baru = (int) $d['id_role'];
        if (! DB::table('acl_role')->where('id_role', $baru)->where('is_active', 1)->exists()) {
            return response()->json(['message' => 'Peran tidak dikenal.'], 422);
        }
        if ($baru === (int) $u->id_role) {
            return response()->json(['message' => 'Peran baru masih sama dengan peran sebelumnya.', 'code' => 'SAMA'], 422);
        }
        if ($id === (int) $r->user()->id_user) {
            return response()->json(['message' => 'Anda tidak bisa mengubah peran akun sendiri.'], 422);
        }
        DB::transaction(function () use ($id, $baru) {
            DB::table('acl_user')->where('id_user', $id)->update(['id_role' => $baru]);
            if (Schema::hasTable('acl_role_permission') && Schema::hasTable('acl_permission') && Schema::hasTable('acl_role_menu') && Schema::hasTable('acl_menu')) {
                DB::table('acl_role_permission')->where('id_user', $id)->delete();
                $izin = json_encode(DB::table('acl_permission')->pluck('permission')->mapWithKeys(fn ($p) => [$p => 1])->all());
                $menus = DB::table('acl_menu as a')->join('acl_role_menu as b', fn ($j) => $j->on('a.id_menu', '=', 'b.id_menu')->where('b.id_role', $baru))
                    ->where('a.menu_level', '<>', 0)->where('a.is_active', 1)->pluck('a.id_menu');
                foreach ($menus->chunk(200) as $c) {
                    DB::table('acl_role_permission')->insert($c->map(fn ($m) => ['id_user' => $id, 'id_role' => $baru, 'id_menu' => $m, 'permission' => $izin])->all());
                }
            }
            DB::table('personal_access_tokens')->where('tokenable_id', $id)->delete(); // menu aplikasi mengikuti peran
        });
        $this->audit($r, 'pengguna_ubah_peran', $id, $u->username.' '.$u->id_role.'>'.$baru);

        return response()->json(['message' => 'Peran '.$u->username.' diubah. Pengguna perlu login ulang.']);
    }

    /**
     * Hapus pengguna. Web lama (deleteTable.php, case "user") langsung menghapus baris acl_user, sehingga id-nya bisa
     * menggantung di dokumen lama. Di sini hanya akun yang belum pernah login (salah buat) yang boleh dihapus;
     * selebihnya dinonaktifkan saja.
     */
    public function destroy(Request $r, int $id): JsonResponse
    {
        $u = DB::table('acl_user')->where('id_user', $id)->first(['id_user', 'username', 'last_login_time']);
        if (! $u) {
            return response()->json(['message' => 'Pengguna tidak ditemukan.'], 404);
        }
        if ($id === (int) $r->user()->id_user) {
            return response()->json(['message' => 'Anda tidak bisa menghapus akun sendiri.'], 422);
        }
        $pernah = $u->last_login_time !== null || DB::table('mobile_auth_log')->where('id_user', $id)->whereIn('event', ['login_ok', 'face_ok'])->exists();
        if ($pernah) {
            return response()->json(['message' => 'Pengguna sudah pernah login, jadi tidak dihapus agar riwayat dokumennya utuh. Nonaktifkan saja.', 'code' => 'PERNAH_LOGIN'], 409);
        }
        DB::transaction(function () use ($id) {
            DB::table('personal_access_tokens')->where('tokenable_id', $id)->delete();
            foreach (['mobile_face_templates', 'mobile_user_photos', 'acl_role_permission'] as $t) {
                if (Schema::hasTable($t)) {
                    DB::table($t)->where('id_user', $id)->delete();
                }
            }
            DB::table('acl_user')->where('id_user', $id)->delete();
        });
        $this->audit($r, 'pengguna_hapus', $id, $u->username);

        return response()->json(['message' => 'Pengguna '.$u->username.' dihapus.']);
    }
}
