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
