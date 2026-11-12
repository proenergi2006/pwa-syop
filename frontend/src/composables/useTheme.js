import { ref } from 'vue'

/**
 * Mode tampilan: 'system' (ikuti HP), 'light', atau 'dark'. Pilihan disimpan di perangkat dan dipasang
 * sebagai <html data-theme>. Skrip kecil di index.html memasangnya sebelum aplikasi tampil (tanpa kedip).
 */
const KEY = 'orcax_theme'
const read = () => { try { const v = localStorage.getItem(KEY); return v === 'light' || v === 'dark' ? v : 'system' } catch { return 'system' } }

export const theme = ref(read())

function apply() {
  const root = document.documentElement
  if (theme.value === 'system') root.removeAttribute('data-theme')
  else root.setAttribute('data-theme', theme.value)
  // Warna bilah status browser/PWA mengikuti mode.
  const dark = theme.value === 'dark' || (theme.value === 'system' && matchMedia?.('(prefers-color-scheme: dark)').matches)
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', dark ? '#050d24' : '#071333')
}

export function setTheme(v) {
  theme.value = v === 'light' || v === 'dark' ? v : 'system'
  try { theme.value === 'system' ? localStorage.removeItem(KEY) : localStorage.setItem(KEY, theme.value) } catch { /* abaikan */ }
  apply()
}

export function initTheme() {
  apply()
  // Mode "sistem": ikuti perubahan pengaturan HP saat aplikasi terbuka.
  matchMedia?.('(prefers-color-scheme: dark)').addEventListener?.('change', () => { if (theme.value === 'system') apply() })
}
