<script setup>
import { t, locale, numLocale } from '../i18n'
import { useScrolled } from '../composables/useScrolled'
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import UserAvatar from '../components/UserAvatar.vue'
import StateBox from '../components/StateBox.vue'
import DayBars from '../components/charts/DayBars.vue'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'
import { showToast } from '../composables/useToast'
import { angka, tanggal, tanggalJam } from '../utils/format'
import { bulanPanjang } from '../utils/chart'

const scrolled = useScrolled()
const route = useRoute()
const router = useRouter()

const now = new Date()
const bulan = ref(Number(route.query.bulan) || now.getMonth() + 1)
const tahun = ref(Number(route.query.tahun) || now.getFullYear())
const periode = ref(Number(route.query.periode) === 2 ? 2 : route.query.periode ? 1 : now.getDate() >= 15 ? 2 : 1)
const tab = ref(route.query.tab === 'request' ? 'request' : 'fill')
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
    const r = await syop.forecastBmData({ bulan: bulan.value, tahun: tahun.value, periode: periode.value })
    if (my !== seq) return
    data.value = r
  } catch (e) { if (my === seq) error.value = errorMessage(e) } finally { if (my === seq) loading.value = false }
}

const review = computed(() => data.value?.review[periode.value] || {})
const st = computed(() => review.value.status || '')
const stCls = (s) => ({ WAITING: 'pill-wait', APPROVED: 'pill-ok', REJECTED: 'pill-no' })[s] || 'pill-draft'
const stTxt = (s) => t('fb.st.' + (s || 'none'))
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
const adaDefault = (c) => c.default.some(Boolean)
const tglDefault = computed(() => (data.value ? tanggal(data.value.tanggal_default) : ''))

function recalc() {
  const d = data.value
  d.total_hari = d.kalender.map((_, i) => d.customers.reduce((s, c) => s + (c.hari[i] || 0), 0))
  d.customers.forEach((c) => { c.total = c.hari.reduce((s, v) => s + (v || 0), 0) })
  d.total_periode = d.total_hari.reduce((s, v) => s + v, 0)
}

// ---- lembar isian harian ----
const ed = ref(null)
const fillQty = ref('')
const wd = (k) => new Date(tahun.value, bulan.value - 1, k.d).toLocaleDateString(locale.value, { weekday: 'short' }).slice(0, 3)
function openEdit(c) {
  ed.value = { c, vals: c.hari.map((v) => (v > 0 ? String(v) : '')), orig: c.hari.map((v) => (v > 0 ? String(v) : '')), cell: c.hari.map(() => ''), err: '' }
  fillQty.value = ''
}
function closeEdit() { ed.value = null; load(true) }
const edTotal = computed(() => (ed.value ? ed.value.vals.reduce((s, v) => s + (parseFloat(String(v).replace(',', '.')) || 0), 0) : 0))
const isDef = (i) => ed.value && ed.value.c.default[i]

async function simpan(items, cells) {
  const e = ed.value
  e.err = ''
  cells.forEach((i) => (e.cell[i] = 'saving'))
  try {
    await syop.forecastBmIsi({ id_customer: e.c.id, items })
    items.forEach((it, k) => {
      const i = cells[k]
      const v = it.qty === null ? null : Number(it.qty)
      e.c.hari[i] = v > 0 ? v : null
      e.orig[i] = e.vals[i]
      e.cell[i] = 'ok'
    })
    e.c.default = e.c.default.map(() => false) // server ikut menyimpan default customer ini
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
  if (mode !== 'clear' && !(v >= 0)) { e.err = t('fo.invalid'); return }
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
  try { addRes.value = (await syop.forecastBmCustomer({ q: addQ.value.trim() || undefined })).data } catch { addRes.value = [] } finally { addLoading.value = false }
}
watch(addQ, () => { clearTimeout(addTimer); addTimer = setTimeout(cari, 300) })
function openAdd() { add.value = true; addQ.value = ''; cari() }
const sudah = (id) => data.value.customers.some((c) => c.id === id)
function pickAdd(c) {
  let row = data.value.customers.find((x) => x.id === c.id)
  if (!row) { row = { id: c.id, nama: c.nama, hari: data.value.kalender.map(() => null), default: data.value.kalender.map(() => false), total: 0 }; data.value.customers.unshift(row) }
  add.value = false
  openEdit(row)
}

// ---- request tambahan qty ke OM ----
const rq = ref({ cust: null, tanggal: '', qty: '', alasan: '' })
const rqPick = ref(false)
const rqQ = ref('')
const rqRes = ref([])
const busy = ref(false)
const formError = ref('')
const hist = ref(null)
const histLoading = ref(false)
let rqTimer
async function cariRq() { try { rqRes.value = (await syop.forecastBmCustomer({ q: rqQ.value.trim() || undefined })).data } catch { rqRes.value = [] } }
watch(rqQ, () => { clearTimeout(rqTimer); rqTimer = setTimeout(cariRq, 300) })
function openRqPick() { rqPick.value = true; rqQ.value = ''; cariRq() }
function choose(c) { rq.value.cust = c; rqPick.value = false }
async function loadHist() {
  histLoading.value = true
  try { hist.value = await syop.forecastBmRequests({ per_page: 30 }) } catch (e) { showToast(errorMessage(e), 'err') } finally { histLoading.value = false }
}
async function kirimRq() {
  formError.value = ''
  const r = rq.value
  if (!r.cust || !r.tanggal || !(Number(r.qty) > 0) || !r.alasan.trim()) return (formError.value = t('fb.rq.required'))
  busy.value = true
  try {
    const res = await syop.forecastBmRequest({ id_customer: r.cust.id, tanggal: r.tanggal, qty_request: Math.round(Number(r.qty)), alasan: r.alasan.trim() })
    showToast(res.message, 'ok')
    rq.value = { cust: null, tanggal: '', qty: '', alasan: '' }
    await loadHist()
  } catch (e) { formError.value = errorMessage(e) } finally { busy.value = false }
}
const rpill = (s) => ({ WAITING_OM: 'pill-wait', WAITING_CEO: 'pill-wait', APPROVED: 'pill-ok', REJECTED_OM: 'pill-no', REJECTED: 'pill-no', REJECTED_CEO: 'pill-no' })[s] || 'pill-draft'
const rtxt = (s) => (t('fo.req.st.' + s) === 'fo.req.st.' + s ? s : t('fo.req.st.' + s))
const minDate = new Date().toISOString().slice(0, 10)

let first = true
watch([bulan, tahun, periode], () => {
  if (first) return
  router.replace({ query: { ...route.query, bulan: bulan.value, tahun: tahun.value, periode: periode.value } })
  load()
})
watch(tab, (v) => { router.replace({ query: { ...route.query, tab: v === 'request' ? 'request' : undefined } }); if (v === 'request' && !hist.value) loadHist() })
onMounted(async () => {
  await load()
  first = false
  if (tab.value === 'request') loadHist()
})
</script>

<template>
  <div class="page">
    <div class="page-head" :class="{ stuck: scrolled }">
      <div>
        <h1 class="page-title">{{ t('fo.title') }}</h1>
        <p class="page-sub">{{ data ? data.cabang.nama : t('fb.sub') }}</p>
      </div>
      <router-link :to="{ name: 'profil' }" class="avatar" :aria-label="t('common.profile')"><UserAvatar /><i class="dot" /></router-link>
    </div>

    <div class="mo" role="group">
      <button :aria-label="t('fc.prevMonth')" @click="geser(-1)"><Icon name="chevron" :size="18" style="transform:rotate(180deg)" /></button>
      <b>{{ bulanPanjang(bulan - 1) }} {{ tahun }}</b>
      <button :aria-label="t('fc.nextMonth')" @click="geser(1)"><Icon name="chevron" :size="18" /></button>
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
      <section class="hero" :class="'h-' + (st || 'none')">
        <span class="orb" />
        <div class="h-top"><span>{{ data.cabang.nama }} · {{ t('fc.period', { p: periode }) }}</span><span class="pill" :class="stCls(st)">{{ stTxt(st) }}</span></div>
        <div class="h-v">{{ km(data.total_periode) }}<small>{{ t('fc.kl') }}</small></div>
        <div class="h-row">
          <div><small>{{ t('fo.custShort') }}</small><b>{{ data.customers.length }}</b></div>
          <div><small>{{ t('fo.avg') }}</small><b>{{ km(avg) }}</b></div>
          <div><small>{{ t('fo.month') }}</small><b>{{ km(data.total_bulan) }}</b></div>
        </div>
        <div v-if="st === 'REJECTED'" class="h-note bad"><b>{{ t('fo.ceoNote') }}</b><span>{{ review.catatan_ceo || '-' }}</span><small v-if="review.ceo_date">{{ tanggalJam(review.ceo_date) }} · {{ t('fb.rejectedHint') }}</small></div>
        <div v-else-if="locked" class="h-note"><Icon name="lock" :size="16" /><span>{{ t('fo.lock.approved') }}</span></div>
        <div v-else-if="st === 'WAITING'" class="h-note"><Icon name="clock" :size="16" /><span>{{ t('fb.waitingNote') }}</span></div>
        <button class="h-cta" @click="tab = 'request'"><Icon name="plus" :size="18" /> {{ t('fb.askMore') }}</button>
      </section>

      <div class="chips" role="tablist">
        <button class="chip" :class="{ on: tab === 'fill' }" role="tab" @click="tab = 'fill'">{{ t('fo.tab.fill') }}</button>
        <button class="chip" :class="{ on: tab === 'request' }" role="tab" @click="tab = 'request'">{{ t('fo.tab.req') }}</button>
      </div>

      <!-- ISI -->
      <template v-if="tab === 'fill'">
        <div class="tools">
          <label class="search"><Icon name="search" :size="20" /><input v-model="q" type="search" :aria-label="t('fo.search')" :placeholder="t('fo.search')" /></label>
          <button v-if="!locked" class="addbtn" @click="openAdd"><Icon name="plus" :size="20" /> {{ t('fo.add') }}</button>
        </div>
        <p v-if="data.ada_default" class="defn"><i class="swatch" /> {{ t('fb.defaultNote') }}</p>
        <StateBox v-if="!data.customers.length" icon="chart" :title="t('fo.none')" :text="t('fo.noneText')" />
        <StateBox v-else-if="!shown.length" icon="search" :title="t('fo.noMatch')" text="" />
        <div v-else class="stack">
          <button v-for="(c, i) in shown" :key="c.id" class="cust rise" :style="{ '--i': i % 8 }" @click="openEdit(c)">
            <div class="c-top"><span class="c-n">{{ c.nama }}</span><b class="c-t" :class="{ df: adaDefault(c) }">{{ c.total > 0 ? km(c.total) : '–' }}<small v-if="c.total > 0"> {{ t('fc.kl') }}</small></b></div>
            <div class="spark" aria-hidden="true">
              <i v-for="(v, k) in c.hari" :key="k" :class="{ we: weekend[k], on: v > 0, df: c.default[k] }" :style="{ height: v > 0 ? Math.max(14, (v / barMax(c)) * 100) + '%' : '' }" />
            </div>
            <div class="c-foot"><span>{{ filled(c) ? t('fo.filled', { n: filled(c) }) : t('fo.empty') }}<em v-if="adaDefault(c)" class="dtag">{{ t('fb.default') }}</em></span><span class="c-go">{{ locked ? t('fo.readOnly') : t('fo.tapFill') }} <Icon name="chevron" :size="14" /></span></div>
          </button>
        </div>
        <p v-if="data.customers.length" class="tip">{{ t('fb.autoNote', { d: tglDefault }) }}</p>
        <section v-if="data.customers.length" class="card box" style="margin-top:14px">
          <h2>{{ t('fo.daily') }} <small>({{ t('fc.kl') }})</small></h2>
          <DayBars :values="data.total_hari" :weekend="weekend" color="#0f8b8d" :label="t('fo.daily')" />
        </section>
      </template>

      <!-- REQUEST -->
      <template v-else>
        <section class="card box rqform">
          <h2>{{ t('fb.rq.title') }}</h2>
          <p class="tip" style="margin:-2px 0 10px">{{ t('fb.rq.sub') }}</p>
          <div class="field">
            <label>{{ t('fb.rq.customer') }}</label>
            <button class="pick" @click="openRqPick"><span :class="{ ph: !rq.cust }">{{ rq.cust ? rq.cust.nama : t('fb.rq.pick') }}</span><Icon name="chevron" :size="16" /></button>
          </div>
          <div class="two">
            <div class="field"><label for="rd">{{ t('fb.rq.date') }}</label><input id="rd" v-model="rq.tanggal" class="plainin" type="date" :min="minDate" /></div>
            <div class="field"><label for="rqty">{{ t('fb.rq.qty') }}</label><input id="rqty" v-model="rq.qty" class="plainin" type="number" inputmode="numeric" min="1" placeholder="0" /></div>
          </div>
          <div class="field"><label for="ral">{{ t('fb.rq.reason') }}</label><textarea id="ral" v-model="rq.alasan" class="plain" maxlength="2000" :placeholder="t('fb.rq.reasonPh')" /></div>
          <p v-if="formError" class="err-text" role="alert" style="margin-bottom:10px">{{ formError }}</p>
          <button class="btn btn-primary btn-block" :disabled="busy" @click="kirimRq"><Icon name="check" :size="18" /> {{ busy ? t('det.processing') : t('fb.rq.send') }}</button>
        </section>

        <h2 class="gh">{{ t('fb.rq.history') }}</h2>
        <div v-if="histLoading && !hist" class="stack"><div class="skel" style="height:120px" /></div>
        <StateBox v-else-if="!hist?.data.length" icon="check" :title="t('fb.rq.none')" :text="t('fb.rq.noneText')" />
        <div v-else class="stack">
          <article v-for="(r, i) in hist.data" :key="r.id" class="card rq rise" :style="{ '--i': i % 8 }">
            <div class="item-top"><div class="item-who"><span class="ico-box tinted tone-teal"><Icon name="chart" :size="18" /></span><span>{{ r.nama_cabang }} · {{ tanggal(r.tanggal) }}</span></div><span class="pill" :class="rpill(r.status)">{{ rtxt(r.status) }}</span></div>
            <div class="item-title">{{ r.nama_customer }}</div>
            <div class="rq-m"><span>{{ tanggalJam(r.created_at) }}</span><b>+{{ angka(r.qty) }}</b></div>
            <p class="nt"><b>{{ t('fb.rq.reasonShort') }}:</b> {{ r.alasan }}</p>
            <p v-if="r.om_catatan" class="nt bad"><b>{{ t('fo.req.omNote') }}:</b> {{ r.om_catatan }}</p>
            <p v-if="r.ceo_catatan" class="nt bad"><b>{{ t('fo.req.ceoNote') }}:</b> {{ r.ceo_catatan }}</p>
            <div class="tl"><span :class="{ on: r.bm_tanggal }"><i />{{ t('fb.rq.tl.bm') }}<small>{{ r.bm_tanggal ? tanggalJam(r.bm_tanggal) : '-' }}</small></span><span :class="{ on: r.om_tanggal }"><i />{{ t('fb.rq.tl.om') }}<small>{{ r.om_tanggal ? tanggalJam(r.om_tanggal) : '-' }}</small></span><span :class="{ on: r.ceo_tanggal }"><i />{{ t('fb.rq.tl.ceo') }}<small>{{ r.ceo_tanggal ? tanggalJam(r.ceo_tanggal) : '-' }}</small></span></div>
          </article>
        </div>
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
        <p v-if="locked" class="lockn"><Icon name="lock" :size="15" /> {{ t('fo.lock.approved') }}</p>
        <p v-else-if="ed.c.default.some(Boolean)" class="defn" style="margin:0 0 10px"><i class="swatch" /> {{ t('fb.sheetDefault') }}</p>
        <div v-if="!locked" class="qf">
          <div class="qf-r">
            <label class="qf-i"><span>{{ t('fo.fillQty') }}</span><input v-model="fillQty" inputmode="decimal" placeholder="0" /></label>
            <button class="mini" @click="quick('all')">{{ t('fo.fillAll') }}</button>
            <button class="mini" @click="quick('work')">{{ t('fo.fillWork') }}</button>
            <button class="mini bad" @click="quick('clear')">{{ t('fo.fillClear') }}</button>
          </div>
        </div>
        <p v-if="ed.err" class="err-text" role="alert">{{ ed.err }}</p>
        <div class="dgrid">
          <label v-for="(k, i) in data.kalender" :key="k.d" class="dc" :class="{ we: k.akhir_pekan || k.libur, sv: ed.cell[i] === 'saving', ok: ed.cell[i] === 'ok', er: ed.cell[i] === 'err', dfc: isDef(i) && ed.vals[i] !== '' }" :title="k.libur || ''">
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

    <!-- pilih customer untuk request -->
    <div v-if="rqPick" class="sheet-back" @click.self="rqPick = false">
      <div class="sheet" role="dialog" aria-modal="true">
        <div class="grab" />
        <h2 class="sheet-t">{{ t('fb.rq.pick') }}</h2>
        <label class="search" style="margin:10px 0"><Icon name="search" :size="20" /><input v-model="rqQ" type="search" :placeholder="t('fo.addSearch')" /></label>
        <div class="results">
          <p v-if="!rqRes.length" class="tip">{{ t('fo.addNone') }}</p>
          <button v-for="c in rqRes" :key="c.id" class="res" @click="choose(c)"><span>{{ c.nama }}</span></button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.mo { display: grid; grid-template-columns: 44px 1fr 44px; align-items: center; margin-bottom: 10px; border: 1px solid var(--line); background: var(--card); border-radius: 14px; overflow: hidden; }
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
.hero.h-APPROVED { background: linear-gradient(150deg, #073a22, #0b6b3f 60%, #14a36b); box-shadow: 0 14px 34px rgba(20, 163, 107, 0.26); }
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
.h-cta { position: relative; width: 100%; margin-top: 12px; min-height: 46px; border: 0; border-radius: 14px; background: rgba(255, 255, 255, 0.95); color: #0b4f4d; font-size: 14.5px; font-weight: 800; display: flex; align-items: center; justify-content: center; gap: 8px; font-family: inherit; }
.cnt { margin-left: 8px; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 10px; background: var(--red); color: #fff; font-size: 11px; display: inline-grid; place-items: center; }
.tools { display: grid; grid-template-columns: 1fr auto; gap: 10px; margin: 12px 0; align-items: center; }
.tools .search { margin: 0; }
.addbtn { min-height: 46px; padding: 0 14px; border-radius: 14px; border: 0; background: var(--accent); color: #fff; font-size: 13.5px; font-weight: 800; display: inline-flex; align-items: center; gap: 6px; font-family: inherit; }
.defn { display: flex; align-items: center; gap: 8px; margin: 0 0 10px; font-size: 12px; color: var(--muted); }
.swatch { flex: none; width: 14px; height: 14px; border-radius: 4px; background: repeating-linear-gradient(135deg, #cdd3df, #cdd3df 3px, #e8ebf2 3px, #e8ebf2 6px); }
.cust { display: grid; gap: 8px; padding: 14px 16px; border-radius: 18px; border: 1px solid var(--line); background: var(--card); text-align: left; font-family: inherit; color: var(--ink); width: 100%; }
.c-top { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; }
.c-n { font-size: 14.5px; font-weight: 800; letter-spacing: -0.01em; overflow-wrap: anywhere; }
.c-t { font-size: 18px; font-weight: 800; white-space: nowrap; color: #0b6b69; }
.c-t.df { color: var(--muted); font-style: italic; }
.c-t small { font-size: 11px; color: var(--muted); font-weight: 600; }
.spark { display: flex; align-items: flex-end; gap: 3px; height: 34px; }
.spark i { flex: 1; min-width: 0; height: 4px; border-radius: 2px; background: var(--line); }
.spark i.on { background: linear-gradient(#2db3ad, #0f8b8d); }
.spark i.on.df { background: repeating-linear-gradient(135deg, #b8bfce, #b8bfce 3px, #d9deea 3px, #d9deea 6px); }
.spark i.we:not(.on) { background: color-mix(in srgb, var(--red) 18%, var(--line)); }
.c-foot { display: flex; justify-content: space-between; font-size: 12px; color: var(--muted); }
.dtag { margin-left: 8px; padding: 1px 8px; border-radius: 99px; background: var(--line); color: var(--muted); font-size: 10.5px; font-style: normal; font-weight: 700; }
.c-go { display: inline-flex; align-items: center; gap: 2px; color: var(--accent); font-weight: 700; }
.box { padding: 14px 16px; }
.box h2 { font-size: 14px; font-weight: 700; margin: 0 0 8px; }
.box h2 small { color: var(--muted); font-weight: 600; }
.gh { margin: 20px 0 10px; font-size: 14px; font-weight: 800; color: var(--ink-2); }
.pick { width: 100%; min-height: 48px; padding: 0 14px; border-radius: 14px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 14.5px; font-weight: 700; display: flex; align-items: center; justify-content: space-between; gap: 8px; text-align: left; font-family: inherit; }
.pick .ph { color: var(--muted); font-weight: 500; }
.two { display: grid; grid-template-columns: 1.3fr 1fr; gap: 10px; }
.plainin { width: 100%; min-width: 0; height: 48px; border-radius: 14px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; font-weight: 600; padding: 0 12px; font-family: inherit; }
.rq { padding: 14px 16px; display: grid; gap: 6px; border: 1px solid var(--line); }
.rq-m { display: flex; align-items: center; justify-content: space-between; font-size: 12.5px; color: var(--muted); }
.rq-m b { font-size: 20px; color: #0b7a4b; letter-spacing: -0.02em; }
.nt { margin: 0; font-size: 12.5px; color: var(--ink-2); overflow-wrap: anywhere; white-space: pre-line; }
.nt.bad { color: #b4232a; }
.tl { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; margin-top: 6px; padding-top: 10px; border-top: 1px dashed var(--line); }
.tl span { display: grid; gap: 1px; font-size: 11.5px; font-weight: 700; color: var(--muted); }
.tl span i { display: block; width: 8px; height: 8px; border-radius: 50%; background: var(--line); margin-bottom: 2px; }
.tl span.on { color: var(--ink); }
.tl span.on i { background: var(--green); }
.tl small { font-size: 10.5px; font-weight: 500; color: var(--muted); }
.tip { margin: 10px 0 0; font-size: 12.5px; color: var(--muted); }
.mini { display: inline-flex; align-items: center; gap: 4px; min-height: 40px; padding: 0 4px; justify-content: center; border-radius: 11px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 11.5px; font-weight: 700; font-family: inherit; }
.mini.bad { color: #b4232a; border-color: color-mix(in srgb, var(--red) 40%, var(--line)); }
.sheet.tall { max-height: 92vh; overflow-y: auto; }
.e-h { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
.e-t { display: grid; gap: 2px; min-width: 0; }
.e-t b { font-size: 16px; font-weight: 800; letter-spacing: -0.01em; overflow-wrap: anywhere; }
.e-t small { font-size: 12px; color: var(--muted); }
.x { width: 40px; height: 40px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); display: grid; place-items: center; flex: none; }
.e-sum { display: flex; align-items: center; justify-content: space-between; margin: 12px 0; padding: 10px 14px; border-radius: 14px; background: linear-gradient(120deg, #e3f4f2, #eaf1ff); font-size: 13px; color: var(--ink-2); }
.e-sum b { font-size: 20px; color: #0b6b69; letter-spacing: -0.02em; }
.lockn { display: flex; align-items: center; gap: 8px; margin: 0 0 12px; padding: 9px 12px; border-radius: 12px; background: #fff3d6; color: #8a5b00; font-size: 12.5px; font-weight: 600; }
.qf { margin-bottom: 12px; }
.qf-r { display: grid; grid-template-columns: 1.2fr 1fr 1fr 1fr; gap: 6px; align-items: end; }
.qf-i { display: grid; gap: 2px; font-size: 11px; color: var(--muted); }
.qf-i input { height: 40px; min-width: 0; border-radius: 11px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; font-weight: 700; padding: 0 10px; font-family: inherit; }
.dgrid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; }
.dc { position: relative; display: grid; gap: 4px; padding: 8px 8px 6px; border-radius: 13px; border: 1.5px solid var(--line); background: var(--card); transition: border-color 0.15s, background 0.15s; }
.dc:focus-within { border-color: var(--accent); background: var(--blue-50); }
.dc.we { background: color-mix(in srgb, var(--red) 6%, var(--card)); }
.dc.we .dc-h small { color: #b4232a; }
.dc.dfc { background: repeating-linear-gradient(135deg, #f1f3f8, #f1f3f8 6px, #e8ebf2 6px, #e8ebf2 12px); }
.dc.dfc input { color: var(--muted); font-style: italic; }
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
