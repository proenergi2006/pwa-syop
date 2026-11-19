# ORCA X Mobile (PWA SYOP ProEnergi)

- `backend/`  Laravel 13 (PHP 8.4) + Sanctum, terhubung ke MySQL `proenergi`.
- `frontend/` Vue 3 + Pinia + Vue Router + vite-plugin-pwa.

## Menjalankan (lokal)

```sh
# Backend (PHP 8.4, bukan php XAMPP 8.2). Port 8000 sering dipakai aplikasi lain, jadi pakai 8010.
export PATH="/opt/homebrew/opt/php@8.4/bin:$PATH"
cd backend && php artisan serve --port=8010

# Frontend (proxy /api -> http://127.0.0.1:8010; ubah dengan env SYOP_BACKEND)
cd frontend && npm install && npm run dev
```

Buka `http://localhost:5173`. Login memakai username + password akun SYOP (`acl_user`).
Role yang boleh masuk: BM (7), OM (6), COO (3), CEO (21).

## Tes backend

```sh
cd backend && php artisan test
```

Tes memakai database `proenergi` LOKAL dan selalu di-rollback; otomatis ditolak bila `DB_HOST`
bukan localhost. Jangan arahkan ke database produksi.

## Catatan penting

- Database `proenergi` dipakai bersama aplikasi lain (POD, API SYOP lama). Backend ini hanya menambah
  tabel baru; riwayat migrasi memakai tabel `migrations_orca`. Jangan jalankan `migrate:fresh`.
- Aksi approval menulis ke `pro_penawaran` dan `pro_approval_hist` dengan aturan yang sama seperti web
  lama (`penawaran-approval.php`).
- Belum didukung: pengajuan perubahan harga (`is_edit_harga = 1`, butuh sinkron Accurate) dan tahap
  SM Marketing. Pengajuan harga baru ditolak dengan pesan jelas, tidak ada data yang ditulis.
- Face Recognition 1:1 (username dulu, lalu wajah): embedding 128 angka dari face-api di perangkat, dicocokkan
  di server (`config/orca.php` -> `face.threshold`, bawaan 0.45; WAJIB ditala dengan pengguna nyata).
  Template dienkripsi (APP_KEY; jangan ganti APP_KEY tanpa pendaftaran ulang). Daftar/hapus wajah wajib password.
  Gagal 5x -> terkunci 15 menit. Sesi hasil face login setara sesi password untuk semua modul (tanpa konfirmasi password tambahan); password hanya wajib di Profil untuk mendaftar/menghapus wajah.
  Kamera butuh HTTPS (localhost dikecualikan). Uji tanpa kamera: `VITE_FACE_MOCK=1 npm run dev` (jangan dipakai di produksi).
- Email notifikasi memakai Laravel Mail; isi `MAIL_*` di `backend/.env` untuk SMTP.


## Produksi (ringkas)

- **HTTPS tepercaya** wajib agar PWA bisa dipasang, push dan service worker aktif (self-signed tidak cukup).
- **`.env` backend** (jangan di-commit): `APP_KEY`, kredensial DB, kunci VAPID (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`; jangan diganti setelah ada pengguna berlangganan), dan opsional Accurate (`ACCURATE_ENABLED`, `ACCURATE_TOKEN`, `ACCURATE_SIGNATURE_SECRET`) — lihat `backend/ACCURATE.md`.
- **Cron** untuk notifikasi approval: `* * * * * php artisan schedule:run`.
- **Migrasi** hanya menambah tabel `mobile_*` baru; riwayat migrasi memakai tabel `migrations_orca`. Database `proenergi` dipakai bersama aplikasi web lama, jangan `migrate:fresh`.
- **Frontend**: `npm run build`, sajikan folder `dist` (ganti utuh termasuk `sw.js`); pengguna mendapat pita "Versi baru tersedia".

## Docker

```sh
cp backend/.env.production.example backend/.env   # isi APP_KEY, DB_*, VAPID_*, dst.
docker compose up -d --build
docker compose exec app php artisan migrate --force   # hanya menambah tabel mobile_*; jalankan sendiri, tidak otomatis
```

Tiga service: `app` (PHP-FPM 8.4), `scheduler` (`schedule:work`, untuk notifikasi push approval) dan `web` (nginx: menyajikan PWA dan meneruskan `/api` ke `app`). Port `8080` diarahkan reverse proxy HTTPS Anda (TLS tepercaya wajib); proxy harus meneruskan `X-Forwarded-For` dan `X-Forwarded-Proto`. MySQL `proenergi` tidak ada di compose (dipakai bersama aplikasi lama): atur `DB_HOST` ke servernya (`host.docker.internal` bila di host yang sama). Volume `storage` menyimpan sesi, cache, dan log. Pembaruan: `git pull && docker compose up -d --build`.
