<script setup>
import { computed, onMounted, ref } from 'vue'
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
const hapus = ref(null)
const riwayat = ref(null)
const busy = ref(false)
const err = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try { d.value = await syop.terimaPoSupplier(props.id) } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'po-supplier-detail', params: { id: props.id } }))
const form = (ri) => router.push({ name: 'po-supplier-terima-form', params: { id: props.id, ri } })
async function doHapus() {
  busy.value = true
  err.value = ''
  try { await syop.terimaHapus(props.id, hapus.value.id); hapus.value = null; showToast('Penerimaan dihapus.', 'success'); await load() } catch (e) { err.value = errorMessage(e) } finally { busy.value = false }
}
async function lihatRiwayat(r) {
  riwayat.value = { r, data: null }
  try { riwayat.value.data = (await syop.terimaRiwayat(props.id, r.id)).data } catch (e) { riwayat.value.error = errorMessage(e) }
}
async function lampiranGl(g) {
  try { window.open(URL.createObjectURL(await syop.lossGainLampiran(props.id, g.id)), '_blank') } catch (e) { showToast(errorMessage(e), 'error') }
}
async function lampiran(r) {
  try {
    const blob = await syop.terimaLampiran(props.id, r.id)
    window.open(URL.createObjectURL(blob), '_blank')
  } catch (e) { showToast(errorMessage(e), 'error') }
}
const sisa = computed(() => (d.value ? d.value.po.volume_po - d.value.total_terima : 0))
onMounted(load)
</script>

<template>
  <div class="page">
    <div class="page-head">
      <button class="icon-btn" aria-label="Kembali" @click="back"><Icon name="back" :size="22" /></button>
      <div style="flex:1;min-width:0"><h1 class="page-title">Terima Barang</h1><p class="page-sub">{{ d ? d.po.nomor_po : 'PO Supplier Receive' }}</p></div>
    </div>
    <StateBox v-if="error" icon="alert" title="Gagal memuat" :text="error" action="Coba lagi" @action="load" />
    <div v-else-if="loading" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:120px" /></div>
    <template v-else-if="d">
      <section class="card">
        <dl class="kv">
          <dt>Nomor PO</dt><dd>{{ d.po.nomor_po }}</dd><dt>Tanggal PO</dt><dd>{{ tanggal(d.po.tanggal) }}</dd><dt>Produk</dt><dd>{{ d.po.produk }}</dd>
          <template v-if="d.po.kode_item_accurate"><dt>Kode item Accurate</dt><dd>{{ d.po.kode_item_accurate }}</dd></template>
          <dt>Terminal / Depot</dt><dd>{{ d.po.terminal }}</dd><dt>Vendor</dt><dd>{{ d.po.vendor }}</dd>
          <dt>Volume PO</dt><dd>{{ angka(d.po.volume_po) }} Liter</dd><dt>Harga dasar</dt><dd>Rp {{ angka(d.po.harga_tebus) }}</dd>
        </dl>
      </section>

      <h2 class="sec">Penerimaan (RI)</h2>
      <StateBox v-if="!d.ri.length" icon="check" title="Belum ada penerimaan" text="Tekan tombol di bawah untuk menambah penerimaan barang." />
      <div v-for="r in d.ri" :key="r.id" class="card">
        <div class="top"><b>{{ r.no_terima || '-' }}</b><span>{{ tanggal(r.tgl_terima) }}</span></div>
        <div class="muted">PIC {{ r.nama_pic }}</div>
        <div class="vols"><span>BL <b>{{ angka(r.volume_bol) }}</b> L</span><span>Terima <b>{{ angka(r.volume_terima) }}</b> L</span><span>Rp {{ angka(r.harga_tebus) }}</span></div>
        <small v-if="r.diubah" class="muted">{{ r.diubah }}</small>
        <div class="acts">
          <button v-if="r.lampiran" class="mini" @click="lampiran(r)"><Icon name="doc" :size="16" /> Lampiran</button>
          <button v-if="r.ada_log" class="mini" @click="lihatRiwayat(r)"><Icon name="history" :size="16" /> Riwayat</button>
          <button v-if="r.bisa_ubah" class="mini" @click="form(r.id)"><Icon name="edit" :size="16" /> Ubah</button>
          <button v-if="r.bisa_hapus" class="mini danger" @click="hapus = r; err = ''"><Icon name="trash" :size="16" /> Hapus</button>
        </div>
      </div>
      <div v-if="d.ri.length" class="card total"><span>Total terima</span><b>{{ angka(d.total_terima) }} Liter</b><span v-if="sisa > 0" class="muted">sisa {{ angka(sisa) }}</span></div>

      <h2 class="sec">Gain / Loss</h2>
      <p v-if="!d.gain_loss.length" class="muted">Belum ada data.</p>
      <div v-for="(g, i) in d.gain_loss" :key="i" class="card"><div class="top"><b>{{ g.jenis }}</b><span>{{ angka(g.volume) }} Liter</span></div><div class="muted">{{ g.ket }}</div><small class="muted">{{ g.status }}</small></div>
      <button v-if="d.bisa_loss_gain" class="btn btn-ghost btn-block" style="margin-top:10px" @click="router.push({ name: 'po-supplier-loss-gain', params: { id } })"><Icon name="plus" :size="18" /> Tambah Losses &amp; Gain</button>

      <button v-if="d.bisa_tambah" class="btn btn-primary btn-block" style="margin:14px 0 24px" @click="form()"><Icon name="plus" :size="18" /> Tambah penerimaan</button>
      <p v-else-if="d.po.terkunci" class="muted" style="margin:14px 0">PO sudah close/cancel; penerimaan tidak dapat diubah.</p>
    </template>

    <div v-if="hapus" class="sheet-back" @click.self="hapus = null">
      <div class="sheet" role="dialog" aria-modal="true">
        <div class="grab" />
        <h2 class="sheet-t">Hapus penerimaan?</h2>
        <p class="muted" style="margin:6px 0 12px">{{ hapus.no_terima }} · {{ angka(hapus.volume_terima) }} Liter. Stok dan data RI di Accurate ikut dihapus.</p>
        <p v-if="err" class="errmsg">{{ err }}</p>
        <div class="sheet-act"><button class="btn btn-ghost" @click="hapus = null">Batal</button><button class="btn btn-primary" :disabled="busy" @click="doHapus">{{ busy ? 'Menghapus…' : 'Hapus' }}</button></div>
      </div>
    </div>

    <div v-if="riwayat" class="sheet-back" @click.self="riwayat = null">
      <div class="sheet" role="dialog" aria-modal="true">
        <div class="grab" />
        <h2 class="sheet-t">Riwayat perubahan RI</h2>
        <p v-if="riwayat.error" class="errmsg">{{ riwayat.error }}</p>
        <p v-else-if="!riwayat.data" class="muted">Memuat…</p>
        <p v-else-if="!riwayat.data.length" class="muted">Belum ada riwayat.</p>
        <div v-for="(h, i) in riwayat.data" :key="i" class="hist">
          <b>{{ h.dibuat }}</b>
          <small>Tgl {{ tanggal(h.tgl_terima) }} · BL {{ angka(h.volume_bol) }} · Terima {{ angka(h.volume_terima) }} · Rp {{ angka(h.harga_tebus) }} · PIC {{ h.nama_pic }}</small>
          <small v-if="h.keterangan">{{ h.keterangan }}</small>
        </div>
        <button class="btn btn-ghost btn-block" style="margin-top:12px" @click="riwayat = null">Tutup</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.card { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 14px; margin-bottom: 12px; }
.kv { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.3fr); gap: 8px 12px; margin: 0; font-size: 14px; }
.kv dt { color: var(--muted); } .kv dd { margin: 0; font-weight: 600; text-align: right; overflow-wrap: anywhere; }
.sec { font-size: 15px; margin: 18px 0 10px; }
.top { display: flex; justify-content: space-between; gap: 8px; } .top b { overflow-wrap: anywhere; }
.muted { color: var(--muted); font-size: 12.5px; }
.vols { display: flex; flex-wrap: wrap; gap: 4px 14px; margin: 8px 0 4px; font-size: 13.5px; } .vols b { color: var(--ink); }
.acts { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
.mini { display: inline-flex; align-items: center; gap: 6px; min-height: 36px; padding: 0 12px; border-radius: 10px; border: 1px solid var(--line); background: var(--card); color: var(--ink-2); font-size: 13px; font-weight: 600; }
.mini.danger { color: var(--red); }
.total { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; } .total b { margin-left: auto; }
.errmsg { color: var(--red); font-size: 13px; margin: 0 0 10px; }
.hist { display: grid; gap: 2px; padding: 8px 0; border-top: 1px dashed var(--line); font-size: 13px; } .hist small { color: var(--muted); }
</style>
