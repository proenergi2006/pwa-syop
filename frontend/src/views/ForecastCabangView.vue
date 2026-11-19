<script setup>
import { t, numLocale } from '../i18n'
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import StateBox from '../components/StateBox.vue'
import DayBars from '../components/charts/DayBars.vue'
import RankBars from '../components/charts/RankBars.vue'
import { useAuth } from '../stores/auth'
import { useInbox } from '../stores/inbox'
import * as syop from '../api/syop'
import { errorCode, errorMessage } from '../api/client'
import { showToast } from '../composables/useToast'
import { tanggalJam } from '../utils/format'
import { bulanPanjang } from '../utils/chart'

const props = defineProps({ id: String })
const route = useRoute()
const router = useRouter()
const auth = useAuth()
const inbox = useInbox()
const now = new Date()
const bulan = Number(route.query.bulan) || now.getMonth() + 1
const tahun = Number(route.query.tahun) || now.getFullYear()
const d = ref(null)
const loading = ref(true)
const error = ref('')
const selDay = ref(-1)
let timer

async function load() {
  loading.value = true
  error.value = ''
  try { d.value = await syop.forecastCabang({ cabang: props.id, bulan, tahun }) } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'forecast' }))
const km = (n) => Number(n || 0).toLocaleString(numLocale.value, { minimumFractionDigits: 0, maximumFractionDigits: 2 })
const stCls = (s) => ({ WAITING: 'pill-wait', APPROVED: 'pill-ok', REJECTED: 'pill-no' })[s] || 'pill-draft'
const stTxt = (s) => (s === 'WAITING' ? t('fc.waiting') : s === 'APPROVED' ? t('fc.approved') : s === 'REJECTED' ? t('fc.rejected') : t('fc.notSubmitted'))
const range = (p) => (p === 1 ? t('fc.range', { a: 1, b: 14 }) : t('fc.range', { a: 15, b: d.value.jumlah_hari }))

const weekend = computed(() => (d.value?.kalender || []).map((k) => k.akhir_pekan || !!k.libur))
const rank = computed(() => (d.value?.ringkasan || []).filter((x) => x.total > 0).map((x) => ({ id: x.id, nama: x.nama, value: x.total })).sort((a, b) => b.value - a.value))
const maxCell = computed(() => Math.max(1, ...(d.value?.customers || []).flatMap((c) => c.hari)))
const heat = (v) => (v > 0 ? { background: `color-mix(in srgb, #0f8b8d ${Math.round(14 + (v / maxCell.value) * 62)}%, var(--card))`, color: v / maxCell.value > 0.6 ? '#fff' : 'var(--ink)' } : {})
const dayInfo = computed(() => (selDay.value >= 0 && d.value ? d.value.kalender[selDay.value] : null))
const dayRows = computed(() => (selDay.value >= 0 && d.value ? d.value.customers.map((c) => ({ nama: c.nama, v: c.hari[selDay.value] })).filter((x) => x.v > 0).sort((a, b) => b.v - a.v) : []))
function pick(i) { selDay.value = selDay.value === i ? -1 : i }

// ---- keputusan ----
const act = ref(null)
const catatan = ref('')
const pwd = ref('')
const busy = ref(false)
const formError = ref('')
const needPassword = computed(() => false) // tidak ada konfirmasi password di modul ini (hanya di Profil untuk wajah)
function open(type, p) { act.value = { type, periode: p }; catatan.value = ''; pwd.value = ''; formError.value = '' }
async function submit() {
  const a = act.value
  formError.value = ''
  if (a.type === 'reject' && !catatan.value.trim()) return (formError.value = t('det.errReason'))
  if (needPassword.value && !pwd.value) return (formError.value = t('det.errPwd'))
  busy.value = true
  try {
    const pw = needPassword.value ? { password: pwd.value } : {}
    const base = { bulan, tahun }
    const r = a.type === 'approve'
      ? await syop.forecastApprove({ ...base, items: [{ cabang: Number(props.id), periode: a.periode }], catatan: catatan.value.trim() || undefined, ...pw })
      : await syop.forecastReject({ ...base, cabang: Number(props.id), periode: a.periode, alasan: catatan.value.trim(), ...pw })
    act.value = null
    showToast(r.message, 'ok')
    inbox.refresh()
    await load()
  } catch (e) {
    if (errorCode(e) === 'STEP_UP_REQUIRED') auth.method = 'face'
    formError.value = errorMessage(e)
  } finally { busy.value = false }
}
onMounted(load)
</script>

<template>
  <div>
    <header class="bar">
      <button class="back" :aria-label="t('common.back')" @click="back"><Icon name="back" :size="20" /></button>
      <div class="bar-t"><b>{{ d?.cabang.nama || t('fc.title') }}</b><small>{{ bulanPanjang(bulan - 1) }} {{ tahun }}</small></div>
      <button class="back" :aria-label="t('det.refresh')" @click="load"><Icon name="refresh" :size="18" /></button>
    </header>

    <div class="wrap">
      <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load" />
      <div v-else-if="loading && !d" class="stack"><div class="skel" style="height:130px" /><div class="skel" style="height:220px" /><div class="skel" style="height:200px" /></div>
      <template v-else-if="d">
        <section class="hero">
          <span class="orb" />
          <div class="h-top"><span>{{ t('fc.total') }} · {{ bulanPanjang(bulan - 1) }} {{ tahun }}</span></div>
          <div class="h-v">{{ km(d.total_bulan) }}<small>{{ t('fc.kl') }}</small></div>
          <div class="h-row">
            <div><small>{{ t('fc.period', { p: 1 }) }}</small><b>{{ km(d.review[1].total) }}</b></div>
            <div><small>{{ t('fc.period', { p: 2 }) }}</small><b>{{ km(d.review[2].total) }}</b></div>
            <div><small>{{ t('fc.alloc.total') }}</small><b :class="{ warn: d.alokasi.melebihi }">{{ km(d.alokasi.total) }}</b></div>
          </div>
        </section>

        <!-- review per periode -->
        <section class="card box">
          <h2>{{ t('fc.sec.review') }}</h2>
          <div v-for="p in [1, 2]" :key="p" class="rv" :class="'s-' + (d.review[p].status || 'none')">
            <div class="rv-h">
              <span class="rv-t">{{ t('fc.period', { p }) }}<small>{{ range(p) }}</small></span>
              <span class="pill" :class="stCls(d.review[p].status)">{{ stTxt(d.review[p].status) }}</span>
            </div>
            <div class="rv-v">{{ km(d.review[p].total) }}<small> {{ t('fc.kl') }}</small></div>
            <p v-if="d.review[p].catatan_om" class="nt"><b>{{ t('fc.omNote') }}:</b> {{ d.review[p].catatan_om }}</p>
            <p v-if="d.review[p].status === 'REJECTED' && d.review[p].catatan_ceo" class="nt bad"><b>{{ t('fc.ceoNote') }}:</b> {{ d.review[p].catatan_ceo }}</p>
            <small v-if="d.review[p].ceo_date && ['APPROVED', 'REJECTED'].includes(d.review[p].status)" class="dt">{{ d.review[p].ceo_pic }} · {{ tanggalJam(d.review[p].ceo_date) }}</small>
            <div v-if="d.review[p].can_decide" class="rv-act">
              <button class="btn btn-danger" @click="open('reject', p)"><Icon name="x" :size="18" /> {{ t('fc.reject') }}</button>
              <button class="btn btn-primary" @click="open('approve', p)"><Icon name="check" :size="18" /> {{ t('fc.approve') }}</button>
            </div>
            <details v-if="d.review[p].riwayat_tolak.length" class="hist">
              <summary>{{ t('fc.history') }} ({{ d.review[p].riwayat_tolak.length }})</summary>
              <div v-for="(h, i) in d.review[p].riwayat_tolak" :key="i" class="hi"><b>{{ h.ceo_date ? tanggalJam(h.ceo_date) : '-' }}</b><span>{{ h.catatan_ceo || '-' }}</span></div>
            </details>
          </div>
        </section>

        <!-- harian -->
        <section class="card box">
          <h2>{{ t('fc.sec.daily') }} <small>({{ t('fc.kl') }})</small></h2>
          <DayBars :values="d.total_hari" :weekend="weekend" :sel="selDay" color="#0f8b8d" :label="t('fc.sec.daily')" @pick="pick" />
          <p v-if="!dayInfo" class="tip">{{ t('fc.tapDay') }}</p>
          <div v-else class="day">
            <div class="day-h"><b>{{ dayInfo.d }} {{ bulanPanjang(bulan - 1) }}</b><span v-if="dayInfo.libur" class="tg">{{ dayInfo.libur }}</span><span v-else-if="dayInfo.akhir_pekan" class="tg">{{ t('fc.weekend') }}</span><em>{{ km(d.total_hari[selDay]) }} {{ t('fc.kl') }}</em></div>
            <div v-for="r in dayRows" :key="r.nama" class="dr"><span>{{ r.nama }}</span><b>{{ km(r.v) }}</b></div>
            <p v-if="!dayRows.length" class="tip">-</p>
          </div>
        </section>

        <!-- per customer -->
        <section class="card box">
          <h2>{{ t('fc.sec.customer') }} <small>({{ t('fc.kl') }})</small></h2>
          <p v-if="!d.customers.length" class="tip">{{ t('fc.customerNone') }}</p>
          <div v-else class="gridw">
            <table class="gt">
              <thead>
                <tr>
                  <th class="c1">{{ t('oa.f.customer') }}</th>
                  <th v-for="k in d.kalender" :key="k.d" :class="{ off: k.akhir_pekan || k.libur }" :title="k.libur || ''">{{ k.d }}</th>
                  <th class="ct">{{ t('fc.perMonth') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="c in d.customers" :key="c.id">
                  <td class="c1">{{ c.nama }}</td>
                  <td v-for="(v, i) in c.hari" :key="i" :style="heat(v)">{{ v > 0 ? km(v) : '' }}</td>
                  <td class="ct">{{ km(c.total) }}</td>
                </tr>
              </tbody>
              <tfoot>
                <tr>
                  <td class="c1">TOTAL</td>
                  <td v-for="(v, i) in d.total_hari" :key="i">{{ v > 0 ? km(v) : '' }}</td>
                  <td class="ct">{{ km(d.total_bulan) }}</td>
                </tr>
              </tfoot>
            </table>
          </div>
        </section>

        <!-- alokasi -->
        <section class="card box">
          <h2>{{ t('fc.sec.alloc') }}</h2>
          <div class="kv"><span>{{ t('fc.alloc.p1') }}</span><span>{{ km(d.alokasi.p1) }} {{ t('fc.kl') }}</span></div>
          <div class="kv"><span>{{ t('fc.alloc.p2') }}</span><span>{{ km(d.alokasi.p2) }} {{ t('fc.kl') }}</span></div>
          <div class="kv tot"><span>{{ t('fc.alloc.total') }}</span><span>{{ km(d.alokasi.total) }} {{ t('fc.kl') }}</span></div>
          <p v-if="d.alokasi.melebihi" class="warnbox"><Icon name="alert" :size="16" /> {{ t('fc.allocWarn') }}</p>
        </section>

        <!-- semua cabang -->
        <section v-if="rank.length" class="card box">
          <h2>{{ t('fc.sec.branches') }} <small>({{ t('fc.kl') }})</small></h2>
          <RankBars :items="rank" color="#0a66e8" :sel="Number(props.id)" :limit="6" @pick="(i) => router.replace({ name: 'forecast-cabang', params: { id: i }, query: { bulan, tahun } }).then(load)" />
        </section>
      </template>
    </div>

    <div v-if="act" class="sheet-back" @click.self="!busy && (act = null)">
      <div class="sheet" role="dialog" aria-modal="true">
        <div class="grab" />
        <h2 class="sheet-t">{{ t(act.type === 'approve' ? 'fc.q.approve' : 'fc.q.reject', { nama: d.cabang.nama }) }}</h2>
        <p class="muted" style="margin:4px 0 6px">{{ t('fc.period', { p: act.periode }) }} · {{ bulanPanjang(bulan - 1) }} {{ tahun }} · {{ km(d.review[act.periode].total) }} {{ t('fc.kl') }}</p>
        <p class="hint">{{ act.type === 'approve' ? t('fc.hint.approve') : t('fc.hint.reject') }}</p>
        <div class="field">
          <label for="cn">{{ act.type === 'reject' ? t('fc.reasonReq') : t('det.noteOpt') }}</label>
          <textarea id="cn" v-model="catatan" class="plain" maxlength="2000" :placeholder="act.type === 'reject' ? t('fc.reasonPh') : t('fc.notePh')" />
        </div>
        <div v-if="needPassword" class="field">
          <label for="cp">{{ t('det.yourPwd') }}</label>
          <div class="input"><Icon name="lock" :size="20" /><input id="cp" v-model="pwd" type="password" autocomplete="current-password" :placeholder="t('det.confirmPwd')" /></div>
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
.bar { position: sticky; top: 0; z-index: 10; display: flex; align-items: center; gap: 8px; padding: calc(var(--safe-top) + 8px) 12px 8px; background: var(--glass-bar); backdrop-filter: blur(12px); border-bottom: 1px solid var(--line); }
.bar-t { flex: 1; min-width: 0; text-align: center; display: grid; }
.bar-t b { font-size: 14.5px; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.bar-t small { font-size: 12px; color: var(--muted); }
.back { width: 44px; height: 44px; border-radius: 13px; border: 1px solid var(--line); background: var(--card); color: var(--ink); display: grid; place-items: center; }
.wrap { padding: 12px 16px calc(var(--safe-bottom) + 40px); display: grid; grid-template-columns: minmax(0, 1fr); gap: 12px; }
.wrap > * { min-width: 0; }
.hero { position: relative; overflow: hidden; border-radius: 22px; padding: 16px 18px; color: #fff; background: linear-gradient(150deg, #06403f, #0b6b69 60%, #0f8b8d); box-shadow: 0 14px 34px rgba(15, 139, 141, 0.28); }
.orb { position: absolute; right: -60px; top: -70px; width: 220px; height: 220px; border-radius: 50%; background: radial-gradient(circle, rgba(150, 240, 230, 0.4), rgba(150, 240, 230, 0)); pointer-events: none; }
.h-top { position: relative; font-size: 13px; color: #c9f1ee; }
.h-v { position: relative; margin: 6px 0 12px; font-size: 36px; font-weight: 800; letter-spacing: -0.03em; }
.h-v small { margin-left: 6px; font-size: 16px; font-weight: 600; color: #c9f1ee; }
.h-row { position: relative; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
.h-row > div { display: grid; gap: 2px; padding: 9px 11px; border-radius: 13px; background: rgba(255, 255, 255, 0.12); min-width: 0; }
.h-row small { font-size: 11px; color: #c9f1ee; }
.h-row b { font-size: 15px; font-weight: 800; }
.h-row b.warn { color: #ffd479; }
.box { padding: 14px 16px; }
.box h2 { font-size: 14px; font-weight: 700; margin: 0 0 10px; }
.box h2 small { color: var(--muted); font-weight: 600; }
.rv { display: grid; gap: 6px; padding: 12px 14px; border-radius: 16px; background: var(--bg); margin-bottom: 10px; border: 1px solid transparent; }
.rv:last-child { margin-bottom: 0; }
.rv.s-WAITING { background: color-mix(in srgb, #e6a100 10%, var(--card)); border-color: color-mix(in srgb, #e6a100 35%, var(--line)); }
.rv.s-APPROVED { background: color-mix(in srgb, var(--green) 9%, var(--card)); }
.rv.s-REJECTED { background: color-mix(in srgb, var(--red) 8%, var(--card)); }
.rv-h { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.rv-t { display: grid; font-size: 14px; font-weight: 700; }
.rv-t small { font-size: 11.5px; color: var(--muted); font-weight: 500; }
.rv-v { font-size: 24px; font-weight: 800; letter-spacing: -0.02em; }
.rv-v small { font-size: 13px; color: var(--muted); font-weight: 600; }
.nt { margin: 0; font-size: 12.5px; color: var(--ink-2); overflow-wrap: anywhere; white-space: pre-line; }
.nt.bad { color: #b4232a; }
.dt { font-size: 11.5px; color: var(--muted); }
.rv-act { display: grid; grid-template-columns: 1fr 1.3fr; gap: 10px; margin-top: 4px; }
.hist { margin-top: 4px; font-size: 12.5px; }
.hist summary { cursor: pointer; font-weight: 700; color: var(--ink-2); min-height: 32px; display: flex; align-items: center; }
.hi { display: grid; gap: 1px; padding: 6px 0; border-top: 1px dashed var(--line); }
.hi span { color: #b4232a; overflow-wrap: anywhere; }
.tip { margin: 8px 0 0; font-size: 12.5px; color: var(--muted); }
.day { margin-top: 10px; padding: 10px 12px; border-radius: 14px; background: var(--bg); display: grid; gap: 4px; }
.day-h { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 13.5px; }
.day-h em { margin-left: auto; font-style: normal; font-weight: 800; color: #0b6b69; }
.tg { padding: 2px 9px; border-radius: 99px; background: #fdeaea; color: #b4232a; font-size: 11.5px; font-weight: 700; }
.dr { display: flex; justify-content: space-between; gap: 10px; font-size: 13px; padding: 4px 0; border-top: 1px dashed var(--line); }
.dr span { overflow-wrap: anywhere; }
.gridw { overflow: auto; max-height: 420px; border: 1px solid var(--line); border-radius: 12px; }
.gt { border-collapse: separate; border-spacing: 0; font-size: 12px; width: max-content; min-width: 100%; background: var(--card); }
.gt th, .gt td { padding: 6px 8px; border-bottom: 1px solid var(--line); border-right: 1px solid var(--line); text-align: center; white-space: nowrap; min-width: 40px; }
.gt thead th { position: sticky; top: 0; z-index: 2; background: var(--bg); font-weight: 700; }
.gt th.off { color: #b4232a; background: #fdeaea; }
.gt .c1 { position: sticky; left: 0; z-index: 3; min-width: 130px; max-width: 150px; text-align: left; font-weight: 700; background: var(--card); overflow: hidden; text-overflow: ellipsis; box-shadow: 2px 0 4px rgba(0, 0, 0, 0.06); }
.gt thead .c1 { background: var(--bg); z-index: 4; }
.gt .ct { position: sticky; right: 0; z-index: 3; font-weight: 800; background: #fff6d8; color: #8a5b00; box-shadow: -2px 0 4px rgba(0, 0, 0, 0.06); }
.gt tfoot td { position: sticky; bottom: 0; z-index: 2; font-weight: 800; background: #dff3ee; color: #0b4f4d; }
.gt tfoot .c1 { z-index: 4; }
.kv.tot span { font-weight: 800; }
.kv span:last-child { text-align: right; font-weight: 600; }
.warnbox { display: flex; align-items: center; gap: 8px; margin: 10px 0 0; padding: 9px 11px; border-radius: 12px; background: #fff3d6; color: #8a5b00; font-size: 12.5px; font-weight: 600; }
.hint { margin: 0 0 14px; padding: 8px 10px; border-radius: 10px; background: var(--blue-50); color: var(--ink-2); font-size: 12.5px; }
</style>
