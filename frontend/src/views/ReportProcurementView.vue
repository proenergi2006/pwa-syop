<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import StateBox from '../components/StateBox.vue'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'
import { openFile } from '../utils/openFile'
import { showToast } from '../composables/useToast'
import { angka, tanggal } from '../utils/format'

const router = useRouter()
const TABS = [
  { k: 'loaded-truck', t: 'Rekap Loaded Truck', excel: true, tgl: 'Tanggal Loaded' },
  { k: 'loaded-kapal', t: 'Rekap Loaded Kapal', excel: true, tgl: 'Tanggal Loaded' },
  { k: 'cancel-trip', t: 'Cancel Trip', excel: true, tgl: 'Tanggal DR' },
  { k: 'stok-truck', t: 'History Stock Truck', terminal: true, tgl: 'Tanggal Potong' },
  { k: 'stok-operasional', t: 'History Stock Operasional (BPUJ)', terminal: true, tgl: 'Tanggal Potong' },
]
const tab = ref('loaded-truck')
const cur = computed(() => TABS.find((x) => x.k === tab.value))
const q = ref('')
const f = reactive({ cabang: '', terminal: '', dari: '', sampai: '' })
const draft = reactive({ ...f })
const sheet = ref(false)
const opsi = ref({ cabang: [], terminal: [] })
const items = ref([])
const meta = ref({ page: 1, last: 1, total: 0 })
const totalHal = ref(0)
const loading = ref(true)
const more = ref(false)
const error = ref('')
const xls = ref(false)
const detail = ref(null)
const edit = ref(null)
const tglBaru = ref('')
const busy = ref(false)
let seq = 0
let timer

const active = computed(() => ['cabang', 'terminal', 'dari', 'sampai'].filter((k) => f[k]).length)
const params = (page) => ({ page, per_page: 15, q: q.value.trim() || undefined, cabang: f.cabang || undefined, terminal: cur.value.terminal || tab.value === 'loaded-truck' ? f.terminal || undefined : undefined, dari: f.dari || undefined, sampai: f.dari && f.sampai ? f.sampai : undefined })

async function load(page = 1) {
  const my = ++seq
  page === 1 ? (loading.value = true) : (more.value = true)
  error.value = ''
  try {
    const r = await syop.rpList(tab.value, params(page))
    if (my !== seq) return
    items.value = page === 1 ? r.data : [...items.value, ...r.data]
    meta.value = r.meta
    totalHal.value = r.total_halaman
  } catch (e) { if (my === seq) error.value = errorMessage(e) } finally { if (my === seq) { loading.value = false; more.value = false } }
}
const apply = () => { Object.assign(f, draft); sheet.value = false; load(1) }
const reset = () => { Object.keys(draft).forEach((k) => { draft[k] = ''; f[k] = '' }); sheet.value = false; load(1) }
watch(q, () => { clearTimeout(timer); timer = setTimeout(() => load(1), 400) })
watch(tab, () => { items.value = []; load(1) })
async function exportXls() {
  xls.value = true
  const p = new URLSearchParams(Object.entries(params(1)).filter(([k, v]) => v !== undefined && !['page', 'per_page'].includes(k)))
  try { await openFile(`/report/procurement/${tab.value}/export?${p}`, ({ 'loaded-truck': 'Rekap-Loaded.xlsx', 'loaded-kapal': 'Rekap-Loaded-Kapal.xlsx', 'cancel-trip': 'Cancel-Trip.xlsx' })[tab.value]) } catch (e) { showToast(errorMessage(e), 'error') } finally { xls.value = false }
}
async function simpanTanggal() {
  if (!tglBaru.value) return
  busy.value = true
  try { await syop.rpUbahTanggal(edit.value.id, tglBaru.value); showToast('Tanggal potong diubah.', 'success'); edit.value = null; await load(1) } catch (e) { showToast(errorMessage(e), 'error') } finally { busy.value = false }
}
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'report' }))
onMounted(() => { load(1); syop.rpOpsi().then((o) => { opsi.value = o }).catch(() => {}) })
</script>

<template>
  <div class="page">
    <div class="page-head">
      <button class="icon-btn" aria-label="Kembali" @click="back"><Icon name="back" :size="22" /></button>
      <div style="flex:1;min-width:0"><h1 class="page-title">Report Procurement</h1><p class="page-sub">{{ cur.t }}</p></div>
    </div>

    <div class="chips" role="tablist"><button v-for="x in TABS" :key="x.k" class="chip" :class="{ on: tab === x.k }" role="tab" @click="tab = x.k">{{ x.t }}</button></div>

    <div class="bar2">
      <label class="search" style="flex:1;margin:0"><Icon name="search" :size="20" /><input v-model="q" type="search" placeholder="Kata kunci…" /></label>
      <button class="fbtn" :class="{ on: active }" aria-label="Filter" @click="Object.assign(draft, f); sheet = true"><Icon name="filter" :size="20" /><b v-if="active">{{ active }}</b></button>
    </div>
    <button v-if="cur.excel" class="btn btn-ghost btn-block" style="margin-bottom:12px" :disabled="xls" @click="exportXls"><Icon name="doc" :size="18" /> {{ xls ? 'Menyiapkan…' : 'Export Data (.xlsx)' }}</button>

    <StateBox v-if="error" icon="alert" title="Gagal memuat" :text="error" action="Coba lagi" @action="load(1)" />
    <div v-else-if="loading" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:150px" /><p v-if="tab === 'stok-operasional'" class="muted" style="text-align:center">Laporan ini berat, mohon tunggu beberapa detik…</p></div>
    <StateBox v-else-if="!items.length" icon="check" title="Data tidak ditemukan" text="Tidak ada data yang cocok." />
    <template v-else>
      <p class="total">{{ angka(meta.total) }} data · total halaman ini {{ angka(totalHal, totalHal % 1 ? 4 : 0) }} L</p>
      <div class="stack">
        <template v-if="tab === 'loaded-truck' || tab === 'loaded-kapal'">
          <div v-for="x in items" :key="x.id" class="item">
            <div class="item-top"><div class="item-who"><span>{{ x.nomor_dr }}</span></div><span class="pill" :class="x.status === 'Delivered' ? 'pill-ok' : x.status === 'Cancel' ? 'pill-no' : 'pill-wait'">{{ x.status || '-' }}</span></div>
            <div class="item-title">{{ x.customer }}</div>
            <div class="item-meta">{{ x.cabang }} · LO {{ x.nomor_lo }}</div>
            <div v-if="x.transportir || x.spj" class="item-meta">{{ x.transportir }}<template v-if="x.spj"> · SPJ {{ x.spj }}</template></div>
            <div class="item-meta">{{ x.terminal }}</div>
            <div class="vols"><span>Qty <b>{{ angka(x.qty) }}</b> L</span><span>DR {{ tanggal(x.tgl_dr) }}</span><span>Loaded {{ tanggal(x.tgl_loaded) }}</span></div>
            <small v-if="x.tgl_loading" class="item-meta">Tgl Loading {{ x.tgl_loading }}<template v-if="x.jam_loading"> · Jam {{ x.jam_loading }}</template></small>
            <small v-if="x.tgl_cancel" class="item-meta">Tgl Cancel {{ x.tgl_cancel }}</small>
          </div>
        </template>
        <template v-else-if="tab === 'cancel-trip'">
          <div v-for="x in items" :key="x.id" class="item">
            <div class="item-top"><div class="item-who"><span>{{ x.nomor_dr }}</span></div><span class="pill" :class="x.status === 'Terverifikasi' ? 'pill-ok' : x.status === 'Ditolak' ? 'pill-no' : 'pill-wait'">{{ x.status || '-' }}</span></div>
            <div class="item-title">{{ x.customer }}</div>
            <div class="item-meta">{{ x.cabang }} · PO {{ x.nomor_po }}<template v-if="x.nomor_dn"> · DN {{ x.nomor_dn }}</template></div>
            <div class="vols"><span>Volume <b>{{ angka(x.qty) }}</b> L</span><span>DR {{ tanggal(x.tgl_dr) }}</span><span v-if="x.tgl_request">Request {{ tanggal(x.tgl_request) }}</span></div>
            <div v-if="x.alasan" class="item-meta">Alasan: {{ x.alasan }}</div>
            <div v-if="x.tgl_status" class="item-meta">{{ x.status }} {{ x.tgl_status }}</div>
          </div>
        </template>
        <template v-else-if="tab === 'stok-truck'">
          <div v-for="x in items" :key="x.id" class="item">
            <div class="item-top"><div class="item-who"><span>{{ x.nomor_dr || '-' }}</span></div><span class="pill" :class="x.jenis === 'Loaded' ? 'pill-ok' : 'pill-wait'">{{ x.jenis }}</span></div>
            <div class="item-title">{{ x.customer || '-' }}</div>
            <div class="item-meta">{{ x.cabang }} · PO {{ x.nomor_po }} · LO {{ x.nomor_lo }}</div>
            <div class="item-meta">{{ x.terminal }}</div>
            <div class="vols"><span>Qty <b>{{ angka(x.qty) }}</b> L</span><span>DR {{ tanggal(x.tgl_dr) }}</span><span>Potong {{ tanggal(x.tgl_potong) }}</span></div>
            <button v-if="x.bisa_ubah" class="mini" @click="edit = x; tglBaru = x.tgl_potong"><Icon name="edit" :size="16" /> Ubah tanggal potong</button>
          </div>
        </template>
        <template v-else>
          <button v-for="(x, i) in items" :key="i" class="item" @click="detail = x">
            <div class="item-top"><div class="item-who"><span>{{ x.nomor || '-' }}</span></div><span class="pill" :class="x.tipe === 'bpuj' ? 'pill-wait' : 'pill-ok'">{{ x.tipe === 'bpuj' ? 'BPUJ' : 'Operasional' }}</span></div>
            <div class="item-title">{{ x.tujuan || '-' }}</div>
            <div class="item-meta">{{ x.terminal }} · PO {{ x.nomor_po || '-' }}</div>
            <div class="item-meta">{{ x.keterangan }}</div>
            <div class="vols"><span>Qty <b>{{ angka(x.qty, x.qty % 1 ? 4 : 0) }}</b> L</span><span>Potong {{ tanggal(x.tgl_potong) }}</span></div>
            <div class="item-meta">Admin PIC {{ x.admin_pic || '-' }} · Dibuat {{ x.created_by || '-' }}</div>
          </button>
        </template>
      </div>
      <button v-if="meta.page < meta.last" class="btn btn-ghost btn-block" style="margin-top:16px" :disabled="more" @click="load(meta.page + 1)">{{ more ? 'Memuat…' : 'Muat lebih' }}</button>
    </template>

    <div v-if="sheet" class="sheet-back" @click.self="sheet = false">
      <div class="sheet" role="dialog" aria-modal="true"><div class="grab" /><h2 class="sheet-t" style="margin-bottom:14px">Filter</h2>
        <div class="field"><label for="fc">Cabang</label><select id="fc" v-model="draft.cabang" class="sel"><option value="">Semua</option><option v-for="c in opsi.cabang" :key="c.id" :value="String(c.id)">{{ c.label }}</option></select></div>
        <div v-if="cur.terminal" class="field"><label for="ft">Terminal</label><select id="ft" v-model="draft.terminal" class="sel"><option value="">Semua</option><option v-for="c in opsi.terminal" :key="c.id" :value="String(c.id)">{{ c.label }}</option></select></div>
        <div class="two"><div class="field"><label for="d1">{{ cur.tgl }} dari</label><input id="d1" v-model="draft.dari" type="date" class="sel" /></div><div class="field"><label for="d2">S/D</label><input id="d2" v-model="draft.sampai" type="date" class="sel" :disabled="!draft.dari" /></div></div>
        <div class="sheet-act" style="margin-top:6px"><button class="btn btn-ghost" @click="reset">Reset</button><button class="btn btn-primary" @click="apply">Terapkan</button></div>
      </div>
    </div>

    <div v-if="edit" class="sheet-back" @click.self="!busy && (edit = null)">
      <div class="sheet" role="dialog" aria-modal="true"><div class="grab" /><h2 class="sheet-t">Ubah tanggal potong</h2>
        <p class="muted" style="margin:6px 0 10px">{{ edit.nomor_dr }} · LO {{ edit.nomor_lo }}. Tanggal loading pada DS ikut berubah.</p>
        <div class="field"><label for="tb">Tanggal potong</label><input id="tb" v-model="tglBaru" type="date" class="sel" /></div>
        <div class="sheet-act"><button class="btn btn-ghost" :disabled="busy" @click="edit = null">Batal</button><button class="btn btn-primary" :disabled="busy || !tglBaru" @click="simpanTanggal">{{ busy ? 'Menyimpan…' : 'Simpan' }}</button></div>
      </div>
    </div>

    <div v-if="detail" class="sheet-back" @click.self="detail = null">
      <div class="sheet" role="dialog" aria-modal="true" style="max-height:85vh;overflow:auto"><div class="grab" />
        <template v-if="detail.tipe === 'operasional'">
          <h2 class="sheet-t">Detail Pengisian Operasional</h2>
          <dl class="kv"><dt>Nomor voucher</dt><dd>{{ detail.detail.nomor_voucher }}</dd><dt>Mobil</dt><dd>{{ detail.detail.mobil }}</dd><dt>Driver</dt><dd>{{ detail.detail.driver }}</dd><dt>Volume</dt><dd>{{ detail.detail.volume }}</dd><dt>Keterangan</dt><dd>{{ detail.detail.keterangan }}</dd>
            <dt>Tanggal realisasi</dt><dd>{{ detail.detail.tanggal_realisasi }}</dd><dt>Driver realisasi</dt><dd>{{ detail.detail.driver_realisasi }}</dd><dt>Volume realisasi</dt><dd>{{ detail.detail.volume_realisasi }}</dd><dt>Keterangan realisasi</dt><dd>{{ detail.detail.keterangan_realisasi }}</dd></dl>
        </template>
        <template v-else>
          <h2 class="sheet-t">Detail BPUJ</h2>
          <dl class="kv"><dt>Nomor BPUJ</dt><dd>{{ detail.detail.nomor_bpuj }}</dd><dt>Customer</dt><dd>{{ detail.detail.customer }}</dd><dt>Tanggal BPUJ</dt><dd>{{ detail.detail.tanggal_bpuj }}</dd><dt>Tanggal realisasi</dt><dd>{{ detail.detail.tanggal_realisasi }}</dd></dl>
          <h3 class="sub">Pengajuan</h3><p v-if="!detail.detail.pengajuan.length" class="muted">Tidak ada.</p>
          <div v-for="(p, i) in detail.detail.pengajuan" :key="'a' + i" class="pg"><b>{{ p.tipe }}</b><span>{{ p.volume }} L</span><small>{{ p.sumber }} · {{ p.tanggal }} · {{ p.driver }} · {{ p.mobil }}</small></div>
          <h3 class="sub">Realisasi</h3><p v-if="!detail.detail.realisasi.length" class="muted">Tidak ada.</p>
          <div v-for="(p, i) in detail.detail.realisasi" :key="'r' + i" class="pg"><b>{{ p.tipe }}</b><span>{{ p.volume }} L</span><small>{{ p.sumber }} · {{ p.tanggal }} · {{ p.driver }} · {{ p.mobil }}</small></div>
        </template>
        <button class="btn btn-ghost btn-block" style="margin-top:12px" @click="detail = null">Tutup</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.chips { display: flex; gap: 8px; overflow-x: auto; margin-bottom: 12px; padding-bottom: 2px; } .chip { flex: none; white-space: nowrap; }
.bar2 { display: flex; gap: 10px; align-items: center; margin-bottom: 12px; }
.fbtn { position: relative; flex: none; width: 48px; height: 48px; border-radius: 14px; background: var(--card); border: 1px solid var(--line); display: grid; place-items: center; color: var(--ink-2); } .fbtn.on { color: var(--accent); border-color: var(--focus); }
.fbtn b { position: absolute; top: -6px; right: -6px; min-width: 20px; height: 20px; padding: 0 5px; border-radius: 10px; background: var(--red); color: #fff; font-size: 11px; display: grid; place-items: center; }
.total { margin: 0 0 8px; color: var(--muted); font-size: 12px; font-weight: 600; }
.stack, .stack > * { min-width: 0; max-width: 100%; }
.item { width: 100%; box-sizing: border-box; text-align: left; overflow: hidden; }
.item-title, .item-meta { min-width: 0; overflow-wrap: anywhere; }
.vols { display: flex; flex-wrap: wrap; gap: 4px 14px; margin: 8px 0 4px; padding-top: 8px; border-top: 1px dashed var(--line); font-size: 13px; color: var(--muted); } .vols b { color: var(--ink); }
.mini { display: inline-flex; align-items: center; gap: 6px; min-height: 36px; padding: 0 12px; margin-top: 8px; border-radius: 10px; border: 1px solid var(--line); background: var(--card); color: var(--ink-2); font-size: 13px; font-weight: 600; }
.sel { width: 100%; min-height: 46px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; box-sizing: border-box; }
.two { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 10px; }
.muted { color: var(--muted); font-size: 12.5px; }
.kv { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.3fr); gap: 8px 12px; margin: 8px 0; font-size: 14px; } .kv dt { color: var(--muted); } .kv dd { margin: 0; font-weight: 600; text-align: right; overflow-wrap: anywhere; }
.sub { font-size: 14px; margin: 14px 0 6px; }
.pg { display: grid; grid-template-columns: 1fr auto; gap: 2px 10px; padding: 8px 0; border-top: 1px dashed var(--line); font-size: 13.5px; } .pg small { grid-column: 1 / -1; color: var(--muted); }
</style>
