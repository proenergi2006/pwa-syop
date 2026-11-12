<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reservasi ruangan (pro_reservasi_ruangan + pro_master_ruangan), sumber: reservasi-ruangan.php (aksi add/update/delete).
 * Ruangan yang tampil hanya milik cabang pengguna (acl_user.id_wilayah = pro_master_ruangan.id_cabang).
 * Berbeda dari web: bentrok jam dicek sebagai tumpang tindih sebenarnya, dan ubah/hapus hanya oleh pemesannya.
 */
class ReservasiController extends Controller
{
    private const T = 'pro_reservasi_ruangan';

    private function cabang(Request $r): array
    {
        $id = (int) $r->user()->id_wilayah;
        $nama = DB::table('pro_master_cabang')->where('id_master', $id)->value('nama_cabang');

        return [$id, $nama];
    }

    private static function menit(string $hm): int
    {
        return (int) substr($hm, 0, 2) * 60 + (int) substr($hm, 3, 2);
    }

    private function item($b, int $me): array
    {
        [$mulai, $selesai] = array_pad(explode('-', (string) $b->jam_reservasi), 2, '');

        return [
            'id' => (int) $b->id_reservasi, 'mulai' => $mulai, 'selesai' => $selesai,
            'personel' => $b->personel, 'keperluan' => $b->keperluan,
            'oleh' => $b->fullname, 'milik_saya' => (int) $b->id_user === $me,
        ];
    }

    public function index(Request $r): JsonResponse
    {
        $v = $r->validate(['tanggal' => ['nullable', 'date_format:Y-m-d']]);
        $tgl = $v['tanggal'] ?? now()->toDateString();
        [$cab, $namaCab] = $this->cabang($r);
        $me = (int) $r->user()->id_user;

        $rooms = DB::table('pro_master_ruangan')->where('id_cabang', $cab)->where('is_active', 1)->orderBy('nama_ruangan')->get(['id_ruangan', 'nama_ruangan']);
        $books = DB::table(self::T.' as a')->join('acl_user as u', 'a.id_user', '=', 'u.id_user')
            ->whereIn('a.id_ruangan', $rooms->pluck('id_ruangan'))->where('a.tanggal_reservasi', $tgl)->whereNull('a.deleted_time')
            ->orderBy('a.jam_reservasi')->get(['a.id_reservasi', 'a.id_ruangan', 'a.id_user', 'a.jam_reservasi', 'a.personel', 'a.keperluan', 'u.fullname'])
            ->groupBy('id_ruangan');

        return response()->json([
            'tanggal' => $tgl,
            'cabang' => ['id' => $cab, 'nama' => $namaCab],
            'ruangan' => $rooms->map(fn ($x) => [
                'id' => (int) $x->id_ruangan, 'nama' => $x->nama_ruangan,
                'reservasi' => ($books[$x->id_ruangan] ?? collect())->map(fn ($b) => $this->item($b, $me))->values(),
            ])->values(),
        ]);
    }

    private function input(Request $r): array
    {
        $d = $r->validate([
            'ruangan' => ['required', 'integer'],
            'tanggal' => ['required', 'date_format:Y-m-d'],
            'mulai' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'selesai' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'personel' => ['required', 'string', 'max:255'],
            'keperluan' => ['required', 'string', 'max:255'],
        ]);
        if (self::menit($d['selesai']) <= self::menit($d['mulai'])) {
            abort(response()->json(['message' => 'Jam selesai harus setelah jam mulai.', 'code' => 'BAD_TIME'], 422));
        }
        if ($d['tanggal'] < now()->toDateString()) {
            abort(response()->json(['message' => 'Tanggal reservasi tidak boleh sebelum hari ini.', 'code' => 'PAST'], 422));
        }
        [$cab] = $this->cabang($r);
        $ok = DB::table('pro_master_ruangan')->where('id_ruangan', $d['ruangan'])->where('id_cabang', $cab)->where('is_active', 1)->exists();
        if (! $ok) {
            abort(response()->json(['message' => 'Ruangan tidak tersedia untuk cabang Anda.', 'code' => 'ROOM'], 422));
        }

        return $d;
    }

    private function bentrok(array $d, ?int $kecuali)
    {
        $m = self::menit($d['mulai']);
        $s = self::menit($d['selesai']);

        return DB::table(self::T.' as a')->join('acl_user as u', 'a.id_user', '=', 'u.id_user')
            ->where('a.id_ruangan', $d['ruangan'])->where('a.tanggal_reservasi', $d['tanggal'])->whereNull('a.deleted_time')
            ->when($kecuali, fn ($q) => $q->where('a.id_reservasi', '!=', $kecuali))
            ->get(['a.jam_reservasi', 'u.fullname'])
            ->first(function ($b) use ($m, $s) {
                [$bm, $bs] = array_pad(explode('-', (string) $b->jam_reservasi), 2, '');
                if ($bm === '' || $bs === '') {
                    return false;
                }

                return $m < self::menit($bs) && $s > self::menit($bm);
            });
    }

    private function tolakBentrok($b): JsonResponse
    {
        return response()->json(['message' => 'Telah direservasi oleh '.$b->fullname.' ('.$b->jam_reservasi.').', 'code' => 'TAKEN'], 409);
    }

    public function store(Request $r): JsonResponse
    {
        $d = $this->input($r);

        return DB::transaction(function () use ($r, $d) {
            DB::table('pro_master_ruangan')->where('id_ruangan', $d['ruangan'])->lockForUpdate()->first();
            if ($b = $this->bentrok($d, null)) {
                return $this->tolakBentrok($b);
            }
            $id = DB::table(self::T)->insertGetId([
                'id_ruangan' => $d['ruangan'], 'id_user' => $r->user()->id_user, 'tanggal_reservasi' => $d['tanggal'],
                'jam_reservasi' => $d['mulai'].'-'.$d['selesai'], 'personel' => $d['personel'], 'keperluan' => $d['keperluan'],
                'created_time' => now(), 'created_by' => $r->user()->fullname, 'deleted_time' => null,
            ]);

            return response()->json(['id' => $id], 201);
        });
    }

    private function own(Request $r, int $id)
    {
        $row = DB::table(self::T)->where('id_reservasi', $id)->whereNull('deleted_time')->first();
        if (! $row) {
            abort(response()->json(['message' => 'Reservasi tidak ditemukan.'], 404));
        }
        if ((int) $row->id_user !== (int) $r->user()->id_user) {
            abort(response()->json(['message' => 'Hanya pemesan yang dapat mengubah atau membatalkan reservasi ini.', 'code' => 'NOT_OWNER'], 403));
        }

        return $row;
    }

    public function update(Request $r, int $id): JsonResponse
    {
        $this->own($r, $id);
        $d = $this->input($r);

        return DB::transaction(function () use ($d, $id) {
            DB::table('pro_master_ruangan')->where('id_ruangan', $d['ruangan'])->lockForUpdate()->first();
            if ($b = $this->bentrok($d, $id)) {
                return $this->tolakBentrok($b);
            }
            DB::table(self::T)->where('id_reservasi', $id)->update([
                'id_ruangan' => $d['ruangan'], 'tanggal_reservasi' => $d['tanggal'], 'jam_reservasi' => $d['mulai'].'-'.$d['selesai'],
                'personel' => $d['personel'], 'keperluan' => $d['keperluan'], 'created_time' => now(),
            ]);

            return response()->json(['ok' => true]);
        });
    }

    public function destroy(Request $r, int $id): JsonResponse
    {
        $this->own($r, $id);
        DB::table(self::T)->where('id_reservasi', $id)->delete();

        return response()->json(['ok' => true]);
    }
}
