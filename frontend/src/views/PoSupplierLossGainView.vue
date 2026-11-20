<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import StateBox from '../components/StateBox.vue'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'
import { showToast } from '../composables/useToast'
import { angka, tanggal } from '../utils/format'

const props = defineProps({ id: String })
const router = useRouter()
const d = ref(null)
const loading = ref(true)
const error = ref('')
const busy = ref(false)
const formError = ref('')
const konfirmasi = ref(false)
const berkas = ref(null)
const f = reactive({ jenis: '1', ket: '', ket_loss_gain: '', akun_penyesuaian: '', nomor_penyesuaian: '' })
const akunQ = ref('')
const akun = ref([])
const akunMsg = ref('')
let timer
const loss = computed(() => f.jenis === '2')
const rp = (v) => 'Rp ' + angka(v, Number(v) % 1 ? 2 : 0)

async function load() {
  loading.value = true
  error.value = ''
  try {
    d.value = await syop.lossGainForm(props.id)
    f.jenis = String(d.value.jenis_default)
    f.ket = d.value.po.keterangan || ''
  } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
async function cariAkun() {
  akunMsg.value = ''
  try {
    const r = await syop.lossGainAkun(akunQ.value.trim())
    akun.value = r.items
    if (r.accurate === false) akunMsg.value = 'Accurate belum aktif di server; isi kode akun manual.'
  } catch (e) { akunMsg.value = errorMessage(e) }
}
watch(akunQ, () => { clearTimeout(timer); timer = setTimeout(cariAkun, 400) })
watch(loss, (v) => { if (v && !akun.value.length) cariAkun() })
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'po-supplier-terima', params: { id: props.id } }))
const MAKS = 5 * 1024 * 1024
const pilih = (e) => {
  const x = e.target.files?.[0] || null
  if (x && (x.size > MAKS || !/\.pdf$/i.test(x.name))) { formError.value = 'Lampiran harus PDF dengan ukuran maksimum 5 MB.'; e.target.value = ''; berkas.value = null; return }
  formError.value = ''
  berkas.value = x
}
function tinjau() {
  formError.value = ''
  if (!f.ket.trim() || !f.ket_loss_gain.trim()) { formError.value = 'Kolom Ket wajib diisi.'; return }
  if (loss.value && (!f.akun_penyesuaian.trim() || !f.nomor_penyesuaian.trim())) { formError.value = 'Akun dan nomor penyesuaian wajib diisi untuk Loss.'; return }
  konfirmasi.value = true
}
async function simpan() {
  busy.value = true
  try {
    const r = await syop.lossGainSimpan(props.id, { jenis: f.jenis, ket: f.ket.trim(), ket_loss_gain: f.ket_loss_gain.trim(), akun_penyesuaian: loss.value ? f.akun_penyesuaian.trim() : '', nomor_penyesuaian: loss.value ? f.nomor_penyesuaian.trim() : '' }, berkas.value)
    showToast(r.accurate_manual ? 'Tersimpan. Accurate belum aktif: sesuaikan manual di Accurate.' : r.message, 'success')
    router.replace({ name: 'po-supplier-terima', params: { id: props.id } })
  } catch (e) { konfirmasi.value = false; formError.value = errorMessage(e) } finally { busy.value = false }
}
onMounted(load)
</script>

<template>
  <div class="page">
    <div class="page-head">
      <button class="icon-btn" aria-label="Kembali" @click="back"><Icon name="back" :size="22" /></button>
      <div style="flex:1;min-width:0"><h1 class="page-title">Losses &amp; Gain</h1><p class="page-sub">{{ d ? d.po.nomor_po : 'PO Supplier' }}</p></div>
    </div>
    <StateBox v-if="error" icon="alert" title="Tidak dapat dibuka" :text="error" action="Coba lagi" @action="load" />
    <div v-else-if="loading" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:120px" /></div>
    <template v-else-if="d">
      <section class="card">
        <h2 class="sec">PO Supplier</h2>
        <dl class="kv">
          <dt>Tanggal PO</dt><dd>{{ tanggal(d.po.tanggal) }}</dd><dt>Produk</dt><dd>{{ d.po.produk }}</dd><dt>Terminal</dt><dd>{{ d.po.terminal }}</dd><dt>Vendor</dt><dd>{{ d.po.vendor }}</dd><dt>Kode tax</dt><dd>{{ d.po.kd_tax }}</dd>
          <dt>Volume PO</dt><dd>{{ angka(d.volume_po) }} L</dd><dt>Volume BL</dt><dd>{{ angka(d.volume_bl) }} L</dd><dt>Volume RI</dt><dd>{{ angka(d.volume_ri) }} L</dd>
          <dt>Harga dasar</dt><dd>{{ rp(d.harga_dasar) }}</dd><dt>Harga dasar RI</dt><dd>{{ rp(d.harga_ri) }}</dd>
          <dt>Sub total</dt><dd>{{ rp(d.subtotal) }}</dd><dt>PPN 11%</dt><dd>{{ rp(d.ppn_11) }}</dd><dt>PPh 22</dt><dd>{{ rp(d.pph_22) }}</dd>
          <dt>PBBKB PO</dt><dd>{{ rp(d.pbbkb_po) }}</dd><dt>PBBKB</dt><dd>{{ rp(d.pbbkb) }}</dd><dt>Total order</dt><dd>{{ rp(d.total_order) }}</dd>
        </dl>
        <div class="field" style="margin-top:12px"><label for="k1">Ket (catatan PO) *</label><textarea id="k1" v-model="f.ket" class="inp" rows="2" /></div>
      </section>

      <section class="card">
        <h2 class="sec">Tambah Gain / Loss</h2>
        <div class="field"><label for="j">Jenis *</label>
          <select id="j" v-model="f.jenis" class="inp"><option value="1">Bertambah / Gain (+)</option><option value="2">Berkurang / Loss (-)</option></select></div>
        <template v-if="loss">
          <div class="field"><label for="aq">Akun Accurate *</label>
            <input id="aq" v-model="akunQ" class="inp" placeholder="Cari kode atau nama akun…" autocomplete="off" />
            <p v-if="akunMsg" class="muted">{{ akunMsg }}</p>
            <select v-if="akun.length" v-model="f.akun_penyesuaian" class="inp" style="margin-top:8px"><option value="">Pilih akun</option><option v-for="a in akun" :key="a.id" :value="a.id">{{ a.text }}</option></select>
            <input v-else v-model="f.akun_penyesuaian" class="inp" placeholder="Kode akun" style="margin-top:8px" /></div>
          <div class="field"><label for="np">Nomor penyesuaian *</label><input id="np" v-model="f.nomor_penyesuaian" class="inp" autocomplete="off" /></div>
        </template>
        <div class="field"><label>Volume Gain / Loss</label><input class="inp" :value="angka(d.volume_loss_gain) + ' Liter'" readonly /></div>
        <div class="field"><label for="fl">Lampiran (PDF, maks. 5 MB)</label><input id="fl" type="file" accept=".pdf,application/pdf" class="inp" @change="pilih" /></div>
        <div class="field"><label for="k2">Ket *</label><textarea id="k2" v-model="f.ket_loss_gain" class="inp" rows="3" /></div>
        <p v-if="formError" class="errmsg">{{ formError }}</p>
        <p class="muted" style="margin-bottom:10px">{{ loss ? 'Loss mengurangi stok dan membuat penyesuaian di Accurate.' : 'Gain mengubah harga PO, mengembalikan PO ke verifikasi, dan membuat ulang PO dan RI di Accurate.' }}</p>
        <button class="btn btn-primary btn-block" @click="tinjau">Simpan</button>
      </section>
    </template>

    <div v-if="konfirmasi" class="sheet-back" @click.self="!busy && (konfirmasi = false)">
      <div class="sheet" role="dialog" aria-modal="true">
        <div class="grab" />
        <h2 class="sheet-t">Konfirmasi simpan</h2>
        <p class="muted" style="margin:6px 0 12px">Anda yakin ingin menyimpan {{ loss ? 'Loss' : 'Gain' }} {{ angka(Math.abs(d.volume_loss_gain)) }} liter untuk PO {{ d.po.nomor_po }}?</p>
        <div class="sheet-act"><button class="btn btn-ghost" :disabled="busy" @click="konfirmasi = false">Batal</button><button class="btn btn-primary" :disabled="busy" @click="simpan">{{ busy ? 'Menyimpan…' : 'Ya, simpan' }}</button></div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.card { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 14px; margin-bottom: 12px; }
.sec { font-size: 15px; margin: 0 0 10px; }
.kv { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.3fr); gap: 8px 12px; margin: 0; font-size: 14px; }
.kv dt { color: var(--muted); } .kv dd { margin: 0; font-weight: 600; text-align: right; overflow-wrap: anywhere; }
.inp { width: 100%; min-height: 46px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; box-sizing: border-box; }
textarea.inp { padding: 10px 12px; }
.muted { color: var(--muted); font-size: 12.5px; }
.errmsg { color: var(--red); font-size: 13px; margin: 8px 0; }
</style>
