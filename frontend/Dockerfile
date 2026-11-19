# --- Tahap 1: build PWA ---
FROM node:22-alpine AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
# Panggilan API memakai alamat relatif /api/v1 (satu domain dengan frontend), jadi tidak ada variabel build yang wajib.
RUN npm run build

# --- Tahap 2: nginx menyajikan PWA dan meneruskan /api ke PHP-FPM ---
FROM nginx:1.27-alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html
