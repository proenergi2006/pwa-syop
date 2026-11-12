<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Jumlah "menunggu keputusan" terakhir yang sudah diketahui per user, supaya notifikasi push hanya dikirim saat ada yang BARU. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_notify_state', function (Blueprint $t) {
            $t->integer('id_user')->primary();
            $t->json('counts');
            $t->timestamp('notified_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_notify_state');
    }
};
