<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import StateBox from '../components/StateBox.vue'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'
import { showToast } from '../composables/useToast'
import { angka, tanggal } from '../utils/format'

const props = defineProps({ id: String, ri: String })
const router = useRouter()
const d = ref(null)
const loading = ref(true)
const error = ref('')
const busy = ref(false)
const formError = ref('')
const detail = ref(false)
const berkas = ref(null)
const MAKS = 10 * 1024 * 1024
const pilihBerkas = (e) => {
  const x = e.target.files?.[0] || null
  if (x && x.size > MAKS) { formError.value = 'Ukuran file melebihi batas maksimum 10 MB.'; e.target.value = ''; berkas.value = null; return }
  formError.value = ''
  berkas.value = x
}
const f = reactive({ tgl_terima: '', no_terima: '', volume_bol: '', volume_terima: '', nama_pic: '', ket_updated: '' })
const ubah = computed(() => !!props.ri)

async function load() {
  loading.value = true
  error.value = ''
  try {
    d.value = await syop.terimaForm(props.id, props.ri)
    Object.assign(f, { tgl_terima: d.value.tgl_terima || '', no_terima: d.value.no_terima || '', volume_bol: d.value.volume_bol || '', volume_terima: d.value.volume_terima || '', nama_pic: d.value.nama_pic || '' })
  } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'po-supplier-terima', params: { id: props.id } }))
async function simpan() {
  formError.value = ''
  if (!f.tgl_terima || !f.no_terima.trim() || f.volume_bol === '' || f.volume_terima === '' || !f.nama_pic.trim()) { formError.value = 'Lengkapi semua kolom bertanda *.'; return }
  if (ubah.value && !f.ket_updated.trim()) { formError.value = 'Catatan edit RI wajib diisi.'; return }
  busy.value = true
  try {
    await syop.terimaSimpan(props.id, props.ri, { tgl_terima: f.tgl_terima, no_terima: f.no_terima.trim(), volume_bol: Number(f.volume_bol), volume_terima: Number(f.volume_terima), nama_pic: f.nama_pic.trim(), ket_updated: ubah.value ? f.ket_updated.trim() : undefined }, berkas.value)
    showToast('Penerimaan barang tersimpan.', 'success')
    router.replace({ name: 'po-supplier-terima', params: { id: props.id } })
  } catch (e) { formError.value = errorMessage(e) } finally { busy.value = false }
}
onMounted(load)
</script>

<template>
  <div class="page">
    <div class="page-head">
      <button class="icon-btn" aria-label="Kembali" @click="back"><Icon name="back" :size="22" /></button>
      <div style="flex:1;min-width:0"><h1 class="page-title">{{ ubah ? 'Ubah Penerimaan' : 'Tambah Penerimaan' }}</h1><p class="page-sub">{{ d ? d.po.nomor_po : 'PO Supplier Receive' }}</p></div>
    </div>
    <StateBox v-if="error" icon="alert" title="Gagal memuat" :text="error" action="Coba lagi" @action="load" />
    <div v-else-if="loading" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:120px" /></div>
    <template v-else-if="d">
      <section class="card">
        <dl class="kv"><dt>Produk</dt><dd>{{ d.po.produk }}</dd><dt>Terminal</dt><dd>{{ d.po.terminal }}</dd><dt>Vendor</dt><dd>{{ d.po.vendor }}</dd><dt>Volume PO</dt><dd>{{ angka(d.po.volume_po) }} Liter</dd></dl>
      </section>

      <section v-if="d.terpakai" class="card">
        <h2 class="sec">Volume terpakai</h2>
        <button class="row" @click="detail = true"><span>Total volume</span><b>{{ angka(d.terpakai.total) }}</b></button>
        <div class="row"><span>Volume keluar (realisasi)</span><b>{{ angka(d.terpakai.keluar) }}</b></div>
        <div class="row"><span>Volume cancel</span><b>{{ angka(d.terpakai.cancel) }}</b></div>
        <div class="row sisa"><span>Sisa stok</span><b>{{ angka(d.terpakai.sisa) }}</b></div>
      </section>

      <section class="card">
        <div class="field"><label for="tt">Tanggal terima *</label><input id="tt" v-model="f.tgl_terima" type="date" class="inp" /></div>
        <div class="field"><label for="nt">Nomor terima *</label><input id="nt" v-model="f.no_terima" class="inp" autocomplete="off" /></div>
        <div class="field"><label>Harga tebus</label><input class="inp" :value="'Rp ' + angka(d.harga_tebus)" readonly /></div>
        <div class="field"><label for="vb">Volume BL * (Liter)</label><input id="vb" v-model="f.volume_bol" type="number" inputmode="numeric" min="0" class="inp" /></div>
        <div class="field"><label for="vt">Volume terima * (Liter)</label><input id="vt" v-model="f.volume_terima" type="number" inputmode="numeric" min="0" class="inp" /></div>
        <div class="field"><label for="np">Nama PIC *</label><input id="np" v-model="f.nama_pic" class="inp" autocomplete="off" /></div>
        <div class="field"><label for="fl">Lampiran (opsional, maks. 10 MB)</label>
          <p v-if="ubah && d.lampiran && !berkas" class="muted">Saat ini: {{ d.lampiran }}. Pilih file baru untuk menggantinya.</p>
          <input id="fl" type="file" class="inp" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx" @change="pilihBerkas" /></div>
        <div v-if="ubah" class="field"><label for="ku">Catatan edit RI *</label><textarea id="ku" v-model="f.ket_updated" class="inp" rows="3" /></div>
        <p v-if="formError" class="errmsg">{{ formError }}</p>
        <button class="btn btn-primary btn-block" :disabled="busy" @click="simpan">{{ busy ? 'Menyimpan…' : 'Simpan' }}</button>
      </section>
    </template>

    <div v-if="detail" class="sheet-back" @click.self="detail = false">
      <div class="sheet" role="dialog" aria-modal="true">
        <div class="grab" />
        <h2 class="sheet-t">Rincian volume terpakai</h2>
        <p v-if="!d.terpakai.detail.length" class="muted">Belum ada.</p>
        <div v-for="(x, i) in d.terpakai.detail" :key="i" class="row"><span>{{ x.no_do }}<small v-if="x.cancel"> · cancel</small><small v-if="x.split"> · split</small></span><b>{{ angka(x.volume) }}</b></div>
        <button class="btn btn-ghost btn-block" style="margin-top:12px" @click="detail = false">Tutup</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.card { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 14px; margin-bottom: 12px; }
.kv { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.3fr); gap: 8px 12px; margin: 0; font-size: 14px; }
.kv dt { color: var(--muted); } .kv dd { margin: 0; font-weight: 600; text-align: right; overflow-wrap: anywhere; }
.sec { font-size: 15px; margin: 0 0 8px; }
.row { display: flex; justify-content: space-between; gap: 10px; width: 100%; padding: 8px 0; border: 0; border-top: 1px dashed var(--line); background: none; font-size: 14px; text-align: left; color: var(--ink); }
.row small { color: var(--muted); }
.sisa { background: #deebff; border-radius: 8px; padding: 8px 10px; }
.inp { width: 100%; min-height: 46px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; box-sizing: border-box; }
textarea.inp { padding: 10px 12px; }
.muted { color: var(--muted); font-size: 12.5px; }
.errmsg { color: var(--red); font-size: 13px; margin: 8px 0; }
</style>
