import { isEn, locale, numLocale, t } from '../i18n'

const TZ = 'Asia/Jakarta'
const NUM = () => numLocale.value
const LOC = () => locale.value

export const rupiah = (n, dec = 0) =>
  'Rp ' + Number(n || 0).toLocaleString(NUM(), { minimumFractionDigits: dec, maximumFractionDigits: dec })

export const angka = (n, dec = 0) =>
  Number(n || 0).toLocaleString(NUM(), { minimumFractionDigits: dec, maximumFractionDigits: dec })

// MySQL mengirim "YYYY-MM-DD HH:mm:ss" tanpa zona; backend berjalan di WIB.
const toDate = (s) => (s ? new Date(String(s).replace(' ', 'T') + (String(s).length > 10 ? '+07:00' : 'T00:00:00+07:00')) : null)

export const tanggal = (s) => {
  const d = toDate(s)
  return d ? d.toLocaleDateString(LOC(), { day: '2-digit', month: 'short', year: 'numeric', timeZone: TZ }) : '-'
}

export const tanggalJam = (s) => {
  const d = toDate(s)
  return d
    ? `${d.toLocaleDateString(LOC(), { day: '2-digit', month: 'short', year: 'numeric', timeZone: TZ })} · ${d.toLocaleTimeString(LOC(), { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: TZ }).replace('.', ':')}`
    : '-'
}

export function lalu(s) {
  const d = toDate(s)
  if (!d) return ''
  const sec = Math.max(0, (Date.now() - d.getTime()) / 1000)
  const en = isEn.value
  if (sec < 60) return en ? 'just now' : 'baru saja'
  if (sec < 3600) return en ? `${Math.floor(sec / 60)} min ago` : `${Math.floor(sec / 60)} menit lalu`
  if (sec < 86400) return en ? `${Math.floor(sec / 3600)} h ago` : `${Math.floor(sec / 3600)} jam lalu`
  if (sec < 86400 * 30) return en ? `${Math.floor(sec / 86400)} d ago` : `${Math.floor(sec / 86400)} hari lalu`
  return tanggal(s)
}

export const inisial = (name = '') =>
  name.trim().split(/\s+/).slice(0, 2).map((w) => w[0]?.toUpperCase() || '').join('') || '?'

export const sapaan = () => {
  const h = Number(new Date().toLocaleString('en-GB', { hour: '2-digit', hour12: false, timeZone: TZ }))
  return t(h < 11 ? 'home.greet.morning' : h < 15 ? 'home.greet.noon' : h < 18 ? 'home.greet.afternoon' : 'home.greet.evening')
}

export const hariIni = () =>
  new Date().toLocaleDateString(LOC(), { weekday: 'short', day: '2-digit', month: 'short', timeZone: TZ })
export const jamSekarang = () =>
  new Date().toLocaleTimeString(LOC(), { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: TZ }).replace('.', ':')

/** Nilai rupiah ringkas: 92.978.550 -> "Rp 93 jt", 1.250.000.000 -> "Rp 1,25 M". */
export function rupiahRingkas(n) {
  const v = Number(n || 0)
  const fmt = (x, d) => x.toLocaleString(NUM(), { minimumFractionDigits: 0, maximumFractionDigits: d })
  // Indonesia: jt = juta, M = miliar. Inggris: mn = million, bn = billion.
  if (v >= 1e9) return `Rp ${fmt(v / 1e9, 2)} ${isEn.value ? 'bn' : 'M'}`
  if (v >= 1e6) return `Rp ${fmt(v / 1e6, v >= 1e8 ? 0 : 1)} ${isEn.value ? 'mn' : 'jt'}`
  return 'Rp ' + fmt(v, 0)
}

/** Lama menunggu dari waktu dibuat: { teks, hari, level } (level: ok | late | urgent). */
export function lamaMenunggu(s) {
  const d = toDate(s)
  if (!d) return { teks: '-', hari: 0, level: 'ok' }
  const jam = Math.max(0, (Date.now() - d.getTime()) / 3600000)
  const hari = Math.floor(jam / 24)
  const en = isEn.value
  const teks = jam < 1 ? (en ? 'new' : 'baru') : jam < 24 ? `${Math.floor(jam)} ${en ? 'h' : 'jam'}` : hari < 60 ? `${hari} ${en ? 'd' : 'hari'}` : `${Math.floor(hari / 30)} ${en ? 'mo' : 'bln'}`
  return { teks, hari, level: hari >= 7 ? 'urgent' : hari >= 3 ? 'late' : 'ok' }
}
