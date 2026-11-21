<script setup>
import { t, ts } from '../i18n'
import { useScrolled } from '../composables/useScrolled'
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import UserAvatar from '../components/UserAvatar.vue'
import { useAuth } from '../stores/auth'
import { useInbox } from '../stores/inbox'
import { showToast } from '../composables/useToast'

const scrolled = useScrolled()
const router = useRouter()
const auth = useAuth()
const inbox = useInbox()
const q = ref('')

// Modul yang belum tersedia ditampilkan terang-terangan "Segera", bukan tombol mati.
const modules = [
  { key: 'penawaran', label: 'mod.penawaran', icon: 'doc', to: 'approval', tone: 'blue', mod: 'penawaran' },
  { key: 'inventory', label: 'home.m.inventory', icon: 'box', to: 'inventory', tone: 'orange', mod: 'inventory' },
  { key: 'shipping', label: 'home.m.shipping', icon: 'ship', to: 'shipping', tone: 'indigo', mod: 'shipping' },
  { key: 'harga', label: 'home.m.harga', icon: 'tag', to: 'harga', tone: 'amber', mod: 'harga' },
  { key: 'reservasi', label: 'home.m.reservasi', icon: 'calendar', to: 'reservasi', tone: 'blue', mod: 'reservasi' },
  { key: 'mobil', label: 'home.m.mobil', icon: 'car', to: 'mobil', tone: 'orange', mod: 'mobil' },
  { key: 'produk', label: 'home.m.produk', icon: 'drop', to: 'produk', tone: 'teal', mod: 'produk' },
  { key: 'dr', label: 'home.m.dr', icon: 'cart', to: 'dr', tone: 'violet', mod: ['dr', 'dr_lihat'] },
  { key: 'master_terminal', label: 'home.m.terminal', icon: 'box', to: 'master-terminal', tone: 'teal', mod: 'master' },
  { key: 'master_vendor', label: 'home.m.vendor', icon: 'cart', to: 'master-vendor', tone: 'violet', mod: 'master' },
  { key: 'po_supplier', label: 'home.m.poSupplier', icon: 'cart', to: 'po-supplier', tone: 'violet', mod: 'po_supplier' },
  { key: 'trucking_ti', label: 'home.m.trucking', icon: 'truck', to: 'trucking-ti', tone: 'orange', mod: 'trucking_ti' },
  { key: 'trucking_dr', label: 'home.m.truckingDr', icon: 'truck', to: 'trucking-dr', tone: 'orange', mod: 'trucking_dr' },
  { key: 'sc', label: 'sc.title', icon: 'invoice', to: 'sc', tone: 'teal', mod: 'sc' },
  { key: 'permohonan', label: 'pm.title', icon: 'doc', to: 'permohonan', tone: 'indigo', mod: 'permohonan' },
  { key: 'lcr', label: 'lcr.title', icon: 'box', to: 'lcr', tone: 'teal', mod: 'lcr' },
  { key: 'refund', label: 'home.m.refund', icon: 'refund', to: 'refund-penerima', tone: 'green', mod: 'refund' },
  { key: 'report', label: 'report.title', icon: 'chart', to: 'report', tone: 'green', mod: ['report', 'rssp', 'report_procurement'] },
  { key: 'verifikasi_customer', label: 'vc.title', icon: 'doc', to: 'verifikasi-customer', tone: 'blue', mod: 'verifikasi_customer' },
  { key: 'fuel', label: 'mod.fuel', icon: 'drop', tone: 'teal' },
  { key: 'po', label: 'mod.po', icon: 'cart', to: 'po', tone: 'violet', mod: 'po' },
  { key: 'sales', label: 'mod.sales', icon: 'chart', tone: 'green' },
  { key: 'trucking', label: 'home.m.trucking', icon: 'truck', to: 'trucking', tone: 'orange', mod: 'trucking' },
  { key: 'oa', label: 'oa.title', icon: 'invoice', to: 'oa', tone: 'violet', mod: 'oa' },
  { key: 'forecast', label: 'fc.title', icon: 'chart', to: 'forecast', tone: 'green', mod: 'forecast' },
  { key: 'forecast_om', label: 'fo.title', icon: 'chart', to: 'forecast-om', tone: 'teal', mod: 'forecast_om' },
  { key: 'forecast_bm', label: 'fo.title', icon: 'chart', to: 'forecast-bm', tone: 'teal', mod: 'forecast_bm' },
  { key: 'pengumuman', label: 'pg.title', icon: 'bell', to: 'pengumuman', tone: 'rose', mod: 'pengumuman' },
  { key: 'pengumuman_admin', label: 'pg.manage', icon: 'edit', to: 'pengumuman-admin', tone: 'indigo', mod: 'pengumuman_admin' },
  { key: 'sistem', label: 'sys.title', icon: 'shield', to: 'sistem', tone: 'indigo', mod: 'sistem' },
  { key: 'pengguna', label: 'usr.menu', icon: 'user', to: 'sistem', tab: 'pengguna', tone: 'teal', mod: 'sistem' },
  { key: 'kirim', label: 'mod.kirim', icon: 'truck', tone: 'orange' },
  { key: 'invoice', label: 'mod.invoice', icon: 'invoice', tone: 'rose' },
]
const tools = [
  { key: 'riwayat', label: 'mod.riwayat', icon: 'history', to: 'riwayat', tone: 'indigo' },
  { key: 'jadwal', label: 'mod.jadwal', icon: 'calendar', tone: 'amber' },
  { key: 'helpdesk', label: 'mod.helpdesk', icon: 'headset', tone: 'teal' },
]

const match = (m) => t(m.label).toLowerCase().includes(q.value.trim().toLowerCase())
const has = (m) => !m || !Array.isArray(auth.user?.modules) || (Array.isArray(m) ? m.some((x) => auth.user.modules.includes(x)) : auth.user.modules.includes(m))
// OM/BM (hanya modul penawaran): menu lain, termasuk yang "segera hadir", disembunyikan.
const limited = computed(() => {
  const mods = auth.user?.modules
  return Array.isArray(mods) && mods.every((x) => ['penawaran', 'stok_terminal', 'reservasi', 'mobil', 'inventory', 'dr', 'po_supplier', 'trucking_ti', 'report_procurement', 'trucking_dr', 'master', 'verifikasi_customer', 'refund', 'permohonan', 'lcr', 'sc', 'report', 'rssp', 'forecast_om', 'forecast_bm', 'rssp_input', 'pengumuman', 'pengumuman_admin', 'sistem'].includes(x))
})
const all = computed(() => [...modules, ...tools].filter((m) => (m.mod ? has(m.mod) : m.key === 'riwayat' ? has('penawaran') : !limited.value)))
// Hanya yang sudah bisa dipakai tampil sebagai kartu; sisanya diringkas sebagai daftar "Segera hadir".
const activeMods = computed(() => all.value.filter((m) => m.to && match(m)))
const soon = computed(() => all.value.filter((m) => !m.to && match(m)))

function go(m) {
  if (m.to) router.push({ name: m.to, query: m.tab ? { tab: m.tab } : undefined })
  else showToast(t('home.comingSoon', { name: t(m.label) }))
}
</script>

<template>
  <div class="page">
    <div class="page-head" :class="{ stuck: scrolled }">
      <div>
        <h1 class="page-title">{{ t('menu.title') }}</h1>
        <p class="page-sub">{{ t('menu.sub') }}</p>
      </div>
      <router-link :to="{ name: 'profil' }" class="avatar" :aria-label="t('common.profile')"><UserAvatar /><i class="dot" /></router-link>
    </div>

    <label class="search"><Icon name="search" :size="20" /><input v-model="q" type="search" :aria-label="t('menu.searchAria')" :placeholder="t('menu.search')" /></label>

    <template v-if="activeMods.length">
      <div class="section-head"><h2>{{ t('menu.available') }}</h2></div>
      <div class="grid">
        <button v-for="(m, i) in activeMods" :key="m.key" class="mod rise" :style="{ '--i': i }" @click="go(m)">
          <span class="qi" :class="'tone-' + m.tone"><Icon :name="m.icon" :size="24" /><b v-if="inbox.count(m.key)" class="mb">{{ inbox.count(m.key) > 99 ? '99+' : inbox.count(m.key) }}</b></span>
          <span>{{ t(m.label) }}</span>
        </button>
      </div>
    </template>

    <template v-if="soon.length">
      <div class="section-head"><h2>{{ t('menu.soon') }}</h2></div>
      <section class="card soonbox rise" style="--i: 3">
        <span v-for="m in soon" :key="m.key" class="sc"><span class="tinted sc-i" :class="'tone-' + m.tone"><Icon :name="m.icon" :size="14" /></span>{{ t(m.label) }}</span>
      </section>
    </template>

    <p v-if="!activeMods.length && !soon.length" class="page-sub" style="text-align:center;margin-top:40px">{{ t('menu.notFound') }}</p>
  </div>
</template>

<style scoped>
.grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.mod { align-content: start; background: var(--card); border: 1px solid var(--line); border-radius: 18px; padding: 14px 6px 12px; display: grid; justify-items: center; gap: 8px; font-weight: 600; font-size: 12px; text-align: center; box-shadow: var(--shadow); min-height: 98px; }
.qi { width: 48px; height: 48px; border-radius: 15px; }
.soon { opacity: 0.72; }
.qi { position: relative; }
.mb { position: absolute; top: -6px; right: -6px; min-width: 20px; height: 20px; padding: 0 5px; border-radius: 10px; background: var(--badge); color: #fff; font-size: 12px; font-weight: 700; display: grid; place-items: center; border: 2px solid var(--card); }
.soonbox .tinted { --c: var(--muted); } /* modul belum aktif: netral, bukan warna status */
.soon em { font-style: normal; font-size: 12px; letter-spacing: 0.02em; font-weight: 700; color: var(--muted); background: var(--chip-bg); padding: 2px 8px; border-radius: 999px; }
.list { padding: 2px 14px; }
.row { display: flex; align-items: center; gap: 12px; width: 100%; min-height: 54px; padding: 0; background: none; border: 0; border-bottom: 1px solid var(--line); text-align: left; color: var(--muted); }
.row:last-child { border-bottom: 0; }
.row b { flex: 1; color: var(--ink); font-size: 14.5px; font-weight: 600; }
.row em { font-style: normal; font-size: 12px; font-weight: 700; color: var(--muted); background: var(--chip-bg); padding: 3px 10px; border-radius: 999px; }
</style>

<style scoped>
.soonbox { display: flex; flex-wrap: wrap; gap: 8px; }
.sc { display: inline-flex; align-items: center; gap: 8px; min-height: 36px; padding: 0 12px 0 6px; border-radius: 999px; background: var(--chip-bg); color: var(--ink-2); font-size: 13px; font-weight: 600; }
.sc-i { width: 26px; height: 26px; border-radius: 9px; }
</style>
