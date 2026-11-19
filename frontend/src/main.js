import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { registerSW } from 'virtual:pwa-register'
import App from './App.vue'
import router from './router'
import { setUnauthorizedHandler } from './api/client'
import { useAuth } from './stores/auth'
import { useInbox } from './stores/inbox'
import { showToast } from './composables/useToast'
import { initTheme } from './composables/useTheme'
import { markUpdateReady, setRegistration } from './composables/useUpdate'
import { t } from './i18n'
import './style.css'

initTheme()

const app = createApp(App)
app.use(createPinia())
app.use(router)

// Sesi habis / token dicabut: bersihkan data lokal lalu kembali ke login.
setUnauthorizedHandler(() => {
  if (router.currentRoute.value.name === 'login') return
  useAuth().clear()
  useInbox().reset()
  showToast(t('err.session'), 'err')
  router.replace({ name: 'login' })
})

app.mount('#app')

// Notifikasi diketuk saat aplikasi sudah terbuka: service worker meminta kita pindah halaman.
navigator.serviceWorker?.addEventListener('message', (e) => {
  if (e.data?.type !== 'navigate') return
  try { const u = new URL(e.data.url); router.push(u.pathname + u.search) } catch { /* abaikan */ }
})

// Versi baru diunduh di latar belakang lalu menunggu; pita "Versi baru tersedia" mengaktifkannya. Cek tiap kali aplikasi kembali ke depan dan tiap 30 menit.
const updateSW = registerSW({
  immediate: true,
  onNeedRefresh() { markUpdateReady(updateSW) },
  onRegisteredSW(_url, reg) {
    if (!reg) return
    setRegistration(reg)
    const check = () => reg.update().catch(() => {})
    document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && check())
    setInterval(check, 30 * 60 * 1000)
  },
})

// Riak sentuhan pada tombol (satu listener; animasi murni CSS).
document.addEventListener('pointerdown', (e) => {
  const t = e.target.closest?.('.btn, .nav a')
  if (!t || t.disabled) return
  const r = t.getBoundingClientRect(), s = Math.max(r.width, r.height) * 2
  const el = document.createElement('span')
  el.className = 'ripple'
  el.style.cssText = `width:${s}px;height:${s}px;left:${e.clientX - r.left - s / 2}px;top:${e.clientY - r.top - s / 2}px`
  t.appendChild(el)
  el.addEventListener('animationend', () => el.remove())
})
