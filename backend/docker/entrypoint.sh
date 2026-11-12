#!/bin/sh
# Menyiapkan cache konfigurasi dari environment container lalu menjalankan perintah (php-fpm atau scheduler).
set -e
cd /var/www/html

if [ -z "$APP_KEY" ]; then
  echo "APP_KEY belum diisi (backend/.env). Berhenti." >&2
  exit 1
fi

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# Cache hanya bila bukan mode debug. Migrasi TIDAK dijalankan otomatis (database dipakai bersama aplikasi web lama).
if [ "${APP_DEBUG:-false}" != "true" ]; then
  php artisan config:cache
  php artisan route:cache
  php artisan event:cache
fi

exec "$@"
