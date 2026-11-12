<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Peminjaman mobil (pro_peminjaman_mobil + pro_master_mobil), sumber: peminjaman-mobil*.php.
 * Mobil yang tampil mengikuti cabang pengguna (cabang 1 dan 2 digabung). Peran 1 dan 6 (OM) bebas memilih cabang,
 * begitu juga peran 14 di cabang 1; peran 14 boleh mengubah/menghapus milik siapa pun, selain itu hanya pemesannya.
 */
class MobilController extends Controller
{
    private const T = 'pro_peminjaman_mobil';

    private static function lintas(Request $r): bool
    {
        $u = $r->user();

        return in_array((int) $u->id_role, [1, 6], true) || ((int) $u->id_role === 14 && (int) $u->id_wilayah === 1);
    }

    private static function admin(Request $r): bool
    {
        return (int) $r->user()->id_role === 14;
    }

    /** Cabang 1 dan 2 satu kelompok (kunci 2). */
    private static function kunci(int $c): int
    {
        return in_array($c, [1, 2], true) ? 2 : $c;
    }

    private static function ids(int $kunci): array
    {
        return $kunci === 2 ? [1, 2] : [$kunci];
    }

    private function opsi(): array
    {
        $ada = DB::table('pro_master_mobil')->where('is_active', 1)->pluck('id_cabang')->map(fn ($c) => self::kunci((int) $c))->unique()->values();
        $nama = DB::table('pro_master_cabang')->whereIn('id_master', $ada)->pluck('nama_cabang', 'id_master');

        return $ada->map(fn ($k) => ['id' => $k, 'nama' => $nama[$k] ?? (string) $k])->sortBy('nama')->values()->all();
    }

    /** Kunci cabang yang sedang dilihat: pilihan (hanya untuk peran lintas) atau cabang pengguna. */
    private function kunciAktif(Request $r): int
    {
        $own = self::kunci((int) $r->user()->id_wilayah);
        if (self::lintas($r) && $r->filled('cabang')) {
            return self::kunci((int) $r->query('cabang'));
        }

        return $own;
    }

    private static function menit(string $hm): int
    {
        return (int) substr($hm, 0, 2) * 60 + (int) substr($hm, 3, 2);
    }

    private function item($b, int $me, bool $admin): array
    {
        return [
            'id' => (int) $b->id_peminjaman, 'mulai' => substr($b->start_jam_peminjaman, 0, 5), 'selesai' => substr($b->end_jam_peminjaman, 0, 5),
            'keperluan' => $b->keperluan, 'oleh' => $b->fullname, 'milik_saya' => (int) $b->id_user === $me,
            'bisa_ubah' => $admin || (int) $b->id_user === $me,
            'km' => (float) $b->last_km, 'bensin' => (int) $b->bensin,
        ];
    }

    public function index(Request $r): JsonResponse
    {
        $v = $r->validate(['tanggal' => ['nullable', 'date_format:Y-m-d'], 'cabang' => ['nullable', 'integer']]);
        $tgl = $v['tanggal'] ?? now()->toDateString();
        $k = $this->kunciAktif($r);
        $me = (int) $r->user()->id_user;
        $admin = self::admin($r);

        $cars = DB::table('pro_master_mobil')->whereIn('id_cabang', self::ids($k))->where('is_active', 1)->orderBy('nama_mobil')->get(['id_mobil', 'nama_mobil', 'plat_mobil']);
        $books = DB::table(self::T.' as a')->join('acl_user as u', 'a.id_user', '=', 'u.id_user')
            ->whereIn('a.id_mobil', $cars->pluck('id_mobil'))->where('a.tanggal_peminjaman', $tgl)->whereNull('a.deleted_time')
            ->orderBy('a.start_jam_peminjaman')
            ->get(['a.id_peminjaman', 'a.id_mobil', 'a.id_user', 'a.start_jam_peminjaman', 'a.end_jam_peminjaman', 'a.keperluan', 'a.last_km', 'a.bensin', 'u.fullname'])
            ->groupBy('id_mobil');

        return response()->json([
            'tanggal' => $tgl,
            'cabang' => ['id' => $k, 'nama' => DB::table('pro_master_cabang')->where('id_master', $k)->value('nama_cabang')],
            'pilih_cabang' => self::lintas($r) ? $this->opsi() : [],
            'mobil' => $cars->map(fn ($x) => [
                'id' => (int) $x->id_mobil, 'nama' => $x->nama_mobil, 'plat' => $x->plat_mobil,
                'reservasi' => ($books[$x->id_mobil] ?? collect())->map(fn ($b) => $this->item($b, $me, $admin))->values(),
            ])->values(),
        ]);
    }

    private function input(Request $r, ?object $lama = null): array
    {
        $jam = ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'];
        $d = $r->validate([
            'mobil' => ['required', 'integer'], 'tanggal' => ['required', 'date_format:Y-m-d'], 'mulai' => $jam, 'selesai' => $jam,
            'keperluan' => ['required', 'string', 'max:255'],
            'km' => ['nullable', 'numeric', 'min:0', 'max:9999999'], 'bensin' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);
        $gagal = fn (string $m, string $c) => abort(response()->json(['message' => $m, 'code' => $c], 422));
        if (self::menit($d['selesai']) <= self::menit($d['mulai'])) {
            $gagal('Jam selesai harus setelah jam mulai.', 'BAD_TIME');
        }
        // Tanggal lampau hanya boleh bila tanggal tidak diubah (mengisi km/bensin sesudah pemakaian).
        if ($d['tanggal'] < now()->toDateString() && (! $lama || $lama->tanggal_peminjaman !== $d['tanggal'])) {
            $gagal('Tanggal peminjaman tidak boleh sebelum hari ini.', 'PAST');
        }
        $car = DB::table('pro_master_mobil')->where('id_mobil', $d['mobil'])->where('is_active', 1)->first();
        $boleh = $car && (self::lintas($r) || in_array((int) $car->id_cabang, self::ids(self::kunci((int) $r->user()->id_wilayah)), true));
        if (! $boleh) {
            $gagal('Mobil tidak tersedia untuk cabang Anda.', 'CAR');
        }
        $d['cabang'] = (int) $car->id_cabang;

        return $d;
    }

    private function bentrok(array $d, ?int $kecuali)
    {
        $m = self::menit($d['mulai']);
        $s = self::menit($d['selesai']);

        return DB::table(self::T.' as a')->join('acl_user as u', 'a.id_user', '=', 'u.id_user')
            ->where('a.id_mobil', $d['mobil'])->where('a.tanggal_peminjaman', $d['tanggal'])->whereNull('a.deleted_time')
            ->when($kecuali, fn ($q) => $q->where('a.id_peminjaman', '!=', $kecuali))
            ->get(['a.start_jam_peminjaman', 'a.end_jam_peminjaman', 'u.fullname'])
            ->first(fn ($b) => $m < self::menit(substr($b->end_jam_peminjaman, 0, 5)) && $s > self::menit(substr($b->start_jam_peminjaman, 0, 5)));
    }

    private function tolak($b): JsonResponse
    {
        return response()->json(['message' => 'Telah dipinjam oleh '.$b->fullname.' ('.substr($b->start_jam_peminjaman, 0, 5).'-'.substr($b->end_jam_peminjaman, 0, 5).').', 'code' => 'TAKEN'], 409);
    }

    public function store(Request $r): JsonResponse
    {
        $d = $this->input($r);

        return DB::transaction(function () use ($r, $d) {
            DB::table('pro_master_mobil')->where('id_mobil', $d['mobil'])->lockForUpdate()->first();
            if ($b = $this->bentrok($d, null)) {
                return $this->tolak($b);
            }
            $id = DB::table(self::T)->insertGetId([
                'id_mobil' => $d['mobil'], 'id_user' => $r->user()->id_user, 'id_cabang' => $d['cabang'], 'tanggal_peminjaman' => $d['tanggal'],
                'start_jam_peminjaman' => $d['mulai'].':00', 'end_jam_peminjaman' => $d['selesai'].':00', 'keperluan' => $d['keperluan'],
                'created_time' => now(), 'created_by' => $r->user()->fullname, 'deleted_time' => null,
            ]);

            return response()->json(['id' => $id], 201);
        });
    }

    private function milik(Request $r, int $id)
    {
        $row = DB::table(self::T)->where('id_peminjaman', $id)->whereNull('deleted_time')->first();
        if (! $row) {
            abort(response()->json(['message' => 'Peminjaman tidak ditemukan.'], 404));
        }
        if (! self::admin($r) && (int) $row->id_user !== (int) $r->user()->id_user) {
            abort(response()->json(['message' => 'Hanya peminjam yang dapat mengubah atau membatalkan peminjaman ini.', 'code' => 'NOT_OWNER'], 403));
        }

        return $row;
    }

    public function update(Request $r, int $id): JsonResponse
    {
        $lama = $this->milik($r, $id);
        $d = $this->input($r, $lama);

        return DB::transaction(function () use ($d, $id) {
            DB::table('pro_master_mobil')->where('id_mobil', $d['mobil'])->lockForUpdate()->first();
            if ($b = $this->bentrok($d, $id)) {
                return $this->tolak($b);
            }
            DB::table(self::T)->where('id_peminjaman', $id)->update([
                'id_mobil' => $d['mobil'], 'id_cabang' => $d['cabang'], 'tanggal_peminjaman' => $d['tanggal'],
                'start_jam_peminjaman' => $d['mulai'].':00', 'end_jam_peminjaman' => $d['selesai'].':00', 'keperluan' => $d['keperluan'],
                'last_km' => $d['km'] ?? 0, 'bensin' => $d['bensin'] ?? 0, 'created_time' => now(),
            ]);

            return response()->json(['ok' => true]);
        });
    }

    public function destroy(Request $r, int $id): JsonResponse
    {
        $this->milik($r, $id);
        DB::table(self::T)->where('id_peminjaman', $id)->delete();

        return response()->json(['ok' => true]);
    }
}
