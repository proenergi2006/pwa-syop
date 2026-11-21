<script setup>
import { onMounted, ref } from 'vue'
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
const tgl = ref('')
const konfirmasi = ref(false)
const busy = ref(false)
const formError = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try { d.value = await syop.poCloseForm(props.id) } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'po-supplier-detail', params: { id: props.id } }))
function tinjau() {
  formError.value = ''
  if (!tgl.value) { formError.value = 'Tanggal close wajib diisi.'; return }
  konfirmasi.value = true
}
async function simpan() {
  busy.value = true
  try {
    const r = await syop.poClose(props.id, { tanggal: tgl.value })
    showToast(r.accurate_manual ? 'PO di-close. Accurate belum aktif: tutup PO manual di Accurate.' : r.message, 'success')
    router.replace({ name: 'po-supplier-detail', params: { id: props.id } })
  } catch (e) { konfirmasi.value = false; formError.value = errorMessage(e) } finally { busy.value = false }
}
onMounted(load)
</script>

<template>
  <div class="page">
    <div class="page-head">
      <button class="icon-btn" aria-label="Kembali" @click="back"><Icon name="back" :size="22" /></button>
      <div style="flex:1;min-width:0"><h1 class="page-title">Close PO</h1><p class="page-sub">{{ d ? d.nomor_po : 'PO Supplier' }}</p></div>
    </div>
    <StateBox v-if="error" icon="alert" title="Tidak dapat dibuka" :text="error" action="Coba lagi" @action="load" />
    <div v-else-if="loading" class="stack"><div v-for="i in 2" :key="i" class="skel" style="height:120px" /></div>
    <template v-else-if="d">
      <section class="card">
        <dl class="kv"><dt>Tanggal PO</dt><dd>{{ tanggal(d.tanggal) }}</dd><dt>Produk</dt><dd>{{ d.produk }}</dd><dt>Terminal</dt><dd>{{ d.terminal }}</dd><dt>Vendor</dt><dd>{{ d.vendor }}</dd>
          <dt>Volume PO</dt><dd>{{ angka(d.volume_po) }} L</dd><dt>Volume terima</dt><dd>{{ angka(d.volume_terima) }} L</dd></dl>
      </section>
      <section class="card">
        <div class="field"><label for="tc">Tanggal close *</label><input id="tc" v-model="tgl" type="date" class="inp" /></div>
        <div class="field"><label>Volume close</label><input class="inp" :value="angka(d.volume_close) + ' Liter'" readonly /></div>
        <p class="muted">Volume close = volume PO dikurangi total volume terima. PO yang di-close tidak bisa diubah lagi dan ditutup juga di Accurate.</p>
        <p v-if="formError" class="errmsg">{{ formError }}</p>
        <button class="btn btn-primary btn-block" @click="tinjau">Simpan</button>
      </section>
    </template>

    <div v-if="konfirmasi" class="sheet-back" @click.self="!busy && (konfirmasi = false)">
      <div class="sheet" role="dialog" aria-modal="true">
        <div class="grab" />
        <h2 class="sheet-t">Close PO ini?</h2>
        <p class="muted" style="margin:6px 0 12px">PO {{ d.nomor_po }} di-close dengan volume close {{ angka(d.volume_close) }} liter. Tindakan ini tidak dapat dibatalkan dari aplikasi.</p>
        <div class="sheet-act"><button class="btn btn-ghost" :disabled="busy" @click="konfirmasi = false">Batal</button><button class="btn btn-primary" :disabled="busy" @click="simpan">{{ busy ? 'Menyimpan…' : 'Ya, close' }}</button></div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.card { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 14px; margin-bottom: 12px; }
.kv { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.3fr); gap: 8px 12px; margin: 0; font-size: 14px; }
.kv dt { color: var(--muted); } .kv dd { margin: 0; font-weight: 600; text-align: right; overflow-wrap: anywhere; }
.inp { width: 100%; min-height: 46px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; box-sizing: border-box; }
.muted { color: var(--muted); font-size: 12.5px; }
.errmsg { color: var(--red); font-size: 13px; margin: 8px 0; }
</style>
