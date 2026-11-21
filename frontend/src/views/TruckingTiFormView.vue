<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import StateBox from '../components/StateBox.vue'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'
import { showToast } from '../composables/useToast'
import { tanggalJam } from '../utils/format'

const props = defineProps({ id: String })
const router = useRouter()
const ada = computed(() => !!props.id)
const info = ref({})
const opsi = ref({ cabang: [], terminal: [] })
const transportir = ref([])
const ongkos = ref([])
const loading = ref(true)
const error = ref('')
const busy = ref(false)
const formError = ref('')
const konfirmasi = ref(false)
const cancelOpen = ref(false)
const ketCancel = ref('')
const nomor = ref('')
const f = reactive({ id_wilayah: '', tanggal: '', volume: '', load_port: '', discharge_port: '', tipe_oa: '', transportir: '', tgl_awal: '', tgl_akhir: '', ongkos_angkut: '', remarks: '' })
const readonly = computed(() => ada.value && !info.value.bisa_ubah)

async function muatTransportir(w) { transportir.value = []; if (w) { try { transportir.value = (await syop.tiTransportir(w)).data } catch { transportir.value = [] } } }
async function muatOngkos() {
  ongkos.value = []
  if (f.transportir && f.volume) { try { ongkos.value = (await syop.tiOngkos(f.transportir, f.volume)).data } catch { ongkos.value = [] } }
}
let init = true
watch(() => f.id_wilayah, (w) => { muatTransportir(w); if (!init) { f.transportir = ''; f.ongkos_angkut = '' } })
watch(() => [f.transportir, f.volume], async () => { await muatOngkos(); if (!init && !ongkos.value.some((o) => o.value === f.ongkos_angkut)) f.ongkos_angkut = '' })

async function load() {
  loading.value = true
  error.value = ''
  init = true
  try {
    opsi.value = await syop.tiOpsi()
    if (ada.value) {
      const d = await syop.tiShow(props.id)
      info.value = d
      nomor.value = d.nomor_ti
      Object.assign(f, { id_wilayah: String(d.id_wilayah), tanggal: d.tanggal, volume: d.volume, load_port: String(d.load_port), discharge_port: String(d.discharge_port), tipe_oa: d.tipe_oa || '', transportir: String(d.id_transportir),
        tgl_awal: d.tgl_awal, tgl_akhir: d.tgl_akhir || d.tgl_awal, ongkos_angkut: d.ongkos, remarks: d.remarks || '' })
    } else {
      const b = await syop.tiBaru()
      nomor.value = b.nomor_ti
      f.tanggal = b.tanggal
    }
    await muatTransportir(f.id_wilayah)
    await muatOngkos()
  } catch (e) { error.value = errorMessage(e) } finally { loading.value = false; setTimeout(() => { init = false }, 0) }
}
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'trucking-ti' }))
function tinjau() {
  formError.value = ''
  const w = []
  ;[['id_wilayah', 'Region'], ['tanggal', 'Request date'], ['volume', 'Volume'], ['load_port', 'Loading port'], ['discharge_port', 'Discharging port'], ['tipe_oa', 'Tipe transporter'], ['transportir', 'Transporter'],
    ['tgl_awal', 'Estimated loading date'], ['tgl_akhir', 's/d'], ['ongkos_angkut', 'Freight cost'], ['remarks', 'Remarks']].forEach(([k, l]) => { if (f[k] === '' || f[k] === null) w.push(l) })
  if (w.length) { formError.value = 'Lengkapi: ' + w.join(', ') + '.'; return }
  konfirmasi.value = true
}
async function simpan() {
  busy.value = true
  try {
    const r = await syop.tiSimpan(props.id, { ...f, id_wilayah: Number(f.id_wilayah), volume: Number(f.volume), load_port: Number(f.load_port), discharge_port: Number(f.discharge_port), transportir: Number(f.transportir) })
    showToast(r.message, 'success')
    konfirmasi.value = false
    if (ada.value) await load(); else router.replace({ name: 'trucking-ti-form', params: { id: r.id } })
  } catch (e) { konfirmasi.value = false; formError.value = errorMessage(e) } finally { busy.value = false }
}
async function doCancel() {
  if (!ketCancel.value.trim()) { formError.value = 'Keterangan cancel wajib diisi.'; return }
  busy.value = true
  try { await syop.tiCancel(props.id, ketCancel.value.trim()); cancelOpen.value = false; ketCancel.value = ''; showToast('Trucking Instruction di-cancel.', 'success'); await load() } catch (e) { formError.value = errorMessage(e); cancelOpen.value = false } finally { busy.value = false }
}
onMounted(load)
</script>

<template>
  <div class="page">
    <div class="page-head">
      <button class="icon-btn" aria-label="Kembali" @click="back"><Icon name="back" :size="22" /></button>
      <div style="flex:1;min-width:0"><h1 class="page-title">Trucking Instruction</h1><p class="page-sub">{{ nomor }}</p></div>
    </div>
    <StateBox v-if="error" icon="alert" title="Tidak dapat dibuka" :text="error" action="Coba lagi" @action="load" />
    <div v-else-if="loading" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:120px" /></div>
    <template v-else>
      <p v-if="info.ceo_result === 2 && info.ceo_summary" class="warn"><b>Catatan pengembalian CEO:</b> {{ info.ceo_summary.replace(/<br\s*\/?>/gi, ' ') }}</p>
      <p v-if="info.is_cancel" class="warn"><b>Cancel:</b> {{ info.ket_cancel }}<br><small>{{ tanggalJam(info.tanggal_cancel) }}</small></p>
      <p v-if="info.is_reschedule" class="warn"><b>Reschedule:</b> {{ info.ket_reschedule }}<br><small>{{ tanggalJam(info.reschedule_tanggal) }}</small></p>
      <p v-if="ada && readonly && !info.is_cancel" class="muted" style="margin:0 0 10px">Sudah diproses; data hanya bisa dilihat.</p>

      <section class="card">
        <div class="field"><label>Trucking Instruction Number</label><input class="inp" :value="nomor" readonly /></div>
        <div class="field"><label for="rg">Region *</label><select id="rg" v-model="f.id_wilayah" class="inp" :disabled="readonly"><option value="">Pilih</option><option v-for="c in opsi.cabang" :key="c.id" :value="String(c.id)">{{ c.label }}</option></select></div>
        <div class="field"><label for="rd">Request date *</label><input id="rd" v-model="f.tanggal" type="date" class="inp" :disabled="readonly" /></div>
        <div class="field"><label for="vo">Volume PO * (Liter)</label><input id="vo" v-model="f.volume" type="number" min="1" inputmode="numeric" class="inp" :disabled="readonly" /></div>
        <div class="field"><label for="lp">Loading port *</label><select id="lp" v-model="f.load_port" class="inp" :disabled="readonly"><option value="">Pilih</option><option v-for="x in opsi.terminal" :key="x.id" :value="String(x.id)">{{ x.label }}</option></select></div>
        <div class="field"><label for="dp">Discharging port *</label><select id="dp" v-model="f.discharge_port" class="inp" :disabled="readonly"><option value="">Pilih</option><option v-for="x in opsi.terminal" :key="x.id" :value="String(x.id)">{{ x.label }}</option></select></div>
        <div class="field"><label for="to">Transporter type *</label><select id="to" v-model="f.tipe_oa" class="inp" :disabled="readonly"><option value="">Pilih</option><option>Pro Energi</option><option>Third Party</option></select></div>
        <div class="field"><label for="tr">Transporter *</label><select id="tr" v-model="f.transportir" class="inp" :disabled="readonly"><option value="">Pilih</option><option v-for="x in transportir" :key="x.id" :value="String(x.id)">{{ x.label }}</option></select>
          <p v-if="f.id_wilayah && !transportir.length" class="muted">Tidak ada transporter untuk region ini.</p></div>
        <div class="two"><div class="field"><label for="t1">Estimated loading *</label><input id="t1" v-model="f.tgl_awal" type="date" class="inp" :disabled="readonly" /></div><div class="field"><label for="t2">s/d *</label><input id="t2" v-model="f.tgl_akhir" type="date" class="inp" :disabled="readonly" /></div></div>
        <div class="field"><label for="og">Freight cost *</label><select id="og" v-model="f.ongkos_angkut" class="inp" :disabled="readonly"><option value="">Pilih</option><option v-for="o in ongkos" :key="o.value" :value="o.value">{{ o.label }}</option></select>
          <p v-if="f.transportir && f.volume && !ongkos.length" class="muted">Tidak ada tarif untuk transporter dan volume ini. Volume harus sama persis dengan volume angkut master.</p></div>
        <div class="field"><label for="rm">Remarks *</label><textarea id="rm" v-model="f.remarks" class="inp" rows="3" :disabled="readonly" /></div>
        <p v-if="formError" class="errmsg">{{ formError }}</p>
        <button v-if="!ada || info.bisa_ubah" class="btn btn-primary btn-block" @click="tinjau">{{ ada ? 'Simpan perubahan' : 'Simpan' }}</button>
        <button v-if="ada && info.status >= 2" class="btn btn-ghost btn-block" style="margin-top:10px" @click="router.push({ name: 'trucking-ti-terima', params: { id } })"><Icon name="truck" :size="18" /> Terima / realisasi</button>
        <button v-if="ada && info.bisa_cetak" class="btn btn-ghost btn-block" style="margin-top:10px" @click="router.push({ name: 'trucking-ti-cetak', params: { id } })"><Icon name="doc" :size="18" /> Cetak Trucking Instruction</button>
        <button v-if="ada && info.bisa_cancel" class="btn btn-ghost btn-block" style="margin-top:10px;color:var(--red)" @click="cancelOpen = true; formError = ''"><Icon name="x" :size="18" /> Cancel</button>
      </section>
    </template>

    <div v-if="konfirmasi" class="sheet-back" @click.self="!busy && (konfirmasi = false)">
      <div class="sheet" role="dialog" aria-modal="true"><div class="grab" /><h2 class="sheet-t">Simpan data ini?</h2>
        <p class="muted" style="margin:6px 0 12px">{{ ada ? 'Perubahan mengembalikan ke Request Procurement dan persetujuan CEO dikosongkan.' : 'Logistik akan diberi tahu lewat email untuk persetujuan.' }}</p>
        <div class="sheet-act"><button class="btn btn-ghost" :disabled="busy" @click="konfirmasi = false">Batal</button><button class="btn btn-primary" :disabled="busy" @click="simpan">{{ busy ? 'Menyimpan…' : 'Ya, simpan' }}</button></div></div>
    </div>
    <div v-if="cancelOpen" class="sheet-back" @click.self="!busy && (cancelOpen = false)">
      <div class="sheet" role="dialog" aria-modal="true"><div class="grab" /><h2 class="sheet-t">Cancel Trucking Instruction?</h2>
        <div class="field" style="margin-top:8px"><label for="kc">Keterangan cancel *</label><textarea id="kc" v-model="ketCancel" class="inp" rows="3" /></div>
        <p v-if="formError" class="errmsg">{{ formError }}</p>
        <div class="sheet-act"><button class="btn btn-ghost" :disabled="busy" @click="cancelOpen = false">Batal</button><button class="btn btn-primary" :disabled="busy" @click="doCancel">{{ busy ? 'Menyimpan…' : 'Ya, cancel' }}</button></div></div>
    </div>
  </div>
</template>

<style scoped>
.card { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 14px; margin-bottom: 12px; }
.inp { width: 100%; min-height: 46px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; box-sizing: border-box; }
textarea.inp { padding: 10px 12px; }
.two { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 10px; }
.muted { color: var(--muted); font-size: 12.5px; margin: 4px 0 0; }
.warn { background: #fff3cd; color: #856404; border-left: 4px solid #ffc107; padding: 10px 12px; border-radius: 8px; font-size: 13px; margin: 0 0 12px; }
.errmsg { color: var(--red); font-size: 13px; margin: 8px 0; }
</style>
