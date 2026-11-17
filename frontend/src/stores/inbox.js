import { defineStore } from 'pinia'
import * as syop from '../api/syop'

/** Jumlah item yang menunggu keputusan user: lencana navigasi, ikon menu Beranda, dan Menu. */
export const useInbox = defineStore('inbox', {
  state: () => ({ total: 0, po: 0, shipping: 0, trucking: 0, dr: 0, trucking_dr: 0, verifikasi_customer: 0, refund: 0, permohonan: 0, lcr: 0, loaded: false }), // total = penawaran
  getters: {
    all: (s) => s.total + s.po + s.shipping + s.trucking + s.dr + s.trucking_dr + s.verifikasi_customer + s.refund + s.permohonan + s.lcr, // semua yang menunggu, dari semua menu
    count: (s) => (key) => (key === 'penawaran' ? s.total : s[key] || 0),
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
        this.loaded = true
      } catch { /* lencana tidak kritis */ }
    },
    reset() { this.total = 0; this.po = 0; this.shipping = 0; this.trucking = 0; this.dr = 0; this.trucking_dr = 0; this.verifikasi_customer = 0; this.refund = 0; this.permohonan = 0; this.lcr = 0; this.loaded = false },
  },
})
