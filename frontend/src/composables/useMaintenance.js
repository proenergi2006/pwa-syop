import { reactive } from 'vue'
import axios from 'axios'

/**
 * Mode pemeliharaan: server menjawab 503 (code MAINTENANCE) untuk semua pengguna kecuali Super Admin.
 * Aplikasi menampilkan layar pemeliharaan, lalu memeriksa /status tiap 30 detik dan pulih sendiri saat selesai.
 */
export const maint = reactive({ aktif: false, pesan: '', sampai: null, mengecek: false })
let timer = null

export function setMaintenance(pesan, sampai) {
  maint.aktif = true
  maint.pesan = pesan || ''
  maint.sampai = sampai || null
  if (!timer) timer = setInterval(cekStatus, 30000)
}

export async function cekStatus() {
  maint.mengecek = true
  try {
    const base = import.meta.env.VITE_API_BASE_URL || '/api/v1'
    const { data } = await axios.get(`${base}/status`, { timeout: 8000 })
    if (data.pemeliharaan) {
      maint.pesan = data.pesan || ''
      maint.sampai = data.sampai || null
    } else {
      maint.aktif = false
      clearInterval(timer)
      timer = null
      location.reload() // pulih: muat ulang agar data segar
    }
  } catch { /* masih tak terjangkau: tetap tampilkan layar */ } finally { maint.mengecek = false }
}
