<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Foto profil yang diunggah dari aplikasi mobile. Tidak menyentuh acl_user.foto milik web lama. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_user_photos', function (Blueprint $t) {
            $t->integer('id_user')->primary();
            $t->string('path', 120);
            $t->unsignedInteger('bytes');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_user_photos');
    }
};
