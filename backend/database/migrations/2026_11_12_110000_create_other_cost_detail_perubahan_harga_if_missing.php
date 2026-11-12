<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Other Cost per pengajuan perubahan harga (LAMA/BARU). Tabel ini sudah dipakai aplikasi web; migrasi ini hanya membuatnya bila belum ada
 * (salinan database lokal), dengan kolom yang dipakai penawaran-approval-detail.php. Tidak melakukan apa pun bila tabel sudah ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pro_other_cost_detail_perubahan_harga')) {
            return;
        }
        DB::statement('CREATE TABLE pro_other_cost_detail_perubahan_harga (
            id_master INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            id_request INT(11) NULL,
            id_penawaran INT(11) NOT NULL,
            tipe VARCHAR(20) NOT NULL,
            keterangan VARCHAR(100) NULL,
            nominal INT(11) NULL,
            created_time DATETIME NULL,
            KEY idx_penawaran_request (id_penawaran, id_request, tipe)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public function down(): void
    {
        // Tabel milik aplikasi web: tidak dihapus.
    }
};
