<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Template wajah (embedding 128 angka) per pengguna. Isi `template` DIENKRIPSI
 * (Crypt/APP_KEY). Hanya tabel baru; tabel acl_user tidak diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_face_templates', function (Blueprint $t) {
            $t->id();
            $t->integer('id_user')->index();       // acl_user.id_user
            $t->text('template');                   // JSON embedding, terenkripsi
            $t->string('model', 40)->default('face-api-128');
            $t->string('device', 190)->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_face_templates');
    }
};
