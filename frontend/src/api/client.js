import axios from 'axios'

const TOKEN_KEY = 'orcax_token'

// Penyimpanan bisa gagal (mode privat / data situs diblokir); aplikasi tetap harus jalan.
export const tokenStore = {
  get: () => { try { return localStorage.getItem(TOKEN_KEY) } catch { return null } },
  set: (t) => { try { localStorage.setItem(TOKEN_KEY, t) } catch { /* abaikan */ } },
  clear: () => { try { localStorage.removeItem(TOKEN_KEY) } catch { /* abaikan */ } },
}

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '/api/v1',
  timeout: 20000,
  headers: { Accept: 'application/json' },
})

// Versi build aplikasi ini (diisi saat build); server memakainya untuk tahu siapa yang belum memperbarui.
const APP_VERSION = typeof __APP_VERSION__ === 'string' ? __APP_VERSION__ : 'dev'

api.interceptors.request.use((cfg) => {
  const t = tokenStore.get()
  cfg.headers['X-App-Version'] = APP_VERSION
  if (t) cfg.headers.Authorization = `Bearer ${t}`
  return cfg
})

let onUnauthorized = () => {}
export const setUnauthorizedHandler = (fn) => (onUnauthorized = fn)

api.interceptors.response.use(
  (r) => r,
  (err) => {
    // 401 pada /auth/login berarti kredensial salah, bukan sesi habis.
    const isLogin = err.config?.url?.includes('/auth/login')
    if (err.response?.status === 401 && !isLogin) onUnauthorized()
    return Promise.reject(err)
  },
)

/** Pesan galat ramah untuk pengguna (validasi 422, kunci 429, offline, dll). */
import { t, ts } from '../i18n'

export function errorMessage(err) {
  if (!err.response) {
    return err.code === 'ECONNABORTED'
      ? t('err.timeout')
      : t('err.offline')
  }
  const { status, data } = err.response
  if (status === 429) {
    const s = Number(err.response.headers?.['retry-after']) || 60
    return t('err.tooMany', { s })
  }
  if (data?.errors) return ts(Object.values(data.errors).flat()[0])
  if (data?.message) return ts(data.message)
  if (status >= 500) return t('err.server')
  return t('err.generic')
}

export const errorCode = (err) => err.response?.data?.code || null
