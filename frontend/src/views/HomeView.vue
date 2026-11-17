<script setup>
import { t, ts } from '../i18n'
import { computed, onMounted, onBeforeUnmount, ref } from 'vue'
import { useRouter } from 'vue-router'
import TankChart from '../components/TankChart.vue'
import Icon from '../components/Icon.vue'
import UserAvatar from '../components/UserAvatar.vue'
import PenawaranItem from '../components/PenawaranItem.vue'
import StateBox from '../components/StateBox.vue'
import { useAuth } from '../stores/auth'
import { useInbox } from '../stores/inbox'
import { useCountUp } from '../composables/useCountUp'
import { useScrolled } from '../composables/useScrolled'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'
import { showToast } from '../composables/useToast'
import { angka, hariIni, jamSekarang, rupiahRingkas, sapaan, lalu, tanggalJam } from '../utils/format'

const router = useRouter()
const scrolled = useScrolled(96) // lewat dari area salam: bilah ringkas menempel di atas
const auth = useAuth()
const inbox = useInbox()

const pending = ref([])
const FACE_KEY = 'orcax_face_banner'
const faceDismissed = ref((() => { try { return localStorage.getItem(FACE_KEY) === '1' } catch { return false } })())
const showFaceBanner = computed(() => !faceDismissed.value && auth.user && !auth.user.face_enrolled && auth.method === 'password')
const dismissFace = () => { faceDismissed.value = true; try { localStorage.setItem(FACE_KEY, '1') } catch { /* abaikan */ } }
const recent = ref([])
const loading = ref(true)
const error = ref('')
const q = ref('')
const clock = ref(jamSekarang())
let timer

const grand = computed(() => inbox.all) // semua yang menunggu keputusan (kartu ringkasan dan lonceng)
const total = grand
const shownTotal = useCountUp(total)

// Panel notifikasi (lonceng) = semua yang menunggu keputusan user: penawaran, PO, Shipping Instruction.
const notifOpen = ref(false)
const notifLoading = ref(false)
const notifs = ref([])
const logoutOpen = ref(false)

// Menu utama (bulat): yang sudah bisa dipakai membuka halamannya, sisanya memberi tahu "segera hadir".
const limitedMods = computed(() => Array.isArray(auth.user?.modules) && auth.user.modules.every((x) => ['penawaran', 'stok_terminal', 'reservasi', 'mobil', 'inventory', 'dr', 'trucking_dr', 'master', 'verifikasi_customer', 'refund', 'permohonan', 'lcr', 'sc'].includes(x)))
const has = (m) => !m || !Array.isArray(auth.user?.modules) || auth.user.modules.includes(m)
const canPenawaran = computed(() => has('penawaran'))
const canApprove = computed(() => ['penawaran', 'po', 'shipping', 'trucking', 'dr', 'trucking_dr', 'verifikasi_customer', 'refund', 'permohonan', 'lcr', 'sc'].some((m) => has(m)))
const breakdown = computed(() => [['penawaran', 'home.m.penawaran'], ['po', 'home.m.po'], ['shipping', 'SI'], ['trucking', 'TI'], ['dr', 'DR'], ['trucking_dr', 'Trucking DR'], ['verifikasi_customer', 'Verifikasi Customer'], ['refund', 'Refund'], ['permohonan', 'Permohonan'], ['lcr', 'LCR'], ['sc', 'SC']].filter(([k]) => has(k) && inbox.count(k) > 0).map(([k, l]) => `${inbox.count(k)} ${l.startsWith('home.') ? t(l) : l}`).join(' · '))
const quick = [
  { key: 'penawaran', label: 'home.m.penawaran', icon: 'doc', to: 'approval', c: 'var(--accent)', mod: 'penawaran' },
  { key: 'inventory', label: 'home.m.inventory', icon: 'box', to: 'inventory', c: '#e07400', mod: 'inventory' },
  { key: 'po', label: 'home.m.po', icon: 'po', to: 'po', c: '#e50e30', mod: 'po' },
  { key: 'harga', label: 'home.m.harga', icon: 'tag', to: 'harga', c: '#e07400', mod: 'harga' },
  { key: 'reservasi', label: 'home.m.reservasi', icon: 'calendar', to: 'reservasi', c: '#04247a', mod: 'reservasi' },
  { key: 'mobil', label: 'home.m.mobil', icon: 'car', to: 'mobil', c: '#e07400', mod: 'mobil' },
  { key: 'produk', label: 'home.m.produk', icon: 'drop', to: 'produk', c: '#0f8b8d', mod: 'produk' },
  { key: 'dr', label: 'home.m.dr', icon: 'cart', to: 'dr', c: '#6d28d9', mod: 'dr' },
  { key: 'trucking_dr', label: 'home.m.truckingDr', icon: 'truck', to: 'trucking-dr', c: '#e07400', mod: 'trucking_dr' },
  { key: 'verifikasi_customer', label: 'vc.title', icon: 'doc', to: 'verifikasi-customer', c: 'var(--accent)', mod: 'verifikasi_customer' },
  { key: 'master_terminal', label: 'home.m.terminal', icon: 'box', to: 'master-terminal', c: '#0f8b8d', mod: 'master' },
  { key: 'master_vendor', label: 'home.m.vendor', icon: 'cart', to: 'master-vendor', c: '#6d28d9', mod: 'master' },
  { key: 'sc', label: 'sc.title', icon: 'invoice', to: 'sc', c: '#0f8b8d', mod: 'sc' },
  { key: 'permohonan', label: 'pm.title', icon: 'doc', to: 'permohonan', c: '#4f46e5', mod: 'permohonan' },
  { key: 'lcr', label: 'lcr.title', icon: 'box', to: 'lcr', c: '#0f8b8d', mod: 'lcr' },
  { key: 'refund', label: 'home.m.refund', icon: 'refund', to: 'refund-penerima', c: 'var(--accent)', mod: 'refund' },
  { key: 'shipping', label: 'home.m.shipping', icon: 'ship', to: 'shipping', c: 'var(--accent)', mod: 'shipping' },
  { key: 'trucking', label: 'home.m.trucking', icon: 'truck', to: 'trucking', c: '#e07400', mod: 'trucking' },
]
// Tata letak menu diatur pengguna (urutan dan sembunyikan); disimpan per akun di perangkat ini.
const LAYOUT_KEY = () => `orcax_menu_${auth.user?.id ?? 'x'}`
const layout = ref((() => { try { const v = JSON.parse(localStorage.getItem(LAYOUT_KEY()) || 'null'); return v && Array.isArray(v.order) ? { order: v.order, hidden: v.hidden || [] } : { order: [], hidden: [] } } catch { return { order: [], hidden: [] } } })())
const allowed = computed(() => quick.filter((x) => (x.mod ? has(x.mod) : !limitedMods.value)))
const arranged = computed(() => {
  const o = layout.value.order
  return [...allowed.value].sort((a, b) => { const ia = o.indexOf(a.key), ib = o.indexOf(b.key); return (ia < 0 ? 99 : ia) - (ib < 0 ? 99 : ib) })
})
const shown = computed(() => arranged.value.filter((x) => !layout.value.hidden.includes(x.key)))
const arrange = ref(false)
const draft = ref({ order: [], hidden: [] })
function openArrange() { draft.value = { order: arranged.value.map((x) => x.key), hidden: [...layout.value.hidden] }; arrange.value = true }
const draftItems = computed(() => draft.value.order.map((k) => quick.find((x) => x.key === k)).filter(Boolean))
function move(i, d) { const o = draft.value.order, j = i + d; if (j < 0 || j >= o.length) return; [o[i], o[j]] = [o[j], o[i]] }
function toggleHide(k) { const h = draft.value.hidden; const i = h.indexOf(k); i < 0 ? h.push(k) : h.splice(i, 1) }
function saveArrange() {
  if (draft.value.hidden.length >= draft.value.order.length) draft.value.hidden = draft.value.hidden.slice(1) // minimal satu menu tampil
  layout.value = { order: [...draft.value.order], hidden: [...draft.value.hidden] }
  try { localStorage.setItem(LAYOUT_KEY(), JSON.stringify(layout.value)) } catch { /* penyimpanan tidak tersedia */ }
  arrange.value = false
}
function resetArrange() { draft.value = { order: allowed.value.map((x) => x.key), hidden: [] } }
function goQuick(m) {
  if (m.to) router.push({ name: m.to })
  else showToast(t('home.comingSoon', { name: t(m.label) }))
}
const loggingOut = ref(false)

async function load() {
  loading.value = true
  error.value = ''
  if (!canPenawaran.value) { loading.value = false; return }
  try {
    const [p, h] = await Promise.all([syop.listPending({ per_page: 2, sort: 'asc' }), syop.listHistory({ per_page: 3 })])
    pending.value = p.data
    inbox.total = p.meta.total
    recent.value = h.data
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}

async function openNotif() {
  notifOpen.value = true
  notifLoading.value = true
  try {
    const jobs = []
    if (has('penawaran')) jobs.push(syop.listPending({ per_page: 6, sort: 'asc' }).then((r) => ({
      key: 'penawaran', to: 'approval', icon: 'doc', tone: 'orange', total: r.meta.total,
      items: r.data.map((n) => ({ id: n.id_penawaran, title: n.nomor_surat, sub: `${n.nama_customer} · ${n.nama_cabang}`, when: n.created_time, go: { name: 'approval-detail', params: { id: n.id_penawaran } } })) })))
    if (has('po')) jobs.push(syop.listPo({ per_page: 6, tab: 'wait' }).then((r) => ({
      key: 'po', to: 'po', icon: 'po', tone: 'rose', total: r.waiting,
      items: r.data.map((n) => ({ id: n.id, title: n.nomor_po, sub: `${n.nama_vendor} · ${rupiahRingkas(n.total_order)}`, when: n.tanggal, go: { name: 'po-detail', params: { id: n.id } } })) })))
    if (has('shipping')) jobs.push(syop.listShipping({ per_page: 6, tab: 'wait' }).then((r) => ({
      key: 'shipping', to: 'shipping', icon: 'ship', tone: 'indigo', total: r.waiting,
      items: r.data.map((n) => ({ id: n.id, title: n.nomor_si || n.nomor_req, sub: `${n.nama_vendor} · ${angka(n.quantity)} L`, when: n.created_at, go: { name: 'shipping-detail', params: { id: n.id } } })) })))
    if (has('trucking')) jobs.push(syop.listTrucking({ per_page: 6, tab: 'wait' }).then((r) => ({
      key: 'trucking', to: 'trucking', icon: 'truck', tone: 'orange', total: r.waiting,
      items: r.data.map((n) => ({ id: n.id, title: n.nomor_ti, sub: `${n.nama_transportir || '-'} · ${angka(n.volume)} L`, when: n.created_at, go: { name: 'trucking-detail', params: { id: n.id } } })) })))
    if (has('dr')) jobs.push(syop.listDr({ per_page: 6, status: '3' }).then((r) => ({
      key: 'dr', to: 'dr', icon: 'cart', tone: 'violet', total: r.waiting,
      items: r.data.map((n) => ({ id: n.id, title: n.nomor_pr, sub: `${n.customer.join(', ') || '-'} · ${angka(n.volume.reduce((s, v) => s + v, 0))} L`, when: n.tanggal_pr, go: { name: 'dr-detail', params: { id: n.id } } })) })))
    if (has('trucking_dr')) jobs.push(syop.listTruckingDr({ per_page: 6, status: '0' }).then((r) => ({
      key: 'trucking_dr', to: 'trucking-dr', icon: 'truck', tone: 'orange', total: r.waiting,
      items: r.data.map((n) => ({ id: n.id, title: n.nomor_dr, sub: `${n.cabang} · ${angka(n.volume.reduce((s, v) => s + v, 0))} L`, when: n.tanggal, go: { name: 'trucking-dr-detail', params: { id: n.id } } })) })))
    if (has('verifikasi_customer')) jobs.push(syop.listVerifikasiCustomer({ per_page: 6, filter: 'menunggu' }).then((r) => ({
      key: 'verifikasi_customer', to: 'verifikasi-customer', icon: 'doc', tone: 'blue', total: r.meta.total,
      items: r.data.map((n) => ({ id: n.id, title: n.kode, sub: `${n.customer} · ${n.cabang}`, when: '', go: { name: 'verifikasi-customer-detail', params: { id: n.id } } })) })))
    if (has('refund')) jobs.push(syop.listRefund({ per_page: 6, filter: 'menunggu' }).then((r) => ({
      key: 'refund', to: 'refund-penerima', icon: 'refund', tone: 'green', total: r.meta.total,
      items: r.data.map((n) => ({ id: n.id, title: n.nama, sub: n.customer, when: '', go: { name: 'refund-penerima-detail', params: { id: n.id } } })) })))
    if (has('permohonan')) jobs.push(syop.listPermohonan({ per_page: 6, filter: 'menunggu' }).then((r) => ({
      key: 'permohonan', to: 'permohonan', icon: 'doc', tone: 'indigo', total: r.meta.total,
      items: r.data.map((n) => ({ id: n.id, title: n.customer, sub: n.judul, when: '', go: { name: 'permohonan-detail', params: { id: n.id } } })) })))
    if (has('lcr')) jobs.push(syop.listLcr({ per_page: 6, filter: 'menunggu' }).then((r) => ({
      key: 'lcr', to: 'lcr', icon: 'box', tone: 'teal', total: r.meta.total,
      items: r.data.map((n) => ({ id: n.id, title: n.kode, sub: n.customer, when: '', go: { name: 'lcr-detail', params: { id: n.id } } })) })))
    if (has('sc')) jobs.push(syop.listSc({ per_page: 6, filter: 'menunggu' }).then((r) => ({
      key: 'sc', to: 'sc', icon: 'invoice', tone: 'teal', total: r.meta.total,
      items: r.data.map((n) => ({ id: n.id, title: n.nomor_po, sub: n.customer, when: '', go: { name: 'sc-detail', params: { id: n.id } } })) })))
    const groups = (await Promise.all(jobs.map((j) => j.catch(() => null)))).filter((g) => g && g.total > 0)
    notifs.value = groups
    const by = Object.fromEntries(groups.map((g) => [g.key, g.total]))
    inbox.total = by.penawaran || 0
    inbox.po = by.po || 0
    inbox.shipping = by.shipping || 0
    inbox.trucking = by.trucking || 0
    inbox.dr = by.dr || 0
    inbox.trucking_dr = by.trucking_dr || 0
    inbox.verifikasi_customer = by.verifikasi_customer || 0
    inbox.refund = by.refund || 0
    inbox.permohonan = by.permohonan || 0
    inbox.lcr = by.lcr || 0
    inbox.sc = by.sc || 0
  } catch { notifs.value = [] } finally { notifLoading.value = false }
}

const open = (it) => { notifOpen.value = false; router.push({ name: 'approval-detail', params: { id: it.id_penawaran } }) } // kartu penawaran di Beranda
const openNotifItem = (it) => { notifOpen.value = false; router.push(it.go) }
const openGroup = (g) => { notifOpen.value = false; router.push({ name: g.to }) }
const search = () => router.push({ name: 'approval', query: q.value.trim() ? { q: q.value.trim() } : {} })

async function doLogout() {
  loggingOut.value = true
  await auth.logout()
  inbox.reset()
  router.replace({ name: 'login' })
}

onMounted(() => { load(); timer = setInterval(() => (clock.value = jamSekarang()), 30000) })
onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <div>
    <!-- Bilah ringkas: muncul saat salam di bawah sudah tergulir keluar layar -->
    <div class="mini hero" :class="{ on: scrolled }" :inert="!scrolled">
      <router-link :to="{ name: 'profil' }" class="mav" :aria-label="t('common.profile')"><UserAvatar /></router-link>
      <div class="mnm">{{ auth.user?.fullname }}</div>
      <button class="ib" :class="{ ring: grand }" :aria-label="t('home.notif')" @click="openNotif">
        <Icon name="bell" :size="20" />
        <b v-if="grand" class="dot">{{ grand > 99 ? '99+' : grand }}</b>
      </button>
      <button class="ib" :aria-label="t('common.logout')" @click="logoutOpen = true"><Icon name="logout" :size="20" /></button>
    </div>

    <header class="top hero">
      <span class="orb" style="width:150px;height:150px;right:-34px;top:-40px;background:#e50e30;opacity:.55" />
      <span class="orb" style="width:130px;height:130px;left:-46px;top:92px;background:#f08007;opacity:.45;animation-delay:-3s" />
      <div class="row">
        <div class="who">
          <div class="hi">{{ sapaan() }},</div>
          <div class="nm">{{ auth.user?.fullname }}</div>
          <div class="dt">{{ hariIni() }} · {{ clock }} WIB</div>
        </div>
        <button class="ib" :class="{ ring: grand }" :aria-label="t('home.notif')" @click="openNotif">
          <Icon name="bell" :size="20" />
          <b v-if="grand" :key="grand" class="dot">{{ grand > 99 ? '99+' : grand }}</b>
        </button>
        <button class="ib" :aria-label="t('common.logout')" @click="logoutOpen = true"><Icon name="logout" :size="20" /></button>
        <router-link :to="{ name: 'profil' }" class="av" :aria-label="t('common.profile')"><UserAvatar /><i /></router-link>
      </div>
      <form v-if="canPenawaran" class="search glass" @submit.prevent="search">
        <Icon name="search" :size="20" />
        <input v-model="q" type="search" :aria-label="t('home.searchAria')" :placeholder="t('home.search')" enterkeyhint="search" />
      </form>
    </header>

    <div class="body">
      <section v-if="canApprove" class="card stat rise" :aria-label="t('home.summaryAria')" style="--i: 0">
        <div>
          <div class="lbl">{{ t('home.pendingTitle') }}</div>
          <div class="num">{{ String(shownTotal).padStart(2, '0') }}<small>{{ t('home.items') }}</small></div>
          <div v-if="breakdown" class="brk">{{ breakdown }}</div>
          <router-link v-if="total" :to="{ name: 'approval' }" class="link">{{ t('home.review') }}</router-link>
          <span v-else class="ok-text">{{ t('home.allDone') }}</span>
        </div>
        <div class="stat-ico tinted tone-blue"><Icon name="doc" :size="30" /></div>
      </section>

      <!-- Menu utama -->
      <nav class="card qm rise" style="--i: 1" :aria-label="t('home.mainMenu')">
        <button v-for="m in shown" :key="m.key" class="qm-i" :style="{ '--c': m.c }" @click="goQuick(m)">
          <span class="qm-c">
            <Icon :name="m.icon" :size="22" />
            <b v-if="inbox.count(m.key)" :key="inbox.count(m.key)" class="qm-b" :aria-label="t('home.waitingAria', { n: inbox.count(m.key) })">{{ inbox.count(m.key) > 99 ? '99+' : inbox.count(m.key) }}</b>
          </span>
          <span class="qm-l">{{ t(m.label) }}</span>
        </button>
        <button class="qm-edit" @click="openArrange"><Icon name="edit" :size="14" /> {{ t('home.arrange') }}</button>
      </nav>

      <div v-if="arrange" class="sheet-back" @click.self="arrange = false">
        <div class="sheet" role="dialog" aria-modal="true" :aria-label="t('home.arrange')">
          <div class="grab" />
          <h2 class="sheet-t" style="margin-bottom:2px">{{ t('home.arrange') }}</h2>
          <p class="page-sub" style="margin:0 0 12px">{{ t('home.arrangeHint') }}</p>
          <ul class="arr">
            <li v-for="(m, i) in draftItems" :key="m.key" :class="{ off: draft.hidden.includes(m.key) }">
              <span class="arr-i" :style="{ '--c': m.c }"><Icon :name="m.icon" :size="18" /></span>
              <span class="arr-l">{{ t(m.label) }}</span>
              <button class="arr-b" :disabled="i === 0" :aria-label="t('home.moveUp')" @click="move(i, -1)"><Icon name="chevron" :size="16" style="transform:rotate(-90deg)" /></button>
              <button class="arr-b" :disabled="i === draftItems.length - 1" :aria-label="t('home.moveDown')" @click="move(i, 1)"><Icon name="chevron" :size="16" style="transform:rotate(90deg)" /></button>
              <label class="arr-t"><input type="checkbox" :checked="!draft.hidden.includes(m.key)" :aria-label="t('home.showMenu', { name: t(m.label) })" @change="toggleHide(m.key)" /><span /></label>
            </li>
          </ul>
          <div class="sheet-act" style="margin-top:12px">
            <button class="btn btn-ghost" @click="resetArrange">{{ t('inv.reset') }}</button>
            <button class="btn btn-primary" @click="saveArrange">{{ t('rsv.save') }}</button>
          </div>
        </div>
      </div>

      <TankChart v-if="auth.user?.modules?.includes('stok_terminal')" class="rise" style="--i: 2" />

      <!-- Ajakan daftar wajah (sekali; bisa ditutup) -->
      <section v-if="showFaceBanner" class="card fb rise" style="--i: 1">
        <span class="tinted tone-indigo fb-i"><Icon name="face" :size="20" /></span>
        <div class="fb-t"><b>{{ t('home.faceBannerTitle') }}</b><small>{{ t('home.faceBannerText') }}</small></div>
        <router-link class="btn btn-ghost fb-b" :to="{ name: 'profil', query: { daftar: 'wajah' } }">{{ t('home.enroll') }}</router-link>
        <button class="fb-x" :aria-label="t('home.closeBanner')" @click="dismissFace"><Icon name="x" :size="18" /></button>
      </section>

      <StateBox v-if="error && canPenawaran" icon="alert" :title="t('common.failedLoadData')" :text="error" :action="t('common.retry')" @action="load" />

      <template v-else-if="canPenawaran">
        <div class="section-head">
          <h2>{{ t('home.pendingSection') }}</h2>
          <router-link v-if="total" :to="{ name: 'approval' }" class="link">{{ t('home.requests', { n: total }) }}</router-link>
        </div>
        <div v-if="loading" class="stack"><div class="skel" style="height:140px" /></div>
        <div v-else-if="pending.length" class="stack">
          <PenawaranItem v-for="(p, i) in pending" :key="p.id_penawaran" :style="{ '--i': i }" :item="p" @open="open" />
        </div>
        <div v-else class="card empty">{{ t('home.nonePending') }}</div>

        <div class="section-head"><h2>{{ t('home.recent') }}</h2><router-link :to="{ name: 'riwayat' }" class="link">{{ t('home.seeHistory') }}</router-link></div>
        <div v-if="loading" class="stack"><div class="skel" style="height:110px" /></div>
        <div v-else-if="recent.length" class="card acts">
          <button v-for="(r, i) in recent" :key="r.id_penawaran + r.tgl_keputusan" class="act rise" :style="{ '--i': i }" @click="open(r)">
            <span class="ico-box" :class="r.keputusan == 1 ? '' : 'bad'"><Icon :name="r.keputusan == 1 ? 'check' : 'x'" :size="18" /></span>
            <span class="act-t"><b>{{ r.nomor_surat }}</b> <em>{{ r.keputusan == 1 ? t('home.approvedL') : t('home.rejectedL') }}</em><br /><small>{{ r.nama_customer }}</small></span>
            <small class="act-d">{{ tanggalJam(r.tgl_keputusan) }}</small>
          </button>
        </div>
        <div v-else class="card empty">{{ t('home.noActivity') }}</div>
      </template>
    </div>

    <!-- Panel notifikasi -->
    <div v-if="notifOpen" class="sheet-back" @click.self="notifOpen = false">
      <div class="sheet" role="dialog" aria-modal="true" :aria-label="t('home.notif')">
        <div class="grab" />
        <h2 class="sheet-t">{{ t('home.notif') }}</h2>
        <p class="muted" style="margin:2px 0 12px">{{ t('home.notifSub') }}</p>
        <div v-if="notifLoading" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:64px" /></div>
        <div v-else-if="notifs.length" class="nlist">
          <section v-for="g in notifs" :key="g.key" class="ngrp">
            <div class="ngh"><b>{{ t('home.m.' + g.key) }}</b><span class="ngc">{{ g.total }}</span><button class="link" @click="openGroup(g)">{{ t('home.seeAll') }}</button></div>
            <button v-for="(n, i) in g.items" :key="n.id" class="nrow rise" :style="{ '--i': i }" @click="openNotifItem(n)">
              <span class="tinted nic" :class="'tone-' + g.tone"><Icon :name="g.icon" :size="18" /></span>
              <span class="ntx">
                <b>{{ n.title }}</b>
                <small>{{ n.sub }}</small>
                <em>{{ t('home.notifItem', { when: lalu(n.when) }) }}</em>
              </span>
              <Icon name="chevron" :size="16" />
            </button>
          </section>
        </div>
        <div v-else class="nempty"><span class="tinted tone-green nic big"><Icon name="check" :size="26" /></span>{{ t('home.notifNone') }}</div>
      </div>
    </div>

    <!-- Konfirmasi keluar -->
    <div v-if="logoutOpen" class="sheet-back" @click.self="!loggingOut && (logoutOpen = false)">
      <div class="sheet" role="dialog" aria-modal="true" :aria-label="t('home.logoutTitle')">
        <div class="grab" />
        <h2 class="sheet-t">{{ t('home.logoutTitle') }}</h2>
        <p class="muted" style="margin:4px 0 16px">{{ t('home.logoutText') }}</p>
        <div class="sheet-act">
          <button class="btn btn-ghost" :disabled="loggingOut" @click="logoutOpen = false">{{ t('common.cancel') }}</button>
          <button class="btn btn-danger" :disabled="loggingOut" @click="doLogout">{{ loggingOut ? t('home.loggingOut') : t('home.logoutYes') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.qm { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 0; padding: 16px 0 12px; margin-top: 12px; border: 1.5px solid var(--orange, #f08007); box-shadow: 0 8px 22px -14px rgba(240, 128, 7, 0.55); }
.qm-i { appearance: none; border: 0; background: none; font: inherit; cursor: pointer; -webkit-tap-highlight-color: transparent; min-width: 0; display: grid; justify-items: center; align-content: start; gap: 6px; padding: 0; min-height: 44px; color: var(--ink-2); font-size: 12px; font-weight: 500; line-height: 1.25; text-align: center; letter-spacing: -0.025em; }
.qm-c {
  position: relative; width: 48px; height: 48px; border-radius: 50%; display: grid; place-items: center;
  color: color-mix(in srgb, var(--c) 78%, var(--ink)); /* terang: sedikit lebih gelap; gelap: sedikit lebih terang */
  background: radial-gradient(circle at 30% 22%, color-mix(in srgb, var(--c) 5%, var(--card)) 0%, color-mix(in srgb, var(--c) 14%, var(--card)) 55%, color-mix(in srgb, var(--c) 28%, var(--card)) 100%);
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--c) 20%, transparent), 0 8px 16px -6px color-mix(in srgb, var(--c) 55%, transparent);
  transition: transform 0.18s cubic-bezier(0.3, 1.5, 0.5, 1), box-shadow 0.18s ease;
}
.qm-i:active .qm-c { transform: scale(0.88); box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--c) 24%, transparent), 0 3px 8px -4px color-mix(in srgb, var(--c) 55%, transparent); }
.qm-i:active svg { animation: navpop 0.5s cubic-bezier(0.3, 1.4, 0.5, 1); }
.qm-b { position: absolute; top: -4px; right: -4px; min-width: 18px; height: 18px; padding: 0 4px; border-radius: 9px; background: var(--badge); color: #fff; font-size: 12px; font-weight: 700; display: grid; place-items: center; border: 2px solid var(--card); }
.mini {
  position: fixed; top: 0; left: 50%; z-index: 30; width: min(480px, 100%);
  display: flex; align-items: center; gap: 8px; padding: calc(var(--safe-top) + 8px) 16px 10px;
  border-radius: 0 0 20px 20px; box-shadow: 0 8px 22px rgba(5, 13, 36, 0.32);
  transform: translate(-50%, -110%); opacity: 0; pointer-events: none;
  transition: transform 0.28s cubic-bezier(0.2, 0.8, 0.2, 1), opacity 0.2s ease;
}
.mini.on { transform: translate(-50%, 0); opacity: 1; pointer-events: auto; }
.mav { width: 36px; height: 36px; border-radius: 12px; flex: none; background: #eaf2ff; color: #0b2a6b; font-weight: 700; font-size: 13px; display: grid; place-items: center; }
.mnm { flex: 1; min-width: 0; font-size: 16px; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.top::before { content: ''; position: absolute; inset: 0; pointer-events: none; background: url('../assets/hero-art.svg') right 0 top 0 / min(100%, 420px) auto no-repeat; opacity: 0.9; } /* kelopak merah + titik oranye ala logo */
.top { position: relative; overflow: hidden; padding: calc(var(--safe-top) + 16px) 16px 58px; border-radius: 0 0 26px 26px; }
.row { position: relative; display: flex; align-items: center; gap: 8px; }
.who { flex: 1; min-width: 0; }
.hi { color: #cfe0ff; font-size: 13px; }
.nm { font-size: 18px; font-weight: 700; letter-spacing: -0.01em; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dt { color: #b3cbef; font-size: 12px; margin-top: 1px; }
.ib { position: relative; width: 44px; height: 44px; flex: none; border-radius: 13px; display: grid; place-items: center; color: #fff; background: rgba(255, 255, 255, 0.12); border: 1px solid rgba(255, 255, 255, 0.18); transition: transform 0.15s; }
.ib:active { transform: scale(0.9); }
.ib .dot { position: absolute; top: -5px; right: -5px; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9px; background: var(--badge); color: #fff; font-size: 12px; font-weight: 700; display: grid; place-items: center; border: 2px solid var(--navy-900); animation: pop 0.45s cubic-bezier(0.3, 1.8, 0.5, 1) both; }
.av { position: relative; width: 44px; height: 44px; border-radius: 14px; background: #eaf2ff; color: #0b2a6b; font-weight: 700; font-size: 14px; display: grid; place-items: center; flex: none; }
.av i { position: absolute; right: -3px; bottom: -3px; width: 12px; height: 12px; border-radius: 50%; background: #20c07c; border: 2.5px solid var(--navy-900); }
.glass { position: relative; margin-top: 14px; background: rgba(255, 255, 255, 0.1); border-color: rgba(255, 255, 255, 0.18); color: #cfe0ff; }
.glass input { color: #fff; }
.glass input::placeholder { color: #aebfdc; }
.body { padding: 0 16px calc(var(--nav-h) + var(--safe-bottom) + 24px); margin-top: -36px; position: relative; }
/* Kartu ringkasan: putih bersih, dengan garis aksen merah-oranye di sisi kiri */
.stat { position: relative; overflow: hidden; display: flex; justify-content: space-between; align-items: center; gap: 12px; border: 1.5px solid var(--orange, #f08007); box-shadow: 0 8px 22px -14px rgba(240, 128, 7, 0.55); }
.stat::before { content: ''; position: absolute; left: 0; top: 16px; bottom: 16px; width: 4px; border-radius: 0 4px 4px 0; background: var(--brand-grad-v); }
.lbl { color: var(--muted); font-size: 13px; }
.num { font-size: 34px; font-weight: 700; letter-spacing: -0.03em; line-height: 1.1; margin: 2px 0 4px; font-variant-numeric: tabular-nums; }
.num small { font-size: 13px; color: var(--muted); font-weight: 600; letter-spacing: 0; }
.ok-text { color: var(--green); font-weight: 600; font-size: 13px; }
.stat-ico { width: 60px; height: 60px; border-radius: 18px; flex: none; }
.fb { display: flex; align-items: center; gap: 10px; margin-top: 12px; padding: 12px; }
.fb-i { width: 40px; height: 40px; border-radius: 13px; flex: none; }
.fb-t { flex: 1; min-width: 0; display: grid; gap: 1px; }
.fb-t b { font-size: 13.5px; font-weight: 700; }
.fb-t small { font-size: 12px; color: var(--muted); line-height: 1.3; }
.fb-b { min-height: 44px; padding: 0 14px; font-size: 13.5px; }
.fb-x { width: 44px; height: 44px; margin: -6px -6px -6px -2px; border: 0; background: none; color: var(--muted); display: grid; place-items: center; flex: none; }
.quick a { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 12px 4px 10px; display: grid; justify-items: center; gap: 8px; font-weight: 600; font-size: 12px; box-shadow: var(--shadow); }
.empty { color: var(--muted); text-align: center; font-size: 13.5px; }
.acts { padding: 4px 14px; }
.act { display: flex; align-items: center; gap: 10px; width: 100%; text-align: left; background: none; border: 0; border-bottom: 1px solid var(--line); padding: 10px 0; }
.act:last-child { border-bottom: 0; }
.act-t { flex: 1; min-width: 0; font-size: 13.5px; line-height: 1.4; }
.act-t em { font-style: normal; color: var(--muted); font-size: 12px; }
.act-t small, .act-d { color: var(--muted); font-size: 12px; }
.act-d { text-align: right; max-width: 96px; }
.ico-box.bad { background: var(--red-bg); color: var(--red); }
.sheet-t { font-size: 19px; font-weight: 700; letter-spacing: -0.02em; }
.sheet-act { display: grid; grid-template-columns: 1fr 1.5fr; gap: 10px; }
.muted { color: var(--muted); font-size: 13px; }
.nlist { display: grid; gap: 14px; }
.ngrp { display: grid; gap: 8px; }
.ngh { display: flex; align-items: center; gap: 8px; font-size: 13px; }
.ngh b { font-size: 13.5px; }
.ngc { min-width: 22px; height: 22px; padding: 0 6px; border-radius: 11px; background: var(--badge); color: #fff; font-size: 12px; font-weight: 700; display: inline-grid; place-items: center; }
.ngh .link { margin-left: auto; font-size: 12.5px; }
.nrow { display: flex; align-items: center; gap: 12px; width: 100%; text-align: left; padding: 12px; border: 1px solid var(--line); background: var(--surface); border-radius: 16px; color: var(--muted); }
.nic { width: 38px; height: 38px; border-radius: 12px; flex: none; }
.nic.big { width: 56px; height: 56px; border-radius: 18px; margin: 0 auto 10px; }
.ntx { flex: 1; min-width: 0; display: grid; gap: 1px; }
.ntx b { color: var(--ink); font-size: 13.5px; font-weight: 600; overflow-wrap: anywhere; }
.ntx small { color: var(--muted); font-size: 12px; }
.ntx em { font-style: normal; color: var(--amber); font-size: 12px; font-weight: 600; }
.nempty { text-align: center; color: var(--muted); padding: 22px 0 8px; font-size: 14px; display: grid; justify-items: center; }
.qm-edit { grid-column: 1 / -1; justify-self: center; margin-top: 10px; display: inline-flex; align-items: center; gap: 6px; background: none; border: 0; color: var(--muted); font: inherit; font-size: 12px; font-weight: 600; padding: 6px 10px; }
.arr { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
.arr li { display: flex; align-items: center; gap: 8px; padding: 6px 8px; border-radius: 12px; background: var(--blue-50); }
.arr li.off { opacity: 0.55; }
.arr-i { flex: none; width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center; color: var(--c); background: color-mix(in srgb, var(--c) 12%, var(--card)); }
.arr-l { flex: 1; min-width: 0; font-weight: 600; font-size: 14px; }
.arr-b { flex: none; width: 36px; height: 36px; border-radius: 10px; border: 1px solid var(--line); background: var(--card); color: var(--ink-2); display: grid; place-items: center; }
.arr-b:disabled { opacity: 0.35; }
.arr-t { position: relative; flex: none; width: 44px; height: 26px; }
.arr-t input { position: absolute; inset: 0; opacity: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; }
.arr-t span { position: absolute; inset: 0; border-radius: 13px; background: #c5cedd; transition: background 0.2s; pointer-events: none; }
.arr-t span::after { content: ''; position: absolute; top: 3px; left: 3px; width: 20px; height: 20px; border-radius: 50%; background: #fff; transition: transform 0.2s; }
.arr-t input:checked + span { background: var(--accent); }
.arr-t input:checked + span::after { transform: translateX(18px); }
.brk { margin: -2px 0 6px; font-size: 12px; font-weight: 600; color: var(--muted); }
</style>
