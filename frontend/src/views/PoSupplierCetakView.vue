<script setup>
import PrintShell from '../components/PrintShell.vue'
import { computed, onMounted, ref } from 'vue'
import qrcode from 'qrcode-generator'
import { useRoute, useRouter } from 'vue-router'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'

const props = defineProps({ id: String })
const route = useRoute()
const router = useRouter()
const c = ref(null)
const error = ref('')
const qr = computed(() => {
  const b = c.value?.kepala?.barcode
  if (!b) return ''
  const q = qrcode(0, 'M')
  q.addData(b)
  q.make()
  return q.createSvgTag({ cellSize: 3, margin: 0, scalable: true })
})
const imprimir = () => window.print()
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'po-supplier-detail', params: { id: props.id } }))
onMounted(async () => {
  try { c.value = await syop.cetakPoSupplier(props.id, route.query.mode === 'gainloss' ? 'gainloss' : 'standar') } catch (e) { error.value = errorMessage(e) }
})
</script>

<template>
  <PrintShell :ready="!!c" :error="error" :judul="c ? `PO ${c.kepala.nomor_po}${c.mode === 'gainloss' ? ' Gain Loss' : ''}` : ''" @back="back" @print="imprimir">
  <div class="cetak">
    <div v-for="(h, n) in (c?.halaman || [])" :key="n" class="kertas">
      <table class="logo"><tr><td class="l1"><img src="/img/logo-kiri-penawaran.png" alt="" /></td><td class="l2"></td><td class="l3"><img src="/img/logo-kanan-penawaran.png" alt="" /></td></tr></table>
      <div class="judul"><h2>PURCHASE ORDER</h2><hr /></div>
      <div class="no-tgl"><span>PO Number : {{ c.kepala.nomor_po }}</span><span>PO Date : {{ c.kepala.tanggal }}</span></div>
      <div class="kepala">
        <div class="kotak"><hr /><div>PT PRO ENERGI</div><hr /><div class="isi">GRAHA IRAMA BUILDING LT.6 UNIT G<br />JL. HR RASUNA SAID KAV 1-2<br />KUNINGAN TIMUR JAKARTA SELATAN</div></div>
        <div class="kotak"><hr /><div>VENDOR</div><hr /><div class="isi">{{ c.kepala.vendor }}</div></div>
        <div class="kotak syarat">
          <table><tr><td>Terms</td><td>:</td><td>{{ c.kepala.terms }}</td></tr><tr><td>Vendor Is Taxable</td><td>:</td><td>YES</td></tr><tr><td>Delivery Date</td><td>:</td><td></td></tr></table>
        </div>
      </div>
      <table class="rinci">
        <thead><tr><th>Item</th><th>Description</th><th>Quantity (Litre)</th><th>Unit Price</th><th>Disc %</th><th>Tax</th><th>Amount</th></tr></thead>
        <tbody>
          <tr v-for="(b, i) in h.baris" :key="i" :class="{ tinggi: i === 0 }">
            <td class="c">{{ b.item }}</td><td class="c">{{ b.deskripsi }}</td><td class="c">{{ b.qty }}</td>
            <td :class="c.mode === 'standar' ? 'r' : 'c'">{{ b.harga }}</td><td class="c">{{ b.diskon }}</td><td class="c">{{ b.pajak }}</td><td :class="c.mode === 'standar' ? 'r' : 'c'">{{ b.jumlah }}</td>
          </tr>
        </tbody>
      </table>
      <div class="bawah">
        <div class="catatan"><b>Note</b><div class="isi"><p v-for="(t, i) in h.catatan" :key="i">{{ t }}</p></div></div>
        <table class="total">
          <tr><td><b>Sub Total</b></td><td>:</td><td><b>{{ h.sub_total }}</b></td></tr>
          <tr><td><b>DPP Nilai Lain</b></td><td>:</td><td><b>{{ h.dpp }}</b></td></tr>
          <tr><td>Discount</td><td>:</td><td>0</td></tr>
          <tr><td>PPN</td><td>:</td><td>{{ h.ppn }}</td></tr>
          <tr><td>PPH 22</td><td>:</td><td>{{ h.pph22 }}</td></tr>
          <tr><td>PBBKB</td><td>:</td><td>{{ h.pbbkb }}</td></tr>
          <tr><td><b>Total Order</b></td><td>:</td><td><b>{{ h.total_order }}</b></td></tr>
        </table>
      </div>
      <div class="say"><span>Say</span><div>{{ h.terbilang }}</div></div>
      <div class="ttd"><div>Approved By System<div class="qr" v-if="qr" v-html="qr" /><div v-else class="ruang" /><small v-if="qr && c.mode === 'gainloss'" class="kode">{{ c.kepala.barcode }}</small><hr /><span>{{ c.kepala.penandatangan }}</span></div></div>
      <p class="kaki"><i>(This form is valid with sign by computerized system)</i><br /><small>Printed by {{ c.kepala.dicetak }}</small></p>
    </div>
  </div>
  </PrintShell>
</template>

<style>
@media print {
  .cetak { padding: 0 !important; background: #fff !important; min-height: 0 !important; }
  .kertas { box-shadow: none !important; margin: 0 !important; page-break-after: always; width: auto !important; }
  @page { size: A4; margin: 10mm; }
}
</style>
<style scoped>
.cetak { background: #e9e9ee; padding: 12px; min-height: 100vh; color: #000; font-family: Arial, sans-serif; }
.bar { display: flex; gap: 10px; justify-content: space-between; margin-bottom: 12px; position: sticky; top: 0; z-index: 2; }
.err { color: #b00020; }
.kertas { background: #fff; width: 210mm; max-width: none; margin: 0 auto 16px; padding: 10mm; box-sizing: border-box; font-size: 8.5pt; box-shadow: 0 2px 10px rgba(0, 0, 0, .15); }
@media (max-width: 800px) { .kertas { zoom: .45; } }
.logo { width: 100%; table-layout: fixed; margin-bottom: 10px; } .logo td { vertical-align: top; } .l1, .l3 { width: 30%; } .logo img { width: 20%; min-width: 60px; }
.judul { text-align: center; margin: 6px 0 14px; } .judul h2 { margin: 0; font-size: 14pt; } .judul hr { width: 25%; border: 1px solid #000; margin: 3px auto; }
.no-tgl { display: flex; font-size: 9pt; margin-bottom: 14px; } .no-tgl span:first-child { width: 70%; }
.kepala { display: flex; gap: 3%; margin-bottom: 12px; }
.kotak { width: 30%; } .kotak hr { border: 1px solid #000; margin: 3px 0; } .kotak .isi { min-height: 90px; padding: 4px 0; }
.syarat table td { padding: 3px 5px; }
.rinci { width: 100%; border-collapse: collapse; border: 1px solid #888; }
.rinci th { border: 1px solid #000; border-width: 1px 1px 1px 1px; padding: 5px; text-align: center; font-weight: bold; }
.rinci td { padding: 5px; vertical-align: top; } .rinci tr.tinggi td { height: 100px; }
.c { text-align: center; } .r { text-align: right; }
.bawah { display: flex; gap: 4%; margin-top: 12px; }
.catatan { width: 40%; border: 1px solid #888; } .catatan b { display: block; padding: 5px; border-bottom: 1px solid #000; } .catatan .isi { min-height: 130px; padding: 5px; } .catatan p { margin: 0 0 8px; white-space: pre-line; }
.total { width: 56%; margin-left: auto; border: 1px solid #888; } .total td { padding: 5px; }
.say { display: flex; gap: 10px; align-items: center; margin-top: 14px; } .say span { width: 10%; } .say div { flex: 1; border: 1px solid #888; padding: 5px; color: #888; }
.ttd { display: flex; justify-content: flex-end; margin-top: 14px; text-align: center; } .ttd > div { width: 30%; } .ruang { height: 70px; } .qr { width: 22mm; height: 22mm; margin: 6px auto; } .qr :deep(svg) { width: 100%; height: 100%; } .kode { display: block; font-size: 6pt; } .ttd hr { width: 70%; }
.kaki { text-align: center; font-size: 7pt; margin: 16px 0 0; }
</style>
