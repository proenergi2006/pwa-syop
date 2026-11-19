<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pengumuman dari Super Admin ke pengguna aplikasi (muncul di aplikasi dan dikirim sebagai push sekali saat dirilis). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_announcements', function (Blueprint $t) {
            $t->id();
            $t->string('judul', 150);
            $t->text('isi');
            $t->string('jenis', 12)->default('info');       // info | penting | pembaruan
            $t->string('sasaran', 255)->nullable();           // null = semua pengguna; selain itu daftar id_role berpagar koma, mis. ",7,21,"
            $t->string('status', 10)->default('draft')->index(); // draft | terkirim
            $t->timestamp('dikirim_pada')->nullable()->index();
            $t->integer('dikirim_ke')->default(0);            // jumlah pengguna sasaran saat dirilis
            $t->integer('push_ke')->default(0);               // jumlah perangkat yang menerima push saat dirilis
            $t->integer('dibuat_oleh')->index();              // id_user Super Admin
            $t->string('dibuat_nama', 100)->nullable();
            $t->timestamps();
        });

        Schema::create('mobile_announcement_reads', function (Blueprint $t) {
            $t->unsignedBigInteger('id_announcement');
            $t->integer('id_user');
            $t->timestamp('dibaca_pada')->useCurrent();
            $t->primary(['id_announcement', 'id_user']);
            $t->index('id_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_announcement_reads');
        Schema::dropIfExists('mobile_announcements');
    }
};
