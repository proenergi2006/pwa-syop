<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pantau approval baru dan kirim push (butuh cron: * * * * * php artisan schedule:run).
Illuminate\Support\Facades\Schedule::command('orca:notify-waiting')->everyMinute()->withoutOverlapping(5);
// Deteksi rilis versi baru aplikasi lalu beri tahu pengguna yang masih memakai versi lama (sekali per versi).
Illuminate\Support\Facades\Schedule::command('orca:kirim-pengumuman')->everyMinute()->withoutOverlapping(5);
Illuminate\Support\Facades\Schedule::command('orca:cek-versi')->everyFiveMinutes()->withoutOverlapping(5);
