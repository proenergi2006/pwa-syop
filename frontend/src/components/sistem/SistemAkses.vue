<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { t } from '../../i18n'
import Icon from '../Icon.vue'
import StateBox from '../StateBox.vue'
import * as syop from '../../api/syop'
import { errorMessage } from '../../api/client'
import { showToast } from '../../composables/useToast'
import { tanggalJam } from '../../utils/format'

// Nama modul untuk tampilan (kunci = config/orca.php module_roles).
const NAMA = {
  penawaran: 'Penawaran', inventory: 'Inventori / Stok', produk: 'Master Produk', harga: 'Master Harga', po: 'Verifikasi PO Supplier (Minyak)', po_batu: 'Verifikasi PO Supplier Batu', po_supplier_batu: 'PO Supplier Batu (Procurement)', dr: 'Delivery Request',
  master: 'Master Terminal & Vendor', trucking_dr: 'Trucking Delivery Request', sc: 'Verifikasi Sales Confirmation', permohonan: 'Verifikasi Permohonan', lcr: 'Verifikasi LCR',
  refund: 'Penerima Refund', verifikasi_customer: 'Verifikasi Data Customer', trucking: 'Trucking Instruction', shipping: 'Shipping Instruction', report: 'Report Marketing',
  rssp_input: 'Input Report RSSP', rssp: 'Report RSSP', oa: 'Verifikasi Request OA', forecast_bm: 'Forecast BM', forecast_om: 'Forecast OM', forecast: 'Verifikasi Forecast (CEO)',
  pengumuman: 'Pengumuman (baca)', pengumuman_admin: 'Kelola Pengumuman', sistem: 'Pusat Admin (Sistem)', mobil: 'Mobil', reservasi: 'Reservasi', stok_terminal: 'Stok Terminal',
}
const nama = (k) => NAMA[k] || k

const d = ref(null)
const loading = ref(true)
const error = ref('')
const q = ref('')
async function load() {
  loading.value = true
  error.value = ''
  try { d.value = await syop.sistemAkses() } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
onMounted(load)

const peranNama = computed(() => Object.fromEntries((d.value?.peran || []).map((p) => [p.id, p.nama])))
const list = computed(() => (d.value?.modul || []).filter((m) => !q.value || nama(m.kunci).toLowerCase().includes(q.value.toLowerCase()) || m.kunci.includes(q.value.toLowerCase())))
const teks = (roles) => (roles[0] === '*' ? t('sys.a.all') : roles.map((r) => peranNama.value[r] || r).join(', '))

const e = reactive({ open: false, modul: null, semua: false, roles: [], busy: false, error: '' })
function ubah(m) {
  Object.assign(e, { open: true, modul: m, semua: m.roles[0] === '*', roles: m.roles[0] === '*' ? [] : [...m.roles], busy: false, error: '' })
}
const toggle = (id) => { e.semua = false; e.roles = e.roles.includes(id) ? e.roles.filter((x) => x !== id) : [...e.roles, id] }
const sama = computed(() => e.modul && JSON.stringify(e.semua ? ['*'] : [...e.roles].sort((a, b) => a - b)) === JSON.stringify(e.modul.bawaan))
async function simpan() {
  if (!e.semua && !e.roles.length) return (e.error = t('sys.a.min'))
  e.busy = true
  e.error = ''
  try {
    const r = await syop.sistemSimpanAkses(e.modul.kunci, e.semua ? ['*'] : e.roles)
    showToast(r.message, 'ok')
    e.open = false
    await load()
  } catch (err) { e.error = errorMessage(err) } finally { e.busy = false }
}
async function kembalikan() {
  e.busy = true
  try {
    const r = await syop.sistemResetAkses(e.modul.kunci)
    showToast(r.message, 'ok')
    e.open = false
    await load()
  } catch (err) { e.error = errorMessage(err) } finally { e.busy = false }
}
</script>

<template>
  <p class="warnbox"><Icon name="info" :size="16" /> {{ t('sys.a.warn') }}</p>
  <input v-model="q" class="in" type="search" :placeholder="t('sys.a.search')" style="margin-bottom:12px" />
  <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load" />
  <div v-else-if="loading" class="stack"><div v-for="i in 5" :key="i" class="skel" style="height:72px" /></div>
  <div v-else class="stack">
    <button v-for="m in list" :key="m.kunci" class="card mrow" @click="ubah(m)">
      <span class="mt"><b>{{ nama(m.kunci) }}</b><span v-if="m.diubah" class="tag">{{ t('sys.a.changed') }}</span><Icon v-if="m.terkunci" name="lock" :size="13" /></span>
      <span class="mr">{{ teks(m.roles) }}</span>
      <small v-if="m.diubah" class="mo">{{ t('sys.a.by', { n: m.oleh || '—', t: tanggalJam(m.pada) }) }}</small>
    </button>
  </div>

  <div v-if="e.open" class="sheet-back full" @click.self="!e.busy && (e.open = false)">
    <div class="sheet tall" role="dialog" aria-modal="true">
      <div class="grab" />
      <div class="fh"><h2 class="sheet-t">{{ nama(e.modul.kunci) }}</h2><button class="x" :aria-label="t('common.close')" @click="e.open = false"><Icon name="x" :size="18" /></button></div>
      <p class="muted" style="margin:0 0 10px">{{ t('sys.a.default') }}: {{ teks(e.modul.bawaan) }}</p>
      <p v-if="e.modul.terkunci" class="hint">{{ t('sys.a.locked') }}</p>
      <div class="chips wrapc">
        <button class="chip" :class="{ on: e.semua }" @click="e.semua = !e.semua; if (e.semua) e.roles = []">{{ t('sys.a.all') }}</button>
        <button v-for="p in d.peran" :key="p.id" class="chip" :class="{ on: !e.semua && e.roles.includes(p.id) }" @click="toggle(p.id)">{{ p.nama }} <small>{{ p.pengguna }}</small></button>
      </div>
      <p v-if="e.error" class="err-text" role="alert" style="margin:10px 0 0">{{ e.error }}</p>
      <div class="sheet-act" style="margin-top:14px">
        <button v-if="e.modul.diubah" class="btn btn-ghost" :disabled="e.busy" @click="kembalikan">{{ t('sys.a.reset') }}</button>
        <button class="btn btn-primary" :disabled="e.busy || sama" @click="simpan">{{ e.busy ? t('det.processing') : t('sys.save') }}</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.in { width: 100%; height: 48px; border-radius: 14px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; font-weight: 600; padding: 0 14px; font-family: inherit; }
.warnbox { display: flex; gap: 8px; align-items: flex-start; margin: 0 0 12px; padding: 10px 12px; border-radius: 12px; background: #fff1d6; color: #8a5600; font-size: 12.5px; font-weight: 600; }
.mrow { width: 100%; text-align: left; padding: 12px 14px; display: grid; gap: 4px; border: 1px solid var(--line); font-family: inherit; color: var(--ink); }
.mt { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.mt b { font-size: 14px; font-weight: 800; }
.tag { padding: 2px 8px; border-radius: 99px; background: #fff1d6; color: #8a5600; font-size: 10.5px; font-weight: 800; }
.mr { font-size: 12.5px; color: var(--ink-2); overflow-wrap: anywhere; }
.mo { font-size: 11px; color: var(--muted); }
.sheet.tall { max-height: 94vh; overflow-y: auto; }
.fh { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.x { width: 40px; height: 40px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); display: grid; place-items: center; }
.wrapc { flex-wrap: wrap; }
.chip small { margin-left: 6px; opacity: 0.7; font-size: 11px; }
.hint { margin: 0 0 12px; padding: 8px 10px; border-radius: 10px; background: var(--blue-50); color: var(--ink-2); font-size: 12.5px; }
</style>
