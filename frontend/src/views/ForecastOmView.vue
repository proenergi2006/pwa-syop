<script setup>
import { t, locale, numLocale } from '../i18n'
import { useScrolled } from '../composables/useScrolled'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import UserAvatar from '../components/UserAvatar.vue'
import StateBox from '../components/StateBox.vue'
import DayBars from '../components/charts/DayBars.vue'
import RankBars from '../components/charts/RankBars.vue'
import { useInbox } from '../stores/inbox'
import * as syop from '../api/syop'
import { errorCode, errorMessage } from '../api/client'
import { showToast } from '../composables/useToast'
import { angka, tanggal, tanggalJam } from '../utils/format'
import { bulanPanjang } from '../utils/chart'

const scrolled = useScrolled()
const route = useRoute()
const router = useRouter()
const inbox = useInbox()

const now = new Date()
const bulan = ref(Number(route.query.bulan) || now.getMonth() + 1)
const tahun = ref(Number(route.query.tahun) || now.getFullYear())
const periode = ref(Number(route.query.periode) === 2 ? 2 : route.query.periode ? 1 : now.getDate() >= 15 ? 2 : 1)
const cabang = ref(Number(route.query.cabang) || 0)
const tab = ref(['fill', 'send', 'request', 'info'].includes(route.query.tab) ? route.query.tab : route.query.tab === 'request' ? 'request' : 'fill')
const cabangs = ref([])
const data = ref(null)
const loading = ref(true)
const error = ref('')
const q = ref('')
let seq = 0

const km = (n) => Number(n || 0).toLocaleString(numLocale.value, { minimumFractionDigits: 0, maximumFractionDigits: 2 })
const idx = computed(() => tahun.value * 12 + bulan.value - 1)
function geser(n) { const i = idx.value + n; tahun.value = Math.floor(i / 12); bulan.value = (i % 12) + 1 }

async function load(silent = false) {
  const my = ++seq
  if (!silent) loading.value = true
  error.value = ''
  try {
    const r = await syop.forecastOmData({ cabang: cabang.value, bulan: bulan.value, tahun: tahun.value, periode: periode.value })
    if (my !== seq) return
    data.value = r
  } catch (e) { if (my === seq) error.value = errorMessage(e) } finally { if (my === seq) loading.value = false }
}

const review = computed(() => data.value?.review[periode.value] || {})
const st = computed(() => review.value.status || '')
const stCls = (s) => ({ WAITING: 'pill-wait', APPROVED: 'pill-ok', REJECTED: 'pill-no' })[s] || 'pill-draft'
const stTxt = (s) => t('fo.st.' + (s || 'none'))
const locked = computed(() => !!data.value?.terkunci)
const range = computed(() => (data.value ? t('fc.range', { a: data.value.hari_awal, b: data.value.hari_akhir }) : ''))
const nHari = computed(() => data.value?.kalender.length || 0)
const avg = computed(() => (nHari.value ? data.value.total_periode / nHari.value : 0))
const weekend = computed(() => (data.value?.kalender || []).map((k) => k.akhir_pekan || !!k.libur))
const shown = computed(() => {
  const k = q.value.trim().toLowerCase()
  return (data.value?.customers || []).filter((c) => !k || c.nama.toLowerCase().includes(k))
})
const filled = (c) => c.hari.filter((v) => v > 0).length
const barMax = (c) => Math.max(1, ...c.hari.map((v) => v || 0))

// ---- hitung ulang total di sisi layar setelah isian berubah ----
function recalc() {
  const d = data.value
  d.total_hari = d.kalender.map((_, i) => d.customers.reduce((s, c) => s + (c.hari[i] || 0), 0))
  d.customers.forEach((c) => { c.total = c.hari.reduce((s, v) => s + (v || 0), 0) })
  d.total_periode = d.total_hari.reduce((s, v) => s + v, 0)
  d.review[periode.value].total = d.total_periode
  d.total_bulan = d.review[1].total + d.review[2].total
  const row = d.cabangan.find((b) => b.id === d.cabang.id)
  if (row) { row.per[periode.value].total = d.total_periode; row.total = d.total_bulan }
}

// ---- lembar isian harian ----
const ed = ref(null) // { c: customer, vals: string[], cell: [{state}] }
const fillQty = ref('')
const wd = (k) => new Date(tahun.value, bulan.value - 1, k.d).toLocaleDateString(locale.value, { weekday: 'short' }).slice(0, 3)
function openEdit(c) {
  ed.value = { c, vals: c.hari.map((v) => (v > 0 ? String(v) : '')), orig: c.hari.map((v) => (v > 0 ? String(v) : '')), cell: c.hari.map(() => ''), err: '' }
  fillQty.value = ''
}
function closeEdit() { ed.value = null; load(true) }
const edTotal = computed(() => (ed.value ? ed.value.vals.reduce((s, v) => s + (parseFloat(String(v).replace(',', '.')) || 0), 0) : 0))

async function simpan(items, cells) {
  const e = ed.value
  e.err = ''
  cells.forEach((i) => (e.cell[i] = 'saving'))
  try {
    await syop.forecastOmIsi({ id_customer: e.c.id, items })
    items.forEach((it, k) => {
      const i = cells[k]
      const v = it.qty === null ? null : Number(it.qty)
      e.c.hari[i] = v > 0 ? v : null
      e.orig[i] = e.vals[i]
      e.cell[i] = 'ok'
    })
    recalc()
    setTimeout(() => cells.forEach((i) => { if (e.cell[i] === 'ok') e.cell[i] = '' }), 1400)
    return true
  } catch (er) {
    cells.forEach((i) => { e.cell[i] = 'err'; e.vals[i] = e.orig[i] })
    e.err = errorMessage(er)
    return false
  }
}
async function commit(i) {
  const e = ed.value
  if (!e || locked.value) return
  const raw = String(e.vals[i]).trim().replace(',', '.')
  if (raw === String(e.orig[i])) return
  if (raw !== '' && (isNaN(raw) || parseFloat(raw) < 0)) { e.vals[i] = e.orig[i]; e.err = t('fo.invalid'); return }
  await simpan([{ tanggal: data.value.kalender[i].tanggal, qty: raw === '' ? null : parseFloat(raw) }], [i])
}
async function quick(mode) {
  const e = ed.value
  const v = mode === 'clear' ? null : parseFloat(String(fillQty.value).replace(',', '.'))
  if (mode !== 'clear' && !(v >= 0) ) { e.err = t('fo.invalid'); return }
  const cells = []
  data.value.kalender.forEach((k, i) => { if (mode === 'clear' || mode === 'all' || (mode === 'work' && !k.akhir_pekan && !k.libur)) cells.push(i) })
  if (!cells.length) return
  const old = [...e.vals]
  cells.forEach((i) => (e.vals[i] = v === null ? '' : String(v)))
  const ok = await simpan(cells.map((i) => ({ tanggal: data.value.kalender[i].tanggal, qty: v })), cells)
  if (!ok) cells.forEach((i) => (e.vals[i] = old[i]))
}
function moveNext(i) { nextTick(() => document.querySelector(`[data-cell="${i + 1}"]`)?.focus()) }

// ---- tambah customer ----
const add = ref(false)
const addQ = ref('')
const addRes = ref([])
const addLoading = ref(false)
let addTimer
async function cari() {
  addLoading.value = true
  try { addRes.value = (await syop.forecastOmCustomer({ cabang: cabang.value, q: addQ.value.trim() || undefined })).data } catch { addRes.value = [] } finally { addLoading.value = false }
}
watch(addQ, () => { clearTimeout(addTimer); addTimer = setTimeout(cari, 300) })
function openAdd() { add.value = true; addQ.value = ''; cari() }
const sudah = (id) => data.value.customers.some((c) => c.id === id)
function pickAdd(c) {
  let row = data.value.customers.find((x) => x.id === c.id)
  if (!row) { row = { id: c.id, nama: c.nama, hari: data.value.kalender.map(() => null), total: 0 }; data.value.customers.unshift(row) }
  add.value = false
  openEdit(row)
}

// ---- kirim ke CEO ----
const sel = ref([])
const catatan = ref('')
const confirmSend = ref(false)
const busy = ref(false)
const formError = ref('')
const perStatus = (b) => b.per[periode.value].status
const bisa = (b) => !['WAITING', 'APPROVED'].includes(perStatus(b))
const selTotal = computed(() => (data.value?.cabangan || []).filter((b) => sel.value.includes(b.id)).reduce((s, b) => s + b.per[periode.value].total, 0))
const selectable = computed(() => (data.value?.cabangan || []).filter(bisa).map((b) => b.id))
const allSel = computed(() => selectable.value.length > 0 && selectable.value.every((i) => sel.value.includes(i)))
function toggleSel(id) { sel.value = sel.value.includes(id) ? sel.value.filter((x) => x !== id) : [...sel.value, id] }
function ensureSel() { sel.value = sel.value.filter((i) => selectable.value.includes(i)) }
function openSend() { if (!sel.value.length) return showToast(t('fo.send.empty'), 'err'); confirmSend.value = true; formError.value = '' }
async function kirim() {
  formError.value = ''
  busy.value = true
  try {
    const r = await syop.forecastOmAjukan({ cabang: sel.value, bulan: bulan.value, tahun: tahun.value, periode: periode.value, catatan: catatan.value.trim() || undefined })
    confirmSend.value = false
    sel.value = []
    catatan.value = ''
    showToast(r.message, 'ok')
    inbox.refresh()
    await load(true)
  } catch (e) {
    formError.value = errorMessage(e)
  } finally { busy.value = false }
}

// ---- request qty dari BM ----
const reqs = ref(null)
const reqTab = ref('wait')
const reqLoading = ref(false)
const act = ref(null)
const rCat = ref('')
async function loadReq() {
  reqLoading.value = true
  try { reqs.value = await syop.forecastOmRequests({ tab: reqTab.value, per_page: 30 }) } catch (e) { showToast(errorMessage(e), 'err') } finally { reqLoading.value = false }
}
const rpill = (s) => ({ WAITING_OM: 'pill-wait', WAITING_CEO: 'pill-wait', APPROVED: 'pill-ok', REJECTED_OM: 'pill-no', REJECTED: 'pill-no', REJECTED_CEO: 'pill-no' })[s] || 'pill-draft'
function openReq(type, r) { act.value = { type, r }; rCat.value = ''; formError.value = '' }
async function submitReq() {
  const a = act.value
  formError.value = ''
  if (a.type === 'reject' && !rCat.value.trim()) return (formError.value = t('det.errReason'))
  busy.value = true
  try {
    const r = await syop.forecastOmDecide(a.r.id, { keputusan: a.type, catatan: rCat.value.trim() || undefined })
    act.value = null
    showToast(r.message, 'ok')
    inbox.refresh()
    await loadReq()
  } catch (e) {
    formError.value = errorMessage(e)
  } finally { busy.value = false }
}

const rank = computed(() => (data.value?.cabangan || []).filter((b) => b.total > 0).map((b) => ({ id: b.id, nama: b.nama, value: b.total })).sort((a, b) => b.value - a.value))
const reqWaiting = computed(() => reqs.value?.waiting ?? inbox.forecast_om)

let first = true
watch([cabang, bulan, tahun, periode], () => {
  if (first) return
  sel.value = []
  router.replace({ query: { ...route.query, cabang: cabang.value, bulan: bulan.value, tahun: tahun.value, periode: periode.value } })
  load()
})
watch(data, () => { if (!sel.value.length && data.value && first) { const me = data.value.cabangan.find((b) => b.id === data.value.cabang.id); if (me && bisa(me) && me.per[periode.value].total > 0) sel.value = [me.id] } ensureSel() })
watch(tab, (v) => { router.replace({ query: { ...route.query, tab: v === 'fill' ? undefined : v } }); if (v === 'request') loadReq() })
watch(reqTab, loadReq)
onMounted(async () => {
  try {
    const c = await syop.forecastOmCabang()
    cabangs.value = c.data
    if (!cabang.value) cabang.value = c.default
  } catch (e) { error.value = errorMessage(e); loading.value = false; return }
  await load()
  first = false
  if (tab.value === 'request') loadReq()
  else syop.forecastOmRequests({ tab: 'wait', per_page: 1 }).then((r) => { reqs.value = r }).catch(() => {})
})
</script>

<template>
  <div class="page">
    <div class="page-head" :class="{ stuck: scrolled }">
      <div>
        <h1 class="page-title">{{ t('fo.title') }}</h1>
        <p class="page-sub">{{ t('fo.sub') }}</p>
      </div>
      <router-link :to="{ name: 'profil' }" class="avatar" :aria-label="t('common.profile')"><UserAvatar /><i class="dot" /></router-link>
    </div>

    <!-- filter -->
    <div class="filt">
      <select v-model.number="cabang" class="sel" :aria-label="t('fo.branch')"><option v-for="c in cabangs" :key="c.id" :value="c.id">{{ c.nama }}</option></select>
      <div class="mo" role="group">
        <button :aria-label="t('fc.prevMonth')" @click="geser(-1)"><Icon name="chevron" :size="18" style="transform:rotate(180deg)" /></button>
        <b>{{ bulanPanjang(bulan - 1) }} {{ tahun }}</b>
        <button :aria-label="t('fc.nextMonth')" @click="geser(1)"><Icon name="chevron" :size="18" /></button>
      </div>
    </div>
    <div class="seg" role="tablist">
      <button v-for="p in [1, 2]" :key="p" class="sg" :class="{ on: periode === p }" role="tab" :aria-selected="periode === p" @click="periode = p">
        {{ t('fc.period', { p }) }}<small>{{ p === 1 ? t('fc.range', { a: 1, b: 14 }) : t('fc.range', { a: 15, b: data?.jumlah_hari || 30 }) }}</small>
        <i v-if="data" class="dot" :class="'d-' + (data.review[p].status || 'none')" />
      </button>
    </div>

    <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load()" />
    <div v-else-if="loading && !data" class="stack"><div class="skel" style="height:180px" /><div class="skel" style="height:92px" /><div class="skel" style="height:92px" /></div>

    <template v-else-if="data">
      <!-- ringkas periode -->
      <section class="hero" :class="'h-' + (st || 'none')">
        <span class="orb" />
        <div class="h-top"><span>{{ data.cabang.nama }} · {{ t('fc.period', { p: periode }) }}</span><span class="pill" :class="stCls(st)">{{ stTxt(st) }}</span></div>
        <div class="h-v">{{ km(data.total_periode) }}<small>{{ t('fc.kl') }}</small></div>
        <div class="h-row">
          <div><small>{{ t('fo.custShort') }}</small><b>{{ data.customers.length }}</b></div>
          <div><small>{{ t('fo.avg') }}</small><b>{{ km(avg) }}</b></div>
          <div><small>{{ t('fo.month') }}</small><b>{{ km(data.total_bulan) }}</b></div>
        </div>
        <div v-if="st === 'REJECTED'" class="h-note bad"><b>{{ t('fo.ceoNote') }}</b><span>{{ review.catatan_ceo || '-' }}</span><small v-if="review.ceo_date">{{ tanggalJam(review.ceo_date) }} · {{ t('fo.rejectedHint') }}</small></div>
        <div v-else-if="locked" class="h-note"><Icon name="lock" :size="16" /><span>{{ st === 'APPROVED' ? t('fo.lock.approved') : t('fo.lock.waiting') }}</span></div>
        <button v-if="!locked && data.total_periode > 0" class="h-cta" @click="tab = 'send'; sel = selectable.includes(data.cabang.id) ? [data.cabang.id] : sel"><Icon name="check" :size="18" /> {{ t('fo.sendNow') }}</button>
      </section>

      <div class="chips scrollx" role="tablist">
        <button class="chip" :class="{ on: tab === 'fill' }" role="tab" @click="tab = 'fill'">{{ t('fo.tab.fill') }}</button>
        <button class="chip" :class="{ on: tab === 'send' }" role="tab" @click="tab = 'send'">{{ t('fo.tab.send') }}</button>
        <button class="chip" :class="{ on: tab === 'request' }" role="tab" @click="tab = 'request'">{{ t('fo.tab.req') }}<b v-if="reqWaiting" class="cnt">{{ reqWaiting }}</b></button>
        <button class="chip" :class="{ on: tab === 'info' }" role="tab" @click="tab = 'info'">{{ t('fo.tab.info') }}</button>
      </div>

      <!-- ISI -->
      <template v-if="tab === 'fill'">
        <div class="tools">
          <label class="search"><Icon name="search" :size="20" /><input v-model="q" type="search" :aria-label="t('fo.search')" :placeholder="t('fo.search')" /></label>
          <button v-if="!locked" class="addbtn" @click="openAdd"><Icon name="plus" :size="20" /><span class="lbl-long">{{ t('fo.add') }}</span><span class="lbl-short">{{ t('fo.addShort') }}</span></button>
        </div>
        <StateBox v-if="!data.customers.length" icon="chart" :title="t('fo.none')" :text="t('fo.noneText')" />
        <StateBox v-else-if="!shown.length" icon="search" :title="t('fo.noMatch')" text="" />
        <div v-else class="stack">
          <button v-for="(c, i) in shown" :key="c.id" class="cust rise" :style="{ '--i': i % 8 }" @click="openEdit(c)">
            <div class="c-top"><span class="c-n">{{ c.nama }}</span><b class="c-t">{{ c.total > 0 ? km(c.total) : '–' }}<small v-if="c.total > 0"> {{ t('fc.kl') }}</small></b></div>
            <div class="spark" aria-hidden="true">
              <i v-for="(v, k) in c.hari" :key="k" :class="{ we: weekend[k], on: v > 0 }" :style="{ height: v > 0 ? Math.max(14, (v / barMax(c)) * 100) + '%' : '' }" />
            </div>
            <div class="c-foot"><span>{{ filled(c) ? t('fo.filled', { n: filled(c) }) : t('fo.empty') }}</span><span class="c-go">{{ locked ? t('fo.readOnly') : t('fo.tapFill') }} <Icon name="chevron" :size="14" /></span></div>
          </button>
        </div>
        <section v-if="data.customers.length" class="card box" style="margin-top:14px">
          <h2>{{ t('fo.daily') }} <small>({{ t('fc.kl') }})</small></h2>
          <DayBars :values="data.total_hari" :weekend="weekend" color="#0f8b8d" :label="t('fo.daily')" />
        </section>
      </template>

      <!-- KIRIM -->
      <template v-else-if="tab === 'send'">
        <p class="hintp">{{ t('fo.send.hint', { p: periode }) }}</p>
        <label class="ck all"><input type="checkbox" :checked="allSel" :disabled="!selectable.length" @change="sel = allSel ? [] : [...selectable]" /> {{ t('fo.send.all') }}</label>
        <div class="stack" style="margin-top:8px">
          <label v-for="b in data.cabangan" :key="b.id" class="br" :class="{ off: !bisa(b), on: sel.includes(b.id) }">
            <input type="checkbox" :checked="sel.includes(b.id)" :disabled="!bisa(b)" @change="toggleSel(b.id)" />
            <span class="br-m"><b>{{ b.nama }}</b>
              <small v-if="!bisa(b)">{{ t('fo.send.locked') }}</small>
              <small v-else-if="perStatus(b) === 'REJECTED'" class="bad">{{ t('fo.rej.by') }}<template v-if="b.per[periode].ceo_date"> · {{ tanggalJam(b.per[periode].ceo_date) }}</template>: {{ b.per[periode].catatan_ceo || '-' }}</small>
            </span>
            <span class="br-r"><b>{{ km(b.per[periode].total) }}</b><span class="pill" :class="stCls(perStatus(b))">{{ stTxt(perStatus(b)) }}</span></span>
          </label>
        </div>
        <div class="field" style="margin-top:14px">
          <label for="cn">{{ t('fo.send.notes') }}</label>
          <textarea id="cn" v-model="catatan" class="plain" maxlength="2000" :placeholder="t('fo.send.notesPh')" />
        </div>
        <div class="sendbar"><div class="sb-i"><small>{{ t('fo.send.selected', { n: sel.length, t: km(selTotal) }) }}</small></div><button class="btn btn-primary" :disabled="!sel.length" @click="openSend"><Icon name="check" :size="18" /> {{ t('fo.send.btn') }}</button></div>
      </template>

      <!-- REQUEST -->
      <template v-else-if="tab === 'request'">
        <div class="chips">
          <button class="chip" :class="{ on: reqTab === 'wait' }" @click="reqTab = 'wait'">{{ t('fo.req.wait') }}<b v-if="reqs?.waiting" class="cnt">{{ reqs.waiting }}</b></button>
          <button class="chip" :class="{ on: reqTab === 'all' }" @click="reqTab = 'all'">{{ t('fo.req.all') }}</button>
        </div>
        <div v-if="reqLoading && !reqs" class="stack"><div class="skel" style="height:150px" /></div>
        <StateBox v-else-if="!reqs?.data.length" icon="check" :title="t('fo.req.none')" :text="t('fo.req.noneText')" />
        <div v-else class="stack">
          <article v-for="(r, i) in reqs.data" :key="r.id" class="card rq rise" :class="{ hot: r.can_decide }" :style="{ '--i': i % 8 }">
            <div class="item-top"><div class="item-who"><span class="ico-box tinted tone-teal"><Icon name="chart" :size="18" /></span><span>{{ r.nama_cabang }}</span></div><span class="pill" :class="rpill(r.status)">{{ t('fo.req.st.' + r.status) === 'fo.req.st.' + r.status ? r.status : t('fo.req.st.' + r.status) }}</span></div>
            <div class="item-title">{{ r.nama_customer }}</div>
            <div class="rq-m"><span><Icon name="calendar" :size="14" /> {{ tanggal(r.tanggal) }}</span><b>+{{ angka(r.qty) }}</b></div>
            <p class="nt"><b>{{ t('fo.req.reason') }}:</b> {{ r.alasan }}</p>
            <p v-if="r.om_catatan" class="nt bad"><b>{{ t('fo.req.omNote') }}:</b> {{ r.om_catatan }}</p>
            <p v-if="r.ceo_catatan" class="nt bad"><b>{{ t('fo.req.ceoNote') }}:</b> {{ r.ceo_catatan }}</p>
            <div v-if="r.can_decide" class="acts">
              <button class="mini bad" @click="openReq('reject', r)">{{ t('fc.reject') }}</button>
              <button class="mini ok solid" @click="openReq('approve', r)">{{ t('fo.req.approve') }}</button>
            </div>
          </article>
        </div>
      </template>

      <!-- INFO -->
      <template v-else>
        <section class="card box">
          <h2>{{ t('fo.info.alloc') }}</h2>
          <div class="kv"><span>{{ t('fc.alloc.p1') }}</span><span>{{ km(data.alokasi.p1) }} {{ t('fc.kl') }}</span></div>
          <div class="kv"><span>{{ t('fc.alloc.p2') }}</span><span>{{ km(data.alokasi.p2) }} {{ t('fc.kl') }}</span></div>
          <div class="kv tot"><span>{{ t('fc.alloc.total') }}</span><span>{{ km(data.alokasi.total) }} {{ t('fc.kl') }}</span></div>
          <p v-if="data.alokasi.melebihi" class="warnbox"><Icon name="alert" :size="16" /> {{ t('fc.allocWarn') }}</p>
        </section>
        <section v-if="rank.length" class="card box" style="margin-top:12px">
          <h2>{{ t('fo.info.branches') }} <small>({{ t('fc.kl') }})</small></h2>
          <RankBars :items="rank" color="#0a66e8" :sel="data.cabang.id" :limit="6" @pick="(i) => (cabang = i)" />
        </section>
        <section class="card box" style="margin-top:12px">
          <h2>{{ t('fo.info.credit') }}</h2>
          <p v-if="!data.kredit.length" class="tip">{{ t('fo.info.creditNone') }}</p>
          <div v-for="k in data.kredit" :key="k.nama" class="cr">
            <div class="cr-h"><b>{{ k.nama }}</b><span class="tg" :class="k.risiko ? 'bad' : 'ok'">{{ k.risiko ? t('fo.info.risk') : t('fo.info.safe') }}</span></div>
            <div class="cr-g"><div><small>{{ t('fo.info.limit') }}</small><b>{{ angka(k.sisa) }}</b></div><div><small>{{ t('fo.info.ar') }}</small><b>{{ angka(k.ar_total) }}</b></div><div :class="{ bad: k.risiko }"><small>{{ t('fo.info.ar90') }}</small><b>{{ angka(k.ar_over_90) }}</b></div></div>
          </div>
          <div v-if="data.kredit.length" class="cr tot"><div class="cr-g"><div><small>{{ t('fo.info.limit') }}</small><b>{{ angka(data.kredit_sisa) }}</b></div><div><small>{{ t('fo.info.ar') }}</small><b>{{ angka(data.kredit_ar) }}</b></div></div></div>
        </section>
      </template>
    </template>

    <!-- lembar isian harian -->
    <div v-if="ed" class="sheet-back full" @click.self="closeEdit">
      <div class="sheet tall" role="dialog" aria-modal="true">
        <div class="grab" />
        <div class="e-h">
          <div class="e-t"><b>{{ ed.c.nama }}</b><small>{{ t('fc.period', { p: periode }) }} · {{ range }} · {{ bulanPanjang(bulan - 1) }}</small></div>
          <button class="x" :aria-label="t('common.close')" @click="closeEdit"><Icon name="x" :size="20" /></button>
        </div>
        <div class="e-sum"><span>{{ t('fo.dayTitle') }}</span><b>{{ km(edTotal) }} {{ t('fc.kl') }}</b></div>
        <p v-if="locked" class="lockn"><Icon name="lock" :size="15" /> {{ st === 'APPROVED' ? t('fo.lock.approved') : t('fo.lock.waiting') }}</p>
        <template v-else>
          <div class="qf">
            <div class="qf-r">
              <label class="qf-i"><span>{{ t('fo.fillQty') }}</span><input v-model="fillQty" inputmode="decimal" placeholder="0" /></label>
              <button class="mini" @click="quick('all')">{{ t('fo.fillAll') }}</button>
              <button class="mini" @click="quick('work')">{{ t('fo.fillWork') }}</button>
              <button class="mini bad" @click="quick('clear')">{{ t('fo.fillClear') }}</button>
            </div>
          </div>
        </template>
        <p v-if="ed.err" class="err-text" role="alert">{{ ed.err }}</p>
        <div class="dgrid">
          <label v-for="(k, i) in data.kalender" :key="k.d" class="dc" :class="{ we: k.akhir_pekan || k.libur, sv: ed.cell[i] === 'saving', ok: ed.cell[i] === 'ok', er: ed.cell[i] === 'err' }" :title="k.libur || ''">
            <span class="dc-h"><b>{{ k.d }}</b><small>{{ k.libur ? t('fo.holiday') : wd(k) }}</small></span>
            <input v-model="ed.vals[i]" :data-cell="i" inputmode="decimal" :readonly="locked" placeholder="–" @blur="commit(i)" @keydown.enter.prevent="$event.target.blur(); moveNext(i)" />
            <i v-if="ed.cell[i] === 'ok'" class="tick"><Icon name="check" :size="12" /></i>
          </label>
        </div>
        <p v-if="!locked" class="tip">{{ t('fo.autosave') }}</p>
        <button class="btn btn-primary btn-block" style="margin-top:12px" @click="closeEdit">{{ t('common.close') }}</button>
      </div>
    </div>

    <!-- tambah customer -->
    <div v-if="add" class="sheet-back" @click.self="add = false">
      <div class="sheet" role="dialog" aria-modal="true">
        <div class="grab" />
        <h2 class="sheet-t">{{ t('fo.addTitle') }}</h2>
        <label class="search" style="margin:10px 0"><Icon name="search" :size="20" /><input v-model="addQ" type="search" :placeholder="t('fo.addSearch')" /></label>
        <div class="results">
          <p v-if="addLoading && !addRes.length" class="tip">{{ t('common.loading') }}</p>
          <p v-else-if="!addRes.length" class="tip">{{ t('fo.addNone') }}</p>
          <button v-for="c in addRes" :key="c.id" class="res" @click="pickAdd(c)"><span>{{ c.nama }}</span><small v-if="sudah(c.id)">{{ t('fo.shown') }}</small></button>
        </div>
      </div>
    </div>

    <!-- konfirmasi kirim -->
    <div v-if="confirmSend" class="sheet-back" @click.self="!busy && (confirmSend = false)">
      <div class="sheet" role="dialog" aria-modal="true">
        <div class="grab" />
        <h2 class="sheet-t">{{ t('fo.send.q', { n: sel.length, p: periode }) }}</h2>
        <p class="muted" style="margin:4px 0 6px">{{ bulanPanjang(bulan - 1) }} {{ tahun }} · {{ km(selTotal) }} {{ t('fc.kl') }}</p>
        <p class="hint">{{ t('fo.send.qHint') }}</p>
        <p v-if="formError" class="err-text" role="alert" style="margin-bottom:12px">{{ formError }}</p>
        <div class="sheet-act">
          <button class="btn btn-ghost" :disabled="busy" @click="confirmSend = false">{{ t('common.cancel') }}</button>
          <button class="btn btn-primary" :disabled="busy" @click="kirim">{{ busy ? t('det.processing') : t('fo.send.btn') }}</button>
        </div>
      </div>
    </div>

    <!-- keputusan request qty -->
    <div v-if="act" class="sheet-back" @click.self="!busy && (act = null)">
      <div class="sheet" role="dialog" aria-modal="true">
        <div class="grab" />
        <h2 class="sheet-t">{{ act.type === 'approve' ? t('fo.req.q.approve') : t('fo.req.q.reject') }}</h2>
        <p class="muted" style="margin:4px 0 6px">{{ act.r.nama_customer }} · {{ tanggal(act.r.tanggal) }} · +{{ angka(act.r.qty) }}</p>
        <p class="hint">{{ act.type === 'approve' ? t('fo.req.hint.approve') : t('fo.req.hint.reject') }}</p>
        <div class="field">
          <label for="rc">{{ act.type === 'reject' ? t('fc.reasonReq') : t('det.noteOpt') }}</label>
          <textarea id="rc" v-model="rCat" class="plain" maxlength="2000" :placeholder="act.type === 'reject' ? t('fc.reasonPh') : t('fc.notePh')" />
        </div>
        <p v-if="formError" class="err-text" role="alert" style="margin-bottom:12px">{{ formError }}</p>
        <div class="sheet-act">
          <button class="btn btn-ghost" :disabled="busy" @click="act = null">{{ t('common.cancel') }}</button>
          <button class="btn" :class="act.type === 'approve' ? 'btn-primary' : 'btn-danger'" :disabled="busy" @click="submitReq">{{ busy ? t('det.processing') : act.type === 'approve' ? t('fo.req.approve') : t('det.yesReject') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.filt { display: grid; gap: 10px; margin-bottom: 10px; }
.sel { width: 100%; min-width: 0; height: 46px; padding: 0 12px; border-radius: 14px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; font-weight: 700; font-family: inherit; }
.mo { display: grid; grid-template-columns: 44px 1fr 44px; align-items: center; border: 1px solid var(--line); background: var(--card); border-radius: 14px; overflow: hidden; }
.mo b { text-align: center; font-size: 15px; font-weight: 800; }
.mo button { height: 44px; border: 0; background: none; color: var(--ink); display: grid; place-items: center; }
.seg { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 14px; }
.sg { position: relative; display: grid; gap: 1px; justify-items: start; padding: 10px 14px; border-radius: 14px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 14px; font-weight: 800; font-family: inherit; text-align: left; }
.sg small { font-size: 11.5px; font-weight: 500; color: var(--muted); }
.sg.on { background: var(--blue-50); border-color: color-mix(in srgb, var(--accent) 45%, var(--line)); color: var(--accent); }
.sg .dot { position: absolute; top: 12px; right: 12px; width: 10px; height: 10px; border-radius: 50%; background: var(--line); }
  .pill-draft { background: var(--line); color: var(--muted); }
.dot.d-WAITING { background: #e6a100; } .dot.d-APPROVED { background: var(--green); } .dot.d-REJECTED { background: var(--red); }
.hero { position: relative; overflow: hidden; border-radius: 22px; padding: 16px 18px; color: #fff; background: linear-gradient(150deg, #06403f, #0b6b69 60%, #0f8b8d); box-shadow: 0 14px 34px rgba(15, 139, 141, 0.28); margin-bottom: 14px; }
.hero.h-REJECTED { background: linear-gradient(150deg, #5a1119, #8e1c28 60%, #c0303d); box-shadow: 0 14px 34px rgba(192, 48, 61, 0.28); }
.hero.h-WAITING { background: linear-gradient(150deg, #4a3500, #7a5a00 60%, #b98500); box-shadow: 0 14px 34px rgba(185, 133, 0, 0.26); }
.orb { position: absolute; right: -60px; top: -70px; width: 220px; height: 220px; border-radius: 50%; background: radial-gradient(circle, rgba(255, 255, 255, 0.3), rgba(255, 255, 255, 0)); pointer-events: none; }
.h-top { position: relative; display: flex; align-items: center; justify-content: space-between; gap: 10px; font-size: 13px; color: rgba(255, 255, 255, 0.85); }
.h-top > span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.h-v { position: relative; margin: 6px 0 12px; font-size: 38px; font-weight: 800; letter-spacing: -0.03em; }
.h-v small { margin-left: 6px; font-size: 16px; font-weight: 600; color: rgba(255, 255, 255, 0.8); }
.h-row { position: relative; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
.h-row > div { display: grid; gap: 2px; padding: 9px 11px; border-radius: 13px; background: rgba(255, 255, 255, 0.14); min-width: 0; }
.h-row small { font-size: 11px; color: rgba(255, 255, 255, 0.8); }
.h-row b { font-size: 15px; font-weight: 800; }
.h-note { position: relative; display: flex; align-items: flex-start; gap: 8px; margin-top: 12px; padding: 10px 12px; border-radius: 13px; background: rgba(255, 255, 255, 0.16); font-size: 13px; }
.h-note.bad { display: grid; gap: 2px; background: rgba(255, 255, 255, 0.2); }
.h-note small { color: rgba(255, 255, 255, 0.85); font-size: 12px; }
.h-cta { position: relative; width: 100%; margin-top: 12px; min-height: 48px; border: 0; border-radius: 14px; background: #fff; color: #0b4f4d; font-size: 15px; font-weight: 800; display: flex; align-items: center; justify-content: center; gap: 8px; font-family: inherit; }
.scrollx { overflow-x: auto; flex-wrap: nowrap; scrollbar-width: none; }
.scrollx::-webkit-scrollbar { display: none; }
.scrollx .chip { flex: none; }
.cnt { margin-left: 8px; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 10px; background: var(--red); color: #fff; font-size: 11px; display: inline-grid; place-items: center; }
.tools { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 10px; margin: 12px 0; align-items: center; }
.tools .search { margin: 0; min-width: 0; }
.tools .search input { min-width: 0; width: 100%; }
@media (max-width: 400px) { .lbl-long { display: none; } }
@media (min-width: 401px) { .lbl-short { display: none; } }
.addbtn { flex: none; white-space: nowrap; min-height: 46px; padding: 0 14px; border-radius: 14px; border: 0; background: var(--accent); color: #fff; font-size: 13.5px; font-weight: 800; display: inline-flex; align-items: center; gap: 6px; font-family: inherit; }
.cust { display: grid; gap: 8px; padding: 14px 16px; border-radius: 18px; border: 1px solid var(--line); background: var(--card); text-align: left; font-family: inherit; color: var(--ink); width: 100%; }
.c-top { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; }
.c-n { font-size: 14.5px; font-weight: 800; letter-spacing: -0.01em; overflow-wrap: anywhere; }
.c-t { font-size: 18px; font-weight: 800; white-space: nowrap; color: #0b6b69; }
.c-t small { font-size: 11px; color: var(--muted); font-weight: 600; }
.spark { display: flex; align-items: flex-end; gap: 3px; height: 34px; }
.spark i { flex: 1; min-width: 0; height: 4px; border-radius: 2px; background: var(--line); }
.spark i.on { background: linear-gradient(#2db3ad, #0f8b8d); }
.spark i.we:not(.on) { background: color-mix(in srgb, var(--red) 18%, var(--line)); }
.c-foot { display: flex; justify-content: space-between; font-size: 12px; color: var(--muted); }
.c-go { display: inline-flex; align-items: center; gap: 2px; color: var(--accent); font-weight: 700; }
.box { padding: 14px 16px; }
.box h2 { font-size: 14px; font-weight: 700; margin: 0 0 8px; }
.box h2 small { color: var(--muted); font-weight: 600; }
.hintp { margin: 0 0 10px; font-size: 13px; color: var(--ink-2); }
.ck { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; color: var(--ink-2); min-height: 32px; }
.ck input { width: 22px; height: 22px; accent-color: var(--accent); }
.br { display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 16px; border: 1px solid var(--line); background: var(--card); }
.br.on { border-color: color-mix(in srgb, var(--accent) 50%, var(--line)); background: var(--blue-50); }
.br.off { opacity: 0.7; }
.br input { width: 22px; height: 22px; accent-color: var(--accent); }
.br-m { display: grid; gap: 2px; min-width: 0; }
.br-m b { font-size: 14.5px; font-weight: 800; }
.br-m small { font-size: 11.5px; color: var(--muted); overflow-wrap: anywhere; }
.br-m small.bad { color: #b4232a; }
.br-r { display: grid; gap: 4px; justify-items: end; }
.br-r b { font-size: 16px; font-weight: 800; }
.sendbar { position: sticky; bottom: calc(var(--safe-bottom) + 96px); display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 14px; padding: 10px 12px; border-radius: 18px; background: var(--glass); backdrop-filter: blur(14px); border: 1px solid var(--line); box-shadow: 0 10px 28px rgba(11, 42, 130, 0.14); z-index: 5; }
.sb-i small { font-size: 13px; font-weight: 700; color: var(--ink-2); }
.rq { padding: 14px 16px; display: grid; gap: 6px; border: 1px solid var(--line); }
.rq.hot { border-color: color-mix(in srgb, #e6a100 55%, var(--line)); }
.rq-m { display: flex; align-items: center; justify-content: space-between; font-size: 13px; color: var(--muted); }
.rq-m span { display: inline-flex; align-items: center; gap: 6px; }
.rq-m b { font-size: 20px; color: #0b7a4b; letter-spacing: -0.02em; }
.nt { margin: 0; font-size: 12.5px; color: var(--ink-2); overflow-wrap: anywhere; white-space: pre-line; }
.nt.bad { color: #b4232a; }
.acts { display: flex; gap: 8px; margin-top: 4px; }
.acts .mini { flex: 1; justify-content: center; }
.mini { display: inline-flex; align-items: center; gap: 4px; min-height: 40px; padding: 0 12px; border-radius: 11px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 12.5px; font-weight: 700; font-family: inherit; }
.mini.ok { color: #0b7a4b; border-color: color-mix(in srgb, var(--green) 45%, var(--line)); }
.mini.bad { color: #b4232a; border-color: color-mix(in srgb, var(--red) 40%, var(--line)); }
.mini.solid.ok { background: var(--green); color: #fff; border-color: var(--green); }
.kv.tot span { font-weight: 800; }
.kv span:last-child { text-align: right; font-weight: 600; }
.warnbox { display: flex; align-items: center; gap: 8px; margin: 10px 0 0; padding: 9px 11px; border-radius: 12px; background: #fff3d6; color: #8a5b00; font-size: 12.5px; font-weight: 600; }
.cr { display: grid; gap: 8px; padding: 10px 0; border-top: 1px dashed var(--line); }
.cr:first-of-type { border-top: 0; }
.cr-h { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.cr-h b { font-size: 13.5px; overflow-wrap: anywhere; }
.cr-g { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
.cr-g > div { display: grid; gap: 1px; }
.cr-g small { font-size: 11px; color: var(--muted); }
.cr-g b { font-size: 13px; }
.cr-g .bad b { color: #b4232a; }
.cr.tot { border-top: 2px solid var(--line); }
.tg { padding: 2px 10px; border-radius: 99px; font-size: 11px; font-weight: 800; }
.tg.ok { background: #e4f6ee; color: #0b7a4b; } .tg.bad { background: #fdeaea; color: #b4232a; }
.tip { margin: 8px 0 0; font-size: 12.5px; color: var(--muted); }
.hint { margin: 0 0 14px; padding: 8px 10px; border-radius: 10px; background: var(--blue-50); color: var(--ink-2); font-size: 12.5px; }
.sheet.tall { max-height: 92vh; overflow-y: auto; }
.e-h { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
.e-t { display: grid; gap: 2px; min-width: 0; }
.e-t b { font-size: 16px; font-weight: 800; letter-spacing: -0.01em; overflow-wrap: anywhere; }
.e-t small { font-size: 12px; color: var(--muted); }
.x { width: 40px; height: 40px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); display: grid; place-items: center; flex: none; }
.e-sum { display: flex; align-items: center; justify-content: space-between; margin: 12px 0; padding: 10px 14px; border-radius: 14px; background: linear-gradient(120deg, #e3f4f2, #eaf1ff); font-size: 13px; color: var(--ink-2); }
.e-sum b { font-size: 20px; color: #0b6b69; letter-spacing: -0.02em; }
.lockn { display: flex; align-items: center; gap: 8px; margin: 0 0 12px; padding: 9px 12px; border-radius: 12px; background: #fff3d6; color: #8a5b00; font-size: 12.5px; font-weight: 600; }
.pw { margin-bottom: 10px; }
.qf { margin-bottom: 12px; }
.qf-r { display: grid; grid-template-columns: 1.2fr 1fr 1fr 1fr; gap: 6px; align-items: end; }
.qf-i { display: grid; gap: 2px; font-size: 11px; color: var(--muted); }
.qf-i input { height: 40px; min-width: 0; border-radius: 11px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; font-weight: 700; padding: 0 10px; font-family: inherit; }
.qf .mini { padding: 0 4px; justify-content: center; font-size: 11.5px; }
.dgrid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; }
.dc { position: relative; display: grid; gap: 4px; padding: 8px 8px 6px; border-radius: 13px; border: 1.5px solid var(--line); background: var(--card); transition: border-color 0.15s, background 0.15s; }
.dc:focus-within { border-color: var(--accent); background: var(--blue-50); }
.dc.we { background: color-mix(in srgb, var(--red) 6%, var(--card)); }
.dc.we .dc-h small { color: #b4232a; }
.dc.sv { opacity: 0.6; }
.dc.ok { border-color: var(--green); }
.dc.er { border-color: var(--red); }
.dc-h { display: flex; align-items: baseline; justify-content: space-between; gap: 4px; }
.dc-h b { font-size: 13px; font-weight: 800; }
.dc-h small { font-size: 10.5px; color: var(--muted); }
.dc input { width: 100%; min-width: 0; height: 36px; border: 0; background: transparent; color: var(--ink); font-size: 17px; font-weight: 800; text-align: center; font-family: inherit; padding: 0; outline: none; }
.dc input::placeholder { color: var(--line); }
.tick { position: absolute; top: 5px; right: 5px; width: 16px; height: 16px; border-radius: 50%; background: var(--green); color: #fff; display: grid; place-items: center; }
.results { display: grid; gap: 6px; max-height: 50vh; overflow-y: auto; }
.res { display: flex; align-items: center; justify-content: space-between; gap: 8px; min-height: 48px; padding: 8px 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 14px; font-weight: 600; text-align: left; font-family: inherit; }
.res small { color: var(--muted); font-size: 11px; flex: none; }
</style>
