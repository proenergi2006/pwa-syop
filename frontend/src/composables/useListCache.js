// Salinan terakhir daftar (hanya baca) supaya layar tidak kosong saat offline.
// Disimpan per pengguna dan DIHAPUS saat keluar. Aksi setuju/tolak tetap wajib online.
const PREFIX = 'orcax_cache_'
const uid = () => { try { return JSON.parse(localStorage.getItem('orcax_user') || 'null')?.id || 0 } catch { return 0 } }
const k = (name) => `${PREFIX}${uid()}_${name}`

export function readCache(name) {
  try { return JSON.parse(localStorage.getItem(k(name)) || 'null') } catch { return null }
}
export function writeCache(name, payload) {
  try { localStorage.setItem(k(name), JSON.stringify({ t: Date.now(), ...payload })) } catch { /* penyimpanan penuh/diblokir */ }
}
export function clearCaches() {
  try { Object.keys(localStorage).filter((x) => x.startsWith(PREFIX)).forEach((x) => localStorage.removeItem(x)) } catch { /* abaikan */ }
}
