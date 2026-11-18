import { reactive } from 'vue'

/** Status pembaruan aplikasi: `ready` = versi baru sudah diunduh dan menunggu; halaman ini masih menjalankan versi lama. */
export const update = reactive({ ready: false, dismissed: false, apply: null })

export function markUpdateReady(apply) {
  update.apply = apply
  update.ready = true
  update.dismissed = false
}

/** Aktifkan versi baru lalu muat ulang halaman (dipanggil dari pita). */
export const reloadApp = () => (update.apply ? update.apply(true) : window.location.reload())

let registration = null
export const setRegistration = (reg) => { registration = reg }

/** Cek manual (tombol di Profil). Mengembalikan true bila ada versi baru yang siap dipakai. */
export async function checkUpdate() {
  if (update.ready) return true
  try { await registration?.update() } catch { /* offline */ }
  // Versi baru perlu waktu diunduh lalu memicu onNeedRefresh; tunggu sebentar.
  for (let i = 0; i < 12 && !update.ready; i++) await new Promise((r) => setTimeout(r, 500))
  return update.ready
}
