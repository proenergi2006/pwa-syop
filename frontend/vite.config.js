import basicSsl from '@vitejs/plugin-basic-ssl'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'
import { VitePWA } from 'vite-plugin-pwa'

// Backend Laravel (php artisan serve --port=8010). Diteruskan lewat proxy supaya
// HP cukup membuka satu alamat HTTPS dan tidak ada masalah CORS / mixed content.
const BACKEND = process.env.SYOP_BACKEND || 'http://127.0.0.1:8010'
const proxy = { '/api': { target: BACKEND, changeOrigin: true } }

// Versi build: dikirim aplikasi ke server (header X-App-Version) dan ditulis ke /version.json, supaya server tahu kapan ada rilis baru
// dan siapa yang masih memakai versi lama (orca:cek-versi di backend).
const APP_VERSION = process.env.SYOP_VERSION || Date.now().toString(36)

export default defineConfig({
  define: { __APP_VERSION__: JSON.stringify(APP_VERSION) },
  plugins: [
    {
      name: 'orca-version-json',
      generateBundle() {
        this.emitFile({ type: 'asset', fileName: 'version.json', source: JSON.stringify({ version: APP_VERSION, built_at: new Date().toISOString() }) })
      },
    },
    // HTTPS lokal bersertifikat self-signed (SYOP_HTTPS=1) supaya kamera bisa dipakai dari HP lewat Wi-Fi.
    ...(process.env.SYOP_HTTPS ? [basicSsl()] : []),
    vue(),
    VitePWA({
      registerType: 'prompt', // versi baru menunggu; pengguna mengetuk pita "Muat ulang" (mengganti sambil menjaga cache lama tetap utuh)
      injectRegister: false,
      includeAssets: ['icons/*.png', 'icons/logo.svg'],
      manifest: {
        name: 'ORCA X Mobile',
        short_name: 'ORCA X',
        description: 'ProEnergi Operations - approval penawaran',
        lang: 'id',
        theme_color: '#071333',
        background_color: '#071333',
        display: 'standalone',
        orientation: 'portrait',
        start_url: '/',
        icons: [
          { src: 'icons/icon-192.png', sizes: '192x192', type: 'image/png', purpose: 'any' },
          { src: 'icons/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'any' },
          { src: 'icons/maskable-192.png', sizes: '192x192', type: 'image/png', purpose: 'maskable' },
          { src: 'icons/maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
      workbox: {
        // Shell aplikasi di-cache; data API tidak pernah di-cache (data approval harus selalu segar).
        skipWaiting: false,
        clientsClaim: true,
        cleanupOutdatedCaches: true,
        importScripts: ['push-sw.js'], // penangan Web Push
        navigateFallback: '/index.html',
        navigateFallbackDenylist: [/^\/api\//],
        // Pustaka & model wajah (~7 MB) tidak di-precache; disimpan saat pertama dipakai agar instal awal tetap ringan.
        globPatterns: ['**/*.{js,css,html,svg}'], // svg ikut disimpan agar gambar latar tetap tampil offline
        globIgnores: ['**/face-api*.js'],
        runtimeCaching: [
          { urlPattern: /\/models\//, handler: 'CacheFirst', options: { cacheName: 'face-models', expiration: { maxEntries: 10 } } },
          { urlPattern: /\/assets\/face-api[^/]*\.js$/, handler: 'CacheFirst', options: { cacheName: 'face-lib', expiration: { maxEntries: 3 } } },
        ],
      },
    }),
  ],
  server: { host: true, proxy },
  preview: { host: true, port: 5199, proxy },
})
