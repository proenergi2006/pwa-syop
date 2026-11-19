<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Perubahan hak akses modul dari aplikasi (menimpa module_roles bawaan di config/orca.php). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_module_overrides', function (Blueprint $t) {
            $t->string('modul', 40)->primary();
            $t->string('roles', 255);                 // JSON: [7,21] atau ["*"]
            $t->integer('diubah_oleh')->nullable();
            $t->string('diubah_nama', 100)->nullable();
            $t->timestamp('diubah_pada')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_module_overrides');
    }
};
