<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Libur nasional + cuti bersama (tanggalmerah.upset.dev). Di-cache sehari; bila gagal diambil, hasilnya kosong (hanya akhir pekan yang dianggap libur). */
class HariLibur
{
    /** @return array<string, array{name:string,type:string}> kunci Y-m-d */
    public static function tahun(int $tahun): array
    {
        return Cache::remember("rssp-libur:$tahun", 86400, function () use ($tahun) {
            try {
                $json = Http::timeout(3)->get('https://tanggalmerah.upset.dev/api/holidays', ['year' => $tahun])->json();
            } catch (\Throwable) {
                $json = null;
            }
            $map = [];
            if (is_array($json) && ! empty($json['success']) && isset($json['data'])) {
                foreach ($json['data'] as $h) {
                    $map[$h['date']] = ['name' => $h['name'], 'type' => $h['type']];
                }
            }

            return $map;
        });
    }
}
