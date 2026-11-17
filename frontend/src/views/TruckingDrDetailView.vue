<script setup>
import { t } from '../i18n'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import StateBox from '../components/StateBox.vue'
import { useAuth } from '../stores/auth'
import { useInbox } from '../stores/inbox'
import * as syop from '../api/syop'
import { errorCode, errorMessage } from '../api/client'
import { showToast } from '../composables/useToast'
import { angka, tanggal, tanggalJam } from '../utils/format'

const props = defineProps({ id: String })
const router = useRouter()
const auth = useAuth()
const inbox = useInbox()
const d = ref(null)
const loading = ref(true)
const error = ref('')
const form = reactive({}) // id_prd -> { po, split: null | { volume, po } }
const catatan = ref('')
const picker = ref(null) // { prd, part }
const poList = ref([])
const poLoading = ref(false)
const poQ = ref('')
const confirmSheet = ref(null) // 'save' | 'revise' | 'return'
const pwd = ref('')
const busy = ref(false)
const formError = ref('')
const needPassword = computed(() => auth.method === 'face')
let timer

const editable = computed(() => !!d.value?.can_save)

async function load() {
  loading.value = true
  error.value = ''
  try {
    d.value = await syop.getTruckingDr(props.id)
    for (const k of Object.keys(form)) delete form[k]
    for (const l of d.value.lines) form[l.id_prd] = { po: null, split: null }
    catatan.value = d.value.terverifikasi ? '' : d.value.catatan_purchasing || ''
  } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'trucking-dr' }))

// ---- hitungan sisi klien (volume bagian 1 / bagian 2 bila di-split)
const vol1 = (l) => (form[l.id_prd]?.split ? Number(form[l.id_prd].split.volume1 || 0) : l.vol_ori)
const vol2 = (l) => (form[l.id_prd]?.split ? l.vol_ori - vol1(l) : 0)
const key = (p) => `${p.id_po_receive}|${p.id_terminal}`
const usedBy = computed(() => {
  const m = {}
  for (const l of d.value?.lines || []) {
    const f = form[l.id_prd]
    if (f?.po) m[key(f.po)] = (m[key(f.po)] || 0) + vol1(l)
    if (f?.split?.po) m[key(f.split.po)] = (m[key(f.split.po)] || 0) + vol2(l)
  }
  return m
})

// ---- pemilih PO Supplier
async function openPicker(l, part) {
  picker.value = { prd: l.id_prd, part }
  poQ.value = ''
  await loadPo()
}
async function loadPo() {
  poLoading.value = true
  try { poList.value = (await syop.truckingDrPoSupplier(props.id, poQ.value.trim())).data } catch (e) { poList.value = []; formError.value = errorMessage(e) } finally { poLoading.value = false }
}
watch(poQ, () => { clearTimeout(timer); timer = setTimeout(loadPo, 300) })
const pickerLine = computed(() => d.value?.lines.find((l) => l.id_prd === picker.value?.prd))
const visiblePo = computed(() => {
  const l = pickerLine.value
  if (!l) return []
  const f = form[l.id_prd]
  // Split harus dari depot yang sama dengan bagian 1.
  return picker.value.part === 2 && f.po ? poList.value.filter((p) => p.id_terminal === f.po.id_terminal) : poList.value
})
function choose(p) {
  const l = pickerLine.value
  const f = form[l.id_prd]
  const part = picker.value.part
  const need = part === 1 ? vol1(l) : vol2(l)
  const other = { ...usedBy.value }
  const cur = part === 1 ? f.po : f.split?.po
  if (cur) other[key(cur)] = (other[key(cur)] || 0) - need
  const used = other[key(p)] || 0
  if (used + need > p.sisa) {
    showToast(`${t('dr.err.stock')}: ${angka(p.sisa - used)} L`, 'err')
    return
  }
  if (part === 1) {
    f.po = p
    if (f.split?.po && f.split.po.id_terminal !== p.id_terminal) f.split.po = null // depot harus sama
  } else f.split.po = p
  picker.value = null
}
function toggleSplit(l) {
  const f = form[l.id_prd]
  f.split = f.split ? null : { volume1: Math.max(1, Math.floor(l.vol_ori / 2)), po: null }
}
const splitBad = (l) => form[l.id_prd]?.split && !(vol1(l) >= 1 && vol1(l) < l.vol_ori)

// ---- aksi
function ask(kind) {
  formError.value = ''
  pwd.value = ''
  if (kind === 'save') {
    for (const l of d.value.lines) {
      const f = form[l.id_prd]
      if (!f.po || (f.split && !f.split.po)) return showToast(t('dr.err.po'), 'err')
      if (splitBad(l)) return showToast(t('dr.err.split'), 'err')
    }
  }
  confirmSheet.value = kind
}
async function run() {
  if (needPassword.value && !pwd.value) return (formError.value = t('det.errPwd'))
  busy.value = true
  formError.value = ''
  const extra = needPassword.value ? { password: pwd.value } : {}
  try {
    let r
    if (confirmSheet.value === 'save') {
      const lines = d.value.lines.map((l) => {
        const f = form[l.id_prd]
        const po = (p) => ({ id_po_supplier: p.id_po_supplier, id_po_receive: p.id_po_receive, id_terminal: p.id_terminal })
        return { id_prd: l.id_prd, volume: vol1(l), po: po(f.po), ...(f.split ? { split: { volume: vol2(l), po: po(f.split.po) } } : {}) }
      })
      r = await syop.verifyTruckingDr(props.id, { catatan: catatan.value.trim() || undefined, lines, ...extra })
    } else if (confirmSheet.value === 'revise') r = await syop.reviseTruckingDr(props.id, extra)
    else r = await syop.returnTruckingDr(props.id, extra)
    const was = confirmSheet.value
    confirmSheet.value = null
    showToast(r.message, 'ok')
    inbox.refresh()
    if (was === 'return') router.replace({ name: 'trucking-dr' })
    else await load()
  } catch (e) {
    if (errorCode(e) === 'STEP_UP_REQUIRED') auth.method = 'face'
    formError.value = errorMessage(e)
  } finally { busy.value = false }
}
const stLabel = (s) => t('dr.st.' + s.kode)
onMounted(load)
</script>

<template>
  <div>
    <header class="bar">
      <button class="back" :aria-label="t('common.back')" @click="back"><Icon name="back" :size="20" /></button>
      <div class="bar-t"><b>{{ t('tdr.detail') }}</b><small v-if="d">{{ d.nomor_dr }}</small></div>
      <button class="back" :aria-label="t('det.refresh')" @click="load"><Icon name="refresh" :size="18" /></button>
    </header>

    <div class="wrap">
      <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load" />
      <div v-else-if="loading" class="stack"><div class="skel" style="height:140px" /><div class="skel" style="height:320px" /></div>
      <template v-else-if="d">
        <section class="card box">
          <h1>{{ d.nomor_dr }}</h1>
          <span class="pill" :class="d.disposisi >= 1 ? 'pill-ok' : 'pill-wait'">{{ d.status }}</span>
          <div class="kv"><span>{{ t('dr.date') }}</span><span>{{ tanggal(d.tanggal) }}</span></div>
          <div class="kv"><span>{{ t('dr.branch') }}</span><span>{{ d.cabang }}</span></div>
          <div class="kv"><span>{{ t('dr.submit') }}</span><span>{{ d.dr_submit ? tanggalJam(d.dr_submit).split(' · ').pop() + ' WIB' : '-' }}</span></div>
          <div class="kv"><span>{{ t('dr.release') }}</span><span>{{ d.dr_release ? tanggalJam(d.dr_release).split(' · ').pop() + ' WIB' : '-' }}</span></div>
        </section>

        <section v-for="(l, i) in d.lines" :key="l.id_prd" class="card box line">
          <div class="lh"><span class="no">{{ i + 1 }}</span><div><b>{{ l.nomor_ti }}</b><small>{{ t('tdr.etl') }} {{ l.tgl_loading ? tanggal(l.tgl_loading) : '-' }}</small></div></div>
          <div class="kv col"><span>{{ t('tdr.loadPort') }}</span><span>{{ l.loading_port }}</span></div>
          <div class="kv col"><span>{{ t('tdr.dischPort') }}</span><span>{{ l.discharging_port }}</span></div>

          <div class="grid3">
            <div><small>{{ t('dr.l.volAwal') }}</small><b>{{ angka(l.volume_awal) }}</b></div>
            <div><small>{{ t('dr.l.vol') }}</small><b>{{ angka(editable ? vol1(l) : l.volume) }}</b></div>
            <div><small>{{ t('dr.l.potong') }}</small><b>{{ angka(editable ? vol2(l) : l.vol_potongan) }}<template v-if="!editable && l.volume_potong"> + {{ angka(l.volume_potong) }}</template></b></div>
          </div>

          <!-- PO Supplier / depot / harga beli -->
          <div class="po">
            <template v-if="editable">
              <div class="part">
                <small v-if="form[l.id_prd].split">{{ t('dr.part1') }} · {{ angka(vol1(l)) }} L</small>
                <button class="pick" :class="{ set: form[l.id_prd].po }" @click="openPicker(l, 1)">
                  <template v-if="form[l.id_prd].po"><b>{{ form[l.id_prd].po.nomor_po }}</b><span>{{ form[l.id_prd].po.terminal }}</span><span>Rp {{ angka(form[l.id_prd].po.harga_tebus) }} /L · {{ t('dr.po.stock') }} {{ angka(form[l.id_prd].po.sisa - (usedBy[key(form[l.id_prd].po)] || 0)) }} L</span></template>
                  <span v-else>{{ t('dr.pickHint') }}</span>
                </button>
              </div>
              <div v-if="form[l.id_prd].split" class="part">
                <small>{{ t('dr.part2') }} · {{ angka(vol2(l)) }} L</small>
                <button class="pick" :class="{ set: form[l.id_prd].split.po }" :disabled="!form[l.id_prd].po" @click="openPicker(l, 2)">
                  <template v-if="form[l.id_prd].split.po"><b>{{ form[l.id_prd].split.po.nomor_po }}</b><span>{{ form[l.id_prd].split.po.terminal }}</span><span>Rp {{ angka(form[l.id_prd].split.po.harga_tebus) }} /L</span></template>
                  <span v-else>{{ t('dr.pickHint') }}</span>
                </button>
                <div class="field" style="margin-top:8px"><label :for="'v' + l.id_prd">{{ t('dr.part1') }} ({{ t('dr.l.vol') }})</label>
                  <input :id="'v' + l.id_prd" v-model.number="form[l.id_prd].split.volume1" class="sel" :class="{ bad: splitBad(l) }" type="number" inputmode="numeric" min="1" :max="l.vol_ori - 1" /></div>
              </div>
              <button class="lnk" @click="toggleSplit(l)">{{ form[l.id_prd].split ? t('dr.unsplit') : t('dr.split') }}</button>
            </template>
            <template v-else>
              <div class="kv col"><span>{{ t('dr.l.po') }}</span><span>{{ l.po_supplier || '-' }}<template v-if="l.po_potong"><br />{{ l.po_potong }}</template></span></div>
              <div class="kv col"><span>{{ t('dr.l.depot') }}</span><span>{{ l.depot || '-' }}<template v-if="l.depot_potong"><br />{{ l.depot_potong }}</template></span></div>
            </template>
          </div>

          <div v-if="l.catatan_logistik" class="kv col"><span>{{ t('dr.l.noteLog') }}</span><span>{{ l.catatan_logistik }}</span></div>
          <div class="kv col"><span>{{ t('dr.l.lo') }}</span><span>{{ l.nomor_lo || 'N/A' }}</span></div>
          <div class="kv"><span>{{ t('dr.l.status') }}</span><span><b>{{ stLabel(l.status) }}</b><template v-if="l.status.tanggal"> · {{ tanggal(l.status.tanggal) }}</template><template v-if="l.status.jam"> {{ l.status.jam }}</template></span></div>
        </section>

        <section class="card box">
          <div class="kv"><span>{{ t('dr.total') }}</span><span>{{ angka(d.terverifikasi ? d.total_volume : d.lines.reduce((s, l) => s + l.vol_ori, 0)) }} L</span></div>
          <div class="field" style="margin-top:10px">
            <label for="cp">{{ t('dr.notePur') }}</label>
            <textarea v-if="editable" id="cp" v-model="catatan" class="plain" maxlength="2000" />
            <p v-else class="pre muted">{{ d.catatan_purchasing || '-' }}<br /><i v-if="d.purchasing_pic">{{ d.purchasing_pic }} · {{ tanggalJam(d.purchasing_tanggal) }}</i></p>
          </div>
        </section>
        <p class="muted tiny">{{ t('tdr.legacyNote') }}</p>
      </template>
    </div>

    <footer v-if="d && (d.can_save || d.can_revise)" class="actions">
      <template v-if="d.can_save">
        <button class="btn btn-ghost danger" @click="ask('return')">{{ t('dr.return') }}</button>
        <button class="btn btn-primary" @click="ask('save')"><Icon name="check" :size="18" /> {{ t('dr.save') }}</button>
      </template>
      <button v-else class="btn btn-primary" style="grid-column:1/-1" @click="ask('revise')">{{ t('dr.revise') }}</button>
    </footer>

    <!-- Pemilih PO Supplier -->
    <div v-if="picker" class="sheet-back" @click.self="picker = null">
      <div class="sheet tall" role="dialog" aria-modal="true" :aria-label="t('dr.poList')">
        <div class="grab" />
        <h2 class="sheet-t">{{ t('dr.poList') }}</h2>
        <label class="search" style="margin:10px 0"><Icon name="search" :size="20" /><input v-model="poQ" type="search" :placeholder="t('dr.poSearch')" :aria-label="t('dr.poSearch')" /></label>
        <div v-if="poLoading" class="skel" style="height:120px" />
        <p v-else-if="!visiblePo.length" class="muted" style="text-align:center;padding:20px 0">{{ t('dr.po.empty') }}</p>
        <ul v-else class="polist">
          <li v-for="p in visiblePo" :key="p.id_po_receive + '-' + p.id_terminal">
            <div>
              <b>{{ p.nomor_po }}</b><span>{{ p.terminal }}</span><small>{{ p.vendor }}</small>
              <small>{{ t('dr.po.stock') }} {{ angka(p.sisa) }} L · {{ t('dr.po.volPo') }} {{ angka(p.vol_po) }} · {{ t('dr.po.volIn') }} {{ angka(p.vol_terima) }}</small>
              <small>{{ t('dr.po.price') }} Rp {{ angka(p.harga_tebus) }}</small>
            </div>
            <button class="btn btn-primary btn-sm" @click="choose(p)">{{ t('dr.po.pick') }}</button>
          </li>
        </ul>
      </div>
    </div>


    <!-- Konfirmasi -->
    <div v-if="confirmSheet" class="sheet-back" @click.self="!busy && (confirmSheet = null)">
      <div class="sheet" role="dialog" aria-modal="true">
        <div class="grab" />
        <h2 class="sheet-t">{{ confirmSheet === 'save' ? t('tdr.q.save') : confirmSheet === 'revise' ? t('dr.q.revise') : t('dr.q.return') }}</h2>
        <p class="muted" style="margin:4px 0 16px">{{ d.nomor_dr }}<template v-if="confirmSheet === 'revise'"><br />{{ t('tdr.q.reviseText') }}</template><template v-if="confirmSheet === 'return'"><br />{{ t('dr.q.returnText') }}</template></p>
        <div v-if="needPassword" class="field">
          <label for="dpw">{{ t('det.yourPwd') }}</label>
          <div class="input"><Icon name="lock" :size="20" /><input id="dpw" v-model="pwd" type="password" autocomplete="current-password" :placeholder="t('det.confirmPwd')" /></div>
          <small class="muted">{{ t('det.faceNote') }}</small>
        </div>
        <p v-if="formError" class="err-text" role="alert" style="margin-bottom:12px">{{ formError }}</p>
        <div class="sheet-act">
          <button class="btn btn-ghost" :disabled="busy" @click="confirmSheet = null">{{ t('common.cancel') }}</button>
          <button class="btn" :class="confirmSheet === 'return' ? 'btn-danger' : 'btn-primary'" :disabled="busy" @click="run">{{ busy ? t('dr.saving') : t('dr.yes') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.bar { position: sticky; top: 0; z-index: 10; display: flex; align-items: center; gap: 8px; padding: calc(var(--safe-top) + 8px) 12px 8px; background: var(--glass-bar); backdrop-filter: blur(12px); border-bottom: 1px solid var(--line); }
.bar-t { flex: 1; min-width: 0; text-align: center; display: grid; }
.bar-t b { font-size: 14.5px; font-weight: 700; }
.bar-t small { font-size: 12px; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.back { width: 44px; height: 44px; border-radius: 13px; border: 1px solid var(--line); background: var(--card); color: var(--ink); display: grid; place-items: center; }
.wrap { padding: 12px 16px calc(var(--safe-bottom) + 110px); display: grid; gap: 12px; }
.box { padding: 14px 16px; min-width: 0; }
.box h1 { font-size: 18px; font-weight: 800; letter-spacing: -0.02em; margin: 0 0 6px; }
.box h2 { font-size: 14px; font-weight: 700; margin: 0 0 6px; }
.kv span:last-child { text-align: right; font-weight: 600; overflow-wrap: anywhere; }
.kv.col { grid-template-columns: 1fr; gap: 2px; }
.kv.col span:last-child { text-align: left; }
.kv.sub { padding-left: 12px; font-size: 12.5px; }
.kv.sub span:first-child { color: var(--muted); }
.line .lh { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 6px; }
.line .no { flex: none; width: 26px; height: 26px; border-radius: 50%; background: var(--blue-50); color: var(--accent); display: grid; place-items: center; font-weight: 800; font-size: 13px; }
.line .lh b { display: block; font-size: 14.5px; }
.line .lh small { color: var(--muted); font-size: 12px; }
.lnk { background: none; border: 0; color: var(--accent); font-weight: 700; font-size: 13px; padding: 6px 0; display: inline-flex; align-items: center; gap: 6px; }
.grid3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin: 8px 0; }
.grid3 > div { display: grid; padding: 8px 10px; border-radius: 12px; background: var(--blue-50); }
.grid3 small { font-size: 11px; color: var(--muted); }
.grid3 b { font-size: 14px; }
.po { margin: 6px 0 10px; padding: 10px; border: 1px dashed var(--line); border-radius: 14px; display: grid; gap: 8px; }
.part small { display: block; font-size: 12px; font-weight: 700; color: var(--muted); margin-bottom: 4px; }
.pick { width: 100%; text-align: left; display: grid; gap: 2px; padding: 10px 12px; border-radius: 12px; border: 1.5px dashed var(--line); background: var(--card); color: var(--muted); font-size: 13px; }
.pick.set { border-style: solid; border-color: var(--focus); color: var(--ink); }
.pick b { font-size: 14px; }
.pick:disabled { opacity: 0.5; }
.neg { color: var(--red); }
.warn { display: flex; align-items: center; gap: 6px; margin: 0; padding: 8px 12px; border-radius: 12px; background: var(--amber-bg); color: var(--late); font-size: 12.5px; font-weight: 600; }
.note-box { background: var(--blue-50); }
.note-box p { margin: 0; font-size: 13px; }
.pre { white-space: pre-line; }
.tiny { font-size: 12px; margin: 2px 0 6px; }
.sel { width: 100%; min-height: 46px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; }
.sel.bad { border-color: var(--red); }
.actions { position: fixed; left: 50%; transform: translateX(-50%); bottom: 0; width: min(480px, 100%); display: grid; grid-template-columns: 1fr 1.4fr; gap: 10px; padding: 10px 16px calc(var(--safe-bottom) + 10px); background: var(--glass); backdrop-filter: blur(14px); border-top: 1px solid var(--line); z-index: 15; }
.danger { color: var(--red); }
.sheet.tall { max-height: 88dvh; }
.polist, .hist { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
.polist li { display: flex; gap: 10px; align-items: center; justify-content: space-between; padding: 10px 12px; border-radius: 14px; background: var(--blue-50); }
.polist li > div { display: grid; gap: 1px; min-width: 0; font-size: 13px; }
.polist small, .hist small { color: var(--muted); font-size: 12px; overflow-wrap: anywhere; }
.hist li { display: grid; gap: 2px; padding: 10px 12px; border-radius: 14px; background: var(--blue-50); }
.btn-sm { padding: 0 14px; min-height: 38px; font-size: 13px; }
</style>
