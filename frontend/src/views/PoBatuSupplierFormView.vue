<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import StateBox from '../components/StateBox.vue'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'
import { showToast } from '../composables/useToast'
import { angka } from '../utils/format'

const props = defineProps({ id: String })
const router = useRouter()
const ubah = computed(() => !!props.id)
const opsi = ref({ produk: [], terminal: [], vendor: [] })
const loading = ref(true)
const error = ref('')
const busy = ref(false)
const formError = ref('')
const konfirmasi = ref(false)
const info = ref({})
const f = reactive({ tanggal: '', id_produk: '', id_terminal: '', id_vendor: '', terms: '', terms_day: '', kd_tax: '', volume_po: '', harga_tebus: '', deskripsi: '', ket: '', syarat: false, terms_condition: '' })

// Rumus sama dengan form web: sub total = volume x harga, PPN 11% dibulatkan (kode tax EC tanpa PPN), total = sub total + PPN.
const hasil = computed(() => {
  const sub = (Number(f.volume_po) || 0) * (Number(f.harga_tebus) || 0)
  const ppn = f.kd_tax === 'EC' ? 0 : Math.round((sub * 11) / 100)
  return { subtotal: sub, ppn_11: ppn, total_order: sub + ppn }
})
const rp = (v) => 'Rp ' + angka(v, Number(v) % 1 ? 2 : 0)
const nama = (list, id) => list.find((x) => String(x.id) === String(id))?.label || ''

async function load() {
  loading.value = true
  error.value = ''
  try {
    opsi.value = await syop.pbsOpsi()
    if (ubah.value) {
      const d = await syop.pbsUbahForm(props.id)
      info.value = d
      Object.assign(f, { tanggal: String(d.tanggal_inven).slice(0, 10), id_produk: String(d.id_produk), id_terminal: String(d.id_terminal), id_vendor: String(d.id_vendor), terms: d.terms || '', terms_day: d.terms_day || '',
        kd_tax: d.kd_tax || '', volume_po: d.volume_po, harga_tebus: d.harga_tebus, deskripsi: d.description || '', ket: d.keterangan || '', syarat: !!d.terms_condition, terms_condition: d.terms_condition || '' })
    } else {
      f.tanggal = new Date(Date.now() + 7 * 3600e3).toISOString().slice(0, 10)
    }
  } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
const back = () => (window.history.length > 1 ? router.back() : router.replace(ubah.value ? { name: 'po-batu-supplier-detail', params: { id: props.id } } : { name: 'po-batu-supplier' }))

function tinjau() {
  formError.value = ''
  const w = []
  if (!f.tanggal) w.push('Tanggal PO')
  if (!ubah.value && !f.id_produk) w.push('Produk')
  if (!ubah.value && !f.id_terminal) w.push('Terminal')
  if (!ubah.value && !f.id_vendor) w.push('Vendor')
  if (!f.terms) w.push('Terms')
  if (f.terms === 'NET' && f.terms_day === '') w.push('Hari untuk terms NET')
  if (!f.kd_tax) w.push('Kode tax')
  if (!(Number(f.volume_po) > 0)) w.push('Volume PO')
  if (!(Number(f.harga_tebus) > 0)) w.push('Harga dasar')
  if (!f.deskripsi.trim()) w.push('Deskripsi')
  if (!f.ket.trim()) w.push('Catatan PO')
  if (f.syarat && !f.terms_condition.trim()) w.push('Syarat & ketentuan tambahan')
  if (w.length) { formError.value = 'Lengkapi: ' + w.join(', ') + '.'; return }
  konfirmasi.value = true
}
function payload() {
  const p = { tanggal: f.tanggal, terms: f.terms, terms_day: f.terms === 'NET' ? Number(f.terms_day) : undefined, kd_tax: f.kd_tax, volume_po: Number(f.volume_po), harga_tebus: Number(f.harga_tebus),
    deskripsi: f.deskripsi.trim(), ket: f.ket.trim(), terms_condition: f.syarat ? f.terms_condition.trim() : '' }
  if (!ubah.value) Object.assign(p, { id_produk: Number(f.id_produk), id_terminal: Number(f.id_terminal), id_vendor: Number(f.id_vendor) })
  return p
}
async function simpan() {
  busy.value = true
  try {
    const r = await syop.pbsSimpan(props.id, payload())
    showToast(r.message, 'success')
    router.replace({ name: 'po-batu-supplier-detail', params: { id: ubah.value ? props.id : r.id } })
  } catch (e) { konfirmasi.value = false; formError.value = errorMessage(e) } finally { busy.value = false }
}
onMounted(load)
</script>

<template>
  <div class="page">
    <div class="page-head">
      <button class="icon-btn" aria-label="Kembali" @click="back"><Icon name="back" :size="22" /></button>
      <div style="flex:1;min-width:0"><h1 class="page-title">{{ ubah ? 'Ubah PO Supplier Batu' : 'Tambah PO Supplier Batu' }}</h1><p class="page-sub">{{ ubah ? info.nomor_po : 'Nomor PO dibuat otomatis' }}</p></div>
    </div>
    <StateBox v-if="error" icon="alert" title="Tidak dapat dibuka" :text="error" action="Coba lagi" @action="load" />
    <div v-else-if="loading" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:120px" /></div>
    <template v-else>
      <p v-if="ubah" class="alert">PERHATIAN! Mengubah data ini akan mengulang proses persetujuan PO supplier.</p>
      <p v-if="info.revert_cfo == 1" class="warn"><b>Catatan pengembalian CFO:</b> {{ String(info.revert_cfo_summary || '').replace(/<br\s*\/?>/gi, ' ') }}</p>
      <p v-if="info.revert_ceo == 1" class="warn"><b>Catatan pengembalian CEO:</b> {{ String(info.revert_ceo_summary || '').replace(/<br\s*\/?>/gi, ' ') }}</p>

      <section class="card">
        <h2 class="sec">Data PO</h2>
        <div class="field"><label for="tg">Tanggal PO *</label><input id="tg" v-model="f.tanggal" type="date" class="inp" /></div>
        <div class="field"><label for="pr">Produk *</label>
          <input v-if="ubah" class="inp" :value="nama(opsi.produk, f.id_produk) || info.jenis_produk + ' - ' + info.merk_dagang" disabled />
          <select v-else id="pr" v-model="f.id_produk" class="inp"><option value="">Pilih produk</option><option v-for="x in opsi.produk" :key="x.id" :value="String(x.id)">{{ x.label }}</option></select></div>
        <div class="field"><label for="tm">Terminal *</label>
          <input v-if="ubah" class="inp" :value="nama(opsi.terminal, f.id_terminal) || info.terminal" disabled />
          <select v-else id="tm" v-model="f.id_terminal" class="inp"><option value="">Pilih terminal</option><option v-for="x in opsi.terminal" :key="x.id" :value="String(x.id)">{{ x.label }}</option></select></div>
        <div class="field"><label for="vd">Vendor *</label>
          <input v-if="ubah" class="inp" :value="nama(opsi.vendor, f.id_vendor) || info.nama_vendor" disabled />
          <select v-else id="vd" v-model="f.id_vendor" class="inp"><option value="">Pilih vendor</option><option v-for="x in opsi.vendor" :key="x.id" :value="String(x.id)">{{ x.label }}</option></select></div>
        <div class="field"><label for="te">Terms *</label><select id="te" v-model="f.terms" class="inp"><option value="">Pilih</option><option value="COD">C.O.D</option><option value="NET">NET</option><option value="CBD">CBD</option></select></div>
        <div v-if="f.terms === 'NET'" class="field"><label for="td">Hari terms NET *</label><input id="td" v-model="f.terms_day" type="number" min="0" max="999" inputmode="numeric" class="inp" /></div>
        <div class="field"><label for="kt">Kode tax *</label><select id="kt" v-model="f.kd_tax" class="inp"><option value="">Pilih</option><option value="E">E</option><option value="EC">EC</option></select></div>
      </section>

      <section class="card">
        <h2 class="sec">Harga</h2>
        <div class="field"><label for="vo">Volume PO * (m³)</label><input id="vo" v-model="f.volume_po" type="number" min="0" step="any" inputmode="decimal" class="inp" /></div>
        <div class="field"><label for="hd">Harga dasar * (Rp per m³)</label><input id="hd" v-model="f.harga_tebus" type="number" min="0" step="any" inputmode="decimal" class="inp" /></div>
        <dl class="kv"><dt>Sub total</dt><dd>{{ rp(hasil.subtotal) }}</dd><dt>PPN 11%</dt><dd>{{ rp(hasil.ppn_11) }}</dd></dl>
        <dl class="kv total"><dt>Total order</dt><dd>{{ rp(hasil.total_order) }}</dd></dl>
      </section>

      <section class="card">
        <h2 class="sec">Catatan</h2>
        <div class="field"><label for="ds">Deskripsi *</label><textarea id="ds" v-model="f.deskripsi" class="inp" rows="2" /></div>
        <div class="field"><label for="ct">Catatan PO *</label><textarea id="ct" v-model="f.ket" class="inp" rows="3" /></div>
        <div class="field"><label class="chk"><input v-model="f.syarat" type="checkbox" /> Syarat &amp; Ketentuan tambahan (tercetak di halaman kedua)</label>
          <textarea v-if="f.syarat" v-model="f.terms_condition" class="inp" rows="4" placeholder="Satu butir per baris" /></div>
        <p v-if="formError" class="errmsg">{{ formError }}</p>
        <button class="btn btn-primary btn-block" @click="tinjau">Simpan</button>
      </section>
    </template>

    <div v-if="konfirmasi" class="sheet-back" @click.self="!busy && (konfirmasi = false)">
      <div class="sheet" role="dialog" aria-modal="true">
        <div class="grab" />
        <h2 class="sheet-t">Yakin simpan?</h2>
        <p class="muted" style="margin:6px 0 12px">{{ ubah ? 'Persetujuan PO diulang dari awal.' : 'PO baru berstatus Terdaftar; ajukan persetujuan dari halaman detail.' }} Total order {{ rp(hasil.total_order) }}.</p>
        <div class="sheet-act"><button class="btn btn-ghost" :disabled="busy" @click="konfirmasi = false">Batal</button><button class="btn btn-primary" :disabled="busy" @click="simpan">{{ busy ? 'Menyimpan…' : 'Simpan' }}</button></div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.card { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 14px; margin-bottom: 12px; }
.sec { font-size: 15px; margin: 0 0 10px; }
.inp { width: 100%; min-height: 46px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; box-sizing: border-box; }
textarea.inp { padding: 10px 12px; }
.chk { display: inline-flex; align-items: center; gap: 6px; font-weight: 500; margin-bottom: 6px; }
.kv { display: grid; grid-template-columns: 1fr auto; gap: 6px 12px; margin: 10px 0; font-size: 14px; } .kv dt { color: var(--muted); } .kv dd { margin: 0; font-weight: 600; text-align: right; }
.kv.total { border-top: 1px dashed var(--line); padding-top: 10px; font-size: 16px; } .kv.total dt { color: var(--ink); font-weight: 700; }
.muted { color: var(--muted); font-size: 12.5px; }
.alert { background: #ffe0e0; color: #b00020; border-left: 4px solid #e50e30; padding: 10px 12px; border-radius: 8px; font-size: 13px; margin: 0 0 12px; font-weight: 600; }
.warn { background: #fff3cd; color: #856404; border-left: 4px solid #ffc107; padding: 10px 12px; border-radius: 8px; font-size: 13px; margin: 0 0 12px; }
.errmsg { color: var(--red); font-size: 13px; margin: 8px 0; }
</style>
