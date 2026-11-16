# Panduan Deploy ORCA X Mobile (untuk Tim IT)

PWA SYOP ProEnergi: frontend Vue 3, backend Laravel 13 (PHP 8.4), berjalan di Docker Compose.
Database **MySQL `proenergi` dipakai bersama aplikasi web lama**, jadi tidak ada database baru.

> Catatan jujur: berkas Docker sudah ditulis tetapi **belum pernah dibangun di mesin dengan Docker**.
> Kalau `docker compose build` gagal, kirim log-nya ke pengembang. Bagian "Pemecahan masalah" di bawah memuat dugaan awal.

---

## 1. Gambaran arsitektur

```
HP / browser ──HTTPS──> Reverse proxy (TLS tepercaya)
                              │  X-Forwarded-For, X-Forwarded-Proto
                              ▼
                     [web]  nginx :8080  ── menyajikan PWA (file statis)
                              │  /api/*, /up
                              ▼
                     [app]  PHP-FPM :9000 (Laravel) ──> MySQL `proenergi` (di luar Docker)
                     [scheduler] php artisan schedule:work  (push notifikasi tiap menit)
                     volume `storage` (sesi, cache, log)
```

Satu domain melayani PWA dan API (`/api/v1`). Tidak ada variabel build frontend yang wajib.

## 2. Prasyarat

| Kebutuhan | Keterangan |
|---|---|
| Server Linux | 2 vCPU, 2 GB RAM cukup. Build image pertama butuh ±2 GB disk dan akses internet (Docker Hub, Packagist, npm). |
| Docker Engine 24+ dan plugin Compose v2 | `docker compose version` |
| Git | Untuk mengambil kode dan pembaruan. |
| **Domain + sertifikat HTTPS tepercaya** | **Wajib.** Tanpa HTTPS tepercaya, kamera (login wajah), push, badge ikon, dan instal PWA tidak berfungsi di HP. Sertifikat self-signed tidak cukup. |
| Akses jaringan ke MySQL `proenergi` | Dari server Docker ke host/port MySQL (biasanya 3306). Cek firewall dan `bind-address`/`skip-networking`. |
| Server SMTP (opsional) | Untuk email notifikasi. |

## 3. Siapkan akun database

Aplikasi hanya menambah tabel berawalan `mobile_*`. Buat user khusus, jangan pakai `root`:

```sql
CREATE USER 'orca_mobile'@'<IP-server-docker>' IDENTIFIED BY '<password-kuat>';
-- Saat migrasi pertama (membuat tabel mobile_*):
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES ON proenergi.* TO 'orca_mobile'@'<IP-server-docker>';
-- Setelah migrasi selesai, CREATE/ALTER/INDEX/REFERENCES boleh dicabut:
-- REVOKE CREATE, ALTER, INDEX, REFERENCES ON proenergi.* FROM 'orca_mobile'@'<IP-server-docker>';
```

Aplikasi juga **menulis** ke tabel web lama (approval penawaran, PO, SI, TI, DR, harga, reservasi, mobil, produk), jadi hak INSERT/UPDATE/DELETE pada `proenergi.*` memang diperlukan.

> **Jangan** menjalankan `php artisan migrate:fresh`, `migrate:reset`, atau `db:wipe`. Itu menghapus database bersama. Riwayat migrasi aplikasi ini disimpan di tabel `migrations_orca`, bukan `migrations`.

## 4. Ambil kode

```sh
git clone https://github.com/proenergi2006/pwa-syop.git
cd pwa-syop
```

Repositori Private: gunakan deploy key (SSH) atau personal access token.

## 5. Isi konfigurasi `backend/.env`

```sh
cp backend/.env.production.example backend/.env
chmod 600 backend/.env
```

`backend/.env` **tidak boleh** masuk Git. Isi:

| Variabel | Isi |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL` | `https://<domain-anda>` |
| `APP_KEY` | Lihat 5.1 |
| `DB_HOST` | Host MySQL. `host.docker.internal` bila MySQL di server yang sama, selain itu IP/hostname |
| `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `3306`, `proenergi`, `orca_mobile`, password dari langkah 3 |
| `ORCA_PASSWORD_SALT` | **Wajib.** Garam password web lama, sama dengan `$salt` di `acl_login.php`. Tulis diapit tanda kutip tunggal. Jika kosong/salah, semua login gagal. Dengan ini username dan password yang sama berlaku di web lama dan PWA |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` | Lihat 5.2 |
| `VAPID_SUBJECT` | `mailto:it@proenergi.co.id` |
| `MAIL_*` | SMTP perusahaan (opsional) |
| `ACCURATE_ENABLED` | **Biarkan `false`** sampai ada keputusan. Lihat bagian 10 |

Nilai lain (`SESSION_DRIVER=file`, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync`, `LOG_CHANNEL=stderr`) biarkan seperti contoh.

### 5.1 Membuat `APP_KEY`

Wajib terisi, kalau kosong container berhenti dengan pesan "APP_KEY belum diisi". Bangun image dulu, lalu hasilkan kuncinya:

```sh
docker compose build app
docker compose run --rm --no-deps --entrypoint php app artisan key:generate --show
# salin hasilnya (base64:....) ke APP_KEY di backend/.env
```

### 5.2 Membuat kunci VAPID (push notifikasi)

```sh
docker compose run --rm --no-deps --entrypoint php app -r \
  'require "vendor/autoload.php"; print_r(Minishlink\WebPush\VAPID::createVapidKeys());'
```

Salin `publicKey` ke `VAPID_PUBLIC_KEY` dan `privateKey` ke `VAPID_PRIVATE_KEY`.
**Jangan mengganti kunci ini setelah ada pengguna yang berlangganan**, karena semua langganan push lama jadi tidak valid. Simpan cadangannya di pengelola rahasia perusahaan.

## 6. Jalankan

```sh
docker compose up -d --build
docker compose exec app php artisan migrate --force   # sekali saja, hanya menambah tabel mobile_*
```

Migrasi **tidak otomatis** (disengaja, karena database dipakai bersama). Hasil yang diharapkan: tabel `mobile_face_templates`, `mobile_auth_log`, `mobile_push_subscriptions`, `mobile_user_photos`, `mobile_notify_state`, dan `migrations_orca`. Setelahnya cabut hak CREATE/ALTER (langkah 3).

Service yang berjalan:

| Service | Fungsi | Port |
|---|---|---|
| `app` | PHP-FPM 8.4 + Laravel | 9000 (internal) |
| `scheduler` | `schedule:work`, menjalankan `orca:notify-waiting` tiap menit untuk push dan badge | tidak ada |
| `web` | nginx: PWA statis + meneruskan `/api` ke `app` | **8080** → ke reverse proxy |

## 7. Reverse proxy HTTPS

Container `web` hanya berbicara HTTP di port 8080. TLS diurus di depannya. Syarat: teruskan `X-Forwarded-For` dan `X-Forwarded-Proto`, ukuran body minimal 6 MB (foto profil), dan jangan mematikan service worker (`/sw.js`) dari cache-nya sendiri.

Contoh nginx di host:

```nginx
server {
    listen 443 ssl http2;
    server_name orca.example.com;
    ssl_certificate     /etc/ssl/orca/fullchain.pem;
    ssl_certificate_key /etc/ssl/orca/privkey.pem;

    client_max_body_size 6m;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 90s;
    }
}
server { listen 80; server_name orca.example.com; return 301 https://$host$request_uri; }
```

Port 8080 sebaiknya tidak terbuka ke internet. Batasi dengan firewall atau ubah mapping di `docker-compose.yml` menjadi `"127.0.0.1:8080:80"` bila proxy berada di host yang sama.

Alamat jaringan privat yang dipercaya sebagai proxy ada di `frontend/nginx.conf` (`set_real_ip_from 10/8, 172.16/12, 192.168/16`). Sesuaikan bila proxy Anda memakai rentang lain, karena ini memengaruhi alamat klien untuk pembatasan percobaan login.

## 8. Verifikasi setelah naik

```sh
docker compose ps                         # app, scheduler, web = Up (app: healthy)
docker compose logs app --tail=50         # tanpa error
docker compose logs scheduler --tail=20   # "Running [orca:notify-waiting]" tiap menit
curl -i https://<domain>/up               # 200
curl -i https://<domain>/                 # 200, halaman PWA
curl -i https://<domain>/api/v1/auth/me   # 401 (benar, belum login)
```

Uji dari HP (bukan hanya laptop):

1. Buka `https://<domain>`, login dengan username dan password.
2. Beranda menampilkan menu sesuai peran.
3. Daftarkan wajah, keluar, lalu login wajah (butuh izin kamera).
4. Aktifkan notifikasi, buat satu pengajuan yang menunggu keputusan, pastikan push dan badge ikon muncul dalam ±1 menit.
5. Instal PWA ke layar utama.

## 9. Operasional

**Pembaruan versi**
```sh
git pull
docker compose up -d --build
docker compose exec app php artisan migrate --force    # hanya bila rilis memuat migrasi baru
```
Pengguna yang membuka aplikasi melihat pita **"Versi baru tersedia"**, lalu mengetuk untuk memuat ulang. Tidak ada pemaksaan reload.

**Log**: `docker compose logs -f app` (semua log Laravel ke stderr). `docker compose logs -f web` untuk akses nginx.

**Cadangan**
- Database `proenergi` (sudah ada kebijakan cadangan web lama; pastikan mencakup tabel `mobile_*`, terutama `mobile_face_templates`, `mobile_user_photos`, `mobile_push_subscriptions`).
- `backend/.env` (di pengelola rahasia, bukan di Git).
- Volume `storage` (hanya sesi, cache, dan log; kehilangannya membuat pengguna perlu login ulang, tidak merusak data).

**Mengembalikan versi lama**: `git checkout <commit>` lalu `docker compose up -d --build`. Migrasi `mobile_*` bersifat menambah, jadi aman dibiarkan.

**Jadwal**: tidak perlu cron di host, service `scheduler` sudah mengurusnya. Pastikan service ini tidak mati (`restart: unless-stopped`).

## 10. Accurate Online (opsional, nonaktif bawaan)

Integrasi sudah terpasang untuk: Delivery Request (Simpan, Revisi), Persetujuan PO Supplier oleh CEO, dan sinkron harga Sales Order pada penawaran harga baru. Detail di `backend/ACCURATE.md`.

Tanpa pengaktifan, **tidak ada satu pun panggilan ke Accurate**. Pengaktifan adalah keputusan bisnis, karena Sales Order/Delivery Order yang terbentuk di Accurate tidak bisa dibatalkan begitu saja. Untuk mengaktifkan di `backend/.env`:

```
ACCURATE_ENABLED=true
ACCURATE_TOKEN=<Bearer token API Accurate>
ACCURATE_SIGNATURE_SECRET=<kunci tanda tangan HMAC>
```
lalu `docker compose up -d` (muat ulang konfigurasi). **Buat token dan secret baru** di Accurate. Jangan memakai yang tertulis di `curl.php` web lama, karena sudah tersimpan sebagai teks biasa di banyak tempat.

Sebelum produksi, uji dulu pada database Accurate percobaan, bukan data live.

## 11. Keamanan, daftar periksa

- [ ] Repositori GitHub berstatus **Private**.
- [ ] `backend/.env` izin `600`, tidak ada di Git.
- [ ] HTTPS tepercaya aktif, HTTP dialihkan ke HTTPS.
- [ ] Port 8080 tidak terbuka ke internet.
- [ ] User MySQL khusus, hak CREATE/ALTER dicabut setelah migrasi.
- [ ] `APP_DEBUG=false`.
- [ ] Kunci VAPID tersimpan aman dan tidak diganti.
- [ ] `ACCURATE_ENABLED=false` kecuali sudah ada persetujuan.
- [ ] Cadangan database mencakup tabel `mobile_*`.
- [ ] Sesi login wajah hanya bisa membaca; keputusan wajib konfirmasi password (sudah di kode, tidak perlu konfigurasi).

## 12. Pemecahan masalah

| Gejala | Kemungkinan penyebab dan tindakan |
|---|---|
| Container `app` berhenti: "APP_KEY belum diisi" | Isi `APP_KEY` (langkah 5.1). |
| `SQLSTATE[HY000] [2002]` / `[1045]` | Host/port salah, firewall MySQL, atau user/password/host-izin salah. Dari container: `docker compose exec app php -r 'new PDO("mysql:host=".getenv("DB_HOST").";dbname=proenergi", getenv("DB_USERNAME"), getenv("DB_PASSWORD")); echo "ok";'` |
| `host.docker.internal` tidak ditemukan | Sudah dipetakan lewat `extra_hosts` di compose. Bila tetap gagal, pakai IP server MySQL. |
| Build `app` gagal pada ekstensi PHP (gd/gmp) | Kirim log. Dockerfile memakai Alpine; paket dev mungkin perlu disesuaikan. |
| Build `web` gagal di `npm ci` | Pastikan `frontend/package-lock.json` ada di repositori dan server bisa mengakses npm registry. |
| Semua login gagal "Username atau password salah" padahal benar | `ORCA_PASSWORD_SALT` kosong atau tidak sama dengan `$salt` web lama. Setelah memperbaiki: `docker compose up -d` |
| Login berhasil tetapi API 502/504 | Cek `docker compose logs app`; pastikan `app` Up. Timeout nginx 90 dtk untuk sinkron Accurate. |
| Kamera tidak mau menyala di HP | Bukan HTTPS tepercaya, atau izin kamera ditolak. |
| Push/badge tidak muncul | HTTPS tidak tepercaya, `scheduler` mati, atau `VAPID_*` kosong/berubah. Cek `docker compose logs scheduler`. |
| Perubahan frontend tidak terlihat | Service worker memegang versi lama; pengguna ketuk pita "Versi baru tersedia", atau tutup semua tab lalu buka lagi. |
| Semua pengguna terhitung satu alamat IP (login terkunci massal) | `X-Forwarded-For` tidak diteruskan proxy, atau rentang proxy belum masuk `set_real_ip_from`. |
| 419/401 acak setelah deploy ulang | `APP_KEY` berubah. Jangan ganti `APP_KEY` pada instalasi berjalan. |

## 13. Kontak

Pengembang: Iwan Hermawan (`iwan.hermawan@proenergi.co.id`). Saat melapor, sertakan hasil `docker compose ps` dan 50 baris terakhir `docker compose logs app`.
