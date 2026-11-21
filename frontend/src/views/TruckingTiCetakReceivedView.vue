<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'

const props = defineProps({ id: String })
const router = useRouter()
const c = ref(null)
const error = ref('')
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'trucking-ti-terima', params: { id: props.id } }))
const imprimir = () => window.print()
onMounted(async () => { try { c.value = await syop.tiCetakReceived(props.id) } catch (e) { error.value = errorMessage(e) } })
</script>

<template>
  <div class="cetak">
    <div class="bar no-print"><button class="btn btn-ghost" @click="back">Kembali</button><button class="btn btn-primary" :disabled="!c" @click="imprimir">Cetak / Simpan PDF</button></div>
    <p class="no-print muted">Cetakan berorientasi landscape; pilih "Landscape" pada dialog cetak bila belum otomatis.</p>
    <p v-if="error" class="no-print err">{{ error }}</p>
    <p v-else-if="!c" class="no-print">Memuat…</p>
    <div v-if="c" class="kertas">
      <div class="head">
        <div class="k1"><img src="/img/logo-kiri-penawaran.png" alt="" /></div>
        <div class="k2"><p class="t1"><b>Trucking Instruction Received</b></p><p class="t2">No : {{ c.nomor }}</p></div>
        <div class="k3"><p><b>PT. Pro Energi</b></p><p class="s">Gd. Graha Irama Lt.6 Unit G<br />Jl. HR. Rasuna Said Kuningan,<br />Jakarta Selatan, 12710<br />Telp: (021)-52892321</p></div>
      </div>
      <div class="info"><span>Loading Port</span><span>: {{ c.load_port }}</span><span>discharge port</span><span>: {{ c.discharge_port }}</span><span>Transportir</span><span>: {{ c.transportir }}</span></div>
      <table class="rinci">
        <thead><tr><th>No</th><th>Tgl Realisasi</th><th>DR</th><th>No. PO</th><th>LO No.</th><th>SPJ No</th><th>Truck No</th><th>Driver</th><th>Volume (Liter)</th><th>Realisasi Volume</th><th>Remarks</th></tr></thead>
        <tbody>
          <tr v-for="(b, i) in c.baris" :key="i" :class="{ batal: b.cancel }"><td class="c">{{ i + 1 }}</td><td class="c">{{ b.tgl_realisasi }}</td><td class="c">{{ b.nomor_dr }}</td><td>{{ b.nomor_po }}</td><td class="c">{{ b.nomor_lo }}</td><td class="c">{{ b.spj }}</td><td class="c">{{ b.plat }}</td><td class="c">{{ b.sopir }}</td><td class="r">{{ b.volume }}</td><td class="r">{{ b.realisasi }}</td><td>{{ b.cancel ? 'CANCEL' : '' }}</td></tr>
          <tr class="tot"><td colspan="9"></td><td class="r">{{ c.total }}</td><td></td></tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style>
@media print {
  #app > *:not(.cetak) { display: none !important; }
  .no-print { display: none !important; }
  .cetak { padding: 0 !important; background: #fff !important; }
  .kertas { box-shadow: none !important; margin: 0 !important; width: auto !important; }
  @page { size: A4 landscape; margin: 10mm; }
}
</style>
<style scoped>
.cetak { background: #e9e9ee; padding: 12px; min-height: 100vh; color: #000; font-family: Arial, sans-serif; }
.bar { display: flex; gap: 10px; justify-content: space-between; margin-bottom: 8px; position: sticky; top: 0; z-index: 2; }
.muted { color: #666; font-size: 12px; } .err { color: #b00020; }
.kertas { background: #fff; width: 297mm; margin: 0 auto 16px; padding: 10mm; box-sizing: border-box; font-size: 8.5pt; box-shadow: 0 2px 10px rgba(0, 0, 0, .15); }
@media (max-width: 1100px) { .kertas { zoom: .3; } }
.head { display: flex; margin-bottom: 8px; } .k1, .k2, .k3 { width: 33%; } .k1 img { width: 30%; }
.t1 { margin: 0 0 5px; text-align: center; font-size: 12pt; font-family: Times, serif; } .t2 { margin: 0; text-align: center; font-size: 11pt; }
.k3 p { margin: 0; text-align: right; } .k3 .s { font-size: 8pt; }
.info { display: grid; grid-template-columns: 80px 1fr; font-size: 8pt; margin-bottom: 10px; row-gap: 2px; }
.rinci { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
.rinci th { border: 1px solid #000; padding: 5px 3px; background: #ffcc99; font-weight: bold; text-align: center; }
.rinci td { border: 1px solid #000; border-width: 0 1px 1px 1px; padding: 3px 2px; } .c { text-align: center; } .r { text-align: right; }
.batal td { background: #ddd; } .tot td { border-top: 0; }
</style>
