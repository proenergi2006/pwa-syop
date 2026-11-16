<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Daftar id item yang menunggu per modul, supaya notifikasi bisa menyebut kode dokumen yang BARU (bukan hanya jumlah). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('mobile_notify_state', 'items')) {
            return;
        }
        Schema::table('mobile_notify_state', function (Blueprint $t) {
            $t->json('items')->nullable()->after('counts');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('mobile_notify_state', 'items')) {
            Schema::table('mobile_notify_state', function (Blueprint $t) {
                $t->dropColumn('items');
            });
        }
    }
};
