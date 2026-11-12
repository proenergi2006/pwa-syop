import { computed, ref } from 'vue'
import { t, lang } from '../i18n'
import { api } from '../api/client'

// 'unsupported' | 'need-install' (iPhone, belum di Layar Utama) | 'denied' | 'off' | 'on' | 'unavailable' (server belum siap)
const state = ref('off')
const busy = ref(false)

const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
const standalone = window.matchMedia?.('(display-mode: standalone)').matches || navigator.standalone === true

const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window

/** Layanan push pihak ketiga bisa tidak menjawab (jaringan, VPN): jangan biarkan tombol macet. */
const withTimeout = (p, ms, msg) => Promise.race([p, new Promise((_, rej) => setTimeout(() => rej(new Error(msg)), ms))])

function b64ToBytes(b64) {
  const pad = '='.repeat((4 - (b64.length % 4)) % 4)
  const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'))
  return Uint8Array.from(raw, (c) => c.charCodeAt(0))
}

/**
 * Service worker aktif, atau null bila belum ada. JANGAN memakai navigator.serviceWorker.ready langsung:
 * promise itu tidak pernah selesai kalau SW tidak terdaftar (mode dev, atau sertifikat self-signed
 * yang membuat pendaftaran SW ditolak), sehingga apa pun yang menunggunya, termasuk keluar akun, macet selamanya.
 */
async function registration() {
  const reg = await navigator.serviceWorker.getRegistration()
  if (!reg) return null
  return reg.active ? reg : withTimeout(navigator.serviceWorker.ready, 3000, 'SW belum aktif').catch(() => null)
}

/** Baca keadaan saat ini (tanpa meminta izin). */
async function refresh() {
  if (!supported) { state.value = isIOS && !standalone ? 'need-install' : 'unsupported'; return }
  if (Notification.permission === 'denied') { state.value = 'denied'; return }
  try {
    const reg = await registration()
    const sub = reg ? await reg.pushManager.getSubscription() : null
    if (!sub) { state.value = 'off'; return }
    // Pastikan server masih menganggap perangkat ini milik akun yang login.
    const { data } = await api.get('/push/status', { params: { endpoint: sub.endpoint } })
    if (!data.subscribed) await sub.unsubscribe().catch(() => {}) // langganan milik akun lain di HP ini: lepas
    state.value = data.subscribed ? 'on' : 'off'
  } catch { state.value = 'off' }
}

async function enable() {
  if (busy.value) return
  busy.value = true
  try {
    const { data: k } = await api.get('/push/key')
    if (!k.enabled) { state.value = 'unavailable'; throw new Error(t('push.notEnabled')) }
    const perm = await Notification.requestPermission()
    if (perm !== 'granted') { state.value = perm === 'denied' ? 'denied' : 'off'; throw new Error(t('push.noPermission')) }
    const reg = await registration()
    if (!reg) throw new Error(t('push.needInstall'))
    const sub = (await reg.pushManager.getSubscription())
      || (await withTimeout(
        reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToBytes(k.public_key) }),
        20000,
        t('push.timeout'),
      ))
    await api.post('/push/subscribe', { ...sub.toJSON(), lang: lang.value })
    state.value = 'on'
  } finally { busy.value = false }
}

async function disable() {
  if (busy.value) return
  busy.value = true
  try {
    const reg = await registration()
    const sub = reg ? await reg.pushManager.getSubscription() : null
    if (sub) {
      await api.post('/push/unsubscribe', { endpoint: sub.endpoint }).catch(() => {})
      await sub.unsubscribe()
    }
    state.value = 'off'
  } finally { busy.value = false }
}

/** Bahasa aplikasi berubah: beri tahu server supaya isi notifikasi ikut berganti. */
async function resync() {
  if (state.value !== 'on') return
  try {
    const sub = await (await registration())?.pushManager.getSubscription()
    if (sub) await api.post('/push/subscribe', { ...sub.toJSON(), lang: lang.value })
  } catch { /* tidak kritis */ }
}

const sendTest = () => api.post('/push/test').then((r) => r.data.sent)

/** Lepas langganan perangkat ini saat keluar akun, supaya orang berikutnya tidak menerima notifikasi akun lama. */
async function releaseOnLogout() {
  if (!supported) return
  try { await disable() } catch { /* abaikan */ }
}

export function usePush() {
  return { state, busy, supported, isIOS, standalone, active: computed(() => state.value === 'on'), refresh, enable, disable, resync, sendTest, releaseOnLogout }
}
