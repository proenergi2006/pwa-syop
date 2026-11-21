<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import StateBox from '../components/StateBox.vue'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'
import { showToast } from '../composables/useToast'
import { angka, tanggalJam } from '../utils/format'

const props = defineProps({ id: String })
const router = useRouter()
const d = ref(null)
const loading = ref(true)
const error = ref('')
const busy = ref(false)
const formError = ref('')
const kapal = ref([])
const konfirmasi = ref(false)
const cancelOpen = ref(false)
const ketCancel = ref('')
const f = reactive({
  id_transportir: '', tipe_kapal: '', id_vessel_tb: '', id_vessel: '', load_port: '', discharge_terminal_id: '', volume: '', flag: 'INDONESIA', tgl_etl_awal: '', tgl_etl_akhir: '', cargo: '', leadtime: '',
  country: 'Indonesia', freight: '', demurrage: '', losstype: '', loss: '', satuan: '', shipper: '', signee_name: '', bl_ship: '', ket_ship: '',
})
const r = computed(() => d.value?.request)
const bisaForm = computed(() => d.value && (!d.value.ada ? d.value.bisa_tambah : d.value.bisa_ubah))
const readonly = computed(() => !bisaForm.value)
const CARGO = ['High-speed Diesel (B40)', 'Biosolar', 'Pertamax', 'Pertalite']

async function muatKapal(t) {
  kapal.value = []
  if (!t) return
  try { kapal.value = (await syop.shipKapal(t)).data } catch { kapal.value = [] }
}
watch(() => f.id_transportir, (t, lama) => { muatKapal(t); if (lama !== undefined && lama !== '') { f.id_vessel = ''; f.id_vessel_tb = '' } })
watch(() => f.tipe_kapal, (v) => { if (v !== '1') f.id_vessel_tb = '' })

async function load() {
  loading.value = true
  error.value = ''
  try {
    d.value = await syop.shipPo(props.id)
    const s = d.value.request
    if (s) {
      Object.assign(f, { id_transportir: String(s.id_transportir), tipe_kapal: String(s.tipe_kapal || ''), id_vessel_tb: s.id_vessel_tb ? String(s.id_vessel_tb) : '', id_vessel: s.id_vessel ? String(s.id_vessel) : '',
        load_port: String(s.load_port), discharge_terminal_id: String(s.discharge_terminal_id), volume: s.volume, flag: s.flag || 'INDONESIA', tgl_etl_awal: s.tgl_etl_awal, tgl_etl_akhir: s.tgl_etl_akhir,
        cargo: s.cargo || '', leadtime: s.leadtime, country: s.country || '', freight: s.freight, demurrage: s.demurrage, losstype: s.losstype || '', loss: s.loss, satuan: s.satuan || '',
        shipper: s.shipper || '', signee_name: s.signee_name || '', bl_ship: s.bl_ship || '', ket_ship: s.ket_ship || '' })
      await muatKapal(f.id_transportir)
    } else {
      Object.assign(f, { volume: d.value.baru.volume, flag: d.value.baru.flag, country: d.value.baru.country })
    }
  } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'po-supplier-detail', params: { id: props.id } }))

function tinjau() {
  formError.value = ''
  const w = []
  const need = [['id_transportir', 'Transportir'], ['tipe_kapal', 'Tipe kapal'], ['id_vessel', 'Vessel'], ['load_port', 'Loading port'], ['discharge_terminal_id', 'Discharging port'], ['volume', 'Volume'],
    ['tgl_etl_awal', 'Estimasi tanggal loading'], ['tgl_etl_akhir', 'Sampai dengan'], ['cargo', 'Cargo name'], ['leadtime', 'Lead time'], ['country', 'Country of origin'], ['freight', 'Freight'],
    ['demurrage', 'Biaya demurrage'], ['losstype', 'Tipe loss tolerance'], ['satuan', 'Satuan loss tolerance'], ['loss', 'Nominal loss tolerance'], ['shipper', 'Shipper'], ['signee_name', 'Consignee'], ['bl_ship', 'BL ship on board']]
  need.forEach(([k, l]) => { if (f[k] === '' || f[k] === null || Number(f[k]) === 0 && ['volume', 'leadtime', 'freight', 'demurrage', 'loss'].includes(k)) w.push(l) })
  if (f.tipe_kapal === '1' && !f.id_vessel_tb) w.push('Vessel TB')
  if (w.length) { formError.value = 'Lengkapi: ' + w.join(', ') + '.'; return }
  konfirmasi.value = true
}
function payload() {
  const p = { ...f }
  ;['id_transportir', 'tipe_kapal', 'id_vessel', 'load_port', 'discharge_terminal_id', 'volume', 'leadtime', 'demurrage'].forEach((k) => { p[k] = Number(f[k]) })
  p.id_vessel_tb = f.tipe_kapal === '1' ? Number(f.id_vessel_tb) : undefined
  p.freight = Number(f.freight)
  p.loss = Number(f.loss)
  return p
}
async function simpan() {
  busy.value = true
  try {
    const x = await syop.shipSimpan(props.id, r.value?.id, payload())
    showToast(x.message, 'success')
    konfirmasi.value = false
    await load()
  } catch (e) { konfirmasi.value = false; formError.value = errorMessage(e) } finally { busy.value = false }
}
async function doCancel() {
  if (!ketCancel.value.trim()) { formError.value = 'Keterangan cancel wajib diisi.'; return }
  busy.value = true
  try { await syop.shipCancel(props.id, r.value.id, ketCancel.value.trim()); cancelOpen.value = false; ketCancel.value = ''; showToast('Shipping request di-cancel.', 'success'); await load() } catch (e) { formError.value = errorMessage(e); cancelOpen.value = false } finally { busy.value = false }
}
const stat = (s) => (s === 1 ? ['Approved', 'ok'] : s === 2 ? ['Rejected', 'no'] : ['Pending', 'wait'])
onMounted(load)
</script>

<template>
  <div class="page">
    <div class="page-head">
      <button class="icon-btn" aria-label="Kembali" @click="back"><Icon name="back" :size="22" /></button>
      <div style="flex:1;min-width:0"><h1 class="page-title">Shipping Request</h1><p class="page-sub">{{ d ? d.po.nomor_po : 'PO Supplier' }}</p></div>
    </div>
    <StateBox v-if="error" icon="alert" title="Tidak dapat dibuka" :text="error" action="Coba lagi" @action="load" />
    <div v-else-if="loading" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:120px" /></div>
    <template v-else-if="d">
      <section class="card">
        <dl class="kv"><dt>Nomor PO</dt><dd>{{ d.po.nomor_po }}</dd><dt>Nomor shipping request</dt><dd>{{ r ? r.nomor_req : d.baru.nomor_req }}</dd><template v-if="r && r.nomor_si"><dt>Nomor SI</dt><dd>{{ r.nomor_si }}</dd></template></dl>
        <p v-if="!d.ada && !d.bisa_tambah" class="muted" style="margin-top:10px">PO belum disetujui atau sudah close/cancel, shipping request tidak dapat dibuat.</p>
        <p v-else-if="d.ada && readonly" class="muted" style="margin-top:10px">Sedang diproses (Logistik, CFO, CEO). Tidak dapat diubah sampai ditolak atau selesai.</p>
      </section>

      <section v-if="r" class="card">
        <h2 class="sec">Riwayat persetujuan</h2>
        <div v-for="(l, i) in r.langkah" :key="i" class="step">
          <b>{{ l.detail }}</b><span class="pill" :class="'pill-' + stat(l.status)[1]">{{ stat(l.status)[0] }}</span>
          <small v-if="l.peran">{{ l.peran }}<template v-if="l.tanggal"> · {{ tanggalJam(l.tanggal) }}</template></small>
          <small v-if="l.catatan">{{ l.catatan }}</small>
        </div>
      </section>

      <template v-if="d.ada || d.bisa_tambah">
        <section class="card">
          <h2 class="sec">Kapal dan rute</h2>
          <div class="field"><label for="tr">Transportir *</label><select id="tr" v-model="f.id_transportir" class="inp" :disabled="readonly"><option value="">Pilih transportir</option><option v-for="x in d.opsi.transportir" :key="x.id" :value="String(x.id)">{{ x.label }}</option></select></div>
          <div class="field"><label for="tk">Tipe kapal *</label><select id="tk" v-model="f.tipe_kapal" class="inp" :disabled="readonly"><option value="">Pilih</option><option value="1">TB OB</option><option value="2">Other</option></select></div>
          <div v-if="f.tipe_kapal === '1'" class="field"><label for="vt">Vessel TB *</label><select id="vt" v-model="f.id_vessel_tb" class="inp" :disabled="readonly"><option value="">Pilih</option><option v-for="k in kapal" :key="k.id" :value="String(k.id)">{{ k.nama }}</option></select></div>
          <div v-if="f.tipe_kapal" class="field"><label for="vs">Vessel *</label><select id="vs" v-model="f.id_vessel" class="inp" :disabled="readonly"><option value="">Pilih</option><option v-for="k in kapal" :key="k.id" :value="String(k.id)">{{ k.nama }}</option></select></div>
          <div class="field"><label for="lp">Loading port *</label><select id="lp" v-model="f.load_port" class="inp" :disabled="readonly"><option value="">Pilih</option><option v-for="x in d.opsi.terminal" :key="x.id" :value="String(x.id)">{{ x.label }}</option></select></div>
          <div class="field"><label for="dp">Discharging port *</label><select id="dp" v-model="f.discharge_terminal_id" class="inp" :disabled="readonly"><option value="">Pilih</option><option v-for="x in d.opsi.terminal" :key="x.id" :value="String(x.id)">{{ x.label }}</option></select></div>
        </section>

        <section class="card">
          <h2 class="sec">Muatan</h2>
          <div class="field"><label for="vo">Volume PO * (Liter)</label><input id="vo" v-model="f.volume" type="number" min="1" inputmode="numeric" class="inp" :disabled="readonly" /></div>
          <div class="field"><label for="fl">Flag</label><input id="fl" v-model="f.flag" class="inp" :disabled="readonly" /></div>
          <div class="two"><div class="field"><label for="e1">Estimasi loading *</label><input id="e1" v-model="f.tgl_etl_awal" type="date" class="inp" :disabled="readonly" /></div><div class="field"><label for="e2">Sampai dengan *</label><input id="e2" v-model="f.tgl_etl_akhir" type="date" class="inp" :disabled="readonly" /></div></div>
          <div class="field"><label for="cg">Cargo name *</label><input id="cg" v-model="f.cargo" class="inp" list="cargo" autocomplete="off" :disabled="readonly" /><datalist id="cargo"><option v-for="c in CARGO" :key="c" :value="c" /></datalist></div>
          <div class="field"><label for="co">Country of origin *</label><input id="co" v-model="f.country" class="inp" :disabled="readonly" /></div>
          <div class="field"><label for="lt">Lead time *</label><input id="lt" v-model="f.leadtime" type="number" min="1" inputmode="numeric" class="inp" :disabled="readonly" /></div>
        </section>

        <section class="card">
          <h2 class="sec">Biaya dan toleransi</h2>
          <div class="field"><label for="fr">Freight * (per liter)</label><input id="fr" v-model="f.freight" type="number" min="0" step="any" inputmode="decimal" class="inp" :disabled="readonly" /></div>
          <div class="field"><label for="dm">Biaya demurrage *</label><input id="dm" v-model="f.demurrage" type="number" min="0" inputmode="numeric" class="inp" :disabled="readonly" /></div>
          <div class="two"><div class="field"><label for="ly">Tipe loss *</label><select id="ly" v-model="f.losstype" class="inp" :disabled="readonly"><option value="">Pilih</option><option v-for="x in ['R1', 'R2', 'R3', 'R4']" :key="x" :value="x">{{ x }}</option></select></div>
            <div class="field"><label for="sa">Satuan *</label><select id="sa" v-model="f.satuan" class="inp" :disabled="readonly"><option value="">Pilih</option><option value="GOV">GOV</option><option value="GSV">GSV</option></select></div></div>
          <div class="field"><label for="ls">Loss tolerance * (%)</label><input id="ls" v-model="f.loss" type="number" min="0" step="any" inputmode="decimal" class="inp" :disabled="readonly" /></div>
        </section>

        <section class="card">
          <h2 class="sec">Pihak dan catatan</h2>
          <div class="field"><label for="sh">Shipper *</label><input id="sh" v-model="f.shipper" class="inp" :disabled="readonly" /></div>
          <div class="field"><label for="cn">Consignee *</label><input id="cn" v-model="f.signee_name" class="inp" :disabled="readonly" /></div>
          <div class="field"><label for="bl">BL ship on board *</label><textarea id="bl" v-model="f.bl_ship" class="inp" rows="2" :disabled="readonly" /></div>
          <div class="field"><label for="kp">Catatan purchasing</label><textarea id="kp" v-model="f.ket_ship" class="inp" rows="2" :disabled="readonly" /></div>
          <p v-if="formError" class="errmsg">{{ formError }}</p>
          <button v-if="bisaForm" class="btn btn-primary btn-block" @click="tinjau">{{ d.ada ? 'Simpan perubahan' : 'Simpan' }}</button>
          <button v-if="d.bisa_cetak" class="btn btn-ghost btn-block" style="margin-top:10px" @click="router.push({ name: 'po-supplier-shipping-cetak', params: { id, sr: r.id } })"><Icon name="doc" :size="18" /> Cetak Shipping Instruction</button>
          <button v-if="d.bisa_cancel" class="btn btn-ghost btn-block" style="margin-top:10px;color:var(--red)" @click="cancelOpen = true; formError = ''"><Icon name="x" :size="18" /> Cancel shipping request</button>
        </section>
      </template>
    </template>

    <div v-if="konfirmasi" class="sheet-back" @click.self="!busy && (konfirmasi = false)">
      <div class="sheet" role="dialog" aria-modal="true">
        <div class="grab" />
        <h2 class="sheet-t">Simpan shipping request?</h2>
        <p class="muted" style="margin:6px 0 12px">{{ d.ada ? 'Perubahan mengulang proses dari Logistik: nomor SI dan semua persetujuan dikosongkan.' : 'Logistik akan diberi tahu lewat email untuk memproses Shipping Instruction.' }}</p>
        <div class="sheet-act"><button class="btn btn-ghost" :disabled="busy" @click="konfirmasi = false">Batal</button><button class="btn btn-primary" :disabled="busy" @click="simpan">{{ busy ? 'Menyimpan…' : 'Simpan' }}</button></div>
      </div>
    </div>

    <div v-if="cancelOpen" class="sheet-back" @click.self="!busy && (cancelOpen = false)">
      <div class="sheet" role="dialog" aria-modal="true">
        <div class="grab" />
        <h2 class="sheet-t">Cancel shipping request?</h2>
        <div class="field" style="margin-top:8px"><label for="kc">Keterangan cancel *</label><textarea id="kc" v-model="ketCancel" class="inp" rows="3" /></div>
        <p v-if="formError" class="errmsg">{{ formError }}</p>
        <div class="sheet-act"><button class="btn btn-ghost" :disabled="busy" @click="cancelOpen = false">Batal</button><button class="btn btn-primary" :disabled="busy" @click="doCancel">{{ busy ? 'Menyimpan…' : 'Ya, cancel' }}</button></div>
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
.two { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 10px; }
.muted { color: var(--muted); font-size: 12.5px; }
.errmsg { color: var(--red); font-size: 13px; margin: 8px 0; }
.step { display: grid; grid-template-columns: 1fr auto; gap: 2px 10px; padding: 8px 0; border-top: 1px dashed var(--line); font-size: 13.5px; } .step small { grid-column: 1 / -1; color: var(--muted); }
</style>
