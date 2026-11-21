<script setup>
import PrintShell from '../components/PrintShell.vue'
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'

const props = defineProps({ id: String, sr: String })
const router = useRouter()
const c = ref(null)
const error = ref('')
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'po-supplier-shipping', params: { id: props.id } }))
const imprimir = () => window.print()
onMounted(async () => { try { c.value = await syop.shipCetak(props.id, props.sr) } catch (e) { error.value = errorMessage(e) } })
</script>

<template>
  <PrintShell :ready="!!c" :error="error" :judul="c ? `Shipping Instruction ${c.nomor}` : ''" @back="back" @print="imprimir">
  <div class="cetak">
    <div v-if="c" class="kertas">
      <img class="logo" src="/img/logo-kiri-penawaran.png" alt="" />
      <h2 class="judul">Shipping Instruction</h2>
      <table class="no"><tr><td class="k">Number</td><td>:</td><td>{{ c.nomor }}</td></tr><tr><td class="k">Date</td><td>:</td><td>{{ c.tanggal }}</td></tr></table>
      <div class="to"><p>To:</p><p><b>{{ c.kepada }}</b></p><p class="alamat">{{ c.alamat }}</p></div>
      <p>Dear sir,</p>
      <p>We hereby request you to arrange shipment with detail as mention below :</p>
      <table class="isi">
        <tr><td class="k2">Product Description</td><td>:</td><td>{{ c.produk }}</td></tr>
        <tr><td class="k2">Vessel/Ship</td><td>:</td><td>{{ c.kapal }}</td></tr>
        <tr><td class="k2">Laycan</td><td>:</td><td>{{ c.laycan }}</td></tr>
        <tr><td class="k2">Quantity</td><td>:</td><td>{{ c.quantity }}</td></tr>
        <tr class="atas"><td class="k2 top"><i>Shipper</i></td><td class="top">:</td><td><p>{{ c.shipper }}</p><p>Graha Irama Building Lt. 6G</p><p>Jl. HR. Rasuna Said Blok X1, Kav 1-2, Kuningan, Jakarta, 12950</p></td></tr>
        <tr class="atas"><td class="k2 top"><i>Consignee</i></td><td class="top">:</td><td><p>{{ c.consignee }}</p><p>Graha Irama Building Lt. 6 Unit F</p><p>Jl. HR. Rasuna Said Blok X1, Kav 1-2, Kuningan, Jakarta, 12950</p></td></tr>
        <tr><td class="k2">Port of Loading</td><td>:</td><td>{{ c.port_loading }}</td></tr>
        <tr class="bawah"><td class="k2">Port of Discharge</td><td>:</td><td>{{ c.port_discharge }}</td></tr>
      </table>
      <p class="salam">Regards,</p>
      <div class="ruang" />
      <p>{{ c.penandatangan }}</p>
      <p class="garis" />
      <p>{{ c.jabatan }}</p>
    </div>
  </div>
  </PrintShell>
</template>

<style>
@media print {
  .cetak { padding: 0 !important; background: #fff !important; min-height: 0 !important; }
  .kertas { box-shadow: none !important; margin: 0 !important; width: auto !important; }
  @page { size: A4; margin: 10mm 20mm 20mm; }
}
</style>
<style scoped>
.cetak { background: #e9e9ee; padding: 12px; min-height: 100vh; color: #000; font-family: Arial, sans-serif; }
.bar { display: flex; gap: 10px; justify-content: space-between; margin-bottom: 12px; position: sticky; top: 0; z-index: 2; }
.err { color: #b00020; }
.kertas { background: #fff; width: 210mm; margin: 0 auto 16px; padding: 10mm 20mm 20mm; box-sizing: border-box; font-size: 9.5pt; box-shadow: 0 2px 10px rgba(0, 0, 0, .15); overflow: hidden; }
@media (max-width: 800px) { .kertas { zoom: .45; } }
.logo { float: right; width: 15%; }
.judul { clear: both; text-align: center; margin: 24px 0 30px; font-size: 14pt; }
.no { margin: 0 0 20px 5px; border-spacing: 0 5px; } .k { width: 18%; padding-right: 30px; }
.to { max-width: 50%; line-height: 1; margin-bottom: 10px; } .alamat { white-space: pre-line; }
p { margin: 3px 0 8px 5px; }
.isi { width: 100%; border-spacing: 0 5px; margin-left: 5px; } .isi td { vertical-align: baseline; } .k2 { width: 23%; } .top { vertical-align: top !important; }
.isi tr.atas td { padding-top: 25px; padding-bottom: 10px; } .isi tr.bawah td { padding-bottom: 20px; } .isi p { margin: 2px 0; padding: 2px 0; }
.salam { font-size: 12px; margin-bottom: 30px; }
.ruang { height: 60px; }
.garis { width: 150px; border-top: 1px solid #000; margin-bottom: 5px; }
</style>
