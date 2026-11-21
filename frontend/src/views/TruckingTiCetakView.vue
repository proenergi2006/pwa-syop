<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'

const props = defineProps({ id: String })
const router = useRouter()
const c = ref(null)
const error = ref('')
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'trucking-ti-form', params: { id: props.id } }))
const imprimir = () => window.print()
onMounted(async () => { try { c.value = await syop.tiCetak(props.id) } catch (e) { error.value = errorMessage(e) } })
</script>

<template>
  <div class="cetak">
    <div class="bar no-print"><button class="btn btn-ghost" @click="back">Kembali</button><button class="btn btn-primary" :disabled="!c" @click="imprimir">Cetak / Simpan PDF</button></div>
    <p v-if="error" class="no-print err">{{ error }}</p>
    <p v-else-if="!c" class="no-print">Memuat…</p>
    <div v-if="c" class="kertas">
      <img class="logo" src="/img/logo-kiri-penawaran.png" alt="" />
      <h5 class="j1">Trucking Instruction</h5>
      <h5 class="j2">{{ c.nomor }}</h5>
      <table class="rinci">
        <thead><tr><th>Doc Date</th><th>Vessel / Truck</th><th>Loading Port</th><th>Discharging Port</th><th>Loading Date</th><th>Qty</th><th>No SJ Truck</th><th>Remarks</th></tr></thead>
        <tbody><tr><td>{{ c.tanggal }}</td><td>{{ c.transporter }}<br />{{ c.volume_angkut }} L</td><td>{{ c.load_port }}</td><td>{{ c.discharge_port }}</td><td>{{ c.loading_date }}</td><td class="r">{{ c.qty }}</td>
          <td><template v-for="(n, i) in c.no_sj" :key="i">{{ n }}<br /></template></td><td>{{ c.remarks }}</td></tr></tbody>
      </table>
      <p class="kaki"><i>(This form is valid with sign by computerized system)</i><br /><small>Printed by {{ c.dicetak }}</small></p>
    </div>
  </div>
</template>

<style>
@media print {
  #app > *:not(.cetak) { display: none !important; }
  .no-print { display: none !important; }
  .cetak { padding: 0 !important; background: #fff !important; }
  .kertas { box-shadow: none !important; margin: 0 !important; width: auto !important; }
  @page { size: A4; margin: 10mm 20mm 20mm; }
}
</style>
<style scoped>
.cetak { background: #e9e9ee; padding: 12px; min-height: 100vh; color: #000; font-family: Arial, sans-serif; }
.bar { display: flex; gap: 10px; justify-content: space-between; margin-bottom: 12px; position: sticky; top: 0; z-index: 2; }
.err { color: #b00020; }
.kertas { background: #fff; width: 210mm; margin: 0 auto 16px; padding: 10mm 20mm 20mm; box-sizing: border-box; font-size: 9pt; box-shadow: 0 2px 10px rgba(0, 0, 0, .15); overflow: hidden; }
@media (max-width: 800px) { .kertas { zoom: .45; } }
.logo { float: left; width: 20%; }
.j1 { clear: both; text-align: center; font-size: 20px; margin: 25px 0 5px; } .j2 { text-align: center; font-size: 16px; margin: 5px 0 20px; }
.rinci { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
.rinci th { background: #e1e1e1; font-size: 8pt; padding: 5px 3px; border: .5px solid #000; }
.rinci td { padding: 4px 3px; border: .5px solid #000; vertical-align: top; } .r { text-align: right; }
.kaki { text-align: right; font-size: 7pt; margin: 40px 0 0; }
</style>
