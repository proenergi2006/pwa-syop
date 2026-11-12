import { defineStore } from 'pinia'
import * as syop from '../api/syop'

/** Jumlah item yang menunggu keputusan user: lencana navigasi, ikon menu Beranda, dan Menu. */
export const useInbox = defineStore('inbox', {
  state: () => ({ total: 0, po: 0, shipping: 0, trucking: 0, dr: 0, loaded: false }), // total = penawaran
  getters: {
    all: (s) => s.total + s.po + s.shipping + s.trucking + s.dr, // semua yang menunggu, dari semua menu
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
        this.loaded = true
      } catch { /* lencana tidak kritis */ }
    },
    reset() { this.total = 0; this.po = 0; this.shipping = 0; this.trucking = 0; this.dr = 0; this.loaded = false },
  },
})
