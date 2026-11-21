<script setup>
import { locale, t } from '../i18n'
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import StateBox from '../components/StateBox.vue'
import DayArea from '../components/charts/DayArea.vue'
import DayBars from '../components/charts/DayBars.vue'
import DayHeat from '../components/charts/DayHeat.vue'
import Donut from '../components/charts/Donut.vue'
import RankBars from '../components/charts/RankBars.vue'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'
import { useAuth } from '../stores/auth'
import { showToast } from '../composables/useToast'
import { openFile } from '../utils/openFile'
import ZoomTable from '../components/ZoomTable.vue'
import { angka, tanggal } from '../utils/format'
import { bulanPanjang, jumlah, ringkas } from '../utils/chart'

const router = useRouter()
const auth = useAuth()
const NOW = new Date()
const bulan = ref(NOW.getMonth() + 1)
const tahun = ref(NOW.getFullYear())
const cabang = ref('nasional')
const cabangs = ref([])
const data = ref(null)
const loading = ref(true)
const error = ref('')
const sel = ref(0) // indeks hari terpilih (0-based)
const metric = ref('stok')
const tableOpen = ref(false)
let seq = 0

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
async function load() {
  const my = ++seq
  loading.value = true
  error.value = ''
  for (let attempt = 0; attempt < 2; attempt++) {
    try {
      const r = await syop.reportRssp({ cabang: cabang.value, bulan: bulan.value, tahun: tahun.value })
      if (my !== seq) return
      data.value = r
      sel.value = r.hari_ini > 0 ? r.hari_ini - 1 : r.tgl_actual > 0 ? Math.min(r.tgl_actual, r.jumlah_hari) - 1 : 0
      loading.value = false
      return
    } catch (e) {
      const st = e.response?.status
      if (attempt === 0 && (!st || st >= 500) && st !== 403) { await sleep(1500); if (my !== seq) return; continue }
      if (my === seq) { error.value = errorMessage(e); loading.value = false }
      return
    }
  }
}
watch([cabang, bulan, tahun], load)
onMounted(() => {
  syop.reportRsspCabang().then((r) => (cabangs.value = r)).catch(() => {})
  load()
})

/* ---------- navigasi bulan ---------- */
const nextLimit = computed(() => NOW.getFullYear() * 12 + NOW.getMonth() + 1) // sampai bulan depan
const idx = computed(() => tahun.value * 12 + bulan.value - 1)
function geser(d) {
  const v = idx.value + d
  if (v < 2022 * 12 || v > nextLimit.value) return
  tahun.value = Math.floor(v / 12)
  bulan.value = (v % 12) + 1
}

/* ---------- turunan ---------- */
const n = computed(() => data.value?.jumlah_hari || 0)
const upto = computed(() => data.value?.tgl_actual || 0)
const weekend = computed(() => (data.value?.hari || []).map((h) => h.weekend))
const nasional = computed(() => data.value?.cabang === 'nasional')
const sum = (key, i) => (data.value?.depots || []).reduce((s, d) => s + (d[key][i] || 0), 0)
const dayLabel = (i) => `${i + 1} ${bulanPanjang(bulan.value - 1)}`
const isActual = (i) => i < upto.value

const hero = computed(() => {
  const d = data.value
  if (!d || !d.depots.length) return null
  const i = sel.value
  return {
    stok: d.stock_akhir_total[i], awal: sum('stock_awal', i), supply: sum('supply', i), sales: sum('sales', i), loss: sum('loss', i), transfer: sum('transfer', i),
    actual: isActual(i), tip: d.hari[i]?.tip || '',
  }
})

const kpi = computed(() => {
  const d = data.value
  if (!d || !d.depots.length) return null
  const sa = jumlah(d.total_sales.slice(0, upto.value))
  const fc = jumlah(d.forecast.slice(0, upto.value))
  const avg = upto.value > 0 ? sa / upto.value : 0
  const stok = d.stock_akhir_total[sel.value]
  return { sa, fc, attain: fc > 0 ? sa / fc : null, avg, cover: avg > 0 && stok > 0 ? stok / avg : null }
})

const mix = computed(() => {
  const d = data.value
  if (!d) return { pos: [], neg: [] }
  const all = d.depots.map((x) => ({ id: x.id, nama: x.nama, value: x.stock_akhir[sel.value] }))
  return { pos: all.filter((x) => x.value > 0).sort((a, b) => b.value - a.value), neg: all.filter((x) => x.value < 0).sort((a, b) => a.value - b.value) }
})
const donutItems = computed(() => {
  const top = mix.value.pos.slice(0, 5).map((x) => ({ label: x.nama, value: x.value }))
  const rest = mix.value.pos.slice(5).reduce((s, x) => s + x.value, 0)
  return rest > 0 ? [...top, { label: t('rm.others'), value: rest }] : top
})

const heatRows = computed(() => (data.value?.depots || []).map((x) => ({ id: x.id, nama: x.nama, vals: x[{ stok: 'stock_akhir', sales: 'sales', supply: 'supply' }[metric.value]] })))
const heatColor = computed(() => ({ stok: '#0a66e8', sales: '#e07400', supply: '#0f8b8d' }[metric.value]))

const insights = computed(() => {
  const d = data.value
  const k = kpi.value
  if (!d || !k) return []
  const out = []
  if (k.attain !== null) out.push({ tone: k.attain >= 0.95 ? 'up' : k.attain < 0.8 ? 'down' : 'flat', text: t('rs.ins.attain', { p: (k.attain * 100).toFixed(0), d: upto.value }) })
  const st = d.stock_akhir_total
  let lo = 0
  st.forEach((v, i) => { if (v < st[lo]) lo = i })
  out.push({ tone: st[lo] < 0 ? 'down' : 'flat', text: t('rs.ins.low', { v: ringkas(st[lo]), d: dayLabel(lo) }) })
  if (mix.value.neg.length) out.push({ tone: 'down', text: t('rs.ins.minus', { n: mix.value.neg.length, d: dayLabel(sel.value), names: mix.value.neg.slice(0, 3).map((x) => x.nama).join(', ') }) })
  const ts = d.total_sales
  let pk = 0
  ts.forEach((v, i) => { if (v > ts[pk]) pk = i })
  if (ts[pk] > 0) out.push({ tone: 'up', text: t('rs.ins.peak', { v: ringkas(ts[pk]), d: dayLabel(pk) }) })
  if (k.cover !== null) out.push({ tone: k.cover < 7 ? 'down' : 'flat', text: t('rs.ins.cover', { n: k.cover.toFixed(1).replace(/\.0$/, '') }) })
  const fcSup = jumlah(d.depots.flatMap((x) => x.supply.map((v, i) => (x.supply_fc[i] ? v : 0))))
  if (fcSup > 0) out.push({ tone: 'flat', text: t('rs.ins.supplyFc', { v: ringkas(fcSup) }) })
  if (mix.value.pos.length > 1) {
    const tot = mix.value.pos.reduce((s, x) => s + x.value, 0)
    out.push({ tone: 'flat', text: t('rs.ins.top', { name: mix.value.pos[0].nama, p: ((mix.value.pos[0].value / tot) * 100).toFixed(0) }) })
  }
  return out
})

/* ---------- strip hari ---------- */
const strip = ref(null)
watch([sel, data], async () => {
  await nextTick()
  strip.value?.querySelector('.dy.on')?.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'smooth' })
})

/* ---------- tabel lengkap (seperti web) ---------- */
const fmtCell = (v) => (!v ? '-' : angka(v, 1))
const fmtLoss = (v) => (!v ? '-' : v < 0 ? `(${angka(Math.abs(v), 1)})` : `+${angka(v, 1)}`)
const table = computed(() => {
  const d = data.value
  if (!d) return null
  const scope = nasional.value ? 'cabang' : 'terminal'
  const cols = []
  for (let i = 0; i < d.jumlah_hari; i++) {
    cols.push({ k: 'day', i })
    if (i === 13) cols.push({ k: 'tot', p: 1 })
  }
  cols.push({ k: 'tot', p: 2 })
  const mk = (key, label, vals, o = {}) => ({ key, label, vals, ...o })
  const sections = [
    { title: t('rs.t.stockAwal'), rows: d.depots.flatMap((x) => [mk('sa' + x.id, x.nama, x.stock_awal), mk('ls' + x.id, t('rs.t.losses', { n: x.nama }), x.loss, { cls: 'loss', fmt: fmtLoss, type: 'loss', tid: x.id })]) },
    { title: t('rs.t.supply'), rows: d.depots.map((x) => mk('su' + x.id, x.nama, x.supply, { type: 'supply', tid: x.id, fc: x.supply_fc, edit: 'supply' })) },
    { title: t('rs.t.transfer'), rows: d.depots.map((x) => mk('tf' + x.id, x.nama, x.transfer, { type: 'transfer', tid: x.id })) },
    { title: t('rs.t.sales'), rows: [mk('fc', t('rs.t.forecast'), d.forecast, { cls: 'fcst', total: true, type: 'forecast', tid: nasional.value ? '' : d.cabang }), ...d.depots.map((x) => mk('pl' + x.id, x.nama, x.plan, { cls: 'plan', edit: 'plan', tid: x.id })), ...d.depots.map((x) => mk('sl' + x.id, x.nama, x.sales, { type: 'sales', tid: x.id }))] },
  ]
  const totals = [
    mk('ts', t('rs.t.totalSales'), d.total_sales, { cls: 'tsales', total: true, actual: true }),
    mk('sk', t('rs.t.stockAkhir'), d.stock_akhir_total, { cls: 'sakhir' }),
    ...d.depots.map((x) => mk('ska' + x.id, t('rs.t.stockAkhirOf', { n: x.nama }), x.stock_akhir, { cls: 'sakhirsub' })),
  ]
  return { cols, sections, totals, scope }
})
const periodeSum = (vals, p) => jumlah(vals.slice(p === 1 ? 0 : 14, p === 1 ? 14 : undefined))
function cellClass(row, col) {
  const c = []
  if (col.k === 'tot') return 'tt'
  if (weekend.value[col.i]) c.push('we')
  if (row.actual && col.i < upto.value) c.push('act')
  if (row.fc?.[col.i]) c.push('fcsup')
  if (row.type && (row.vals[col.i] || 0) !== 0 && !row.fc?.[col.i]) c.push('click')
  if (canInput.value && row.edit && !(row.edit === 'supply' && (row.vals[col.i] || 0) > 0 && !row.fc?.[col.i])) c.push('ed')
  return c.join(' ')
}

/* ---------- rincian ---------- */
const det = reactive({ open: false, mode: 'summary', title: '', sub: '', loading: false, error: '', data: null, ctx: null })
function bukaRingkas(depotId, i) {
  const x = data.value.depots.find((z) => z.id === depotId)
  const ymd = data.value.hari[i].ymd
  Object.assign(det, { open: true, mode: 'summary', title: x.nama, sub: `${dayLabel(i)} ${tahun.value}`, loading: false, error: '', data: null, ctx: { depot: x, i, ymd } })
}
async function bukaDetail(type, tid, ymd, title, fromSummary = false) {
  const scope = nasional.value ? 'cabang' : 'terminal'
  Object.assign(det, { open: true, mode: 'detail', title: t('rs.dt.' + type), sub: title, loading: true, error: '', data: null, ctx: fromSummary ? det.ctx : null })
  try {
    det.data = await syop.reportRsspDetail({ type, tid, tanggal: ymd, scope })
  } catch (e) { det.error = errorMessage(e) } finally { det.loading = false }
}
function klikSel(row, col) {
  if (col.k !== 'day') return
  const ymd = data.value.hari[col.i].ymd
  if (canInput.value && row.edit && !(row.edit === 'supply' && (row.vals[col.i] || 0) > 0 && !row.fc?.[col.i])) return bukaInput(row.edit, row.tid, ymd, `${row.label} · ${dayLabel(col.i)}`)
  if (!row.type) return
  const v = row.vals[col.i] || 0
  if (v === 0 || row.fc?.[col.i]) return
  bukaDetail(row.type, row.tid, data.value.hari[col.i].ymd, `${row.label} · ${dayLabel(col.i)}`)
}
function dariRingkas(type) {
  const c = det.ctx
  bukaDetail(type, c.depot.id, c.ymd, `${c.depot.nama} · ${dayLabel(c.i)}`, true)
}
const ringkasRows = computed(() => {
  const c = det.ctx
  if (!c) return []
  const { depot: x, i } = c
  return [
    { k: 'open', label: t('rs.d.open'), v: x.stock_awal[i] },
    { k: 'supply', label: x.supply_fc[i] ? t('rs.d.supplyFc') : t('rs.d.supply'), v: x.supply[i], type: x.supply_fc[i] ? '' : 'supply', fmt: fmtCell },
    { k: 'sales', label: t('rs.d.sales'), v: x.sales[i], type: 'sales' },
    { k: 'loss', label: t('rs.d.loss'), v: x.loss[i], type: 'loss', fmt: fmtLoss },
    { k: 'transfer', label: t('rs.d.transfer'), v: x.transfer[i], type: 'transfer' },
    { k: 'plan', label: t('rs.d.plan'), v: x.plan[i] },
    { k: 'close', label: t('rs.d.close'), v: x.stock_akhir[i], strong: true },
  ].filter((r) => r.k === 'open' || r.k === 'close' || r.v !== 0)
})

/* ---------- unduh Excel ---------- */
const xlsBusy = ref(false)
async function unduhExcel() {
  if (xlsBusy.value) return
  xlsBusy.value = true
  try { await openFile(`/report/rssp/export?cabang=${encodeURIComponent(cabang.value)}&bulan=${bulan.value}&tahun=${tahun.value}`, 'RSSP-Fuel.xlsx') } finally { xlsBusy.value = false }
}

/* ---------- input Purchasing: Forecast Supply dan alokasi Forecast Sales (hanya per terminal, bukan mode semua cabang) ---------- */
const canInput = computed(() => Array.isArray(auth.user?.modules) && auth.user.modules.includes('rssp_input') && !nasional.value)
const inp = reactive({ jenis: '', tid: 0, ymd: '', label: '', loading: false, busy: false, error: '', info: null, qty: '', ket: '' })
async function bukaInput(jenis, tid, ymd, label, fromSummary = false) {
  Object.assign(det, { open: true, mode: 'input', title: t(jenis === 'supply' ? 'rs.in.supplyTitle' : 'rs.in.planTitle'), sub: label, loading: false, error: '', data: null, ctx: fromSummary ? det.ctx : null })
  Object.assign(inp, { jenis, tid, ymd, label, loading: true, busy: false, error: '', info: null, qty: '', ket: '' })
  try {
    const r = await syop.rsspInputShow({ jenis, tid, tanggal: ymd })
    inp.info = r
    inp.qty = r.qty != null ? String(r.qty) : ''
    inp.ket = r.keterangan || ''
  } catch (e) { inp.error = errorMessage(e) } finally { inp.loading = false }
}
const inpBlocked = computed(() => !inp.info || inp.info.terkunci || (inp.jenis === 'plan' && !inp.info.disetujui))
async function simpanInput() {
  const v = String(inp.qty).trim().replace(',', '.')
  if (v === '' || isNaN(v) || parseFloat(v) < 0) return (inp.error = t('rs.in.invalid'))
  inp.busy = true
  inp.error = ''
  try {
    const r = await syop.rsspInputSave({ jenis: inp.jenis, tid: inp.tid, tanggal: inp.ymd, qty: parseFloat(v), keterangan: inp.ket.trim() || undefined })
    showToast(r.message === 'Tersimpan.' ? t('rs.in.saved') : r.message, 'ok')
    det.open = false
    load()
  } catch (e) { inp.error = errorMessage(e) } finally { inp.busy = false }
}
async function hapusInput() {
  inp.busy = true
  inp.error = ''
  try {
    await syop.rsspInputDelete({ jenis: inp.jenis, tid: inp.tid, tanggal: inp.ymd })
    showToast(t('rs.in.deleted'), 'ok')
    det.open = false
    load()
  } catch (e) { inp.error = errorMessage(e) } finally { inp.busy = false }
}
const sudahActual = (x, i) => x.supply[i] > 0 && !x.supply_fc[i]

const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'report' }))
</script>

<template>
  <div>
    <header class="bar">
      <button class="back" :aria-label="t('common.back')" @click="back"><Icon name="back" :size="20" /></button>
      <div class="bar-t"><b>{{ t('rs.title') }}</b></div>
      <button class="xl" :disabled="xlsBusy || !data" :aria-label="t('rs.xls')" @click="unduhExcel"><Icon name="doc" :size="16" />{{ xlsBusy ? '…' : t('rs.xls') }}</button>
    </header>

    <div class="wrap">
      <div class="filters">
        <div class="mo" role="group">
          <button :aria-label="t('rs.prevMonth')" :disabled="idx <= 2022 * 12" @click="geser(-1)"><Icon name="chevron" :size="18" style="transform:rotate(180deg)" /></button>
          <b>{{ bulanPanjang(bulan - 1) }} {{ tahun }}</b>
          <button :aria-label="t('rs.nextMonth')" :disabled="idx >= nextLimit" @click="geser(1)"><Icon name="chevron" :size="18" /></button>
        </div>
        <select v-model="cabang" class="sel" :aria-label="t('rs.nasional')">
          <option value="nasional">{{ t('rs.nasional') }}</option>
          <option v-for="c in cabangs" :key="c.id" :value="String(c.id)">{{ c.nama }}</option>
        </select>
      </div>

      <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load" />
      <div v-else-if="loading && !data" class="stack"><div class="skel" style="height:190px" /><div class="skel" style="height:96px" /><div class="skel" style="height:210px" /></div>

      <template v-else-if="data">
        <StateBox v-if="!data.depots.length" icon="drop" :title="t('rs.none')" :text="t('rs.noneText')" />
        <template v-else>
          <div class="dim-wrap" :class="{ dim: loading }">
            <!-- strip hari -->
            <div ref="strip" class="strip" role="tablist">
              <button v-for="(h, i) in data.hari" :key="h.d" class="dy" :class="{ on: sel === i, we: h.weekend, hi: i + 1 === data.hari_ini }" role="tab" :aria-selected="sel === i" :title="h.tip" @click="sel = i">
                <small>{{ new Date(tahun, bulan - 1, h.d).toLocaleDateString(locale, { weekday: 'short' }).slice(0, 3) }}</small><b>{{ h.d }}</b>
              </button>
            </div>

            <!-- hero -->
            <section v-if="hero" class="hero">
              <span class="orb" />
              <div class="h-top"><span>{{ t('rs.hero.stock') }}</span><em>{{ dayLabel(sel) }} · {{ hero.actual ? t('rs.hero.actual') : t('rs.hero.proj') }}</em></div>
              <div class="h-big" :class="{ neg: hero.stok < 0 }">{{ angka(hero.stok) }}<small> L</small></div>
              <p v-if="hero.tip" class="h-tip">{{ hero.tip }}</p>
              <div class="h-grid">
                <div><span>{{ t('rs.hero.open') }}</span><b>{{ ringkas(hero.awal) }}</b></div>
                <div class="pl"><span>{{ t('rs.hero.supply') }}</span><b>+{{ ringkas(hero.supply) }}</b></div>
                <div class="mi"><span>{{ t('rs.hero.sales') }}</span><b>−{{ ringkas(hero.sales) }}</b></div>
                <div><span>{{ t('rs.hero.loss') }} / TF</span><b>{{ ringkas(hero.loss + hero.transfer) }}</b></div>
              </div>
            </section>

            <!-- KPI -->
            <div v-if="kpi" class="kpis">
              <div class="card kpi rise" style="--i:0;--c:#e07400"><span class="kl">{{ t('rs.kpi.sales') }}</span><span class="kv">{{ ringkas(kpi.sa) }}</span><span class="ks">{{ t('rs.kpi.salesSub', { d: upto }) }}</span></div>
              <div class="card kpi rise" :class="kpi.attain === null ? '' : kpi.attain >= 0.95 ? 'up' : kpi.attain < 0.8 ? 'down' : ''" style="--i:1;--c:#0b7a4b">
                <span class="kl">{{ t('rs.kpi.attain') }}</span>
                <span class="kv">{{ kpi.attain === null ? '–' : (kpi.attain * 100).toFixed(0) + '%' }}</span>
                <span class="ks">{{ kpi.attain === null ? t('rs.kpi.noForecast') : t('rs.kpi.attainSub', { v: ringkas(kpi.fc) }) }}</span>
              </div>
              <div class="card kpi rise" style="--i:2;--c:#0a66e8"><span class="kl">{{ t('rs.kpi.avg') }}</span><span class="kv">{{ ringkas(kpi.avg) }}</span><span class="ks">liter</span></div>
              <div class="card kpi rise" :class="kpi.cover !== null && kpi.cover < 7 ? 'down' : ''" style="--i:3;--c:#0f8b8d">
                <span class="kl">{{ t('rs.kpi.cover') }}</span>
                <span class="kv">{{ kpi.cover === null ? '–' : t('rs.kpi.days', { n: kpi.cover.toFixed(1).replace(/\.0$/, '') }) }}</span>
                <span class="ks">{{ t('rs.kpi.coverSub') }}</span>
              </div>
            </div>

            <div class="card box rise" style="--i:4">
              <div class="ttl"><h3>{{ t('rs.c.stock') }}</h3></div>
              <DayArea :values="data.stock_akhir_total" :weekend="weekend" :upto="upto" :sel="sel" color="#0a66e8" :label="t('rs.c.stock')" @pick="(i) => (sel = i)" />
              <p class="hint">{{ t('rs.c.stockNote') }}</p>
            </div>

            <div class="card box rise" style="--i:5">
              <div class="ttl"><h3>{{ t('rs.c.sales') }}</h3></div>
              <DayBars :values="data.total_sales" :forecast="data.forecast" :weekend="weekend" :upto="upto" :sel="sel" :label="t('rs.c.sales')" @pick="(i) => (sel = i)" />
              <p class="hint">{{ t('rs.c.salesNote') }}</p>
            </div>

            <div class="card box rise" style="--i:6">
              <div class="ttl"><h3>{{ nasional ? t('rs.c.mixCab') : t('rs.c.mixTerm') }}</h3><span class="tag" style="--c:#0a66e8">{{ t('rs.c.mixNote', { d: dayLabel(sel) }) }}</span></div>
              <Donut v-if="donutItems.length" :items="donutItems" :center="ringkas(jumlah(mix.pos.map((x) => x.value)))" caption="liter" />
              <RankBars v-if="mix.pos.length" :items="mix.pos" color="#0a66e8" :limit="6" />
              <div v-if="mix.neg.length" class="neg-box">
                <b>{{ t('rs.c.minus') }}</b>
                <div v-for="x in mix.neg" :key="x.id" class="neg-r"><span>{{ x.nama }}</span><b>{{ angka(x.value) }}</b></div>
              </div>
            </div>

            <div class="card box rise" style="--i:7">
              <div class="ttl"><h3>{{ t('rs.c.heat') }}</h3></div>
              <div class="chips sm" role="tablist">
                <button v-for="[k, l] in [['stok', 'rs.m.stock'], ['sales', 'rs.m.sales'], ['supply', 'rs.m.supply']]" :key="k" class="chip" :class="{ on: metric === k }" role="tab" :aria-selected="metric === k" @click="metric = k">{{ t(l) }}</button>
              </div>
              <p class="hint" style="margin-top:0">{{ t('rs.c.heatNote') }}</p>
              <DayHeat :rows="heatRows" :weekend="weekend" :color="heatColor" :sel="sel" @pick="(p) => { sel = p.i; bukaRingkas(p.id, p.i) }" />
            </div>

            <div v-if="insights.length" class="card box rise ins" style="--i:8">
              <div class="ttl"><h3>{{ t('rs.ins') }}</h3></div>
              <ul><li v-for="(x, i) in insights" :key="i" :class="x.tone"><i /><span>{{ x.text }}</span></li></ul>
            </div>

            <div class="card box rise" style="--i:9">
              <div class="ttl"><h3>{{ t('rs.t.title') }}</h3></div>
              <button class="btn btn-ghost btn-block" @click="tableOpen = !tableOpen">{{ tableOpen ? t('rs.t.hide') : t('rs.t.show') }}</button>
              <template v-if="tableOpen && table">
                <p class="hint">{{ t('rs.t.legend') }}</p>
                <p v-if="canInput" class="hint in-hint">{{ t('rs.in.hint') }}</p>
                <ZoomTable>
                  <table class="rt">
                    <thead>
                      <tr>
                        <th class="lbl" />
                        <th v-for="(c, k) in table.cols" :key="k" :class="c.k === 'tot' ? 'tt' : weekend[c.i] ? 'we' : ''">{{ c.k === 'tot' ? t('rs.t.p' + c.p) : c.i + 1 }}</th>
                      </tr>
                    </thead>
                    <tbody>
                      <template v-for="sec in table.sections" :key="sec.title">
                        <tr class="sec"><td class="lbl"><b>{{ sec.title }}</b></td><td :colspan="table.cols.length" /></tr>
                        <tr v-for="row in sec.rows" :key="row.key" :class="row.cls">
                          <td class="lbl">{{ row.label }}</td>
                          <td v-for="(c, k) in table.cols" :key="k" :class="cellClass(row, c)" @click="klikSel(row, c)">
                            <template v-if="c.k === 'tot'">{{ row.total ? angka(periodeSum(row.vals, c.p), 1) : '' }}</template>
                            <template v-else>{{ (row.fmt || fmtCell)(row.vals[c.i]) }}</template>
                          </td>
                        </tr>
                      </template>
                      <tr v-for="row in table.totals" :key="row.key" :class="row.cls">
                        <td class="lbl"><b>{{ row.label }}</b></td>
                        <td v-for="(c, k) in table.cols" :key="k" :class="cellClass(row, c)">
                          <template v-if="c.k === 'tot'">{{ row.total ? angka(periodeSum(row.vals, c.p), 1) : '' }}</template>
                          <template v-else>{{ fmtCell(row.vals[c.i]) }}</template>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </ZoomTable>
              </template>
            </div>
          </div>
        </template>
      </template>
    </div>

    <!-- ===== RINCIAN ===== -->
    <div v-if="det.open" class="sheet-back" @click.self="det.open = false">
      <div class="sheet" role="dialog" aria-modal="true">
        <div class="grab" />
        <div class="sh-h">
          <button v-if="(det.mode === 'detail' || det.mode === 'input') && det.ctx" class="back" :aria-label="t('common.back')" @click="det.mode = 'summary'"><Icon name="back" :size="18" /></button>
          <div class="sh-w">
            <h2 class="sh-t">{{ det.title }}</h2>
            <p class="sh-s">{{ det.sub }}</p>
          </div>
          <button class="back" :aria-label="t('common.close')" @click="det.open = false"><Icon name="x" :size="18" /></button>
        </div>

        <template v-if="det.mode === 'summary'">
          <div v-for="r in ringkasRows" :key="r.k" class="srow" :class="{ strong: r.strong }">
            <span>{{ r.label }}</span>
            <b>{{ (r.fmt || ((v) => angka(v, 1)))(r.v) }} L</b>
            <button v-if="r.type" class="btn btn-ghost lnk" @click="dariRingkas(r.type)">{{ t('rm.k.view') }}</button>
          </div>
          <div v-if="canInput && det.ctx" class="in-cta">
            <button v-if="!sudahActual(det.ctx.depot, det.ctx.i)" class="btn btn-ghost" @click="bukaInput('supply', det.ctx.depot.id, det.ctx.ymd, `${det.ctx.depot.nama} · ${dayLabel(det.ctx.i)}`, true)">{{ t('rs.in.ctaSupply') }}</button>
            <button class="btn btn-ghost" @click="bukaInput('plan', det.ctx.depot.id, det.ctx.ymd, `${det.ctx.depot.nama} · ${dayLabel(det.ctx.i)}`, true)">{{ t('rs.in.ctaPlan') }}</button>
          </div>
        </template>

        <template v-else-if="det.mode === 'input'">
          <div v-if="inp.loading" class="stack"><div class="skel" style="height:90px" /><div class="skel" style="height:120px" /></div>
          <template v-else>
            <p v-if="inp.info" class="in-t">{{ inp.info.terminal.nama }} · {{ inp.info.terminal.cabang }}</p>
            <div v-if="inp.info && inp.jenis === 'plan'" class="in-box">
              <div><span>{{ t('rs.in.total') }}</span><b>{{ angka(inp.info.total_forecast, 1) }} L</b></div>
              <div><span>{{ t('rs.in.other') }}</span><b>{{ angka(inp.info.dialokasi_lain, 1) }} L</b></div>
              <div class="hi"><span>{{ t('rs.in.left') }}</span><b>{{ angka(inp.info.sisa, 1) }} L</b></div>
            </div>
            <p v-if="inp.info && inp.jenis === 'plan' && !inp.info.disetujui" class="in-warn"><Icon name="alert" :size="16" /> {{ t('rs.in.notApproved', { p: inp.info.periode }) }}</p>
            <p v-else-if="inp.info && inp.info.terkunci" class="in-warn"><Icon name="alert" :size="16" /> {{ t('rs.in.locked') }}</p>
            <template v-if="inp.info">
              <div class="field"><label for="iq">{{ t('rs.in.qty') }}</label><input id="iq" v-model="inp.qty" class="in-i" inputmode="decimal" :disabled="inpBlocked" placeholder="0" /></div>
              <div class="field"><label for="ik">{{ t('rs.in.note') }}</label><textarea id="ik" v-model="inp.ket" class="plain" maxlength="255" :disabled="inpBlocked" /></div>
            </template>
            <p v-if="inp.error" class="err-text" role="alert" style="margin-bottom:10px">{{ inp.error }}</p>
            <div v-if="inp.info" class="in-act">
              <button v-if="inp.info.ada" class="btn btn-ghost bad" :disabled="inp.busy" @click="hapusInput">{{ t('rs.in.delete') }}</button>
              <button class="btn btn-primary" :disabled="inp.busy || inpBlocked" @click="simpanInput">{{ inp.busy ? t('det.processing') : t('rs.in.save') }}</button>
            </div>
          </template>
        </template>

        <template v-else>
          <div v-if="det.loading" class="stack"><div class="skel" style="height:70px" /><div class="skel" style="height:120px" /></div>
          <StateBox v-else-if="det.error" icon="alert" :title="t('common.failedLoad')" :text="det.error" />
          <template v-else-if="det.data">
            <p v-if="!(det.data.po?.length || det.data.truck?.length || det.data.data?.length)" class="hint">{{ t('rs.d.none') }}</p>

            <template v-if="det.data.type === 'supply'">
              <div v-for="(x, i) in det.data.po" :key="'p' + i" class="d-r">
                <div class="d-r-t"><b>{{ x.nomor_po || '-' }}</b><span>{{ angka(x.volume, 1) }} L</span></div>
                <small>{{ t('rs.d.receive') }} {{ x.nomor_receive || '-' }} · {{ t('rs.d.received') }} {{ x.tgl_terima ? tanggal(x.tgl_terima) : '-' }}</small>
              </div>
              <div v-for="(x, i) in det.data.truck" :key="'t' + i" class="d-r plus">
                <div class="d-r-t"><b>{{ t('rs.d.truck') }} #{{ i + 1 }}</b><span>+{{ angka(x.volume, 1) }} L</span></div>
                <small>{{ t('rs.d.docNo') }}: {{ x.nomor || x.id_accurate || '-' }} · {{ t('rs.d.itemCode') }}: {{ x.kode_item || '-' }}</small>
                <small v-if="x.keterangan || x.pic">{{ [x.keterangan, x.pic].filter(Boolean).join(' · ') }}</small>
              </div>
            </template>

            <template v-else-if="det.data.type === 'sales'">
              <div v-for="(x, i) in det.data.data" :key="i" class="d-r">
                <div class="d-r-t"><b>{{ x.customer || '-' }}</b><span>{{ angka(x.volume, 1) }} L</span></div>
                <small>{{ t('rs.d.dr') }} {{ x.nomor_dr || '-' }} · {{ t('rs.d.do') }} {{ x.nomor_do || '-' }} · {{ t('rs.d.lo') }} {{ x.nomor_lo || '-' }}</small>
              </div>
            </template>

            <template v-else-if="det.data.type === 'forecast'">
              <div v-for="(x, i) in det.data.data" :key="i" class="d-r"><div class="d-r-t"><b>{{ x.customer }}</b><span>{{ angka(x.volume, 1) }} L</span></div></div>
            </template>

            <template v-else>
              <div v-for="(x, i) in det.data.data" :key="i" class="d-r" :class="x.volume < 0 ? 'minus' : 'plus'">
                <div class="d-r-t"><b>{{ x.jenis }}</b><span>{{ x.volume < 0 ? `(${angka(Math.abs(x.volume), 1)})` : '+' + angka(x.volume, 1) }} L</span></div>
                <small>{{ t('rs.d.docNo') }}: {{ x.nomor || x.id_accurate || '-' }} · {{ t('rs.d.itemCode') }}: {{ x.kode_item || '-' }}</small>
                <small v-if="x.keterangan || x.pic">{{ [x.keterangan, x.pic].filter(Boolean).join(' · ') }}</small>
              </div>
            </template>

            <div v-if="det.data.total" class="d-sum"><span>{{ t('rs.d.total') }}</span><b>{{ angka(det.data.total, 1) }} L</b></div>
          </template>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
.bar { position: sticky; top: 0; z-index: 10; display: flex; align-items: center; gap: 8px; padding: calc(var(--safe-top) + 8px) 12px 8px; background: var(--glass-bar); backdrop-filter: blur(12px); border-bottom: 1px solid var(--line); }
.bar-t { flex: 1; text-align: center; font-size: 14.5px; }
.xl { flex: none; min-width: 44px; height: 44px; padding: 0 12px; border-radius: 13px; border: 1px solid var(--line); background: var(--card); color: #0b7a4b; font-size: 13px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-family: inherit; }
.xl:disabled { opacity: 0.6; }
.rt td.ed { outline: 1.5px dashed color-mix(in srgb, var(--accent) 55%, transparent); outline-offset: -2px; cursor: pointer; background: color-mix(in srgb, var(--accent) 5%, transparent); }
.in-cta { display: grid; gap: 8px; margin-top: 14px; }
.in-t { margin: 0 0 10px; font-size: 13px; color: var(--muted); font-weight: 600; }
.in-box { display: grid; gap: 6px; padding: 10px 12px; border-radius: 14px; background: var(--blue-50); margin-bottom: 12px; }
.in-box div { display: flex; justify-content: space-between; gap: 10px; font-size: 13px; color: var(--ink-2); }
.in-box .hi b { color: #0b7a4b; font-size: 15px; }
.in-warn { display: flex; align-items: flex-start; gap: 8px; margin: 0 0 12px; padding: 9px 11px; border-radius: 12px; background: #fff3d6; color: #8a5b00; font-size: 12.5px; font-weight: 600; }
.in-i { width: 100%; height: 48px; border-radius: 14px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 18px; font-weight: 800; padding: 0 14px; font-family: inherit; }
.in-act { display: grid; grid-template-columns: auto 1fr; gap: 10px; }
.in-act .bad { color: #b4232a; }
.back { width: 44px; height: 44px; border-radius: 13px; border: 1px solid var(--line); background: var(--card); color: var(--ink); display: grid; place-items: center; flex: none; }
.wrap { padding: 12px 16px calc(var(--safe-bottom) + 32px); display: grid; gap: 12px; min-width: 0; }
.stack { display: grid; gap: 12px; }
.dim-wrap { display: grid; gap: 12px; min-width: 0; transition: opacity 0.2s; } .dim-wrap.dim { opacity: 0.55; pointer-events: none; }

.filters { display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr); gap: 8px; align-items: center; }
.mo { display: flex; align-items: center; justify-content: space-between; background: var(--card); border: 1px solid var(--line); border-radius: 13px; height: 44px; padding: 0 2px; }
.mo b { font-size: 14px; text-align: center; flex: 1; }
.mo button { width: 36px; height: 40px; display: grid; place-items: center; color: var(--accent); border: 0; background: none; padding: 0; border-radius: 10px; } .mo button:disabled { opacity: 0.3; }
.sel { width: 100%; min-width: 0; height: 44px; padding: 0 10px; border-radius: 13px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 13.5px; font-family: inherit; }

.strip { display: flex; gap: 6px; overflow-x: auto; padding: 2px 2px 6px; scrollbar-width: none; margin: 0 -16px; padding-left: 16px; padding-right: 16px; }
.strip::-webkit-scrollbar { display: none; }
.dy { flex: none; width: 46px; height: 58px; border-radius: 14px; border: 1px solid var(--line); background: var(--card); display: grid; align-content: center; justify-items: center; gap: 1px; font: inherit; color: var(--ink); }
.dy small { font-size: 10px; color: var(--muted); text-transform: uppercase; } .dy b { font-size: 16px; }
.dy.we small, .dy.we b { color: var(--red); }
.dy.hi { border-color: var(--focus); box-shadow: 0 0 0 1px var(--focus); }
.dy.on { background: var(--navy-800, #04247a); border-color: transparent; } .dy.on small, .dy.on b { color: #fff; }

.hero { position: relative; overflow: hidden; border-radius: 24px; padding: 18px 18px 16px; color: #fff; background: var(--hero-bg); box-shadow: 0 16px 34px -18px rgba(4, 36, 122, 0.7); }
.orb { position: absolute; width: 160px; height: 160px; right: -40px; top: -50px; border-radius: 50%; background: #f08007; opacity: 0.35; filter: blur(34px); }
.h-top { position: relative; display: flex; justify-content: space-between; gap: 8px; font-size: 12.5px; opacity: 0.9; } .h-top em { font-style: normal; font-weight: 600; }
.h-big { position: relative; font-size: 38px; font-weight: 800; letter-spacing: -0.02em; margin-top: 4px; font-variant-numeric: tabular-nums; } .h-big small { font-size: 16px; font-weight: 600; opacity: 0.8; } .h-big.neg { color: #ffb4ad; }
.h-tip { position: relative; margin: 0 0 4px; font-size: 12px; color: #ffd9a8; }
.h-grid { position: relative; display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-top: 12px; }
.h-grid div { background: rgba(255, 255, 255, 0.12); border-radius: 12px; padding: 8px 8px 7px; display: grid; gap: 2px; min-width: 0; }
.h-grid span { font-size: 10px; opacity: 0.8; } .h-grid b { font-size: 13.5px; font-variant-numeric: tabular-nums; }
.h-grid .pl b { color: #9be7bd; } .h-grid .mi b { color: #ffc7a0; }

.kpis { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.kpi { display: grid; gap: 3px; padding: 12px 14px; border-left: 3px solid var(--c, var(--line)); min-width: 0; }
.kl { font-size: 11.5px; color: var(--muted); font-weight: 600; } .kv { font-size: 22px; font-weight: 800; letter-spacing: -0.02em; overflow-wrap: anywhere; } .ks { font-size: 11.5px; color: var(--muted); }
.kpi.up { border-left-color: var(--green); } .kpi.up .kv { color: var(--green); } .kpi.down { border-left-color: var(--red); } .kpi.down .kv { color: var(--red); }

.box { padding: 14px 16px; display: grid; grid-template-columns: minmax(0, 1fr); gap: 8px; min-width: 0; }
.ttl { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
h3 { margin: 0; font-size: 13px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); }
.hint { margin: 0; font-size: 12px; color: var(--muted); }
.tag { font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 999px; background: color-mix(in srgb, var(--c) 12%, var(--card)); color: color-mix(in srgb, var(--c) 82%, var(--ink)); }
.chips.sm { padding-bottom: 2px; gap: 6px; } .chips.sm .chip { padding: 0 12px; font-size: 13px; min-height: 40px; }
.neg-box { display: grid; gap: 4px; padding: 10px 12px; border-radius: 12px; background: var(--red-bg); color: var(--red); font-size: 13px; }
.neg-r { display: flex; justify-content: space-between; gap: 8px; } .neg-r span { overflow-wrap: anywhere; }

.ins ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.ins li { display: grid; grid-template-columns: 10px minmax(0, 1fr); gap: 10px; font-size: 13.5px; line-height: 1.45; }
.ins li i { width: 10px; height: 10px; margin-top: 5px; border-radius: 50%; background: var(--muted); } .ins li.up i { background: var(--green); } .ins li.down i { background: var(--red); }

/* tabel lengkap (meniru warna web) */
.tscroll { overflow: auto; max-height: 560px; margin: 0 -16px; padding: 0 16px; border-top: 1px solid var(--line); -webkit-overflow-scrolling: touch; }
.rt { border-collapse: separate; border-spacing: 0; font-size: 11.5px; width: max-content; min-width: 100%; }
.rt th, .rt td { border: 1px solid var(--line); padding: 4px 7px; white-space: nowrap; text-align: center; font-variant-numeric: tabular-nums; background: var(--card); }
.rt thead th { position: sticky; top: 0; z-index: 3; background: var(--blue-50); font-weight: 700; color: var(--ink-2); }
.rt .lbl { position: sticky; left: 0; z-index: 2; text-align: left; min-width: 150px; max-width: 170px; overflow: hidden; text-overflow: ellipsis; box-shadow: 2px 0 4px rgba(0, 0, 0, 0.06); background: var(--card); }
.rt thead .lbl { z-index: 4; }
.rt .we { color: var(--red); } .rt td.we { background: color-mix(in srgb, var(--red) 7%, var(--card)); }
.rt .tt { background: #c6efce; color: #1f3d1f; font-weight: 700; }
.rt .sec td { background: #9dc3e6; color: #1f3864; font-weight: 700; } .rt .sec .lbl { background: #9dc3e6; }
.rt .tsales td, .rt .tsales .lbl { background: #ffdcaa; color: #3d2a00; font-weight: 700; } .rt .tsales td.act { background: #ffff00; } .rt .tsales .tt { background: #c6efce; }
.rt .sakhir td, .rt .sakhir .lbl { background: #00e0e0; color: #00363a; font-weight: 700; }
.rt .sakhirsub td, .rt .sakhirsub .lbl { background: #d4e1f6; color: #12285c; font-weight: 700; }
.rt .fcst td, .rt .fcst .lbl { background: #eeeeee; color: #222; font-weight: 700; } .rt .fcst .tt { background: #c6efce; }
.rt .plan td, .rt .plan .lbl { background: #eef4ff; color: #1d4ed8; font-style: italic; }
.rt .loss td, .rt .loss .lbl { color: #cc0000; font-style: italic; }
.rt td.fcsup { background: #fff7e6; color: #b45f06; font-style: italic; }
.rt td.click { cursor: pointer; text-decoration: underline dotted; } .rt td.click:hover { background: #dbeafe; color: #1d4ed8; }

/* rincian */
.sh-h { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 10px; } .sh-w { flex: 1; min-width: 0; }
.sh-t { margin: 0; font-size: 18px; overflow-wrap: anywhere; } .sh-s { margin: 2px 0 0; font-size: 13px; color: var(--muted); }
.srow { display: grid; grid-template-columns: minmax(0, 1fr) auto auto; gap: 10px; align-items: center; padding: 9px 0; border-top: 1px solid var(--line); font-size: 14px; }
.srow span { color: var(--muted); } .srow b { font-variant-numeric: tabular-nums; } .srow.strong span, .srow.strong b { color: var(--ink); font-size: 15.5px; }
.lnk { min-height: 32px; padding: 0 12px; font-size: 12px; }
.d-r { display: grid; gap: 2px; padding: 9px 0; border-top: 1px solid var(--line); }
.d-r-t { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; font-size: 13.5px; } .d-r-t b { overflow-wrap: anywhere; } .d-r-t span { white-space: nowrap; font-weight: 700; font-variant-numeric: tabular-nums; }
.d-r small { color: var(--muted); font-size: 12px; overflow-wrap: anywhere; }
.d-r.minus { border-left: 3px solid var(--red); padding-left: 10px; } .d-r.plus { border-left: 3px solid var(--green); padding-left: 10px; }
.d-sum { display: flex; justify-content: space-between; align-items: center; padding: 12px 14px; margin-top: 8px; border-radius: 14px; background: var(--blue-50); } .d-sum span { color: var(--muted); font-weight: 600; font-size: 13px; } .d-sum b { font-size: 17px; }
</style>
