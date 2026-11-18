import { locale, numLocale } from '../i18n'

/** Nama bulan singkat/panjang (0-11) mengikuti bahasa aplikasi. */
export const bulanPendek = (i) => new Date(2000, i, 1).toLocaleDateString(locale.value, { month: 'short' })
export const bulanPanjang = (i) => new Date(2000, i, 1).toLocaleDateString(locale.value, { month: 'long' })

/** Angka ringkas: 1.250.000 -> "1,3 jt" (id) / "1.3M" (en). */
export function ringkas(n, dec = 1) {
  const v = Number(n) || 0
  const a = Math.abs(v)
  const en = numLocale.value === 'en-US'
  const f = (x) => x.toLocaleString(numLocale.value, { minimumFractionDigits: 0, maximumFractionDigits: dec })
  if (a >= 1e9) return f(v / 1e9) + (en ? 'B' : ' M')
  if (a >= 1e6) return f(v / 1e6) + (en ? 'M' : ' jt')
  if (a >= 1e4) return f(v / 1e3) + (en ? 'K' : ' rb')
  return v.toLocaleString(numLocale.value, { maximumFractionDigits: a < 100 && !Number.isInteger(v) ? 1 : 0 })
}

/** Batas atas sumbu yang "rapi" (1, 2, 2,5, 5, 10 x 10^k). */
export function niceMax(v) {
  if (!(v > 0)) return 1
  const p = 10 ** Math.floor(Math.log10(v))
  const f = v / p
  return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * p
}

export const jumlah = (a) => (a || []).reduce((s, x) => s + (Number(x) || 0), 0)

/** Palet kategori untuk donat/legenda. */
export const PALET = ['#0a66e8', '#0f8b8d', '#e07400', '#6d28d9', '#e50e30', '#8a96ad']
