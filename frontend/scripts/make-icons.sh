#!/usr/bin/env bash
# Membuat ikon PWA dari public/icons/logo.svg. Butuh ImageMagick (brew install imagemagick).
set -euo pipefail
cd "$(dirname "$0")/.."
SRC="public/icons/logo.svg"; OUT="public/icons"
# $1=kanvas, $2=ukuran logo, $3=berkas. Latar OPAK navy agar iOS/Android tidak mengisi hitam.
icon() { magick -background none "$SRC" -resize "${2}x${2}" -background "#071333" -gravity center -extent "${1}x${1}" -alpha remove -alpha off -strip "$3"; }
icon 192 192 "$OUT/icon-192.png"
icon 512 512 "$OUT/icon-512.png"
icon 192 124 "$OUT/maskable-192.png"   # maskable: konten ~64% agar aman dari potongan lingkaran
icon 512 328 "$OUT/maskable-512.png"
icon 180 180 "$OUT/apple-touch-icon.png"
icon 48  48  "$OUT/favicon-48.png"
echo "Ikon dibuat di $OUT"
