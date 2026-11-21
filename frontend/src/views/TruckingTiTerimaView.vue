<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import StateBox from '../components/StateBox.vue'
import AccuratePicker from '../components/AccuratePicker.vue'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'
import { showToast } from '../composables/useToast'
import { angka, tanggal, tanggalJam } from '../utils/format'

const props = defineProps({ id: String })
const router = useRouter()
const d = ref(null)
const loading = ref(true)
const error = ref('')
const busy = ref(false)
const formError = ref('')
const konfirmasi = ref(false)
const detail = ref(null)
const cancelDsd = ref(null)
const pisah = ref(false)
const f = reactive({ nomor_penyesuaian: '', kode_item: '', akun_penyesuaian: '', nomor_penyesuaian_terima: '', kode_item_terima: '', harga_transfer: '', keterangan: '' })
const grup = ref([])
const barang = (q) => syop.poBarangAccurate(q)
const akun = (q) => syop.lossGainAkun(q)
const acc = computed(() => !!d.value?.accurate)

async function load() {
  loading.value = true
  error.value = ''
  try {
    d.value = await syop.tiTerima(props.id)
    grup.value = d.value.grup_po.map((g) => ({ ...g, nomor_penyesuaian: '', kode_item: '', nomor_penyesuaian_terima: '', kode_item_terima: '', harga: '' }))
  } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'trucking-ti-form', params: { id: props.id } }))
function tinjau() {
  formError.value = ''
  if (f.harga_transfer === '' || Number(f.harga_transfer) < 0) { formError.value = 'Harga wajib diisi.'; return }
  if (acc.value) {
    if (!f.akun_penyesuaian) { formError.value = 'Akun Accurate wajib diisi.'; return }
    if (!pisah.value && (!f.nomor_penyesuaian || !f.kode_item || !f.nomor_penyesuaian_terima || !f.kode_item_terima)) { formError.value = 'Nomor penyesuaian dan kode item (keluar dan terima) wajib diisi.'; return }
    if (pisah.value && grup.value.some((g) => !g.nomor_penyesuaian || !g.kode_item || !g.nomor_penyesuaian_terima || !g.kode_item_terima || g.harga === '')) { formError.value = 'Lengkapi nomor penyesuaian, kode item, dan harga untuk semua PO.'; return }
  }
  konfirmasi.value = true
}
async function simpan() {
  busy.value = true
  const p = { ...f, harga_transfer: Number(f.harga_transfer), pisah_accurate: pisah.value }
  if (pisah.value) p.grup = grup.value.map((g) => ({ id_po_supplier: g.id_po_supplier, id_po_receive: g.id_po_receive, nomor_penyesuaian: g.nomor_penyesuaian, kode_item: g.kode_item, nomor_penyesuaian_terima: g.nomor_penyesuaian_terima, kode_item_terima: g.kode_item_terima, harga: Number(g.harga) }))
  try {
    const r = await syop.tiReceived(props.id, p)
    showToast(r.accurate_manual ? 'Tersimpan. Accurate belum aktif: buat penyesuaian OUT/IN manual di Accurate.' : r.message, 'success')
    konfirmasi.value = false
    await load()
  } catch (e) { konfirmasi.value = false; formError.value = errorMessage(e) } finally { busy.value = false }
}
async function lihatDetail(b) {
  detail.value = { b, data: null }
  try { detail.value.data = (await syop.tiDetailAccurate(props.id, b.id_prd)).data } catch (e) { detail.value.error = errorMessage(e) }
}
async function approveCancel() {
  busy.value = true
  try { await syop.tiApproveCancel(props.id, cancelDsd.value.id_dsd); cancelDsd.value = null; showToast('Request cancel di-approve.', 'success'); await load() } catch (e) { showToast(errorMessage(e), 'error'); cancelDsd.value = null } finally { busy.value = false }
}
async function bukaSj(b) { try { window.open(URL.createObjectURL(await syop.tiSj(props.id, b.id_prd)), '_blank') } catch (e) { showToast(errorMessage(e), 'error') } }
onMounted(load)
</script>

<template>
  <div class="page">
    <div class="page-head">
      <button class="icon-btn" aria-label="Kembali" @click="back"><Icon name="back" :size="22" /></button>
      <div style="flex:1;min-width:0"><h1 class="page-title">Trucking Instruction Receive</h1><p class="page-sub">{{ d ? d.ti.nomor_ti : '' }}</p></div>
    </div>
    <StateBox v-if="error" icon="alert" title="Tidak dapat dibuka" :text="error" action="Coba lagi" @action="load" />
    <div v-else-if="loading" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:120px" /></div>
    <template v-else-if="d">
      <section class="card">
        <dl class="kv"><dt>Nomor TI</dt><dd>{{ d.ti.nomor_ti }}</dd><dt>Tanggal pengajuan</dt><dd>{{ tanggal(d.ti.tanggal) }}</dd><dt>Wilayah</dt><dd>{{ d.ti.wilayah }}</dd><dt>Loading port</dt><dd>{{ d.ti.load_port }}</dd><dt>Discharge port</dt><dd>{{ d.ti.discharge_port }}</dd>
          <dt>Transportir</dt><dd>{{ d.ti.transportir }}</dd><dt>Ongkos angkut</dt><dd>{{ angka(d.ti.ongkos) }}</dd></dl>
        <span v-if="d.ti.is_received" class="tag">Received</span>
      </section>

      <h2 class="sec">Detail Trucking Instruction</h2>
      <p v-if="!d.baris.length" class="muted">Data tidak ditemukan.</p>
      <div v-for="b in d.baris" :key="b.id_prd" class="card" :class="{ req: b.request }">
        <div class="top"><b>{{ b.nomor_dr }}</b><span class="muted">LO {{ b.nomor_lo }}</span></div>
        <p v-if="b.request" class="red">{{ b.request }}<template v-if="b.catatan_cancel"> · {{ b.catatan_cancel }}</template></p>
        <div class="muted">PO {{ b.nomor_po || '-' }}<template v-if="b.nomor_po_potong"> · {{ b.nomor_po_potong }}</template></div>
        <div class="vols"><span>Loading <b>{{ b.tgl_realisasi ? tanggalJam(b.tgl_realisasi) : 'belum loading' }}</b></span></div>
        <div class="vols"><span>Volume <b>{{ angka(b.vol_potongan) }}</b><template v-if="b.vol_potong2 !== null"> / {{ angka(b.vol_potong2) }}</template></span><span>Realisasi <b>{{ angka(b.realisasi) }}</b> L</span></div>
        <div class="acts">
          <button v-if="b.sj" class="mini" @click="bukaSj(b)"><Icon name="doc" :size="16" /> SJ</button>
          <button v-if="d.ti.is_received" class="mini" @click="lihatDetail(b)"><Icon name="info" :size="16" /> Detail Accurate</button>
          <button v-if="b.bisa_approve_cancel" class="mini ok" @click="cancelDsd = b"><Icon name="check" :size="16" /> Approve cancel</button>
        </div>
      </div>
      <div v-if="d.baris.length" class="card total"><span>Total realisasi</span><b>{{ angka(d.total_realisasi) }} Liter</b></div>

      <section v-if="d.bisa_received" class="card">
        <h2 class="sec">Received</h2>
        <p class="muted" style="margin-bottom:10px">Stok dipindahkan dari depot loading ke depot discharging{{ acc ? ' dan dicatat sebagai penyesuaian OUT/IN di Accurate' : '' }}. Volume TI {{ angka(d.ti.volume) }} L · total realisasi {{ angka(d.total_realisasi) }} L.</p>
        <label v-if="d.grup_po.length > 1" class="chk"><input v-model="pisah" type="checkbox" /> Pisah transaksi Accurate per PO ({{ d.grup_po.length }} PO)</label>
        <template v-if="!pisah">
          <div v-if="acc || f.nomor_penyesuaian" class="field"><label>Nomor penyesuaian *</label><input v-model="f.nomor_penyesuaian" class="inp" autocomplete="off" /></div>
          <AccuratePicker v-if="acc || f.kode_item" v-model="f.kode_item" :fetcher="barang" label="Kode item Accurate" required />
          <div v-if="acc || f.nomor_penyesuaian_terima" class="field"><label>Nomor penyesuaian terima *</label><input v-model="f.nomor_penyesuaian_terima" class="inp" autocomplete="off" /></div>
          <AccuratePicker v-if="acc || f.kode_item_terima" v-model="f.kode_item_terima" :fetcher="barang" label="Kode item terima" required />
        </template>
        <AccuratePicker v-if="acc || f.akun_penyesuaian" v-model="f.akun_penyesuaian" :fetcher="akun" label="Akun Accurate" required />
        <div class="field"><label for="hg">Harga * (Rp / liter)</label><input id="hg" v-model="f.harga_transfer" type="number" min="0" step="any" inputmode="decimal" class="inp" /></div>
        <div v-if="pisah" class="grp"><div v-for="(g, i) in grup" :key="i" class="g">
          <b>{{ g.nomor_po || '-' }}</b><small>Volume potong {{ angka(g.vol_potong) }}</small>
          <input v-model="g.nomor_penyesuaian" class="inp" placeholder="Nomor penyesuaian *" /><AccuratePicker v-model="g.kode_item" :fetcher="barang" label="Kode item Accurate" required />
          <input v-model="g.nomor_penyesuaian_terima" class="inp" placeholder="Nomor penyesuaian terima *" /><AccuratePicker v-model="g.kode_item_terima" :fetcher="barang" label="Kode item terima" required />
          <input v-model="g.harga" type="number" min="0" step="any" class="inp" placeholder="Harga *" /></div></div>
        <div class="field"><label for="kt">Keterangan</label><textarea id="kt" v-model="f.keterangan" class="inp" rows="2" /></div>
        <p v-if="formError" class="errmsg">{{ formError }}</p>
        <button class="btn btn-primary btn-block" @click="tinjau">Simpan received</button>
      </section>
      <button v-if="d.baris.length" class="btn btn-ghost btn-block" style="margin:6px 0 14px" @click="router.push({ name: 'trucking-ti-cetak-received', params: { id } })"><Icon name="doc" :size="18" /> Cetak Received</button>
      <p v-else-if="!d.ti.is_received" class="muted">TI ini belum bisa di-received (belum ada DO atau sudah cancel).</p>
    </template>

    <div v-if="konfirmasi" class="sheet-back" @click.self="!busy && (konfirmasi = false)">
      <div class="sheet" role="dialog" aria-modal="true"><div class="grab" /><h2 class="sheet-t">Simpan received?</h2>
        <p class="muted" style="margin:6px 0 12px">Stok dipindahkan ke depot discharging dan TI ditandai Received. Tindakan ini tidak dapat dibatalkan dari aplikasi.</p>
        <div class="sheet-act"><button class="btn btn-ghost" :disabled="busy" @click="konfirmasi = false">Batal</button><button class="btn btn-primary" :disabled="busy" @click="simpan">{{ busy ? 'Menyimpan…' : 'Ya, simpan' }}</button></div></div>
    </div>
    <div v-if="cancelDsd" class="sheet-back" @click.self="!busy && (cancelDsd = null)">
      <div class="sheet" role="dialog" aria-modal="true"><div class="grab" /><h2 class="sheet-t">Approve request cancel?</h2>
        <p class="muted" style="margin:6px 0 12px">DO {{ cancelDsd.nomor_dr }} dibatalkan, TI berstatus Cancel, dan potongan stok DO dihapus. Data tidak dapat dikembalikan.</p>
        <div class="sheet-act"><button class="btn btn-ghost" :disabled="busy" @click="cancelDsd = null">Batal</button><button class="btn btn-primary" :disabled="busy" @click="approveCancel">{{ busy ? 'Menyimpan…' : 'Ya, approve' }}</button></div></div>
    </div>
    <div v-if="detail" class="sheet-back" @click.self="detail = null">
      <div class="sheet" role="dialog" aria-modal="true" style="max-height:85vh;overflow:auto"><div class="grab" /><h2 class="sheet-t">Detail Accurate</h2>
        <p v-if="detail.error" class="errmsg">{{ detail.error }}</p><p v-else-if="!detail.data" class="muted">Mengambil data dari Accurate…</p>
        <template v-else>
          <dl class="kv"><dt>Nomor TI</dt><dd>{{ detail.data.do.nomor_ti }}</dd><dt>Nomor DR</dt><dd>{{ detail.data.do.nomor_dr }}</dd><dt>Nomor LO</dt><dd>{{ detail.data.do.nomor_lo }}</dd><dt>Nomor PO</dt><dd>{{ detail.data.do.nomor_po }}</dd></dl>
          <p v-if="!detail.data.transaksi.length" class="warnbox">Belum ada transaksi Accurate yang tercatat untuk DO ini.</p>
          <div v-for="(x, i) in detail.data.transaksi" :key="i" class="trx">
            <div class="trx-h"><b :class="x.tipe === 'OUT' ? 'out' : 'in'">{{ x.tipe }}</b><span class="muted">{{ x.keterangan }}</span><span class="muted">ID Accurate : {{ x.id_accurate }}</span></div>
            <dl class="kv"><dt>Nomor PO</dt><dd>{{ x.nomor_po }}</dd><dt>Volume (SYOP)</dt><dd>{{ angka(x.volume_syop) }} Liter dari {{ x.jml_baris }} baris</dd></dl>
            <p v-if="x.pesan" class="warnbox">{{ x.pesan }}</p>
            <template v-if="x.accurate">
              <dl class="kv"><dt>Nomor penyesuaian</dt><dd>{{ x.accurate.nomor }}</dd><dt>Tanggal</dt><dd>{{ x.accurate.tanggal }}</dd><dt>Cabang</dt><dd>{{ x.accurate.cabang }}</dd><dt>Akun penyesuaian</dt><dd>{{ x.accurate.akun }}</dd><dt>Keterangan</dt><dd>{{ x.accurate.keterangan }}</dd></dl>
              <p v-if="!x.items.length" class="warnbox">Tidak ada detail item pada transaksi ini.</p>
              <div v-for="(it, k) in x.items" :key="k" class="it"><b>{{ it.kode }}</b><span>{{ angka(it.qty, 2) }}</span><small>{{ it.nama }} · {{ it.tipe }} · {{ it.gudang }}<template v-if="it.unit_cost !== null"> · Unit cost {{ angka(it.unit_cost, 2) }}</template></small></div>
            </template>
          </div>
        </template>
        <button class="btn btn-ghost btn-block" style="margin-top:12px" @click="detail = null">Tutup</button></div>
    </div>
  </div>
</template>

<style scoped>
.card { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 14px; margin-bottom: 12px; } .card.req { border-color: #f5b5ae; background: #fff1f0; }
.kv { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.3fr); gap: 8px 12px; margin: 0; font-size: 14px; } .kv dt { color: var(--muted); } .kv dd { margin: 0; font-weight: 600; text-align: right; overflow-wrap: anywhere; }
.sec { font-size: 15px; margin: 16px 0 10px; }
.top { display: flex; justify-content: space-between; gap: 8px; } .top b { overflow-wrap: anywhere; }
.muted { color: var(--muted); font-size: 12.5px; } .red { color: var(--red); font-size: 13px; font-weight: 700; margin: 4px 0; }
.vols { display: flex; flex-wrap: wrap; gap: 4px 14px; margin: 6px 0 0; font-size: 13.5px; color: var(--muted); } .vols b { color: var(--ink); }
.acts { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
.mini { display: inline-flex; align-items: center; gap: 6px; min-height: 36px; padding: 0 12px; border-radius: 10px; border: 1px solid var(--line); background: var(--card); color: var(--ink-2); font-size: 13px; font-weight: 600; } .mini.ok { color: #17713a; }
.total { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; }
.tag { display: inline-block; margin-top: 8px; padding: 3px 9px; border-radius: 8px; font-size: 12px; font-weight: 600; background: #c6e9ff; color: #2459b6; }
.chk { display: flex; align-items: center; gap: 8px; margin: 0 0 12px; font-size: 14px; }
.inp { width: 100%; min-height: 46px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; box-sizing: border-box; margin-bottom: 8px; } textarea.inp { padding: 10px 12px; }
.grp .g { border: 1px solid var(--line); border-radius: 12px; padding: 10px; margin-bottom: 10px; display: grid; gap: 4px; } .grp small { color: var(--muted); }
.tr { display: grid; grid-template-columns: 1fr auto; gap: 2px 10px; padding: 8px 0; border-top: 1px dashed var(--line); font-size: 13.5px; } .tr small { grid-column: 1 / -1; color: var(--muted); }
.errmsg { color: var(--red); font-size: 13px; margin: 8px 0; }
.warnbox { background: #fff3cd; color: #856404; border-radius: 8px; padding: 10px 12px; font-size: 13px; margin: 8px 0; }
.trx { border: 1px solid var(--line); border-radius: 12px; padding: 10px; margin: 12px 0; } .trx-h { display: flex; flex-wrap: wrap; gap: 4px 10px; align-items: baseline; margin-bottom: 6px; } .out { color: #de2e2e; } .in { color: #2459b6; }
.it { display: grid; grid-template-columns: 1fr auto; gap: 2px 10px; padding: 6px 0; border-top: 1px dashed var(--line); font-size: 13.5px; } .it small { grid-column: 1 / -1; color: var(--muted); }
</style>
