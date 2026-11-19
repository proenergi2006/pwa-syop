<script setup>
import { t, numLocale } from '../i18n'
import { useScrolled } from '../composables/useScrolled'
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import UserAvatar from '../components/UserAvatar.vue'
import StateBox from '../components/StateBox.vue'
import RankBars from '../components/charts/RankBars.vue'
import { useAuth } from '../stores/auth'
import { useInbox } from '../stores/inbox'
import * as syop from '../api/syop'
import { errorCode, errorMessage } from '../api/client'
import { showToast } from '../composables/useToast'
import { angka, tanggal, tanggalJam } from '../utils/format'
import { bulanPanjang } from '../utils/chart'

const scrolled = useScrolled()
const router = useRouter()
const route = useRoute()
const auth = useAuth()
const inbox = useInbox()

const now = new Date()
const bulan = ref(Number(route.query.bulan) || now.getMonth() + 1)
const tahun = ref(Number(route.query.tahun) || now.getFullYear())
const tab = ref(route.query.tab === 'request' ? 'request' : 'rekap')
const data = ref(null)
const reqs = ref(null)
const loading = ref(true)
const error = ref('')
const sel = ref([]) // "cabang:periode" yang dicentang
let seq = 0

const idx = computed(() => tahun.value * 12 + bulan.value - 1)
function geser(n) {
  const i = idx.value + n
  tahun.value = Math.floor(i / 12)
  bulan.value = (i % 12) + 1
}
const km = (n) => Number(n || 0).toLocaleString(numLocale.value, { minimumFractionDigits: 0, maximumFractionDigits: 2 })

async function load(silent = false) {
  const my = ++seq
  if (!silent) loading.value = true
  error.value = ''
  try {
    const [r, q] = await Promise.all([syop.forecastRekap({ bulan: bulan.value, tahun: tahun.value }), syop.forecastRequests({ bulan: bulan.value, tahun: tahun.value })])
    if (my !== seq) return
    data.value = r
    reqs.value = q
    sel.value = sel.value.filter((k) => waitingKeys.value.includes(k))
  } catch (e) { if (my === seq) error.value = errorMessage(e) } finally { if (my === seq) loading.value = false }
}

const rows = computed(() => data.value?.cabang || [])
const waitingKeys = computed(() => rows.value.flatMap((c) => c.waiting.map((p) => `${c.id}:${p}`)))
const rank = computed(() => rows.value.map((c) => ({ id: c.id, nama: c.nama, value: (c.per[1]?.total || 0) + (c.per[2]?.total || 0) })).filter((x) => x.value > 0).sort((a, b) => b.value - a.value))
const reqWaiting = computed(() => (reqs.value?.data || []).filter((r) => r.can_decide).length)
const stCls = (s) => ({ WAITING: 'pill-wait', APPROVED: 'pill-ok', REJECTED: 'pill-no', WAITING_CEO: 'pill-wait' })[s] || 'pill-draft'
const stTxt = (s) => (s === 'WAITING' ? t('fc.waiting') : s === 'APPROVED' ? t('fc.approved') : s === 'REJECTED' ? t('fc.rejected') : t('fc.notSubmitted'))
const range = (p, hari) => (p === 1 ? t('fc.range', { a: 1, b: 14 }) : t('fc.range', { a: 15, b: hari }))
const isSel = (c, p) => sel.value.includes(`${c}:${p}`)
function toggle(c, p) { const k = `${c}:${p}`; sel.value = sel.value.includes(k) ? sel.value.filter((x) => x !== k) : [...sel.value, k] }
const allPicked = computed(() => waitingKeys.value.length > 0 && sel.value.length === waitingKeys.value.length)
const pickAll = () => { sel.value = allPicked.value ? [] : [...waitingKeys.value] }

// ---- keputusan (periode dan request) ----
const act = ref(null) // { kind: 'periode'|'request', type: 'approve'|'reject', items: [{cabang, periode}], nama, req }
const catatan = ref('')
const pwd = ref('')
const busy = ref(false)
const formError = ref('')
const needPassword = computed(() => false) // tidak ada konfirmasi password di modul ini (hanya di Profil untuk wajah)
function openPeriode(type, c, periods) {
  act.value = { kind: 'periode', type, items: periods.map((p) => ({ cabang: c.id, periode: p })), nama: c.nama }
  reset()
}
function openSelected() {
  act.value = { kind: 'periode', type: 'approve', items: sel.value.map((k) => { const [c, p] = k.split(':').map(Number); return { cabang: c, periode: p } }), nama: '', many: true }
  reset()
}
function openRequest(type, r) { act.value = { kind: 'request', type, req: r }; reset() }
function reset() { catatan.value = ''; pwd.value = ''; formError.value = '' }
const title = computed(() => {
  const a = act.value
  if (!a) return ''
  if (a.kind === 'request') return t(a.type === 'approve' ? 'fc.req.q.approve' : 'fc.req.q.reject')
  if (a.many) return t('fc.q.approveSel', { n: a.items.length })
  return t(a.type === 'approve' ? 'fc.q.approve' : 'fc.q.reject', { nama: a.nama })
})
const hint = computed(() => (act.value?.kind === 'request' ? t(act.value.type === 'approve' ? 'fc.req.hint.approve' : 'fc.req.hint.reject') : t(act.value?.type === 'approve' ? 'fc.hint.approve' : 'fc.hint.reject')))

async function submit() {
  const a = act.value
  formError.value = ''
  if (a.type === 'reject' && !catatan.value.trim()) return (formError.value = t('det.errReason'))
  if (needPassword.value && !pwd.value) return (formError.value = t('det.errPwd'))
  busy.value = true
  try {
    const pw = needPassword.value ? { password: pwd.value } : {}
    let r
    if (a.kind === 'request') {
      r = a.type === 'approve' ? await syop.forecastApproveRequest(a.req.id, pw) : await syop.forecastRejectRequest(a.req.id, { alasan: catatan.value.trim(), ...pw })
    } else if (a.type === 'approve') {
      r = await syop.forecastApprove({ items: a.items, bulan: bulan.value, tahun: tahun.value, catatan: catatan.value.trim() || undefined, ...pw })
    } else {
      r = await syop.forecastReject({ ...a.items[0], bulan: bulan.value, tahun: tahun.value, alasan: catatan.value.trim(), ...pw })
    }
    act.value = null
    sel.value = []
    showToast(r.dilewati?.length ? `${r.message} ${t('fc.skipped', { n: r.dilewati.length })}` : r.message, 'ok')
    inbox.refresh()
    await load(true)
  } catch (e) {
    if (errorCode(e) === 'STEP_UP_REQUIRED') auth.method = 'face'
    formError.value = errorMessage(e)
  } finally { busy.value = false }
}

const openDetail = (c) => router.push({ name: 'forecast-cabang', params: { id: c.id }, query: { bulan: bulan.value, tahun: tahun.value } })
let first = true
watch([bulan, tahun], () => { if (!first) { sel.value = []; router.replace({ query: { ...route.query, bulan: bulan.value, tahun: tahun.value } }); load() } })
watch(tab, (v) => router.replace({ query: { ...route.query, tab: v === 'request' ? 'request' : undefined } }))
onMounted(async () => {
  // Tanpa bulan di alamat: buka bulan tertua yang punya forecast menunggu CEO (biasanya bulan depan), bukan bulan berjalan.
  if (!route.query.bulan) {
    try { const m = await syop.forecastMenunggu(); if (m.data.length) { bulan.value = m.data[0].bulan; tahun.value = m.data[0].tahun } } catch { /* pakai bulan berjalan */ }
  }
  await load()
  first = false
})
</script>

<template>
  <div class="page">
    <div class="page-head" :class="{ stuck: scrolled }">
      <div>
        <h1 class="page-title">{{ t('fc.title') }}</h1>
        <p class="page-sub">{{ t('fc.sub') }}</p>
      </div>
      <router-link :to="{ name: 'profil' }" class="avatar" :aria-label="t('common.profile')"><UserAvatar /><i class="dot" /></router-link>
    </div>

    <div class="mo" role="group">
      <button :aria-label="t('fc.prevMonth')" @click="geser(-1)"><Icon name="chevron" :size="18" style="transform:rotate(180deg)" /></button>
      <b>{{ bulanPanjang(bulan - 1) }} {{ tahun }}</b>
      <button :aria-label="t('fc.nextMonth')" @click="geser(1)"><Icon name="chevron" :size="18" /></button>
    </div>

    <div class="chips" role="tablist">
      <button class="chip" :class="{ on: tab === 'rekap' }" role="tab" :aria-selected="tab === 'rekap'" @click="tab = 'rekap'">{{ t('fc.tab.rekap') }}<b v-if="data?.count.waiting" class="cnt">{{ data.count.waiting }}</b></button>
      <button class="chip" :class="{ on: tab === 'request' }" role="tab" :aria-selected="tab === 'request'" @click="tab = 'request'">{{ t('fc.tab.request') }}<b v-if="reqWaiting" class="cnt">{{ reqWaiting }}</b></button>
    </div>

    <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load()" />
    <div v-else-if="loading && !data" class="stack"><div class="skel" style="height:96px" /><div class="skel" style="height:210px" /><div class="skel" style="height:210px" /></div>

    <template v-else-if="data && tab === 'rekap'">
      <div class="kpis">
        <div class="hero-k">
          <span>{{ t('fc.total') }} · {{ bulanPanjang(bulan - 1) }}</span>
          <b>{{ km(data.total) }} <small>{{ t('fc.kl') }}</small></b>
        </div>
        <div class="kpi" style="--c: #e6a100"><small>{{ t('fc.waiting') }}</small><b>{{ data.count.waiting }}</b></div>
        <div class="kpi" style="--c: var(--green)"><small>{{ t('fc.approved') }}</small><b>{{ data.count.approved }}</b></div>
        <div class="kpi" style="--c: var(--red)"><small>{{ t('fc.rejected') }}</small><b>{{ data.count.rejected }}</b></div>
      </div>

      <section v-if="rank.length" class="card box" style="margin-top:12px">
        <h2>{{ t('fc.sec.branches') }} <small>({{ t('fc.kl') }})</small></h2>
        <RankBars :items="rank" color="#0f8b8d" :limit="6" @pick="(id) => openDetail(rows.find((c) => c.id === id))" />
      </section>

      <div v-if="waitingKeys.length" class="selbar">
        <label class="ck"><input type="checkbox" :checked="allPicked" @change="pickAll" /> {{ t('fc.pickAll') }}</label>
      </div>

      <div class="stack" style="margin-top:12px">
        <article v-for="(c, i) in rows" :key="c.id" class="card cab rise" :class="{ hot: c.waiting.length }" :style="{ '--i': i % 8 }">
          <header class="cab-h">
            <b>{{ c.nama }}</b>
            <div class="cab-a">
              <button v-if="c.waiting.length > 1" class="mini ok" @click="openPeriode('approve', c, c.waiting)">{{ t('fc.approveAll') }}</button>
              <button class="mini" @click="openDetail(c)">{{ t('fc.detail') }} <Icon name="chevron" :size="14" /></button>
            </div>
          </header>
          <div class="pgrid">
            <div v-for="p in [1, 2]" :key="p" class="per" :class="'s-' + (c.per[p].status || 'none')">
              <div class="per-h">
                <label v-if="c.per[p].status === 'WAITING'" class="ck" @click.stop><input type="checkbox" :checked="isSel(c.id, p)" @change="toggle(c.id, p)" /></label>
                <span class="per-t">{{ t('fc.period', { p }) }}<small>{{ range(p, data.jumlah_hari) }}</small></span>
              </div>
              <div class="per-v">{{ km(c.per[p].total) }}<small> {{ t('fc.kl') }}</small></div>
              <span class="pill" :class="stCls(c.per[p].status)">{{ stTxt(c.per[p].status) }}</span>
              <p v-if="c.per[p].catatan_om" class="nt"><b>OM:</b> {{ c.per[p].catatan_om }}</p>
              <p v-if="c.per[p].status === 'REJECTED' && c.per[p].catatan_ceo" class="nt bad"><b>CEO:</b> {{ c.per[p].catatan_ceo }}</p>
              <small v-if="c.per[p].ceo_date && c.per[p].status !== 'WAITING'" class="dt">{{ tanggalJam(c.per[p].ceo_date) }}</small>
              <div v-if="c.per[p].status === 'WAITING'" class="per-act">
                <button class="mini bad" @click="openPeriode('reject', c, [p])">{{ t('fc.reject') }}</button>
                <button class="mini ok solid" @click="openPeriode('approve', c, [p])">{{ t('fc.approve') }}</button>
              </div>
            </div>
          </div>
        </article>
      </div>
    </template>

    <template v-else-if="reqs && tab === 'request'">
      <StateBox v-if="!reqs.data.length" icon="check" :title="t('fc.req.none')" :text="t('fc.req.noneText')" />
      <div v-else class="stack">
        <article v-for="(r, i) in reqs.data" :key="r.id" class="card rq rise" :class="{ hot: r.can_decide }" :style="{ '--i': i % 8 }">
          <div class="item-top"><div class="item-who"><span class="ico-box tinted tone-green"><Icon name="chart" :size="18" /></span><span>{{ r.nama_cabang || '-' }}</span></div><span class="pill" :class="stCls(r.status)">{{ t('fc.req.st.' + r.status) === 'fc.req.st.' + r.status ? r.status : t('fc.req.st.' + r.status) }}</span></div>
          <div class="item-title">{{ r.nama_customer }}</div>
          <div class="rq-m"><span><Icon name="calendar" :size="14" /> {{ tanggal(r.tanggal) }}</span><b>+{{ angka(r.qty) }}</b></div>
          <p class="nt"><b>{{ t('fc.req.reason') }}:</b> {{ r.alasan }}</p>
          <p v-if="r.om_catatan" class="nt"><b>{{ t('fc.req.omNote') }}:</b> {{ r.om_catatan }}</p>
          <p v-if="r.status === 'REJECTED' && r.ceo_catatan" class="nt bad"><b>CEO:</b> {{ r.ceo_catatan }}</p>
          <div v-if="r.can_decide" class="per-act">
            <button class="mini bad" @click="openRequest('reject', r)">{{ t('fc.reject') }}</button>
            <button class="mini ok solid" @click="openRequest('approve', r)">{{ t('fc.approve') }}</button>
          </div>
        </article>
      </div>
    </template>

    <div v-if="tab === 'rekap' && sel.length" class="floatbar">
      <button class="btn btn-primary" @click="openSelected"><Icon name="check" :size="18" /> {{ t('fc.approveSel') }} ({{ sel.length }})</button>
    </div>

    <div v-if="act" class="sheet-back" @click.self="!busy && (act = null)">
      <div class="sheet" role="dialog" aria-modal="true">
        <div class="grab" />
        <h2 class="sheet-t">{{ title }}</h2>
        <p v-if="act.kind === 'request'" class="muted" style="margin:4px 0 6px">{{ act.req.nama_customer }} · {{ tanggal(act.req.tanggal) }} · +{{ angka(act.req.qty) }}</p>
        <p v-else-if="!act.many" class="muted" style="margin:4px 0 6px">{{ act.items.map((x) => t('fc.period', { p: x.periode })).join(' + ') }} · {{ bulanPanjang(bulan - 1) }} {{ tahun }}</p>
        <p class="hint">{{ hint }}</p>
        <div class="field">
          <label for="fn">{{ act.type === 'reject' ? t('fc.reasonReq') : t('det.noteOpt') }}</label>
          <textarea id="fn" v-model="catatan" class="plain" maxlength="2000" :placeholder="act.type === 'reject' ? t('fc.reasonPh') : t('fc.notePh')" />
        </div>
        <div v-if="needPassword" class="field">
          <label for="fp">{{ t('det.yourPwd') }}</label>
          <div class="input"><Icon name="lock" :size="20" /><input id="fp" v-model="pwd" type="password" autocomplete="current-password" :placeholder="t('det.confirmPwd')" /></div>
          <small class="muted">{{ t('det.faceNote') }}</small>
        </div>
        <p v-if="formError" class="err-text" role="alert" style="margin-bottom:12px">{{ formError }}</p>
        <div class="sheet-act">
          <button class="btn btn-ghost" :disabled="busy" @click="act = null">{{ t('common.cancel') }}</button>
          <button class="btn" :class="act.type === 'approve' ? 'btn-primary' : 'btn-danger'" :disabled="busy" @click="submit">{{ busy ? t('det.processing') : act.type === 'approve' ? t('det.yesApprove') : t('det.yesReject') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.mo { display: grid; grid-template-columns: 44px 1fr 44px; align-items: center; margin-bottom: 12px; border: 1px solid var(--line); background: var(--card); border-radius: 14px; overflow: hidden; }
.mo b { text-align: center; font-size: 15px; font-weight: 800; }
.mo button { height: 44px; border: 0; background: none; color: var(--ink); display: grid; place-items: center; }
.cnt { margin-left: 8px; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 10px; background: var(--red); color: #fff; font-size: 11px; display: inline-grid; place-items: center; }
.kpis { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
.hero-k { grid-column: 1 / -1; padding: 16px 18px; border-radius: 20px; color: #fff; background: linear-gradient(150deg, #06403f, #0b6b69 60%, #0f8b8d); box-shadow: 0 12px 30px rgba(15, 139, 141, 0.28); display: grid; gap: 4px; }
.hero-k span { font-size: 13px; color: #c9f1ee; }
.hero-k b { font-size: 32px; font-weight: 800; letter-spacing: -0.03em; }
.hero-k small { font-size: 15px; font-weight: 600; color: #c9f1ee; }
.kpi { display: grid; gap: 3px; padding: 12px 14px; border-radius: 16px; background: var(--card); border: 1px solid var(--line); border-left: 3px solid var(--c, var(--line)); min-width: 0; }
.kpi small { font-size: 12px; color: var(--muted); }
.kpi b { font-size: 24px; font-weight: 800; letter-spacing: -0.02em; }
.box { padding: 14px 16px; }
.box h2 { font-size: 14px; font-weight: 700; margin: 0 0 8px; }
.box h2 small { color: var(--muted); font-weight: 600; }
.selbar { margin-top: 14px; }
.ck { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: var(--ink-2); min-height: 32px; }
.ck input { width: 20px; height: 20px; accent-color: var(--accent); }
.cab { padding: 12px 14px 14px; border: 1px solid var(--line); }
.cab.hot { border-color: color-mix(in srgb, #e6a100 55%, var(--line)); box-shadow: 0 6px 20px rgba(230, 161, 0, 0.12); }
.cab-h { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 10px; }
.cab-h b { font-size: 15.5px; font-weight: 800; letter-spacing: -0.01em; }
.cab-a { display: flex; gap: 6px; }
.pgrid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.per { display: grid; align-content: start; gap: 6px; padding: 10px 12px; border-radius: 14px; background: var(--bg); border: 1px solid transparent; min-width: 0; }
.per.s-WAITING { background: color-mix(in srgb, #e6a100 10%, var(--card)); border-color: color-mix(in srgb, #e6a100 35%, var(--line)); }
.per.s-APPROVED { background: color-mix(in srgb, var(--green) 9%, var(--card)); }
.per.s-REJECTED { background: color-mix(in srgb, var(--red) 8%, var(--card)); }
.per-h { display: flex; align-items: center; gap: 6px; }
.per-t { display: grid; font-size: 13px; font-weight: 700; }
.per-t small { font-size: 11px; color: var(--muted); font-weight: 500; }
.per-v { font-size: 20px; font-weight: 800; letter-spacing: -0.02em; }
.per-v small { font-size: 12px; color: var(--muted); font-weight: 600; }
.per .pill { justify-self: start; }
.nt { margin: 0; font-size: 12px; color: var(--ink-2); overflow-wrap: anywhere; white-space: pre-line; }
.nt.bad { color: #b4232a; }
.dt { font-size: 11px; color: var(--muted); }
.per-act { display: flex; gap: 8px; margin-top: 4px; }
.per-act .mini { flex: 1; justify-content: center; }
.mini { display: inline-flex; align-items: center; gap: 4px; min-height: 36px; padding: 0 12px; border-radius: 10px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 12.5px; font-weight: 700; font-family: inherit; }
.mini.ok { color: #0b7a4b; border-color: color-mix(in srgb, var(--green) 45%, var(--line)); }
.mini.bad { color: #b4232a; border-color: color-mix(in srgb, var(--red) 40%, var(--line)); }
.mini.solid.ok { background: var(--green); color: #fff; border-color: var(--green); }
.rq { padding: 14px 16px; display: grid; gap: 6px; border: 1px solid var(--line); }
.rq.hot { border-color: color-mix(in srgb, #e6a100 55%, var(--line)); }
.rq-m { display: flex; align-items: center; justify-content: space-between; font-size: 13px; color: var(--muted); }
.rq-m span { display: inline-flex; align-items: center; gap: 6px; }
.rq-m b { font-size: 20px; color: #0b7a4b; letter-spacing: -0.02em; }
.floatbar { position: fixed; left: 50%; transform: translateX(-50%); bottom: calc(var(--safe-bottom) + 92px); width: min(440px, calc(100% - 32px)); z-index: 16; }
.floatbar .btn { width: 100%; box-shadow: 0 10px 28px rgba(11, 42, 130, 0.35); }
.hint { margin: 0 0 14px; padding: 8px 10px; border-radius: 10px; background: var(--blue-50); color: var(--ink-2); font-size: 12.5px; }
</style>
