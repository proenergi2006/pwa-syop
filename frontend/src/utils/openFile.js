import { api } from '../api/client'
import { showToast } from '../composables/useToast'

/** Buka berkas dari endpoint ber-token: ambil sebagai blob lalu tampilkan (PDF/gambar) atau unduh. Jendela dibuka dulu agar tidak diblokir browser. */
export async function openFile(url, fallbackName = 'berkas') {
  const w = window.open('', '_blank')
  try {
    const r = await api.get(url, { responseType: 'blob' })
    const blob = r.data
    const href = URL.createObjectURL(blob)
    const m = /filename="([^"]+)"/.exec(r.headers?.['content-disposition'] || '')
    const viewable = /pdf|image\//.test(blob.type)
    if (w && viewable) { w.location.href = href } else {
      w?.close()
      const a = document.createElement('a')
      a.href = href
      a.download = m?.[1] || fallbackName
      document.body.appendChild(a)
      a.click()
      a.remove()
    }
    setTimeout(() => URL.revokeObjectURL(href), 120000)
  } catch (e) {
    w?.close()
    let msg = 'Berkas tidak dapat dibuka.'
    if (e.response?.status === 404) msg = 'Berkas tidak ditemukan di server.'
    showToast(msg, 'err')
  }
}
