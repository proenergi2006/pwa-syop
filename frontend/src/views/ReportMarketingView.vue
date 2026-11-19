<script setup>
import { t } from '../i18n'
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import StateBox from '../components/StateBox.vue'
import MonthBars from '../components/charts/MonthBars.vue'
import Donut from '../components/charts/Donut.vue'
import RankBars from '../components/charts/RankBars.vue'
import HeatGrid from '../components/charts/HeatGrid.vue'
import Spark from '../components/charts/Spark.vue'
import * as syop from '../api/syop'
import { useAuth } from '../stores/auth'
import { errorMessage } from '../api/client'
import { angka, tanggal } from '../utils/format'
import { openFile } from '../utils/openFile'
import { clean } from '../utils/html'
import { bulanPanjang, jumlah, ringkas } from '../utils/chart'

const router = useRouter()
const NOW = new Date().getFullYear()
const JENIS = ['volume', 'aktif', 'baru', 'kegiatan']
const META = {
  volume: { color: '#0a66e8', label: 'rm.tab.vol', title: 'rm.volume', sub: 'rm.volumeSub', icon: 'drop' },
  aktif: { color: '#0f8b8d', label: 'rm.tab.act', title: 'rm.active', sub: 'rm.activeSub', icon: 'user' },
  baru: { color: '#e07400', label: 'rm.tab.new', title: 'rm.new', sub: 'rm.newSub', icon: 'tag' },
  kegiatan: { color: '#6d28d9', label: 'rm.tab.keg', title: 'rm.kegiatan', sub: 'rm.kegiatanSub', icon: 'doc' },
}

const tab = ref('sum') // sum | volume | aktif | baru
const tahun = ref(NOW)
const auth = useAuth()
const cabang = ref('')
const cabangTetap = computed(() => auth.user?.id_role === 7) // BM: laporan otomatis hanya cabangnya sendiri (diatur server)
const mkt = ref(0) // 0 = semua marketing
const selM = ref(-1) // bulan yang diketuk pada grafik (0-11)
const cabangs = ref([])
const data = reactive({ volume: null, aktif: null, baru: null, kegiatan: null })
const loading = reactive({ volume: false, aktif: false, baru: false, kegiatan: false })
const errs = reactive({ volume: '', aktif: '', baru: '', kegiatan: '' }) // kegagalan per data; satu yang gagal tidak menutup yang lain
let seq = 0

const need = computed(() => (tab.value === 'sum' ? JENIS : [tab.value]))
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

/** Muat satu data; gangguan sementara server (5xx / jaringan) dicoba ulang sekali otomatis. */
async function fetchJenis(j, my) {
  loading[j] = true
  errs[j] = ''
  try {
    for (let attempt = 0; attempt < 2; attempt++) {
      try {
        const r = await syop.reportMarketing(j, { tahun: tahun.value, cabang: cabang.value || undefined })
        if (my === seq) data[j] = r
        return
      } catch (e) {
        const st = e.response?.status
        if (attempt === 0 && (!st || st >= 500) && st !== 403) { await sleep(1500); if (my !== seq) return; continue }
        if (my === seq) errs[j] = errorMessage(e)
        return
      }
    }
  } finally {
    if (my === seq) loading[j] = false
  }
}
function reload() {
  const my = ++seq
  selM.value = -1
  JENIS.forEach((j) => { data[j] = null; loading[j] = false; errs[j] = '' })
  need.value.forEach((j) => fetchJenis(j, my))
}
/** Coba ulang hanya data yang gagal. */
function retry() { need.value.filter((j) => errs[j]).forEach((j) => fetchJenis(j, seq)) }
const failed = computed(() => need.value.filter((j) => errs[j]))
watch([tahun, cabang], reload)
watch(tab, () => {
  selM.value = -1
  need.value.forEach((j) => { if (!data[j] && !loading[j] && !errs[j]) fetchJenis(j, seq) })
})
onMounted(() => {
  syop.reportCabang().then((r) => (cabangs.value = r)).catch(() => {})
  reload()
})

/* ---------- turunan data ---------- */
const nol = () => Array(12).fill(0)
function view(j) {
  const d = data[j]
  if (!d) return null
  const row = mkt.value ? d.rows.find((r) => r.id === mkt.value) : null
  const vals = mkt.value ? (row ? row.bulan : nol()) : d.totals
  const prev = mkt.value ? null : d.prev_totals
  const upto = d.bulan_berjalan || 12
  const total = jumlah(vals)
  let pk = -1, pv = 0, lo = -1, lv = Infinity
  vals.forEach((v, i) => {
    if (v > pv) { pv = v; pk = i }
    if (i < upto && v < lv) { lv = v; lo = i }
  })
  const ytd = jumlah(vals.slice(0, upto))
  const pytd = prev ? jumlah(prev.slice(0, upto)) : 0
  return {
    d, vals, prev, upto, total, avg: total / Math.max(1, upto), pk, pv, lo, lv,
    growth: prev && pytd > 0 ? (ytd - pytd) / pytd : null,
    ranked: d.rows.map((r) => ({ id: r.id, nama: r.nama, value: r.total })).sort((a, b) => b.value - a.value),
    empty: jumlah(d.totals) === 0 && jumlah(d.prev_totals) === 0,
  }
}
const V = computed(() => ({ volume: view('volume'), aktif: view('aktif'), baru: view('baru'), kegiatan: view('kegiatan') }))
const cur = computed(() => (tab.value === 'sum' ? null : V.value[tab.value]))

const mktOptions = computed(() => {
  const m = new Map()
  JENIS.forEach((j) => data[j]?.rows.forEach((r) => m.set(r.id, r.nama)))
  return [...m].map(([id, nama]) => ({ id, nama })).sort((a, b) => a.nama.localeCompare(b.nama))
})
const mktName = computed(() => mktOptions.value.find((x) => x.id === mkt.value)?.nama || '')
const sumEmpty = computed(() => {
  const got = JENIS.map((j) => V.value[j]).filter(Boolean)
  return !failed.value.length && !loadingAny.value && got.length > 0 && got.every((v) => v.empty)
})
const loadingAny = computed(() => need.value.some((j) => loading[j]))

const fmt = (j, v) => (j === 'volume' ? ringkas(v) + ' L' : angka(v))
const fmtShort = (j, v) => (j === 'volume' ? ringkas(v) : angka(v))
const pctText = (g) => (g === null ? '' : (g > 0 ? '+' : '') + (g * 100).toFixed(Math.abs(g) < 0.1 ? 1 : 0) + '%')
const growthTone = (g) => (g === null ? 'flat' : g > 0.005 ? 'up' : g < -0.005 ? 'down' : 'flat')

function kpis(j) {
  const v = V.value[j]
  if (!v) return []
  return [
    { k: 'total', label: j === 'aktif' ? t('rm.kpi.totalAcc') : t('rm.kpi.total'), val: fmtShort(j, v.total), c: META[j].color },
    { k: 'avg', label: t('rm.kpi.avg'), val: fmtShort(j, v.avg), c: META[j].color },
    { k: 'peak', label: t('rm.kpi.peak'), val: v.pk >= 0 ? bulanPanjang(v.pk) : '-', sub: v.pk >= 0 ? fmt(j, v.pv) : '', c: META[j].color },
    { k: 'growth', label: t('rm.kpi.growth'), val: v.growth === null ? '–' : pctText(v.growth), sub: v.growth === null ? t('rm.kpi.noPrev') : t('rm.kpi.ytd'), tone: growthTone(v.growth) },
  ]
}

function donutItems(j) {
  const v = V.value[j]
  if (!v) return []
  const top = v.ranked.slice(0, 5).filter((x) => x.value > 0).map((x) => ({ label: x.nama, value: x.value }))
  const rest = v.ranked.slice(5).reduce((s, x) => s + x.value, 0)
  return rest > 0 ? [...top, { label: t('rm.others'), value: rest }] : top
}

/** Bulan terpilih: marketing teratas pada bulan itu (hanya bila belum memilih satu marketing). */
function topBulan(j) {
  const d = data[j]
  if (!d || selM.value < 0) return []
  return [...d.rows].map((r) => ({ id: r.id, nama: r.nama, value: r.bulan[selM.value] })).filter((x) => x.value > 0).sort((a, b) => b.value - a.value).slice(0, 5)
}

function insights(j) {
  const v = V.value[j]
  if (!v || v.empty) return []
  const out = []
  if (v.growth !== null) {
    const p = Math.abs(v.growth * 100).toFixed(1).replace(/\.0$/, '')
    out.push({ tone: growthTone(v.growth), text: growthTone(v.growth) === 'up' ? t('rm.ins.up', { p }) : growthTone(v.growth) === 'down' ? t('rm.ins.down', { p }) : t('rm.ins.flat') })
  }
  if (v.pk >= 0) out.push({ tone: 'up', text: t('rm.ins.peak', { m: bulanPanjang(v.pk), v: fmt(j, v.pv) }) })
  if (v.lo >= 0 && v.lv > 0 && v.lo !== v.pk) out.push({ tone: 'down', text: t('rm.ins.low', { m: bulanPanjang(v.lo), v: fmt(j, v.lv) }) })
  const all = jumlah(v.d.totals)
  if (!mkt.value && v.ranked.length >= 2 && all > 0) {
    out.push({ tone: 'flat', text: t('rm.ins.best', { name: v.ranked[0].nama, v: fmt(j, v.ranked[0].value), p: ((v.ranked[0].value / all) * 100).toFixed(0) }) })
    if (v.ranked.length >= 5) out.push({ tone: 'flat', text: t('rm.ins.top', { n: 3, p: ((v.ranked.slice(0, 3).reduce((s, x) => s + x.value, 0) / all) * 100).toFixed(0) }) })
  }
  if (j === 'kegiatan' && !mkt.value && v.d.extra?.customer) out.push({ tone: 'flat', text: t('rm.ins.cust', { n: angka(v.d.extra.customer) }) })
  if (j === 'volume' && !mkt.value && V.value.aktif && jumlah(V.value.aktif.d.totals) > 0) {
    out.push({ tone: 'flat', text: t('rm.ins.perCust', { v: angka(Math.round(jumlah(v.d.totals) / jumlah(V.value.aktif.d.totals))) }) })
  }
  return out
}

/* ---------- ringkasan ---------- */
function sumCard(j) {
  const v = V.value[j]
  if (!v) return null
  return {
    j, v, color: META[j].color,
    big: j === 'aktif' ? fmtShort(j, v.avg) : fmtShort(j, v.total),
    unit: j === 'volume' ? 'L' : j === 'aktif' ? t('rm.kpi.avg').toLowerCase() : '',
    growth: v.growth,
  }
}
const sumCards = computed(() => JENIS.map(sumCard).filter(Boolean))
const perCust = computed(() => {
  const v = V.value.volume, a = V.value.aktif
  if (!v || !a) return null
  const acc = jumlah(a.vals)
  return acc > 0 ? Math.round(jumlah(v.vals) / acc) : null
})
const leader = computed(() => {
  const v = data.volume, a = data.aktif, b = data.baru
  if (!v) return []
  const by = new Map()
  const up = (r, key) => {
    const x = by.get(r.id) || { id: r.id, nama: r.nama, vol: 0, aktif: 0, baru: 0 }
    x[key] = r.total
    by.set(r.id, x)
  }
  v.rows.forEach((r) => up(r, 'vol'))
  a?.rows.forEach((r) => up(r, 'aktif'))
  b?.rows.forEach((r) => up(r, 'baru'))
  const upto = Math.max(1, v.bulan_berjalan || 12)
  return [...by.values()].map((x) => ({ ...x, aktif: x.aktif / upto })).sort((p, q) => q.vol - p.vol)
})
const leaderMax = computed(() => Math.max(1, ...leader.value.map((x) => x.vol)))
const leaderAll = ref(false)
const leaderShown = computed(() => (leaderAll.value ? leader.value : leader.value.slice(0, 8)))

function pickMkt(id) { mkt.value = mkt.value === id ? 0 : id; selM.value = -1 }

/* ---------- rincian (sheet) ---------- */
const det = reactive({ open: false, jenis: '', id: 0, month: 0, loading: false, error: '', data: null })
async function openDetail(jenis, id, month) {
  if (jenis === 'kegiatan') { mkt.value = id; selM.value = month; await nextTick(); document.getElementById('kegList')?.scrollIntoView({ behavior: 'smooth', block: 'start' }); return }
  Object.assign(det, { open: true, jenis, id, month, loading: true, error: '', data: null })
  try {
    det.data = await syop.reportMarketingDetail(jenis, { tahun: tahun.value, bulan: month + 1, marketing: id, cabang: cabang.value || undefined })
  } catch (e) { det.error = errorMessage(e) } finally { det.loading = false }
}
const detMax = computed(() => Math.max(1, ...(det.data?.customer || []).map((x) => x.volume)))

/* ---------- daftar & rincian kegiatan ---------- */
const kl = reactive({ items: [], meta: { page: 1, last_page: 1, total: 0 }, loading: false, more: false, q: '', error: '' })
let kseq = 0
let ktimer
async function loadKegiatan(page = 1) {
  const my = ++kseq
  page === 1 ? (kl.loading = true) : (kl.more = true)
  kl.error = ''
  try {
    const r = await syop.reportKegiatanList({ tahun: tahun.value, cabang: cabang.value || undefined, marketing: mkt.value || undefined, bulan: selM.value >= 0 ? selM.value + 1 : undefined, q: kl.q.trim() || undefined, page, per_page: 12 })
    if (my !== kseq) return
    kl.items = page === 1 ? r.data : [...kl.items, ...r.data]
    kl.meta = r.meta
  } catch (e) { if (my === kseq) kl.error = errorMessage(e) } finally { if (my === kseq) { kl.loading = false; kl.more = false } }
}
watch([tab, tahun, cabang, mkt, selM], () => { if (tab.value === 'kegiatan') loadKegiatan(1) })
watch(() => kl.q, () => { clearTimeout(ktimer); ktimer = setTimeout(() => { if (tab.value === 'kegiatan') loadKegiatan(1) }, 350) })
const kd = reactive({ open: false, loading: false, error: '', data: null })
async function openKegiatan(id) {
  Object.assign(kd, { open: true, loading: true, error: '', data: null })
  try { kd.data = await syop.reportKegiatanShow(id) } catch (e) { kd.error = errorMessage(e) } finally { kd.loading = false }
}
const statusTone = (st) => (st.startsWith('Approve') ? 'pill-ok' : st === 'Draf' ? '' : 'pill-wait')

const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'report' }))
const tabs = [['sum', 'rm.tab.sum'], ['volume', 'rm.tab.vol'], ['aktif', 'rm.t.act'], ['baru', 'rm.t.new'], ['kegiatan', 'rm.t.keg']]
</script>

<template>
  <div>
    <header class="bar">
      <button class="back" :aria-label="t('common.back')" @click="back"><Icon name="back" :size="20" /></button>
      <div class="bar-t"><b>{{ t('rm.title') }}</b></div>
      <span class="back" style="visibility:hidden" />
    </header>

    <div class="wrap">
      <!-- filter -->
      <div class="filters">
        <div class="yr" role="group" :aria-label="t('rm.year')">
          <button :disabled="tahun <= 2017" aria-label="-" @click="tahun--"><Icon name="chevron" :size="18" style="transform:rotate(180deg)" /></button>
          <b>{{ tahun }}</b>
          <button :disabled="tahun >= NOW" aria-label="+" @click="tahun++"><Icon name="chevron" :size="18" /></button>
        </div>
        <div v-if="cabangTetap" class="sel fixed" :title="t('rm.myBranch')"><Icon name="lock" :size="16" /><span>{{ cabangs[0]?.nama || t('rm.myBranch') }}</span></div>
        <select v-else v-model="cabang" class="sel" :aria-label="t('rm.allBranch')">
          <option value="">{{ t('rm.allBranch') }}</option>
          <option v-for="c in cabangs" :key="c.id" :value="c.id">{{ c.nama }}</option>
        </select>
        <select v-model.number="mkt" class="sel" :aria-label="t('rm.allMkt')" @change="selM = -1">
          <option :value="0">{{ t('rm.allMkt') }}</option>
          <option v-for="m in mktOptions" :key="m.id" :value="m.id">{{ m.nama }}</option>
        </select>
      </div>

      <div class="chips" role="tablist">
        <button v-for="[k, l] in tabs" :key="k" class="chip" :class="{ on: tab === k }" role="tab" :aria-selected="tab === k" @click="tab = k">{{ t(l) }}</button>
      </div>

      <button v-if="mkt" class="mkt-chip" @click="mkt = 0">
        <Icon name="user" :size="16" /> {{ t('rm.filtered', { name: mktName }) }} <Icon name="x" :size="16" />
      </button>

      <StateBox v-if="tab !== 'sum' && errs[tab]" icon="alert" :title="t('common.failedLoad')" :text="errs[tab]" :action="t('common.retry')" @action="retry" />
      <div v-else-if="loadingAny && need.every((j) => !data[j])" class="stack">
        <div class="skel" style="height:96px" /><div class="skel" style="height:210px" /><div class="skel" style="height:160px" />
      </div>

      <!-- ===== RINGKASAN ===== -->
      <template v-else-if="tab === 'sum' && (V.volume || V.aktif || V.baru || V.kegiatan || failed.length)">
        <div v-for="j in failed" :key="'e' + j" class="card errc">
          <span><b>{{ t(META[j].title) }}</b><br>{{ errs[j] }}</span>
          <button class="btn btn-ghost" @click="retry">{{ t('common.retry') }}</button>
        </div>
        <StateBox v-if="sumEmpty" icon="chart" :title="t('rm.none')" :text="t('rm.noneText')" />
        <template v-else>
          <div class="cards3">
            <div v-for="j in JENIS.filter((x) => loading[x] && !data[x])" :key="'l' + j" class="skel" style="height:132px" />
            <button v-for="(c, i) in sumCards" :key="c.j" class="card sc rise" :style="{ '--i': i, '--c': c.color }" @click="tab = c.j">
              <span class="sc-h"><i class="dotc" /> {{ t(META[c.j].title) }}</span>
              <span class="sc-v">{{ c.big }}<small v-if="c.unit"> {{ c.unit }}</small></span>
              <span v-if="c.growth !== null" class="gp" :class="growthTone(c.growth)">{{ pctText(c.growth) }} <em>{{ t('rm.kpi.ytd') }}</em></span>
              <Spark :values="c.v.vals" :color="c.color" :upto="c.v.upto" />
            </button>
          </div>

          <div v-if="V.volume && V.aktif" class="card box rise" style="--i:3">
            <div class="ttl"><h3>{{ t('rm.sum.perCust') }}</h3></div>
            <div class="pc"><b>{{ perCust === null ? '–' : angka(perCust) }}<small> {{ t('rm.d.liter') }}</small></b><span>{{ t('rm.sum.perCustSub') }}</span></div>
          </div>

          <div v-if="V.volume" class="card box rise" style="--i:4">
            <div class="ttl"><h3>{{ t('rm.share') }}</h3><span class="tag" style="--c:#0a66e8">{{ t('rm.tab.vol') }}</span></div>
            <Donut :items="donutItems('volume')" :center="ringkas(V.volume.d.total)" caption="liter" />
          </div>

          <div v-if="V.volume" class="card box rise" style="--i:5">
            <div class="ttl"><h3>{{ t('rm.sum.leader') }}</h3></div>
            <p class="hint">{{ t('rm.sum.leaderSub') }}</p>
            <div class="lh"><span /><span>{{ t('rm.sum.col.vol') }}</span><span>{{ t('rm.sum.col.act') }}</span><span>{{ t('rm.sum.col.new') }}</span></div>
            <button v-for="x in leaderShown" :key="x.id" class="lrow" :class="{ on: mkt === x.id, dim: mkt && mkt !== x.id }" @click="pickMkt(x.id)">
              <span class="ln">{{ x.nama }}</span>
              <span class="lv"><i :style="{ width: (x.vol / leaderMax) * 100 + '%' }" /><b>{{ ringkas(x.vol) }}</b></span>
              <span class="lc">{{ x.aktif ? angka(Math.round(x.aktif)) : '–' }}</span>
              <span class="lc">{{ x.baru ? angka(x.baru) : '–' }}</span>
            </button>
            <button v-if="leader.length > 8" class="btn btn-ghost btn-block" style="margin-top:8px" @click="leaderAll = !leaderAll">{{ leaderAll ? t('rm.less') : t('rm.more', { n: leader.length }) }}</button>
          </div>
        </template>
      </template>

      <!-- ===== VOLUME / AKTIF / BARU ===== -->
      <template v-else-if="cur">
        <StateBox v-if="cur.empty" icon="chart" :title="t('rm.none')" :text="t('rm.noneText')" />
        <template v-else>
          <p class="sub">{{ t(META[tab].sub) }}</p>
          <div class="kpis">
            <div v-for="(k, i) in kpis(tab)" :key="k.k" class="card kpi rise" :class="k.tone" :style="{ '--i': i, '--c': META[tab].color }">
              <span class="kl">{{ k.label }}</span>
              <span class="kv">{{ k.val }}</span>
              <span v-if="k.sub" class="ks">{{ k.sub }}</span>
            </div>
          </div>

          <div class="card box rise" style="--i:4">
            <div class="ttl"><h3>{{ t('rm.trend') }}</h3></div>
            <MonthBars :values="cur.vals" :prev="cur.prev" :color="META[tab].color" :upto="cur.upto" :sel="selM" :label="t('rm.trend')" @pick="(i) => (selM = selM === i ? -1 : i)" />
            <p class="hint">{{ cur.prev ? t('rm.trendNote') : t('rm.trendNoteMkt') }}</p>
            <div v-if="selM >= 0" class="pick">
              <div class="pk-h">
                <b>{{ t('rm.picked', { m: bulanPanjang(selM), y: tahun }) }}</b>
                <span>{{ fmt(tab, cur.vals[selM]) }}<template v-if="cur.prev"> · {{ t('rm.lastYear', { v: fmt(tab, cur.prev[selM]) }) }}</template></span>
              </div>
              <template v-if="!mkt">
                <button v-for="x in topBulan(tab)" :key="x.id" class="pk-r" @click="openDetail(tab, x.id, selM)">
                  <span class="nm">{{ x.nama }}</span><b>{{ fmt(tab, x.value) }}</b><Icon name="chevron" :size="16" />
                </button>
              </template>
              <button v-else-if="cur.vals[selM] > 0" class="btn btn-ghost btn-block" @click="openDetail(tab, mkt, selM)">{{ t('rm.detail') }}</button>
            </div>
          </div>

          <div class="card box rise" style="--i:5">
            <div class="ttl"><h3>{{ t('rm.share') }}</h3></div>
            <Donut :items="donutItems(tab)" :center="ringkas(jumlah(cur.d.totals))" :caption="tab === 'volume' ? 'liter' : tab === 'aktif' ? 'akumulasi' : tab === 'kegiatan' ? 'kegiatan' : 'customer'" />
          </div>

          <div v-if="tab === 'kegiatan' && cur.d.extra" class="card box rise" style="--i:6">
            <div class="ttl"><h3>{{ t('rm.k.status') }}</h3><span class="tag" style="--c:#6d28d9">{{ t('rm.k.cust', { n: angka(cur.d.extra.customer) }) }}</span></div>
            <Donut :items="cur.d.extra.status.map((x) => ({ label: x.label, value: x.jumlah }))" :center="angka(jumlah(cur.d.totals))" caption="kegiatan" />
          </div>

          <div class="card box rise" style="--i:6">
            <div class="ttl"><h3>{{ t('rm.rank') }}</h3></div>
            <RankBars :items="cur.ranked.filter((x) => x.value > 0)" :color="META[tab].color" :sel="mkt" @pick="pickMkt" />
          </div>

          <div class="card box rise" style="--i:7">
            <div class="ttl"><h3>{{ t('rm.heat') }}</h3></div>
            <p class="hint" style="margin-top:0">{{ t('rm.heatNote') }}</p>
            <HeatGrid :rows="cur.d.rows" :color="META[tab].color" :sel="mkt" @pick="(p) => openDetail(tab, p.id, p.month)" />
          </div>

          <div v-if="tab === 'kegiatan'" id="kegList" class="card box rise" style="--i:8">
            <div class="ttl"><h3>{{ t('rm.k.list') }}</h3><span class="tag" style="--c:#6d28d9">{{ angka(kl.meta.total) }}</span></div>
            <div v-if="selM >= 0 || mkt" class="kfil">
              <button v-if="selM >= 0" class="kf" @click="selM = -1">{{ t('rm.k.month', { m: bulanPanjang(selM) }) }} <Icon name="x" :size="14" /></button>
              <button v-if="mkt" class="kf" @click="mkt = 0">{{ mktName }} <Icon name="x" :size="14" /></button>
            </div>
            <label class="ksearch"><Icon name="search" :size="18" /><input v-model="kl.q" type="search" :placeholder="t('rm.k.search')" :aria-label="t('rm.k.search')" /></label>
            <StateBox v-if="kl.error" icon="alert" :title="t('common.failedLoad')" :text="kl.error" :action="t('common.retry')" @action="loadKegiatan(1)" />
            <div v-else-if="kl.loading" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:78px" /></div>
            <StateBox v-else-if="!kl.items.length" icon="doc" :title="t('rm.k.none')" :text="t('rm.k.noneText')" />
            <template v-else>
              <button v-for="x in kl.items" :key="x.id" class="krow" @click="openKegiatan(x.id)">
                <span class="k-t"><b>{{ x.customer }}</b><span class="pill" :class="statusTone(x.status)">{{ x.status }}</span></span>
                <span class="k-a">{{ x.kegiatan }}</span>
                <span class="k-m">{{ tanggal(x.tanggal) }} · {{ x.marketing }}</span>
              </button>
              <button v-if="kl.meta.page < kl.meta.last_page" class="btn btn-ghost btn-block" :disabled="kl.more" @click="loadKegiatan(kl.meta.page + 1)">{{ kl.more ? t('common.loading') : t('common.loadMore') }}</button>
            </template>
          </div>

          <div v-if="insights(tab).length" class="card box rise ins" style="--i:8">
            <div class="ttl"><h3>{{ t('rm.insight') }}</h3></div>
            <ul>
              <li v-for="(x, i) in insights(tab)" :key="i" :class="x.tone"><i /><span>{{ x.text }}</span></li>
            </ul>
          </div>
        </template>
      </template>
    </div>

    <!-- ===== RINCIAN KEGIATAN ===== -->
    <div v-if="kd.open" class="sheet-back" @click.self="kd.open = false">
      <div class="sheet" role="dialog" aria-modal="true">
        <div class="grab" />
        <div class="sh-h">
          <div>
            <h2 class="sh-t">{{ kd.data?.customer || t('rm.k.detail') }}</h2>
            <p v-if="kd.data" class="sh-s">{{ kd.data.marketing }} · {{ tanggal(kd.data.tanggal) }}</p>
          </div>
          <button class="back" :aria-label="t('common.close')" @click="kd.open = false"><Icon name="x" :size="18" /></button>
        </div>
        <div v-if="kd.loading" class="stack"><div class="skel" style="height:70px" /><div class="skel" style="height:160px" /></div>
        <StateBox v-else-if="kd.error" icon="alert" :title="t('common.failedLoad')" :text="kd.error" />
        <template v-else-if="kd.data">
          <span class="pill" :class="statusTone(kd.data.status)">{{ kd.data.status }}</span>
          <dl class="kdl">
            <dt>{{ t('rm.k.activity') }}</dt><dd>{{ kd.data.kegiatan || '-' }}</dd>
            <dt>{{ t('rm.k.pic') }}</dt><dd>{{ kd.data.pic || '-' }}</dd>
            <dt>{{ t('rm.k.contact') }}</dt><dd>{{ [kd.data.telp, kd.data.email].filter(Boolean).join(' · ') || '-' }}</dd>
            <dt>{{ t('rm.k.address') }}</dt><dd class="rich" v-html="clean(kd.data.alamat) || '-'" />
          </dl>
          <h3 class="d-h">{{ t('rm.k.result') }}</h3>
          <div class="rich kres" v-html="clean(kd.data.hasil) || '-'" />
          <template v-if="kd.data.files.length">
            <h3 class="d-h">{{ t('rm.k.attach') }}</h3>
            <div v-for="f in kd.data.files" :key="f.id" class="d-r">
              <div class="d-r-t"><b>{{ f.nama }}</b><button type="button" class="btn btn-ghost lnk" @click="openFile(`/report/marketing/kegiatan/${kd.data.id}/berkas/${f.id}`, f.nama)">{{ t('rm.k.view') }}</button></div>
              <small v-if="f.keterangan">{{ f.keterangan }}</small>
            </div>
          </template>
          <h3 class="d-h">{{ t('rm.k.flow') }}</h3>
          <p v-if="!kd.data.tahap.length" class="hint">{{ t('rm.k.noFlow') }}</p>
          <div v-for="(x, i) in kd.data.tahap" :key="i" class="d-r">
            <div class="d-r-t"><b>{{ x.tahap }}</b><span>{{ x.tanggal ? tanggal(x.tanggal) : '' }}</span></div>
            <small>{{ [x.pic, x.catatan].filter(Boolean).join(' · ') }}</small>
          </div>
        </template>
      </div>
    </div>

    <!-- ===== RINCIAN ===== -->
    <div v-if="det.open" class="sheet-back" @click.self="det.open = false">
      <div class="sheet" role="dialog" aria-modal="true">
        <div class="grab" />
        <div class="sh-h">
          <div>
            <h2 class="sh-t">{{ det.data?.marketing || '…' }}</h2>
            <p class="sh-s">{{ t(META[det.jenis]?.title || 'rm.title') }} · {{ bulanPanjang(det.month) }} {{ tahun }}</p>
          </div>
          <button class="back" :aria-label="t('common.close')" @click="det.open = false"><Icon name="x" :size="18" /></button>
        </div>
        <div v-if="det.loading" class="stack"><div class="skel" style="height:70px" /><div class="skel" style="height:140px" /></div>
        <StateBox v-else-if="det.error" icon="alert" :title="t('common.failedLoad')" :text="det.error" />
        <template v-else-if="det.data">
          <div class="d-sum" :style="{ '--c': META[det.jenis].color }">
            <span>{{ t('rm.d.total') }}</span>
            <b>{{ det.jenis === 'volume' ? angka(det.data.total) + ' L' : det.jenis === 'aktif' ? t('rm.d.count', { n: det.data.data.length }) : t('rm.d.count', { n: det.data.total }) }}</b>
          </div>
          <p v-if="!det.data.data.length" class="hint">{{ t('rm.d.empty') }}</p>

          <template v-if="det.jenis === 'volume'">
            <h3 class="d-h">{{ t('rm.d.customers') }}</h3>
            <div v-for="c in det.data.customer" :key="c.id" class="d-c">
              <div class="d-c-t"><span>{{ c.nama }}</span><b>{{ angka(c.volume) }}</b></div>
              <span class="bar-t2"><i :style="{ width: (c.volume / detMax) * 100 + '%' }" /></span>
              <small>{{ t('rm.d.shipments', { n: c.kirim }) }}</small>
            </div>
            <h3 class="d-h">{{ t('rm.d.po') }}</h3>
            <div v-for="(x, i) in det.data.data" :key="i" class="d-r">
              <div class="d-r-t"><b>{{ x.customer }}</b><span>{{ angka(x.volume) }} L</span></div>
              <small>{{ x.nomor_poc }} · {{ t('rm.d.sendDate') }} {{ tanggal(x.tanggal_kirim) }}</small>
            </div>
          </template>

          <template v-else-if="det.jenis === 'aktif'">
            <div v-for="x in det.data.data" :key="x.id" class="d-r">
              <div class="d-r-t"><b>{{ x.customer }}</b><span>{{ angka(x.realisasi) }} L</span></div>
              <small>{{ x.provinsi }} · {{ x.cabang }}</small>
            </div>
          </template>

          <template v-else>
            <div v-for="x in det.data.data" :key="x.id" class="d-r">
              <div class="d-r-t">
                <b>{{ x.customer }}</b>
                <span class="pill" :class="x.tipe === 'baru' ? 'pill-ok' : 'pill-wait'">{{ x.tipe === 'baru' ? t('rm.d.newTag') : t('rm.d.backTag', { n: x.jeda_bulan }) }}</span>
              </div>
              <small>{{ tanggal(x.tanggal) }} · {{ x.cabang }}<template v-if="x.tipe === 'baru'"> · {{ t('rm.d.firstOrder') }}</template></small>
            </div>
          </template>
          <p v-if="det.data.data.length >= 500" class="hint">{{ t('rm.d.truncated') }}</p>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
.bar { position: sticky; top: 0; z-index: 10; display: flex; align-items: center; gap: 8px; padding: calc(var(--safe-top) + 8px) 12px 8px; background: var(--glass-bar); backdrop-filter: blur(12px); border-bottom: 1px solid var(--line); }
.bar-t { flex: 1; text-align: center; font-size: 14.5px; }
.back { width: 44px; height: 44px; border-radius: 13px; border: 1px solid var(--line); background: var(--card); color: var(--ink); display: grid; place-items: center; flex: none; }
.wrap { padding: 12px 16px calc(var(--safe-bottom) + 32px); display: grid; gap: 12px; min-width: 0; }
.stack { display: grid; gap: 12px; }

.filters { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 8px; align-items: center; }
.filters .sel:last-child { grid-column: 1 / -1; }
.yr { display: flex; align-items: center; gap: 2px; background: var(--card); border: 1px solid var(--line); border-radius: 13px; height: 44px; padding: 0 2px; }
.yr b { min-width: 44px; text-align: center; font-size: 15px; font-variant-numeric: tabular-nums; }
.yr button { width: 36px; height: 40px; display: grid; place-items: center; color: var(--accent); border-radius: 10px; border: 0; background: none; padding: 0; }
.yr button:disabled { opacity: 0.3; }
.sel.fixed { display: flex; align-items: center; gap: 8px; font-weight: 700; color: var(--ink-2); background: var(--blue-50); }
.sel.fixed span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sel { width: 100%; min-width: 0; height: 44px; padding: 0 10px; border-radius: 13px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 13.5px; font-family: inherit; }
.chips { padding-bottom: 2px; gap: 6px; }
.chip { padding: 0 10px; font-size: 13px; }
.mkt-chip { border: 0; font: inherit; display: flex; align-items: center; gap: 8px; min-height: 40px; padding: 0 14px; border-radius: 999px; background: var(--blue-50); color: var(--accent); font-weight: 700; font-size: 13px; justify-self: start; max-width: 100%; }
.sub { margin: 0 2px; font-size: 13px; color: var(--muted); }

.box { padding: 14px 16px; display: grid; grid-template-columns: minmax(0, 1fr); gap: 8px; min-width: 0; }
.ttl { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
h3 { margin: 0; font-size: 13px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); }
.hint { margin: 0; font-size: 12px; color: var(--muted); }
.tag { font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 999px; background: color-mix(in srgb, var(--c) 12%, var(--card)); color: color-mix(in srgb, var(--c) 82%, var(--ink)); }

/* kartu ringkasan */
.cards3 { display: grid; gap: 12px; }
.sc { font: inherit; color: inherit; text-align: left; display: grid; gap: 4px; padding: 14px 16px 10px; border-color: color-mix(in srgb, var(--c) 30%, var(--line)); overflow: hidden; position: relative; }
.errc { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 12px 14px; border-color: var(--red); font-size: 13px; }
.errc .btn { flex: none; min-height: 40px; padding: 0 14px; }
.sc-h { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; color: var(--muted); }
.dotc { width: 10px; height: 10px; border-radius: 3px; background: var(--c); }
.sc-v { font-size: 30px; font-weight: 800; letter-spacing: -0.02em; color: var(--ink); }
.sc-v small { font-size: 13px; font-weight: 600; color: var(--muted); letter-spacing: 0; margin-left: 6px; }
.gp { font-size: 12.5px; font-weight: 700; }
.gp em { font-style: normal; font-weight: 500; color: var(--muted); }
.gp.up { color: var(--green); } .gp.down { color: var(--red); } .gp.flat { color: var(--muted); }
.sc :deep(.sp) { margin-top: 4px; }
.pc { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; }
.pc b { font-size: 32px; font-weight: 800; letter-spacing: -0.02em; }
.pc b small { font-size: 13px; font-weight: 600; color: var(--muted); letter-spacing: 0; }
.pc span { color: var(--muted); font-size: 13px; }

/* KPI */
.kpis { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.kpi { display: grid; gap: 3px; padding: 12px 14px; border-left: 3px solid var(--c, var(--line)); min-width: 0; }
.kl { font-size: 11.5px; color: var(--muted); font-weight: 600; }
.kv { font-size: 22px; font-weight: 800; letter-spacing: -0.02em; overflow-wrap: anywhere; }
.ks { font-size: 11.5px; color: var(--muted); }
.kpi.up { border-left-color: var(--green); } .kpi.up .kv { color: var(--green); }
.kpi.down { border-left-color: var(--red); } .kpi.down .kv { color: var(--red); }
.kpi.flat { border-left-color: var(--line); }

/* bulan terpilih */
.pick { display: grid; gap: 4px; padding: 10px 12px; border-radius: 14px; background: var(--blue-50); }
.pk-h { display: flex; flex-direction: column; gap: 2px; margin-bottom: 4px; font-size: 13px; }
.pk-h span { color: var(--muted); font-size: 12px; }
.pk-r { border: 0; background: none; font: inherit; padding: 0; display: grid; grid-template-columns: minmax(0, 1fr) auto 16px; gap: 8px; align-items: center; min-height: 40px; text-align: left; font-size: 13px; border-top: 1px solid var(--line); color: var(--ink); }
.pk-r .nm { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; }

/* peringkat ringkasan */
.lh { display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(0, 1.3fr) 46px 40px; gap: 8px; font-size: 10.5px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.03em; padding: 4px 6px 0; }
.lrow { border: 0; background: none; font: inherit; color: inherit; display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(0, 1.3fr) 46px 40px; gap: 8px; align-items: center; min-height: 40px; padding: 4px 6px; border-radius: 10px; text-align: left; font-size: 12.5px; transition: background 0.15s, opacity 0.2s; }
.lrow.on { background: var(--blue-50); } .lrow.dim { opacity: 0.5; }
.ln { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; }
.lv { position: relative; height: 22px; border-radius: 7px; background: var(--line); overflow: hidden; display: flex; align-items: center; }
.lv i { position: absolute; inset: 0 auto 0 0; background: color-mix(in srgb, #0a66e8 38%, var(--card)); border-radius: 7px; transition: width 0.6s cubic-bezier(0.2, 0.9, 0.3, 1); }
.lv b { position: relative; padding-left: 8px; font-size: 11.5px; font-variant-numeric: tabular-nums; }
.lh span:nth-child(n+3) { text-align: right; }
.lc { text-align: right; font-variant-numeric: tabular-nums; color: var(--ink-2); font-weight: 600; }

/* insight */
.ins ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.ins li { display: grid; grid-template-columns: 10px minmax(0, 1fr); gap: 10px; font-size: 13.5px; line-height: 1.45; }
.ins li i { width: 10px; height: 10px; margin-top: 5px; border-radius: 50%; background: var(--muted); }
.ins li.up i { background: var(--green); } .ins li.down i { background: var(--red); }

/* rincian */
.sh-h { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 10px; }
.sh-t { margin: 0; font-size: 18px; } .sh-s { margin: 2px 0 0; font-size: 13px; color: var(--muted); }
.d-sum { display: flex; justify-content: space-between; align-items: center; padding: 12px 14px; border-radius: 14px; background: color-mix(in srgb, var(--c) 12%, var(--card)); margin-bottom: 8px; }
.d-sum span { color: var(--muted); font-weight: 600; font-size: 13px; } .d-sum b { font-size: 18px; color: color-mix(in srgb, var(--c) 80%, var(--ink)); }
.d-h { margin: 14px 0 6px; }
.d-c { display: grid; gap: 4px; padding: 8px 0; border-top: 1px solid var(--line); }
.d-c-t { display: flex; justify-content: space-between; gap: 10px; font-size: 13.5px; } .d-c-t span { overflow-wrap: anywhere; font-weight: 600; }
.bar-t2 { display: block; height: 6px; border-radius: 3px; background: var(--line); overflow: hidden; } .bar-t2 i { display: block; height: 100%; background: #0a66e8; border-radius: 3px; }
.d-c small, .d-r small { color: var(--muted); font-size: 12px; }
.d-r { display: grid; gap: 2px; padding: 9px 0; border-top: 1px solid var(--line); }
.d-r-t { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; font-size: 13.5px; } .d-r-t b { overflow-wrap: anywhere; }
.d-r-t span:not(.pill) { white-space: nowrap; font-weight: 700; font-variant-numeric: tabular-nums; }
@media (prefers-reduced-motion: reduce) { .lv i { transition: none; } }
/* kegiatan */
.kfil { display: flex; flex-wrap: wrap; gap: 6px; }
.kf { border: 0; font: inherit; display: inline-flex; align-items: center; gap: 6px; min-height: 34px; padding: 0 12px; border-radius: 999px; background: var(--blue-50); color: var(--accent); font-size: 12.5px; font-weight: 700; }
.ksearch { display: flex; align-items: center; gap: 10px; height: 44px; padding: 0 14px; border-radius: 13px; border: 1px solid var(--line); background: var(--card); color: var(--muted); }
.ksearch input { flex: 1; min-width: 0; border: 0; background: none; outline: none; font: inherit; font-size: 14px; color: var(--ink); }
.krow { border: 0; background: none; font: inherit; color: inherit; text-align: left; display: grid; gap: 3px; padding: 10px 0; border-top: 1px solid var(--line); min-width: 0; }
.k-t { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; font-size: 14px; } .k-t b { overflow-wrap: anywhere; } .k-t .pill { white-space: normal; text-align: center; flex: none; max-width: 46%; }
.k-a { font-size: 13px; color: var(--ink-2); overflow-wrap: anywhere; } .k-m { font-size: 12px; color: var(--muted); }
.kdl { display: grid; grid-template-columns: minmax(0, 32%) minmax(0, 1fr); gap: 6px 10px; margin: 10px 0 0; font-size: 13.5px; }
.kdl dt { color: var(--muted); } .kdl dd { margin: 0; font-weight: 600; overflow-wrap: anywhere; }
.kres { font-size: 13.5px; line-height: 1.5; overflow-wrap: anywhere; }
.rich :deep(p) { margin: 0 0 6px; } .rich :deep(ul), .rich :deep(ol) { margin: 0 0 6px; padding-left: 18px; }
.lnk { min-height: 32px; padding: 0 12px; font-size: 12px; flex: none; }
</style>
