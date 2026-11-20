<?php

/*
|--------------------------------------------------------------------------
| Konfigurasi alur approval penawaran ORCA X Mobile
|--------------------------------------------------------------------------
| Semua angka di sini disalin dari aplikasi web lama (penawaran-approval*.php)
| supaya perilaku mobile identik dengan web.
*/

return [

    // id_role (acl_user.id_role) per tahap approval.
    'roles' => [
        'bm'  => 7,
        'om'  => 6,
        'coo' => 3,
        'ceo' => 21,
        'purchasing' => 5,
    ],

    // Modul => id_role yang boleh memakainya (disalin dari aplikasi web lama).
    // id_produk pada pengurangan stok depot Trucking DR; web lama menulis angka 15 langsung di kodenya.
    'trucking_dr_produk' => (int) env('ORCA_TRUCKING_DR_PRODUK', 15),

    // Folder induk web lama (berisi files/uploaded_user), dipasang baca-saja ke container. Dipakai untuk membuka lampiran.
    'web_files' => env('ORCA_WEB_FILES', '/var/www/legacy-web'),

    'module_roles' => [
        'penawaran' => [7, 6, 3, 21],
        'inventory' => [1, 21, 4, 5],
        'produk' => [21], // Master produk (daftar, detail, tambah, ubah)
        'harga' => [21], // Master harga jual (daftar, tambah, ubah, hapus)
        'po' => [21], // Verifikasi PO Supplier: tahap CEO (CFO menyusul)
        'dr' => [5], // Delivery Request: verifikasi Purchasing
        'dr_lihat' => [21], // Delivery Request (Procurement) hanya lihat: CEO. Tanpa verifikasi, simpan, revisi, atau kembalikan
        'master' => [5], // Master Terminal dan Master Vendor: Purchasing/Procurement
        'trucking_dr' => [5], // Trucking Delivery Request: verifikasi Purchasing/Procurement
        'sc' => [7], // Verifikasi Sales Confirmation: BM, per cabang
        'permohonan' => [7], // Verifikasi Permohonan (pemutakhiran data customer): BM, per cabang
        'lcr' => [7], // Verifikasi LCR: BM, per cabang
        'refund' => [7, 21], // Penerima Refund: verifikasi BM lalu CEO
        'verifikasi_customer' => [7, 6], // Verifikasi Data Customer: BM dan OM
        'trucking' => [21], // Trucking Instruction: persetujuan CEO
        'shipping' => [21], // Shipping Instruction: persetujuan akhir CEO
        'report' => [6, 7, 21], // Menu Report + Report Marketing (volume, customer aktif, customer baru, kegiatan): OM dan CEO semua cabang, BM hanya cabangnya
        'rssp_input' => [5], // Input Report RSSP: Forecast Supply dan Forecast Sales Planning per terminal (Procurement/Purchasing)
        'rssp' => [5, 6, 21], // Report RSSP Fuel (stok, supply, sales harian): Purchasing, OM, dan CEO
        'oa' => [21], // Verifikasi Request Input OA: persetujuan CEO
        'sistem' => [1], // Pusat Admin: kesehatan sistem, wajah login, log aktivitas, hak akses modul, pengaturan aplikasi (Super Admin)
        'pengumuman' => ['*'], // Pengumuman dari Super Admin: semua pengguna membaca
        'pengumuman_admin' => [1], // Kelola Pengumuman (tulis, rilis, hapus): Super Admin
        'forecast_bm' => [7], // Forecast Customer BM: isi forecast harian cabang sendiri, request tambahan qty ke OM
        'forecast_om' => [6], // Forecast Customer OM: isi forecast harian, ajukan ke CEO, setujui/tolak request qty dari BM
        'forecast' => [21], // Verifikasi Forecast cabang per periode dan request tambahan qty: persetujuan CEO
        'mobil' => ['*'], // Peminjaman mobil: semua peran, mobil sesuai cabang pengguna
        'reservasi' => ['*'], // Reservasi ruangan: semua peran, ruangan sesuai cabang pengguna
        'stok_terminal' => [21, 6, 7, 5], // grafik tangki di Beranda: CEO (dengan COGS), OM dan Purchasing semua cabang, BM cabangnya saja; COGS hanya CEO dan Purchasing
    ],

    // flag_disposisi => id_role yang berhak memutuskan pada tahap itu.
    // Alamat version.json hasil build frontend (dibaca orca:cek-versi). Kosong = APP_URL + /version.json.
    'version_url' => env('ORCA_VERSION_URL'),

    'stage_role' => [
        3 => 7,   // BM cabang
        4 => 6,   // OM
        5 => 3,   // COO
        6 => 21,  // CEO
    ],

    // id_role => awalan kolom pro_penawaran (<awalan>_result, _pic, _tanggal, _summary).
    'role_column' => [
        7  => 'sm_wil',
        6  => 'om',
        3  => 'coo',
        21 => 'ceo',
    ],

    'stage_label' => [
        0 => 'Draft',
        1 => 'Verifikasi SM Marketing',
        2 => 'Verifikasi SM Marketing',
        3 => 'Verifikasi BM',
        4 => 'Verifikasi OM',
        5 => 'Verifikasi COO',
        6 => 'Verifikasi CEO',
    ],

    // Batas yang memaksa BM meneruskan ke CEO (penawaran-approval-detail-data.php).
    'limits' => [
        'other_cost'   => 50,
        'refund'       => 70,
        'refund_total' => 1000000,
    ],

    // Masa berlaku token login (menit).
    'token_ttl_minutes' => (int) env('ORCA_TOKEN_TTL', 60 * 24 * 7),

    // Garam password web lama ($salt di acl_login.php). WAJIB sama agar username+password web dan PWA identik.
    'password_salt' => env('ORCA_PASSWORD_SALT'),

    'per_page_max' => 50,

    /*
    | Face Recognition 1:1 (username dulu, lalu wajah). Embedding 128 angka dari
    | face-api di perangkat. threshold = jarak Euclidean maksimum yang dianggap
    | cocok (lebih kecil = lebih ketat). Bawaan face-api 0.6; dipakai 0.45 karena
    | untuk login. WAJIB ditala dengan data pengguna nyata (salah-tolak vs salah-terima).
    */
    'face' => [
        'threshold'    => (float) env('ORCA_FACE_THRESHOLD', 0.45),
        'dimensions'   => 128,
        'max_attempts' => 5,        // gagal berturut-turut sebelum dikunci
        'lock_minutes' => 15,
        // Sesi hasil face login hanya boleh membaca; menyetujui/menolak wajib memasukkan password.
        'token_ttl_minutes' => (int) env('ORCA_FACE_TOKEN_TTL', 60 * 12),
    ],

    /*
    | Web Push (VAPID). Kunci dibuat sekali dengan VAPID::createVapidKeys() dan disimpan di .env.
    | Mengganti kunci membuat semua langganan lama tidak valid (user harus mengaktifkan ulang).
    */
    'push' => [
        'public_key'  => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject'     => env('VAPID_SUBJECT', 'https://localhost'),
        'ttl'         => 60 * 60 * 24,   // detik; notifikasi kedaluwarsa bila perangkat offline lebih lama
    ],

    // Verifikasi PO tahap CEO. Persetujuan di web juga membuka kembali PO di Accurate; selama itu belum
    // dipindahkan, persetujuan mobile hanya mengubah database. Set false agar PO ber-id_accurate ditolak.
    'po_allow_without_accurate' => env('ORCA_PO_ALLOW_WITHOUT_ACCURATE', true),
];
