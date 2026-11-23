import { defineStore } from 'pinia'
import * as syop from '../api/syop'

/** Jumlah item yang menunggu keputusan user: lencana navigasi, ikon menu Beranda, dan Menu. */
export const useInbox = defineStore('inbox', {
  state: () => ({ total: 0, po: 0, shipping: 0, trucking: 0, dr: 0, trucking_dr: 0, verifikasi_customer: 0, refund: 0, permohonan: 0, lcr: 0, sc: 0, oa: 0, forecast: 0, forecast_request: 0, forecast_om: 0, forecast_revisi: 0, forecast_bm: 0, pengumuman: 0, loaded: false }), // pengumuman = belum dibaca (bukan "menunggu keputusan", tidak ikut `all`) // total = penawaran
  getters: {
    all: (s) => s.total + s.po + s.shipping + s.trucking + s.dr + s.trucking_dr + s.verifikasi_customer + s.refund + s.permohonan + s.lcr + s.sc + s.oa + s.forecast + s.forecast_request + s.forecast_om + s.forecast_revisi + s.forecast_bm, // semua yang menunggu, dari semua menu
    icon: (s) => s.all + s.pengumuman, // angka di ikon aplikasi: yang menunggu keputusan + pengumuman belum dibaca
    count: (s) => (key) => (key === 'penawaran' ? s.total : key === 'forecast' ? s.forecast + s.forecast_request : key === 'forecast_om' ? s.forecast_om + s.forecast_revisi : s[key] || 0), // 'forecast' = periode + request qty (CEO); 'forecast_om' = request BM + periode ditolak (OM)
  },
  actions: {
    async refresh() {
      try {
        const r = await syop.badges()
        this.total = r.penawaran
        this.po = r.po
        this.shipping = r.shipping
        this.trucking = r.trucking || 0
        this.dr = r.dr || 0
        this.trucking_dr = r.trucking_dr || 0
        this.verifikasi_customer = r.verifikasi_customer || 0
        this.refund = r.refund || 0
        this.permohonan = r.permohonan || 0
        this.lcr = r.lcr || 0
        this.sc = r.sc || 0
        this.oa = r.oa || 0
        this.forecast = r.forecast || 0
        this.forecast_request = r.forecast_request || 0
        this.forecast_om = r.forecast_om || 0
        this.forecast_revisi = r.forecast_revisi || 0
        this.forecast_bm = r.forecast_bm || 0
        this.pengumuman = r.pengumuman || 0
        this.loaded = true
      } catch { /* lencana tidak kritis */ }
    },
    reset() { this.total = 0; this.po = 0; this.shipping = 0; this.trucking = 0; this.dr = 0; this.trucking_dr = 0; this.verifikasi_customer = 0; this.refund = 0; this.permohonan = 0; this.lcr = 0; this.sc = 0; this.oa = 0; this.forecast = 0; this.forecast_request = 0; this.forecast_om = 0; this.forecast_revisi = 0; this.forecast_bm = 0; this.pengumuman = 0; this.loaded = false },
  },
})
