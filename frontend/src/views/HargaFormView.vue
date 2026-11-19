<script setup>
import { t } from '../i18n'
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import PriceInput from '../components/PriceInput.vue'
import StateBox from '../components/StateBox.vue'
import { useAuth } from '../stores/auth'
import * as syop from '../api/syop'
import { errorCode, errorMessage } from '../api/client'
import { showToast } from '../composables/useToast'

const route = useRoute()
const router = useRouter()
const auth = useAuth()
const editing = computed(() => !!route.query.awal)
const key = { awal: route.query.awal, akhir: route.query.akhir, area: route.query.area, produk: route.query.produk }

const opts = ref({ area: [], produk: [] })
const loading = ref(true)
const error = ref('')
const busy = ref(false)
const formError = ref('')
const pwd = ref('')
const submitted = ref(false)
const needPassword = computed(() => false) // tidak ada konfirmasi password di modul ini (hanya di Profil untuk wajah)
const awal = ref('')
const akhir = ref('')
const blank = () => ({ area: '', produk: '', normal: '', sm: '', om: '', coo: '', ceo: '', note: '' })
const rows = reactive([blank()])
const label = ref('') // nama area - produk saat ubah

const strip = (s) => (s || '').replace(/<br\s*\/?>|<\/p>/gi, '\n').replace(/<[^>]+>/g, '').replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&').trim()
const n = (v) => (v === '' || v == null ? NaN : Number(v))
// Peringatan lunak: urutan harga biasanya menurun Dasar ≥ BM ≥ OM ≥ COO ≥ CEO.
const orderWarn = (r) => {
  const seq = [r.normal, r.sm, r.om, r.coo, r.ceo].map(n).filter((x) => !isNaN(x) && x > 0)
  return seq.some((x, i) => i > 0 && x > seq[i - 1])
}
const rowErr = (r) => submitted.value && (!r.area && !editing.value ? 'area' : !r.produk && !editing.value ? 'produk' : !(n(r.normal) > 0) ? 'normal' : !(n(r.sm) > 0) ? 'sm' : !(n(r.om) > 0) ? 'om' : '')

async function init() {
  loading.value = true
  try {
    opts.value = await syop.hargaOptions()
    if (editing.value) {
      const d = await syop.getHarga(key)
      awal.value = d.periode_awal
      akhir.value = d.periode_akhir
      label.value = `${d.nama_area} · ${d.jenis_produk} - ${d.merk_dagang}`
      Object.assign(rows[0], { area: d.id_area, produk: d.produk, normal: String(d.harga_normal), sm: String(d.harga_sm), om: String(d.harga_om), coo: String(d.harga_coo || ''), ceo: String(d.harga_ceo || ''), note: strip(d.note_jual) })
    }
  } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
const addRow = () => rows.push(blank())
const delRow = (i) => rows.splice(i, 1)
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'harga' }))

async function submit() {
  submitted.value = true
  formError.value = ''
  if (!editing.value) {
    if (!awal.value || !akhir.value) return (formError.value = t('hrg.err.periode'))
    if (akhir.value < awal.value) return (formError.value = t('hrg.err.periodeOrder'))
  }
  const bad = rows.findIndex((r) => rowErr(r))
  if (bad >= 0) return (formError.value = t('hrg.err.row', { n: bad + 1 }))
  if (needPassword.value && !pwd.value) return (formError.value = t('det.errPwd'))
  busy.value = true
  try {
    const pw = needPassword.value ? pwd.value : undefined
    const price = (r) => ({ normal: +r.normal, sm: +r.sm, om: +r.om, coo: r.coo === '' ? null : +r.coo, ceo: r.ceo === '' ? null : +r.ceo, note: r.note || null })
    const res = editing.value
      ? await syop.updateHarga({ ...key, h: price(rows[0]), password: pw })
      : await syop.createHarga({ periode_awal: awal.value, periode_akhir: akhir.value, items: rows.map((r) => ({ area: +r.area, produk: +r.produk, ...price(r) })), password: pw })
    showToast(res.message, 'ok')
    router.replace({ name: 'harga' })
  } catch (e) {
    if (errorCode(e) === 'STEP_UP_REQUIRED') auth.method = 'face'
    formError.value = errorMessage(e)
  } finally { busy.value = false }
}
onMounted(init)
</script>

<template>
  <div>
    <header class="bar">
      <button class="back" :aria-label="t('common.back')" @click="back"><Icon name="back" :size="20" /></button>
      <div class="bar-t"><b>{{ editing ? t('hrg.editTitle') : t('hrg.addTitle') }}</b></div>
      <span style="width:44px" />
    </header>

    <div class="wrap">
      <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="init" />
      <div v-else-if="loading" class="stack"><div class="skel" style="height:140px" /><div class="skel" style="height:300px" /></div>
      <form v-else class="stack" @submit.prevent="submit">
        <section class="card box">
          <h2>{{ t('hrg.periode') }}</h2>
          <template v-if="editing">
            <div class="kv"><span>{{ t('hrg.start') }}</span><span>{{ awal }}</span></div>
            <div class="kv"><span>{{ t('hrg.end') }}</span><span>{{ akhir }}</span></div>
            <p class="hint">{{ t('hrg.periodeLocked') }}</p>
            <p class="lbl2">{{ label }}</p>
          </template>
          <div v-else class="two">
            <div class="field"><label for="pa">{{ t('hrg.start') }} *</label><input id="pa" v-model="awal" type="date" class="sel" required /></div>
            <div class="field"><label for="pe">{{ t('hrg.end') }} *</label><input id="pe" v-model="akhir" type="date" class="sel" required :min="awal" /></div>
          </div>
        </section>

        <section v-for="(r, i) in rows" :key="i" class="card box">
          <div class="rh">
            <h2>{{ editing ? t('hrg.prices') : t('hrg.rowN', { n: i + 1 }) }}</h2>
            <button v-if="!editing && rows.length > 1" type="button" class="rm" :aria-label="t('hrg.removeRow')" @click="delRow(i)"><Icon name="x" :size="16" /></button>
          </div>
          <template v-if="!editing">
            <div class="field"><label :for="'a' + i">{{ t('inv.f.cabang') }} *</label>
              <select :id="'a' + i" v-model="r.area" class="sel" :class="{ bad: submitted && !r.area }"><option value="">{{ t('hrg.choose') }}</option><option v-for="o in opts.area" :key="o.id" :value="o.id">{{ o.label }}</option></select></div>
            <div class="field"><label :for="'p' + i">{{ t('inv.f.produk') }} *</label>
              <select :id="'p' + i" v-model="r.produk" class="sel" :class="{ bad: submitted && !r.produk }"><option value="">{{ t('hrg.choose') }}</option><option v-for="o in opts.produk" :key="o.id" :value="o.id">{{ o.label }}</option></select></div>
          </template>
          <div class="grid2">
            <PriceInput v-model="r.normal" :label="t('hrg.base') + ' *'" :hint="t('hrg.baseHint')" :invalid="submitted && !(n(r.normal) > 0)" class="span2" />
            <PriceInput v-model="r.sm" :label="t('hrg.t1') + ' *'" :invalid="submitted && !(n(r.sm) > 0)" />
            <PriceInput v-model="r.om" :label="t('hrg.t2') + ' *'" :invalid="submitted && !(n(r.om) > 0)" />
            <PriceInput v-model="r.coo" :label="t('hrg.t3coo')" />
            <PriceInput v-model="r.ceo" :label="t('hrg.t3ceo')" />
          </div>
          <p v-if="orderWarn(r)" class="warn"><Icon name="alert" :size="14" /> {{ t('hrg.orderWarn') }}</p>
          <div class="field" style="margin-top:10px"><label :for="'n' + i">{{ t('hrg.note') }}</label><textarea :id="'n' + i" v-model="r.note" class="plain" maxlength="5000" /></div>
        </section>

        <button v-if="!editing" type="button" class="btn btn-ghost btn-block" @click="addRow"><Icon name="plus" :size="18" /> {{ t('hrg.addRow') }}</button>

        <section v-if="needPassword" class="card box">
          <div class="field"><label for="sp">{{ t('det.yourPwd') }}</label>
            <div class="input"><Icon name="lock" :size="20" /><input id="sp" v-model="pwd" type="password" autocomplete="current-password" :placeholder="t('det.confirmPwd')" /></div>
            <small class="muted">{{ t('det.faceNote') }}</small></div>
        </section>
        <p v-if="formError" class="err-text" role="alert">{{ formError }}</p>
        <button class="btn btn-primary btn-block" :disabled="busy">{{ busy ? t('det.processing') : editing ? t('hrg.saveEdit') : t('hrg.save') }}</button>
      </form>
    </div>
  </div>
</template>

<style scoped>
.bar { position: sticky; top: 0; z-index: 10; display: flex; align-items: center; gap: 8px; padding: calc(var(--safe-top) + 8px) 12px 8px; background: var(--glass-bar); backdrop-filter: blur(12px); border-bottom: 1px solid var(--line); }
.bar-t { flex: 1; text-align: center; font-size: 14.5px; }
.back { width: 44px; height: 44px; border-radius: 13px; border: 1px solid var(--line); background: var(--card); color: var(--ink); display: grid; place-items: center; }
.wrap { padding: 12px 16px calc(var(--safe-bottom) + 32px); }
.box { padding: 14px 16px; }
.box h2 { font-size: 14px; font-weight: 700; margin: 0 0 10px; }
.two { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 10px; }
.two .sel { padding: 0 8px; font-size: 14px; }
.grid2 { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 10px; }
.wrap, .stack > * { min-width: 0; }
.span2 { grid-column: 1 / -1; }
.rh { display: flex; justify-content: space-between; align-items: center; }
.rm { width: 32px; height: 32px; border-radius: 10px; border: 1px solid var(--line); background: var(--card); color: var(--red); display: grid; place-items: center; margin-bottom: 10px; }
.sel { width: 100%; min-height: 46px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; }
.sel.bad { border-color: var(--red); }
.hint { font-size: 12.5px; color: var(--muted); margin: 8px 0 4px; }
.lbl2 { font-weight: 700; margin: 4px 0 0; font-size: 14px; }
.warn { display: flex; align-items: center; gap: 6px; margin: 10px 0 0; padding: 6px 10px; border-radius: 10px; background: var(--amber-bg); color: var(--late); font-size: 12px; font-weight: 600; }
</style>
