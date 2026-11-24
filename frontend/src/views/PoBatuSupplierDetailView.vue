<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import StateBox from '../components/StateBox.vue'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'
import { showToast } from '../composables/useToast'
import { angka, tanggal, tanggalJam } from '../utils/format'

const props = defineProps({ id: String })
const router = useRouter()
const d = ref(null)
const loading = ref(true)
const error = ref('')
const konfirmasi = ref(false)
const busy = ref(false)
const formError = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try { d.value = await syop.pbsShow(props.id) } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'po-batu-supplier' }))
// Catatan dari web tersimpan sebagai teks ber-<br />; tampilkan sebagai baris baru, tanpa HTML.
const plain = (s) => (s ? String(s).replace(/<br\s*\/?>/gi, '\n').replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&') : '')
const rp = (v) => 'Rp ' + angka(v, Number(v) % 1 ? 2 : 0)
async function ajukan() {
  busy.value = true
  formError.value = ''
  try {
    const r = await syop.pbsAjukan(props.id)
    konfirmasi.value = false
    showToast(r.message, 'success')
    await load()
  } catch (e) { formError.value = errorMessage(e) } finally { busy.value = false }
}
onMounted(load)
</script>

<template>
  <div>
    <header class="bar">
      <button class="back" aria-label="Kembali" @click="back"><Icon name="back" :size="20" /></button>
      <div class="bar-t"><b>Detail PO Supplier Batu</b><small v-if="d">{{ d.nomor_po }}</small></div>
      <button class="back" aria-label="Segarkan" @click="load"><Icon name="refresh" :size="18" /></button>
    </header>

    <div class="wrap">
      <StateBox v-if="error" icon="alert" title="Gagal memuat" :text="error" action="Coba lagi" @action="load" />
      <div v-else-if="loading" class="stack"><div class="skel" style="height:150px" /><div class="skel" style="height:240px" /></div>
      <template v-else-if="d">
        <section class="card box">
          <h1>{{ d.nama_vendor }}</h1>
          <p>{{ d.nomor_po }} · {{ d.jenis_produk }} - {{ d.merk_dagang }}</p>
          <div class="big">{{ rp(d.total_order) }}</div>
          <span class="pill" :class="d.is_cancel == 1 || d.is_close == 1 || /Ditolak/.test(d.status) ? 'pill-no' : d.status === 'Terverifikasi' ? 'pill-ok' : 'pill-wait'">{{ d.is_cancel == 1 ? 'Cancel' : d.is_close == 1 ? 'Close' : d.status }}</span>
          <small v-if="d.status_tgl" class="muted"> {{ d.status_tgl }}</small>
          <div class="kv"><span>Tanggal PO</span><span>{{ tanggal(d.tanggal_inven) }}</span></div>
          <div class="kv"><span>Terminal</span><span>{{ d.terminal }}</span></div>
          <div class="kv"><span>Terms</span><span>{{ d.terms || '-' }}<template v-if="d.terms_day"> - {{ d.terms_day }}</template></span></div>
          <div class="kv"><span>Volume PO</span><span>{{ angka(d.volume_po) }} m³</span></div>
          <div v-if="d.volume_bol > 0" class="kv"><span>Volume BL</span><span>{{ angka(d.volume_bol) }} m³</span></div>
          <div v-if="d.volume_ri > 0" class="kv"><span>Volume RI</span><span>{{ angka(d.volume_ri) }} m³</span></div>
          <div v-if="d.gain_loss !== null" class="kv"><span>Gain / Loss</span><span :class="d.gain_loss < 0 ? 'neg' : 'pos'">{{ angka(d.gain_loss) }} m³</span></div>
          <div v-if="d.is_close == 1" class="kv"><span>Close PO</span><span class="neg">{{ angka(d.volume_close) }} m³</span></div>
          <p v-if="d.is_cancel == 1 && d.keterangan_cancel" class="note neg">{{ plain(d.keterangan_cancel) }}</p>
        </section>

        <section class="card box">
          <h2>Rincian harga</h2>
          <div class="kv"><span>Harga Dasar</span><span>{{ rp(d.harga_tebus) }}</span></div>
          <div class="kv"><span>Subtotal</span><span>{{ rp(d.subtotal) }}</span></div>
          <div class="kv"><span>PPN 11%</span><span>{{ rp(d.ppn_11) }}</span></div>
          <div class="kv tot"><span>Total Order</span><span>{{ rp(d.total_order) }}</span></div>
          <div class="kv"><span>Kode tax</span><span>{{ d.kd_tax || '-' }}</span></div>
        </section>

        <section class="card box">
          <h2>Keterangan</h2>
          <p v-if="d.description" class="note"><b>Deskripsi:</b> {{ plain(d.description) }}</p>
          <p v-if="d.keterangan" class="note"><b>Catatan PO:</b> {{ plain(d.keterangan) }}</p>
          <p v-if="d.terms_condition" class="note"><b>Syarat &amp; Ketentuan (halaman 2):</b> {{ plain(d.terms_condition) }}</p>
        </section>

        <section v-if="d.cfo_result == 1 || d.ceo_result == 1 || d.revert_cfo == 1 || d.revert_ceo == 1" class="card box">
          <h2>Catatan persetujuan</h2>
          <p v-if="d.cfo_result == 1 && d.revert_cfo != 1" class="note"><b>CFO:</b> {{ plain(d.cfo_summary) }}<br /><i>{{ d.cfo_pic }} · {{ tanggalJam(d.cfo_tanggal) }}</i></p>
          <p v-if="d.revert_cfo == 1" class="note neg"><b>Dikembalikan CFO:</b> {{ plain(d.revert_cfo_summary) }}</p>
          <p v-if="d.ceo_result == 1 && d.revert_ceo != 1" class="note"><b>CEO:</b> {{ plain(d.ceo_summary) }}<br /><i>{{ d.ceo_pic }} · {{ tanggalJam(d.ceo_tanggal) }}</i></p>
          <p v-if="d.revert_ceo == 1" class="note neg"><b>Dikembalikan CEO:</b> {{ plain(d.revert_ceo_summary) }}</p>
        </section>

        <div class="acts">
          <button v-if="d.bisa_ubah" class="btn btn-ghost btn-block" @click="router.push({ name: 'po-batu-supplier-ubah', params: { id: d.id_master } })"><Icon name="edit" :size="18" /> Ubah</button>
          <button v-if="d.aksi.includes('cetak')" class="btn btn-ghost btn-block" @click="router.push({ name: 'po-batu-supplier-cetak', params: { id: d.id_master } })"><Icon name="doc" :size="18" /> Cetak PO</button>
          <button v-if="d.bisa_ajukan" class="btn btn-primary btn-block" @click="formError = ''; konfirmasi = true"><Icon name="check" :size="18" /> Ajukan persetujuan</button>
        </div>
      </template>
    </div>

    <div v-if="konfirmasi" class="sheet-back" @click.self="!busy && (konfirmasi = false)">
      <div class="sheet" role="dialog" aria-modal="true">
        <div class="grab" />
        <h2 class="sheet-t">Ajukan persetujuan?</h2>
        <p class="muted" style="margin:6px 0 12px">{{ d.nomor_po }} dikirim ke CFO untuk diverifikasi.</p>
        <p v-if="formError" class="err-text" role="alert" style="margin-bottom:12px">{{ formError }}</p>
        <div class="sheet-act"><button class="btn btn-ghost" :disabled="busy" @click="konfirmasi = false">Batal</button><button class="btn btn-primary" :disabled="busy" @click="ajukan">{{ busy ? 'Memproses…' : 'Ya, ajukan' }}</button></div>
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
.wrap { padding: 12px 16px calc(var(--safe-bottom) + 24px); display: grid; gap: 12px; }
.box { padding: 14px 16px; }
.box h1 { font-size: 17px; font-weight: 700; letter-spacing: -0.02em; margin: 0; }
.box h2 { font-size: 14px; font-weight: 700; margin: 0 0 6px; }
.box p { color: var(--muted); font-size: 13px; margin: 2px 0 8px; }
.big { font-size: 26px; font-weight: 800; letter-spacing: -0.03em; color: var(--accent); margin-bottom: 6px; }
.kv span:last-child { text-align: right; font-weight: 600; overflow-wrap: anywhere; }
.kv.tot span { font-weight: 800; color: var(--ink); }
.pos { color: #14a36b; } .neg { color: var(--red); }
.muted { color: var(--muted); font-size: 12.5px; }
.note { padding: 8px 10px; border-radius: 10px; background: var(--blue-50); white-space: pre-line; overflow-wrap: anywhere; }
.acts { display: grid; gap: 10px; }
</style>
