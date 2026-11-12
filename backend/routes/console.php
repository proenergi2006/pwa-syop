<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pantau approval baru dan kirim push (butuh cron: * * * * * php artisan schedule:run).
Illuminate\Support\Facades\Schedule::command('orca:notify-waiting')->everyMinute()->withoutOverlapping()->runInBackground();
