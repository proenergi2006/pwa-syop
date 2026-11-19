<?php

namespace App\Http\Controllers;

use App\Services\ModuleRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Hak akses modul per peran, dapat diubah Super Admin dari aplikasi (menimpa bawaan config/orca.php). */
class AksesController extends Controller
{
    private static function norm(array $r): array
    {
        if (in_array('*', $r, true)) {
            return ['*'];
        }
        $r = array_values(array_unique(array_map('intval', $r)));
        sort($r);

        return $r;
    }

    public function index(): JsonResponse
    {
        $bawaan = ModuleRoles::bawaan();
        $ov = ModuleRoles::overrides();
        $now = config('orca.module_roles');
        $modul = collect($bawaan)->map(fn ($def, $k) => [
            'kunci' => $k, 'roles' => self::norm($now[$k]), 'bawaan' => self::norm($def), 'diubah' => isset($ov[$k]),
            'oleh' => $ov[$k]['oleh'] ?? null, 'pada' => $ov[$k]['pada'] ?? null, 'terkunci' => in_array($k, ModuleRoles::KUNCI, true),
        ])->values()->all();
        $peran = DB::table('acl_role as r')->leftJoin('acl_user as u', fn ($j) => $j->on('u.id_role', '=', 'r.id_role')->where('u.is_active', 1))
            ->groupBy('r.id_role', 'r.role_name')->orderBy('r.role_name')->get(['r.id_role as id', 'r.role_name as nama', DB::raw('count(u.id_user) as pengguna')])
            ->map(fn ($x) => ['id' => (int) $x->id, 'nama' => preg_replace('/^Role /', '', (string) $x->nama), 'pengguna' => (int) $x->pengguna])->all();

        return response()->json(['modul' => $modul, 'peran' => $peran]);
    }

    public function update(Request $r, string $modul): JsonResponse
    {
        $bawaan = ModuleRoles::bawaan();
        if (! array_key_exists($modul, $bawaan)) {
            return response()->json(['message' => 'Modul tidak dikenal.'], 404);
        }
        $d = $r->validate(['roles' => ['required', 'array', 'min:1', 'max:60'], 'roles.*' => ['required']], ['roles.min' => 'Pilih minimal satu peran.', 'roles.required' => 'Pilih minimal satu peran.']);
        $roles = array_values($d['roles']);
        if (in_array('*', $roles, true)) {
            $roles = ['*'];
        } else {
            foreach ($roles as $x) {
                if (! is_numeric($x)) {
                    return response()->json(['message' => 'Peran tidak valid.'], 422);
                }
            }
            $roles = self::norm($roles);
            if (DB::table('acl_role')->whereIn('id_role', $roles)->count() !== count($roles)) {
                return response()->json(['message' => 'Peran tidak dikenal.'], 422);
            }
            if (in_array($modul, ModuleRoles::KUNCI, true) && ! in_array(1, $roles, true)) {
                $roles = self::norm(array_merge($roles, [1])); // Super Admin tidak boleh terkunci dari menu adminnya
            }
        }
        if ($roles === self::norm($bawaan[$modul])) { // sama dengan bawaan: hapus override
            DB::table('mobile_module_overrides')->where('modul', $modul)->delete();
        } else {
            DB::table('mobile_module_overrides')->upsert([['modul' => $modul, 'roles' => json_encode($roles), 'diubah_oleh' => $r->user()->id_user, 'diubah_nama' => $r->user()->fullname, 'diubah_pada' => now()]], ['modul'], ['roles', 'diubah_oleh', 'diubah_nama', 'diubah_pada']);
        }
        $this->audit($r, 'akses_ubah', $modul);
        ModuleRoles::lupakan();

        return response()->json(['message' => 'Hak akses modul disimpan.', 'roles' => $roles]);
    }

    public function reset(Request $r, string $modul): JsonResponse
    {
        if (! array_key_exists($modul, ModuleRoles::bawaan())) {
            return response()->json(['message' => 'Modul tidak dikenal.'], 404);
        }
        DB::table('mobile_module_overrides')->where('modul', $modul)->delete();
        $this->audit($r, 'akses_reset', $modul);
        ModuleRoles::lupakan();

        return response()->json(['message' => 'Dikembalikan ke bawaan.']);
    }

    private function audit(Request $r, string $event, string $modul): void
    {
        DB::table('mobile_auth_log')->insert(['id_user' => $r->user()->id_user, 'username' => mb_substr($modul.' oleh '.$r->user()->username, 0, 50), 'event' => $event, 'ip' => $r->ip(), 'user_agent' => mb_substr((string) $r->userAgent(), 0, 255)]);
    }
}
