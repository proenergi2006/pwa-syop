<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import AccuratePicker from '../components/AccuratePicker.vue'
import StateBox from '../components/StateBox.vue'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'
import { showToast } from '../composables/useToast'
import { angka, tanggal } from '../utils/format'
import { t } from '../i18n'

const router = useRouter()
const hariIni = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}` }
const opsi = ref({ produk: [], accurate: false })
const terminal = ref([])
const po = ref([])
const loading = ref(true)
const loadPo = ref(false)
const error = ref('')
const formError = ref('')
const busy = ref(false)
const konfirmasi = ref(false)
const f = reactive({
  id_jenis: '3', id_produk: '', tanggal: hariIni(), tanggal_terima: hariIni(), terminal_dari: '', terminal_ke: '', harga_transfer: '', keterangan: '',
  nomor_penyesuaian: '', kode_item: '', akun_penyesuaian: '', nomor_penyesuaian_terima: '', kode_item_terima: '',
})
const vol = reactive({}) // "ps|pr" -> volume transfer
const pilih = ref(null) // Adjustment: PO terpilih
const adj = reactive({ tanda: '+', volume: '', harga: '', q: '' })
const adjMode = computed(() => f.id_jenis === '3')
const poTampil = computed(() => { const q = adj.q.trim().toUpperCase(); return q ? po.value.filter((x) => String(x.nomor_po).toUpperCase().includes(q)) : po.value })
const barang = (q) => syop.poBarangAccurate(q)
const akun = (q) => syop.lossGainAkun(q)
const acc = computed(() => opsi.value.accurate)
const key = (x) => `${x.id_po_supplier}|${x.id_po_receive}`
const total = computed(() => po.value.reduce((s, x) => s + (Number(vol[key(x)]) || 0), 0))
const terminalKe = computed(() => terminal.value.filter((x) => String(x.id) !== String(f.terminal_dari)))

async function load() {
  loading.value = true
  error.value = ''
  try { opsi.value = await syop.adjFormOpsi() } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
watch(() => f.id_jenis, () => { f.terminal_dari = ''; f.terminal_ke = ''; po.value = []; pilih.value = null; formError.value = '' })
watch(() => f.id_produk, async (p) => {
  f.terminal_dari = ''
  f.terminal_ke = ''
  terminal.value = []
  po.value = []
  if (!p) return
  try { terminal.value = (await syop.adjTerminal(p)).data } catch (e) { formError.value = errorMessage(e) }
})
watch(() => f.terminal_dari, async (tm) => {
  po.value = []
  pilih.value = null
  Object.keys(vol).forEach((k) => delete vol[k])
  if (f.terminal_ke === tm) f.terminal_ke = ''
  if (!tm || !f.id_produk) return
  loadPo.value = true
  try { po.value = (await syop.adjPoTransfer(tm, f.id_produk)).data } catch (e) { formError.value = errorMessage(e) } finally { loadPo.value = false }
})
function isiSemua(x) { vol[key(x)] = Math.floor(x.sisa) }
function onVol(x, e) {
  const d = e.target.value.replace(/\D/g, '').slice(0, 12)
  vol[key(x)] = d
  e.target.value = d ? angka(d) : ''
}

function tinjau() {
  formError.value = ''
  if (adjMode.value) { tinjauAdj(); return }
  if (!f.id_produk || !f.terminal_dari || !f.terminal_ke || !f.tanggal || !f.tanggal_terima) { formError.value = 'Lengkapi produk, tanggal, dan terminal asal serta tujuan.'; return }
  if (!total.value) { formError.value = 'Isi volume transfer minimal pada satu PO.'; return }
  const lebih = po.value.find((x) => (Number(vol[key(x)]) || 0) > x.sisa)
  if (lebih) { formError.value = `Volume PO ${lebih.nomor_po} melebihi sisa (${angka(lebih.sisa)} L).`; return }
  if (f.harga_transfer === '' || Number(f.harga_transfer) < 0) { formError.value = 'Harga wajib diisi.'; return }
  if (acc.value && (!f.nomor_penyesuaian.trim() || !f.kode_item || !f.nomor_penyesuaian_terima.trim() || !f.kode_item_terima || !f.akun_penyesuaian)) {
    formError.value = 'Nomor penyesuaian, kode item, dan akun Accurate wajib diisi.'
    return
  }
  konfirmasi.value = true
}
function tinjauAdj() {
  if (!f.id_produk || !f.terminal_dari || !f.tanggal) { formError.value = 'Lengkapi produk, terminal, dan tanggal.'; return }
  if (!pilih.value) { formError.value = 'Pilih PO terlebih dahulu.'; return }
  const v = Number(adj.volume)
  if (!(v > 0)) { formError.value = 'Volume harus lebih dari 0.'; return }
  if (adj.tanda === '-' && v > pilih.value.sisa) { formError.value = `Volume loss melebihi sisa PO (${angka(pilih.value.sisa, 2)} L).`; return }
  if (adj.tanda === '+' && (adj.harga === '' || Number(adj.harga) < 0)) { formError.value = 'Harga wajib diisi untuk Gain.'; return }
  if (acc.value && (!f.nomor_penyesuaian.trim() || !f.kode_item || !f.akun_penyesuaian)) { formError.value = 'Nomor penyesuaian, kode item, dan akun Accurate wajib diisi.'; return }
  konfirmasi.value = true
}
function dataAdj() {
  return {
    id_jenis: 3, id_produk: f.id_produk, tanggal: f.tanggal, id_terminal: f.terminal_dari, id_po_supplier: pilih.value.id_po_supplier, id_po_receive: pilih.value.id_po_receive,
    tanda: adj.tanda, volume: adj.volume, harga: adj.tanda === '+' ? adj.harga : null, keterangan: f.keterangan || null,
    nomor_penyesuaian: f.nomor_penyesuaian || null, kode_item: f.kode_item || null, akun_penyesuaian: f.akun_penyesuaian || null,
  }
}
async function simpan() {
  busy.value = true
  try {
    const r = await syop.adjSimpan(adjMode.value ? dataAdj() : {
      id_jenis: 4, id_produk: f.id_produk, tanggal: f.tanggal, tanggal_terima: f.tanggal_terima, terminal_dari: f.terminal_dari, terminal_ke: f.terminal_ke,
      harga_transfer: f.harga_transfer, keterangan: f.keterangan || null,
      nomor_penyesuaian: f.nomor_penyesuaian || null, kode_item: f.kode_item || null, akun_penyesuaian: f.akun_penyesuaian || null,
      nomor_penyesuaian_terima: f.nomor_penyesuaian_terima || null, kode_item_terima: f.kode_item_terima || null,
      rincian: po.value.map((x) => ({ id_po_supplier: x.id_po_supplier, id_po_receive: x.id_po_receive, volume: Number(vol[key(x)]) || 0 })),
    })
    showToast(r.accurate_manual ? 'Tersimpan. Accurate belum aktif di server, transaksi Accurate tidak dikirim.' : 'Data berhasil disimpan.', 'success')
    router.replace({ name: 'adjustment-stock' })
  } catch (e) { konfirmasi.value = false; formError.value = errorMessage(e) } finally { busy.value = false }
}
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'adjustment-stock' }))
onMounted(load)
</script>

<template>
  <div class="page">
    <div class="page-head">
      <button class="icon-btn" :aria-label="t('common.back')" @click="back"><Icon name="back" :size="22" /></button>
      <div style="flex:1;min-width:0"><h1 class="page-title">Tambah Adjustment Stock</h1><p class="page-sub">{{ adjMode ? 'Adjustment' : 'Transfer Stock' }}</p></div>
    </div>
    <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load" />
    <div v-else-if="loading" class="skel" style="height:300px" />
    <template v-else>
      <section class="card">
        <div class="field"><label for="jn">Jenis penambahan *</label>
          <select id="jn" v-model="f.id_jenis" class="inp"><option value="3">Adjustment</option><option value="4">Transfer Stock</option></select></div>
        <div class="field"><label for="pr">Produk *</label>
          <select id="pr" v-model="f.id_produk" class="inp"><option value="">Pilih produk…</option><option v-for="p in opsi.produk" :key="p.id" :value="p.id">{{ p.label }}</option></select></div>
        <div class="field"><label for="tg">Tanggal {{ adjMode ? '*' : 'transfer *' }}</label><input id="tg" v-model="f.tanggal" type="date" class="inp" /></div>
        <div class="field"><label for="td">{{ adjMode ? 'Terminal / depot *' : 'Dari terminal / depot *' }}</label>
          <select id="td" v-model="f.terminal_dari" class="inp" :disabled="!f.id_produk"><option value="">{{ f.id_produk ? (adjMode ? 'Pilih terminal…' : 'Pilih terminal asal…') : 'Pilih produk dulu' }}</option><option v-for="x in terminal" :key="x.id" :value="x.id">{{ x.label }}</option></select></div>
      </section>

      <section v-if="f.terminal_dari && !adjMode" class="card">
        <h2 class="sec">PO yang dipindahkan</h2>
        <div v-if="loadPo" class="skel" style="height:90px" />
        <p v-else-if="!po.length" class="muted">Tidak ada inventory pada Terminal / Depot ini.</p>
        <div v-for="x in po" :key="key(x)" class="po">
          <div class="r1"><b>{{ x.nomor_po }}</b></div>
          <div class="r2">{{ x.vendor }} · PO {{ tanggal(x.tgl_po) }} · Terima {{ tanggal(x.tgl_terima) }}</div>
          <div class="r2">Terima {{ angka(x.volume_terima) }} L · Sisa <b>{{ angka(x.sisa) }} L</b></div>
          <div class="vrow">
            <input :value="vol[key(x)] ? angka(vol[key(x)]) : ''" inputmode="numeric" autocomplete="off" class="inp" placeholder="Volume transfer (L)" :aria-label="'Volume ' + x.nomor_po" @input="onVol(x, $event)" />
            <button type="button" class="mini" @click="isiSemua(x)">Semua</button>
          </div>
        </div>
        <div v-if="po.length" class="tot"><span>Total transfer</span><b>{{ angka(total) }} Liter</b></div>
      </section>

      <section v-if="f.terminal_dari && !adjMode" class="card">
        <h2 class="sec">Tujuan dan Accurate</h2>
        <div class="field"><label for="tk">Ke terminal / depot *</label>
          <select id="tk" v-model="f.terminal_ke" class="inp"><option value="">Pilih terminal tujuan…</option><option v-for="x in terminalKe" :key="x.id" :value="x.id">{{ x.label }}</option></select></div>
        <div class="field"><label for="tt">Tanggal penerimaan *</label><input id="tt" v-model="f.tanggal_terima" type="date" class="inp" /></div>
        <div class="field"><label for="hg">Harga * (Rp / liter)</label><input id="hg" v-model="f.harga_transfer" type="number" min="0" step="any" inputmode="decimal" class="inp" /></div>
        <template v-if="acc">
          <div class="field"><label for="np">Nomor penyesuaian *</label><input id="np" v-model="f.nomor_penyesuaian" class="inp" autocomplete="off" /></div>
          <AccuratePicker v-model="f.kode_item" :fetcher="barang" label="Kode item Accurate (dari terminal)" required />
          <div class="field"><label for="npt">Nomor penyesuaian terima *</label><input id="npt" v-model="f.nomor_penyesuaian_terima" class="inp" autocomplete="off" /></div>
          <AccuratePicker v-model="f.kode_item_terima" :fetcher="barang" label="Kode item Accurate (ke terminal)" required />
          <AccuratePicker v-model="f.akun_penyesuaian" :fetcher="akun" label="Akun Accurate" required />
        </template>
        <p v-else class="muted">Accurate belum aktif di server; data hanya disimpan di aplikasi.</p>
        <div class="field"><label for="kt">Keterangan</label><textarea id="kt" v-model="f.keterangan" class="inp" rows="3" /></div>
        <p v-if="formError" class="errmsg" role="alert">{{ formError }}</p>
        <button class="btn btn-primary btn-block" :disabled="!po.length" @click="tinjau">Simpan</button>
      </section>
      <section v-if="f.terminal_dari && adjMode" class="card">
        <h2 class="sec">Pilih PO</h2>
        <input v-model="adj.q" type="search" class="inp" placeholder="Cari nomor PO…" aria-label="Cari nomor PO" style="margin-bottom:6px" />
        <div v-if="loadPo" class="skel" style="height:90px" />
        <p v-else-if="!poTampil.length" class="muted">Data tidak ditemukan.</p>
        <div v-for="x in poTampil" :key="key(x)" class="po" :class="{ on: pilih && key(pilih) === key(x) }">
          <div class="r1"><b>{{ x.nomor_po }}</b></div>
          <div class="r2">{{ x.vendor }} · PO {{ tanggal(x.tgl_po) }} · Terima {{ tanggal(x.tgl_terima) }}</div>
          <div class="r2">Terima {{ angka(x.volume_terima) }} L · Sisa <b>{{ angka(x.sisa) }} L</b></div>
          <button type="button" class="mini pilihbtn" :class="{ sel: pilih && key(pilih) === key(x) }" @click="pilih = x">{{ pilih && key(pilih) === key(x) ? 'Dipilih' : 'Pilih' }}</button>
        </div>
      </section>

      <section v-if="f.terminal_dari && adjMode" class="card">
        <h2 class="sec">Adjustment</h2>
        <div class="field"><label for="tn">Jenis adjustment *</label>
          <select id="tn" v-model="adj.tanda" class="inp"><option value="+">Bertambah / Gain (+)</option><option value="-">Berkurang / Loss (-)</option></select></div>
        <div class="field"><label for="vl">Volume * (Liter)</label><input id="vl" v-model="adj.volume" type="number" min="0" step="any" inputmode="decimal" class="inp" /></div>
        <p v-if="adj.tanda === '-' && pilih" class="muted" style="margin:-6px 0 10px">Sisa PO terpilih {{ angka(pilih.sisa, 2) }} L.</p>
        <div v-if="adj.tanda === '+'" class="field"><label for="hl">Harga * (Rp / liter)</label><input id="hl" v-model="adj.harga" type="number" min="0" step="any" inputmode="decimal" class="inp" /></div>
        <template v-if="acc">
          <div class="field"><label for="npa">Nomor penyesuaian *</label><input id="npa" v-model="f.nomor_penyesuaian" class="inp" autocomplete="off" /></div>
          <AccuratePicker v-model="f.kode_item" :fetcher="barang" label="Kode item Accurate" required />
          <AccuratePicker v-model="f.akun_penyesuaian" :fetcher="akun" label="Akun Accurate" required />
        </template>
        <p v-else class="muted">Accurate belum aktif di server; data hanya disimpan di aplikasi.</p>
        <div class="field"><label for="kta">Keterangan</label><textarea id="kta" v-model="f.keterangan" class="inp" rows="3" /></div>
        <p v-if="formError" class="errmsg" role="alert">{{ formError }}</p>
        <button class="btn btn-primary btn-block" :disabled="!pilih" @click="tinjau">Simpan</button>
      </section>
      <p v-else-if="formError" class="errmsg" role="alert">{{ formError }}</p>
    </template>

    <div v-if="konfirmasi" class="sheet-back" @click.self="!busy && (konfirmasi = false)">
      <div class="sheet" role="dialog" aria-modal="true"><div class="grab" /><h2 class="sheet-t">{{ adjMode ? 'Simpan adjustment?' : 'Simpan transfer stock?' }}</h2>
        <p v-if="adjMode" class="muted" style="margin:6px 0 12px">{{ adj.tanda === '+' ? 'Gain' : 'Loss' }} {{ angka(adj.volume, 2) }} L pada PO {{ pilih?.nomor_po }}{{ acc ? ' dan dicatat sebagai penyesuaian ' + (adj.tanda === '+' ? 'IN' : 'OUT') + ' di Accurate' : '' }}. Bila Accurate menolak, data tidak disimpan.</p>
        <p v-else class="muted" style="margin:6px 0 12px">{{ angka(total) }} L dipindahkan antar terminal{{ acc ? ' dan dicatat sebagai penyesuaian OUT dan IN di Accurate' : '' }}. Bila Accurate menolak, data tidak disimpan.</p>
        <div class="sheet-act"><button class="btn btn-ghost" :disabled="busy" @click="konfirmasi = false">Batal</button><button class="btn btn-primary" :disabled="busy" @click="simpan">{{ busy ? 'Menyimpan…' : 'Ya, simpan' }}</button></div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.card { margin-bottom: 12px; }
.sec { margin: 0 0 10px; font-size: 14.5px; }
.inp { width: 100%; min-height: 46px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; box-sizing: border-box; }
textarea.inp { padding: 10px 12px; }
.po { padding: 10px 0; border-bottom: 1px solid var(--line); }
.r1 { font-size: 14px; overflow-wrap: anywhere; } .r2 { color: var(--muted); font-size: 12.5px; margin-top: 2px; }
.vrow { display: flex; gap: 8px; margin-top: 8px; align-items: center; } .vrow .inp { flex: 1; min-width: 0; }
.mini { flex: none; min-height: 46px; padding: 0 14px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--accent); font-weight: 600; font-size: 13px; }
.po.on { background: color-mix(in srgb, var(--accent) 7%, transparent); border-radius: 10px; padding-left: 8px; padding-right: 8px; }
.pilihbtn { margin-top: 8px; width: 100%; } .pilihbtn.sel { background: var(--accent); color: #fff; border-color: var(--accent); }
.tot { display: flex; justify-content: space-between; padding-top: 12px; font-size: 14px; }
.errmsg { color: var(--red); font-size: 13.5px; margin: 0 0 10px; }
</style>
