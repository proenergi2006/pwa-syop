<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bahasa pilihan pengguna per perangkat, supaya isi notifikasi push mengikuti bahasa aplikasi. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_push_subscriptions', function (Blueprint $t) {
            $t->string('lang', 2)->default('id')->after('auth');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_push_subscriptions', function (Blueprint $t) {
            $t->dropColumn('lang');
        });
    }
};
