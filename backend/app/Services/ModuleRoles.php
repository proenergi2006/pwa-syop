<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Hak akses modul: bawaan dari config/orca.php, bisa ditimpa Super Admin lewat tabel mobile_module_overrides.
 * Modul admin (KUNCI) selalu memuat Super Admin supaya tidak ada yang mengunci dirinya sendiri.
 */
class ModuleRoles
{
    public const KUNCI = ['sistem', 'pengumuman_admin'];

    public static function bawaan(): array
    {
        return config('orca.module_roles_default') ?? config('orca.module_roles');
    }

    public static function overrides(): array
    {
        try {
            return Cache::remember('module_overrides', 60, fn () => DB::table('mobile_module_overrides')->get(['modul', 'roles', 'diubah_nama', 'diubah_pada'])
                ->mapWithKeys(fn ($r) => [$r->modul => ['roles' => json_decode($r->roles, true), 'oleh' => $r->diubah_nama, 'pada' => $r->diubah_pada]])->all());
        } catch (\Throwable) {
            return [];
        }
    }

    /** Terapkan override ke config('orca.module_roles') untuk request ini. */
    public static function terapkan(): void
    {
        if (config('orca.module_roles_default') === null) {
            config(['orca.module_roles_default' => config('orca.module_roles')]);
        }
        $m = self::bawaan();
        foreach (self::overrides() as $modul => $o) {
            if (array_key_exists($modul, $m) && is_array($o['roles']) && $o['roles']) {
                $m[$modul] = $o['roles'];
            }
        }
        foreach (self::KUNCI as $k) {
            if (isset($m[$k]) && ! in_array('*', $m[$k], true) && ! in_array(1, $m[$k], true)) {
                $m[$k][] = 1;
            }
        }
        config(['orca.module_roles' => $m]);
    }

    public static function lupakan(): void
    {
        Cache::forget('module_overrides');
        self::terapkan();
    }
}
