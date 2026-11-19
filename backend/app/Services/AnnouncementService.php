<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Merilis pengumuman: tandai terkirim (sekali) lalu kirim satu push ke perangkat pengguna sasaran. */
class AnnouncementService
{
    public static function sasaranArray(?string $s): array
    {
        return $s ? array_values(array_filter(array_map('intval', explode(',', $s)))) : [];
    }

    /** Id pengguna aktif sasaran, dan jumlah perangkat push mereka. */
    public static function penerima(?array $roles): array
    {
        $q = DB::table('acl_user')->where('is_active', 1);
        if ($roles) {
            $q->whereIn('id_role', $roles);
        }
        $ids = $q->pluck('id_user')->map(fn ($v) => (int) $v)->all();
        $perangkat = $ids ? DB::table('mobile_push_subscriptions')->whereIn('id_user', $ids)->count() : 0;

        return [$ids, $perangkat];
    }

    /** @return array{dikirim_ke:int,push_ke:int}|string|null  null = tidak ada, 'sudah' = sudah pernah dirilis */
    public function rilis(int $id): array|string|null
    {
        $a = DB::transaction(function () use ($id) {
            $a = DB::table('mobile_announcements')->where('id', $id)->lockForUpdate()->first();
            if (! $a) {
                return null;
            }
            if ($a->status === 'terkirim') {
                return 'sudah';
            }
            [$ids] = self::penerima(self::sasaranArray($a->sasaran) ?: null);
            DB::table('mobile_announcements')->where('id', $id)->update(['status' => 'terkirim', 'jadwal_pada' => null, 'dikirim_pada' => now(), 'dikirim_ke' => count($ids), 'updated_at' => now()]);
            $a->ids = $ids;

            return $a;
        });
        if (! is_object($a)) {
            return $a;
        }
        $isi = Str::limit(trim(preg_replace('/\s+/', ' ', (string) $a->isi)), 140);
        $push = app(PushService::class)->send($a->ids, [
            'title' => $a->judul, 'body' => $isi, 'url' => '/pengumuman?buka='.$id, 'tag' => 'pengumuman-'.$id,
            'en' => ['title' => $a->judul, 'body' => $isi],
        ]);
        DB::table('mobile_announcements')->where('id', $id)->update(['push_ke' => $push]);

        return ['dikirim_ke' => count($a->ids), 'push_ke' => $push];
    }
}
