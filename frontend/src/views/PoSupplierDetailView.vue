<script setup>
import { t } from '../i18n'
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import Collapse from '../components/Collapse.vue'
import StateBox from '../components/StateBox.vue'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'
import { angka, tanggal, tanggalJam } from '../utils/format'

const props = defineProps({ id: String })
const router = useRouter()
const d = ref(null)
const loading = ref(true)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try { d.value = await syop.getPoSupplier(props.id) } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'po-supplier' }))
const plain = (s) => (s ? String(s).replace(/<br\s*\/?>/gi, '\n').replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&') : '')
const rp = (v, dec = 0) => 'Rp ' + angka(v, Number(v) % 1 ? Math.max(dec, 2) : dec)
const konfirmHarga = ref(false)
const busyHarga = ref(false)
const errHarga = ref('')
// Seperti web: tombol Perubahan Harga hanya bila PO sudah ada RI, belum close/cancel, harga Final, dan belum pernah diubah harganya.
const statusHarga = computed(() => {
  const x = d.value
  if (!x || x.is_close || x.is_cancel || !(x.vol_ri > 0) || x.jenis_harga === null) return null
  if (x.jenis_harga === 2) return 'sementara'
  return x.jenis_harga === 1 && ![1, 2].includes(x.is_price_changed ?? 0) ? 'bisa' : null
})
async function jadiSementara() {
  busyHarga.value = true
  errHarga.value = ''
  try { await syop.poHargaSementara(props.id); konfirmHarga.value = false; await load() } catch (e) { errHarga.value = errorMessage(e) } finally { busyHarga.value = false }
}
const bisaCetak = computed(() => d.value && (d.value.aksi.includes('cetak') || d.value.aksi.includes('cetak_penuh')))
const bisaTerima = computed(() => d.value && ['terima', 'ri', 'lihat'].some((k) => d.value.aksi.includes(k)))
const cetak = (mode) => router.push({ name: 'po-supplier-cetak', params: { id: props.id }, query: { mode } })
const rows = computed(() => {
  const x = d.value
  if (!x) return []
  return [
    ['Vendor', x.vendor], ['Terminal', x.terminal], ['Produk', x.produk], ['Tanggal PO', tanggal(x.tanggal)],
    ['Jenis kirim', x.jenis_kirim === 2 ? 'Ship' : 'Truck'], ['Terms', `${x.terms || ''} ${x.terms_day || ''}`.trim() || '-'], ['Kode tax', x.kd_tax],
  ]
})
const biaya = computed(() => {
  const x = d.value
  if (!x) return []
  return [
    ['Volume PO', angka(x.volume_po) + ' L'], ['Harga PO', rp(x.harga_po)], ['Harga RI', rp(x.harga_tebus)],
    ...(x.kategori_oa === 2 ? [['Ongkos angkut', rp(x.ongkos_angkut)], ['Kategori plat', x.kategori_plat || '-']] : []),
    ['Sub total', rp(x.subtotal)], ['PPN 11%', rp(x.ppn_11)], ['DPP 11/12', rp(x.dpp_11_12)], ['PPN 12%', rp(x.ppn_12)], ['PPh 22', rp(x.pph_22)],
    [`PBBKB ${x.pbbkb_persen}%`, rp(x.pbbkb)], ...(x.iuran_migas ? [['Iuran migas', rp(x.nominal_migas)]] : []), ['Total order', rp(x.total_order)],
  ]
})
onMounted(load)
</script>

<template>
  <div class="page">
    <div class="page-head">
      <button class="icon-btn" :aria-label="t('common.back')" @click="back"><Icon name="back" :size="22" /></button>
      <div style="flex:1;min-width:0"><h1 class="page-title" style="overflow-wrap:anywhere">{{ d ? d.nomor_po : t('ps.title') }}</h1><p class="page-sub">{{ t('ps.title') }}</p></div>
    </div>
    <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load" />
    <div v-else-if="loading" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:140px" /></div>
    <template v-else-if="d">
      <section class="card">
        <div class="tags">
          <span v-if="d.is_cancel" class="tag tag-red">Cancel</span>
          <span v-else-if="d.is_close" class="tag tag-red">Close</span>
          <span v-if="d.status" class="tag" :class="/Ditolak/.test(d.status) ? 'tag-red' : d.status === 'Terverifikasi' ? 'tag-green' : 'tag-amber'">{{ d.status }}</span>
          <span v-if="d.jenis_harga === 2" class="tag tag-blue">Harga Sementara</span>
          <span v-if="d.pengajuan_ulang" class="tag tag-amber">Pengajuan Ulang ke-{{ d.pengajuan_ulang }}</span>
          <span v-if="d.shipping" class="tag" :class="d.shipping.warna === 'danger' ? 'tag-red' : 'tag-blue'">{{ d.shipping.teks }}</span>
        </div>
        <small v-if="d.status_tgl" class="muted">{{ d.status_tgl }}</small>
        <p v-if="d.is_cancel" class="red">{{ d.keterangan_cancel }}</p>
        <p v-if="d.is_close" class="red">Close PO {{ tanggal(d.tanggal_close) }} · volume {{ angka(d.volume_close) }} L</p>
        <dl class="kv"><template v-for="r in rows" :key="r[0]"><dt>{{ r[0] }}</dt><dd>{{ r[1] }}</dd></template></dl>
      </section>

      <Collapse title="Rincian harga" :open="true">
        <dl class="kv"><template v-for="r in biaya" :key="r[0]"><dt>{{ r[0] }}</dt><dd>{{ r[1] }}</dd></template></dl>
      </Collapse>

      <Collapse title="Catatan">
        <p class="note"><b>Catatan PO</b><br>{{ plain(d.keterangan) || '-' }}</p>
        <p v-if="d.internal_notes" class="note"><b>Internal notes</b><br>{{ plain(d.internal_notes) }}</p>
        <p v-if="d.cfo.dikembalikan" class="note"><b>Pengembalian CFO</b><br>{{ plain(d.cfo.dikembalikan) }}</p>
        <p v-if="d.ceo.dikembalikan" class="note"><b>Pengembalian CEO</b><br>{{ plain(d.ceo.dikembalikan) }}</p>
        <p v-if="d.cfo.catatan" class="note"><b>Catatan CFO</b> · {{ d.cfo.pic }} · {{ tanggalJam(d.cfo.tanggal) }}<br>{{ plain(d.cfo.catatan) }}</p>
        <p v-if="d.ceo.catatan" class="note"><b>Catatan CEO</b> · {{ d.ceo.pic }} · {{ tanggalJam(d.ceo.tanggal) }}<br>{{ plain(d.ceo.catatan) }}</p>
      </Collapse>

      <Collapse :title="`Penerimaan barang (${d.ri.length})`">
        <p class="muted">BL {{ angka(d.vol_bl) }} L · RI {{ angka(d.vol_ri) }} L<span v-if="d.gain_loss"> · Gain/Loss {{ angka(d.gain_loss.volume) }} L</span></p>
        <p v-if="!d.ri.length" class="muted">Belum ada penerimaan.</p>
        <div v-for="r in d.ri" :key="r.id_po_receive" class="ri">
          <b>{{ r.no_terima }}</b><span>{{ tanggal(r.tgl_terima) }}</span>
          <small>BL {{ angka(r.volume_bol) }} · RI {{ angka(r.volume_terima) }} · Rp {{ angka(r.harga_tebus) }}</small>
        </div>
      </Collapse>

      <Collapse v-if="d.riwayat.length" :title="`Riwayat pengajuan (${d.riwayat.length})`">
        <div v-for="(h, i) in d.riwayat" :key="i" class="ri">
          <b>{{ tanggalJam(h.tanggal) }}</b>
          <small>Volume {{ angka(h.volume_po) }} · Harga {{ angka(h.harga_tebus) }} · Total {{ rp(h.total_order) }}</small>
          <small v-if="h.keterangan_pengajuan_ulang">{{ plain(h.keterangan_pengajuan_ulang) }}</small>
        </div>
      </Collapse>

      <section v-if="statusHarga === 'bisa'" class="card">
        <h2 class="sec-t">Perubahan harga</h2>
        <p class="muted">PO sudah ada penerimaan. Jenis harga akan diubah menjadi Harga Sementara sebelum harga bisa diperbarui.</p>
        <button class="btn btn-ghost" :disabled="busyHarga" @click="konfirmHarga = true"><Icon name="edit" :size="18" /> Perubahan Harga</button>
      </section>
      <section v-else-if="statusHarga === 'sementara'" class="card warnbox">
        <h2 class="sec-t">Harga Sementara</h2>
        <p class="muted">Harga Dasar PO ini perlu diperbarui agar sesuai dengan data terbaru karena jenis harga adalah Harga Sementara.</p>
        <button class="btn btn-primary" @click="router.push({ name: 'po-supplier-ubah-harga', params: { id } })"><Icon name="edit" :size="18" /> Ubah harga</button>
      </section>

      <div v-if="konfirmHarga" class="sheet-back" @click.self="!busyHarga && (konfirmHarga = false)">
        <div class="sheet" role="dialog" aria-modal="true">
          <div class="grab" />
          <h2 class="sheet-t">Perubahan harga PO?</h2>
          <p class="muted" style="margin:6px 0 12px">Jenis harga PO akan diubah menjadi Harga Sementara.</p>
          <p v-if="errHarga" class="red">{{ errHarga }}</p>
          <div class="sheet-act"><button class="btn btn-ghost" :disabled="busyHarga" @click="konfirmHarga = false">Batal</button><button class="btn btn-primary" :disabled="busyHarga" @click="jadiSementara">{{ busyHarga ? 'Menyimpan…' : 'Simpan' }}</button></div>
        </div>
      </div>

      <section v-if="d.aksi.includes('edit')" class="card">
        <h2 class="sec-t">Ubah PO</h2>
        <button class="btn btn-ghost" @click="router.push({ name: 'po-supplier-ubah', params: { id } })"><Icon name="edit" :size="18" /> Ubah data PO</button>
      </section>

      <section v-if="bisaTerima" class="card">
        <h2 class="sec-t">Penerimaan barang (RI)</h2>
        <button class="btn btn-primary" @click="router.push({ name: 'po-supplier-terima', params: { id } })"><Icon name="truck" :size="18" /> {{ d.aksi.includes('terima') ? 'Terima barang' : 'Lihat RI' }}</button>
      </section>

      <section v-if="d.aksi.includes('close')" class="card">
        <h2 class="sec-t">Close PO</h2>
        <button class="btn btn-ghost" @click="router.push({ name: 'po-supplier-close', params: { id } })"><Icon name="check" :size="18" /> Close PO</button>
      </section>

      <section v-if="bisaCetak" class="card">
        <h2 class="sec-t">Cetak PO</h2>
        <div class="acts">
          <button class="btn btn-primary" @click="cetak('standar')"><Icon name="doc" :size="18" /> Tanpa Gain &amp; Loss</button>
          <button class="btn btn-ghost" @click="cetak('gainloss')"><Icon name="doc" :size="18" /> Dengan Gain &amp; Loss</button>
        </div>
      </section>
      <p class="muted foot">Shipping request belum tersedia di PWA.</p>
    </template>
  </div>
</template>

<style scoped>
.card { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 14px; margin-bottom: 12px; }
.tags { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 6px; }
.tag { padding: 3px 9px; border-radius: 8px; font-size: 12px; font-weight: 600; }
.tag-blue { background: #c6e9ff; color: #2459b6; }
.tag-amber { background: #fdebc8; color: #8a5a00; }
.tag-red { background: #ffc6c6; color: #de2e2e; }
.tag-green { background: #cdf1d8; color: #17713a; }
.red { color: var(--red); font-size: 13px; margin: 6px 0 0; }
.muted { color: var(--muted); font-size: 12.5px; }
.kv { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr); gap: 8px 12px; margin: 8px 0 0; font-size: 14px; }
.kv dt { color: var(--muted); }
.kv dd { margin: 0; font-weight: 600; text-align: right; overflow-wrap: anywhere; }
.note { margin: 0 0 12px; white-space: pre-line; font-size: 14px; }
.ri { display: grid; grid-template-columns: 1fr auto; gap: 2px 10px; padding: 8px 0; border-top: 1px dashed var(--line); font-size: 13.5px; }
.ri small { grid-column: 1 / -1; color: var(--muted); }
.sec-t { margin: 0 0 10px; font-size: 15px; }
.acts { display: grid; gap: 10px; }
.foot { text-align: center; margin: 12px 0 24px; }
.warnbox { background: #fff3cd; border-color: #ffc107; }
.red { color: var(--red); font-size: 13px; }
</style>
