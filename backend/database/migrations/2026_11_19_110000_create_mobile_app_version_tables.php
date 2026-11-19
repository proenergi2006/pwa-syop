<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Versi aplikasi yang sedang dipakai tiap pengguna (dilaporkan aplikasi lewat header) dan versi terakhir yang sudah diumumkan lewat push. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_client_versions', function (Blueprint $t) {
            $t->integer('id_user')->primary();
            $t->string('versi', 40);
            $t->timestamp('dilihat_pada')->useCurrent();
        });

        Schema::create('mobile_settings', function (Blueprint $t) {
            $t->string('nama', 60)->primary();
            $t->text('nilai')->nullable();
            $t->timestamp('diubah_pada')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_settings');
        Schema::dropIfExists('mobile_client_versions');
    }
};
