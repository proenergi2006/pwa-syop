<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Jejak audit autentikasi mobile (login, face login, daftar/hapus wajah). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_auth_log', function (Blueprint $t) {
            $t->id();
            $t->integer('id_user')->nullable()->index();
            $t->string('username', 50)->nullable();
            $t->string('event', 40)->index();       // login_ok, login_fail, face_ok, face_fail, face_enroll, face_remove, face_locked, step_up_fail
            $t->float('distance')->nullable();       // jarak wajah (tanpa menyimpan vektor)
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_auth_log');
    }
};
