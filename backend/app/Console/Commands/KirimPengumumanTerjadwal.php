<?php

namespace App\Console\Commands;

use App\Services\AnnouncementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Merilis pengumuman terjadwal yang waktunya sudah tiba (dijalankan scheduler tiap menit). */
class KirimPengumumanTerjadwal extends Command
{
    protected $signature = 'orca:kirim-pengumuman';

    protected $description = 'Kirim pengumuman terjadwal yang sudah waktunya';

    public function handle(AnnouncementService $svc): int
    {
        $ids = DB::table('mobile_announcements')->where('status', 'terjadwal')->where('jadwal_pada', '<=', now())->orderBy('jadwal_pada')->pluck('id');
        foreach ($ids as $id) {
            $r = $svc->rilis((int) $id);
            if (is_array($r)) {
                $this->info("Pengumuman #{$id} dirilis ke {$r['dikirim_ke']} pengguna, push {$r['push_ke']}");
            }
        }

        return self::SUCCESS;
    }
}
