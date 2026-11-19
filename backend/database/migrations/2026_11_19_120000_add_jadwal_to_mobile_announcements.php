<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pengumuman terjadwal: dikirim otomatis oleh orca:kirim-pengumuman saat jadwalnya tiba. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_announcements', function (Blueprint $t) {
            $t->timestamp('jadwal_pada')->nullable()->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('mobile_announcements', function (Blueprint $t) {
            $t->dropColumn('jadwal_pada');
        });
    }
};
