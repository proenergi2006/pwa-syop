<?php

/*
| Integrasi Accurate Online (zeus.accurate.id), setara dengan curl.php aplikasi web lama.
| NILAI RAHASIA TIDAK DITULIS DI SINI: isi di .env server (jangan commit):
|   ACCURATE_ENABLED=true
|   ACCURATE_TOKEN=<Bearer token API Accurate>
|   ACCURATE_SIGNATURE_SECRET=<kunci tanda tangan HMAC>
| Selama ACCURATE_ENABLED bukan true (atau token/secret kosong), tidak ada satu pun panggilan ke Accurate dan alur berjalan seperti
| sebelumnya (hanya database).
*/
return [
    'enabled' => (bool) env('ACCURATE_ENABLED', false),
    'base_url' => env('ACCURATE_BASE_URL', 'https://zeus.accurate.id/accurate/api'),
    'token' => env('ACCURATE_TOKEN'),
    'secret' => env('ACCURATE_SIGNATURE_SECRET'),
    'timeout' => (int) env('ACCURATE_TIMEOUT', 30),
    // Batas Accurate per token: 8 request per detik dan 8 paralel (dipakai batch()).
    'max_concurrent' => (int) env('ACCURATE_MAX_CONCURRENT', 8),
    'rate_window' => (float) env('ACCURATE_RATE_WINDOW', 1.0),
    // Zona waktu untuk header X-Api-Timestamp (web lama memakai jam server WIB).
    'timezone' => env('ACCURATE_TIMEZONE', 'Asia/Jakarta'),
];
