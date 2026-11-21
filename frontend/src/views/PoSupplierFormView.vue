<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import StateBox from '../components/StateBox.vue'
import AccuratePicker from '../components/AccuratePicker.vue'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'
import { showToast } from '../composables/useToast'
import { angka } from '../utils/format'

const props = defineProps({ id: String, mode: { type: String, default: 'ubah' } })
const harga = computed(() => props.mode === 'harga')
const router = useRouter()
const ubah = computed(() => !!props.id)
const opsi = ref({ produk: [], terminal: [], vendor: [], pbbkb: [], accurate: false })
const loading = ref(true)
const error = ref('')
const busy = ref(false)
const formError = ref('')
const konfirmasi = ref(false)
const info = ref({})
const hasil = ref({ subtotal: 0, ppn_11: 0, dpp_11_12: 0, ppn_12: 0, pph_22: 0, pbbkb: 0, total_order: 0, nominal_iuran: 0 })
const f = reactive({
  tanggal: '', id_produk: '', id_vendor: '', id_terminal: '', jenis_kirim: '1', terms: '', terms_day: '', kd_tax: '', volume_po: '', jenis_harga: '1', harga_dasar: '',
  kategori_oa: '1', jenis_oa: '', ongkos_angkut: '', kategori_plat: '', pbbkb_tawar: '', iuran_migas: false, nominal_iuran: '', ket: '', internal_notes: '', ket_resubmission: '',
  kode_item: '', keterangan_item1: '', kode_item2: '', keterangan_item2: '', biaya_oa: '', keterangan_biaya1: '', alokasi_barang1: false, biaya_lain: '', jumlah_biaya: '', keterangan_biaya2: '', alokasi_barang2: false,
  kode_item3: '', keterangan_biaya3: '', alokasi_barang3: false, kode_biaya1: '', keterangan_biaya4: '', alokasi_barang4: false, kode_biaya2: '', keterangan_biaya5: '', alokasi_barang5: false,
})
const oa2 = computed(() => f.kategori_oa === '2')
const rp = (v) => 'Rp ' + angka(v, Number(v) % 1 ? 2 : 0)
const akun = (q) => syop.lossGainAkun(q)
const barang = (q) => syop.poBarangAccurate(q)

function paramHitung() {
  return { volume_po: Number(f.volume_po) || 0, harga_dasar: Number(f.harga_dasar) || 0, kategori_oa: Number(f.kategori_oa), ongkos_angkut: oa2.value ? Number(f.ongkos_angkut) || 0 : 0,
    kategori_plat: oa2.value && f.kategori_plat ? f.kategori_plat : undefined, kd_tax: f.kd_tax || undefined, pbbkb_tawar: f.pbbkb_tawar === '' ? undefined : Number(f.pbbkb_tawar),
    iuran_migas: f.iuran_migas, nominal_iuran: f.iuran_migas && f.nominal_iuran !== '' ? Number(f.nominal_iuran) : undefined }
}
let timer
let seq = 0
function hitung() {
  clearTimeout(timer)
  timer = setTimeout(async () => {
    const my = ++seq
    try { const r = await syop.poHitung(paramHitung()); if (my === seq) hasil.value = r } catch { /* abaikan; validasi ada di simpan */ }
  }, 300)
}
watch(() => [f.volume_po, f.harga_dasar, f.kategori_oa, f.ongkos_angkut, f.kategori_plat, f.kd_tax, f.pbbkb_tawar, f.iuran_migas, f.nominal_iuran], hitung)
watch(() => f.iuran_migas, (v) => { f.nominal_iuran = v ? '' : '' })
watch(() => f.kategori_oa, (v) => { if (v === '1') { f.jenis_oa = ''; f.ongkos_angkut = ''; f.kategori_plat = ''; f.kode_item2 = ''; f.biaya_oa = ''; f.biaya_lain = ''; f.jumlah_biaya = '' } })

async function load() {
  loading.value = true
  error.value = ''
  try {
    opsi.value = await syop.poFormOpsi()
    if (ubah.value) {
      const d = await (harga.value ? syop.poUbahHargaForm(props.id) : syop.poUbahForm(props.id))
      info.value = d
      Object.assign(f, { tanggal: d.tanggal, id_produk: String(d.id_produk), id_vendor: String(d.id_vendor), id_terminal: String(d.id_terminal), jenis_kirim: String(d.jenis_kirim), terms: d.terms || '', terms_day: d.terms_day || '',
        kd_tax: d.kd_tax || '', volume_po: d.volume_po, jenis_harga: String(d.jenis_harga), harga_dasar: d.harga_dasar, kategori_oa: String(d.kategori_oa), jenis_oa: d.jenis_oa === null ? '' : String(d.jenis_oa),
        ongkos_angkut: d.ongkos_angkut || '', kategori_plat: d.kategori_plat || '', pbbkb_tawar: String(d.pbbkb_tawar), iuran_migas: d.iuran_migas, nominal_iuran: d.iuran_migas ? d.nominal_iuran : '', ket: d.ket || '', internal_notes: d.internal_notes || '' })
      const a = d.accurate
      if (a) {
        Object.assign(f, { kode_item: a.kode_item || '', keterangan_item1: a.catatan_item || '', kode_item2: a.kode_oa || '', keterangan_item2: a.catatan_oa || '' })
        const b = a.biaya || {}
        if (b.oa) Object.assign(f, { biaya_oa: b.oa.akun, keterangan_biaya1: b.oa.catatan || '', alokasi_barang1: b.oa.alokasi })
        if (b.lain) Object.assign(f, { biaya_lain: b.lain.akun, jumlah_biaya: b.lain.jumlah ?? '', keterangan_biaya2: b.lain.catatan || '', alokasi_barang2: b.lain.alokasi })
        if (b.pph22) Object.assign(f, { kode_item3: b.pph22.akun, keterangan_biaya3: b.pph22.catatan || '', alokasi_barang3: b.pph22.alokasi })
        if (b.pbbkb) Object.assign(f, { kode_biaya1: b.pbbkb.akun, keterangan_biaya4: b.pbbkb.catatan || '', alokasi_barang4: b.pbbkb.alokasi })
        if (b.migas) Object.assign(f, { kode_biaya2: b.migas.akun, keterangan_biaya5: b.migas.catatan || '', alokasi_barang5: b.migas.alokasi })
      }
    } else {
      f.tanggal = new Date(Date.now() + 7 * 3600e3).toISOString().slice(0, 10)
    }
    hitung()
  } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
const back = () => (window.history.length > 1 ? router.back() : router.replace(ubah.value ? { name: 'po-supplier-detail', params: { id: props.id } } : { name: 'po-supplier' }))

function tinjau() {
  formError.value = ''
  const w = []
  if (!f.tanggal) w.push('Tanggal PO')
  if (!ubah.value && !f.id_produk) w.push('Produk')
  if (!ubah.value && !f.id_vendor) w.push('Vendor')
  if (!f.id_terminal) w.push('Terminal')
  if (!f.terms) w.push('Terms')
  if (f.terms === 'NET' && f.terms_day === '') w.push('Hari untuk terms NET')
  if (!f.kd_tax) w.push('Kode tax')
  if (!f.volume_po) w.push('Volume PO')
  if (!f.harga_dasar) w.push('Harga dasar')
  if (f.pbbkb_tawar === '') w.push('PBBKB')
  if (oa2.value && (f.jenis_oa === '' || f.ongkos_angkut === '' || !f.kategori_plat)) w.push('Jenis OA, ongkos angkut, dan kategori plat')
  if (!harga.value && !f.ket.trim()) w.push('Catatan PO')
  if (info.value.perlu_pengajuan_ulang && !f.ket_resubmission.trim()) w.push('Catatan pengajuan ulang PO')
  if (f.iuran_migas && !Number(hasil.value.nominal_iuran)) w.push('Nominal iuran migas')
  if (w.length) { formError.value = 'Lengkapi: ' + w.join(', ') + '.'; return }
  konfirmasi.value = true
}
function payload() {
  const p = {
    tanggal: f.tanggal, id_terminal: Number(f.id_terminal), jenis_kirim: Number(f.jenis_kirim), terms: f.terms, terms_day: f.terms === 'NET' ? Number(f.terms_day) : undefined, kd_tax: f.kd_tax,
    volume_po: Number(f.volume_po), harga_dasar: Number(f.harga_dasar), jenis_harga: Number(f.jenis_harga), kategori_oa: Number(f.kategori_oa), pbbkb_tawar: Number(f.pbbkb_tawar),
    iuran_migas: f.iuran_migas, nominal_iuran: f.iuran_migas ? Number(hasil.value.nominal_iuran) : undefined, ket: harga.value ? undefined : f.ket.trim(), internal_notes: harga.value ? undefined : f.internal_notes.trim() || undefined,
    ket_resubmission: info.value.perlu_pengajuan_ulang ? f.ket_resubmission.trim() : undefined,
    jenis_oa: oa2.value ? Number(f.jenis_oa) : undefined, ongkos_angkut: oa2.value ? Number(f.ongkos_angkut) : undefined, kategori_plat: oa2.value ? f.kategori_plat : undefined,
  }
  if (!ubah.value) { p.id_produk = Number(f.id_produk); p.id_vendor = Number(f.id_vendor) }
  const k = ['kode_item', 'keterangan_item1', 'kode_item2', 'keterangan_item2', 'biaya_oa', 'keterangan_biaya1', 'biaya_lain', 'keterangan_biaya2', 'kode_item3', 'keterangan_biaya3', 'kode_biaya1', 'keterangan_biaya4', 'kode_biaya2', 'keterangan_biaya5']
  k.forEach((x) => { if (f[x] !== '') p[x] = f[x] })
  ;['alokasi_barang1', 'alokasi_barang2', 'alokasi_barang3', 'alokasi_barang4', 'alokasi_barang5'].forEach((x) => { p[x] = !!f[x] })
  if (f.jumlah_biaya !== '') p.jumlah_biaya = Number(f.jumlah_biaya)
  return p
}
async function simpan() {
  busy.value = true
  try {
    const r = await (harga.value ? syop.poSimpanHarga(props.id, payload()) : syop.poSimpan(props.id, payload()))
    showToast(r.accurate_manual ? 'Tersimpan. Accurate belum aktif: PO perlu dibuat/disesuaikan manual di Accurate.' : r.message, 'success')
    router.replace(ubah.value ? { name: 'po-supplier-detail', params: { id: props.id } } : { name: 'po-supplier-detail', params: { id: r.id } })
  } catch (e) { konfirmasi.value = false; formError.value = errorMessage(e) } finally { busy.value = false }
}
onMounted(load)
</script>

<template>
  <div class="page">
    <div class="page-head">
      <button class="icon-btn" aria-label="Kembali" @click="back"><Icon name="back" :size="22" /></button>
      <div style="flex:1;min-width:0"><h1 class="page-title">{{ harga ? 'Perubahan Harga PO' : ubah ? 'Ubah PO Supplier' : 'Tambah PO Supplier' }}</h1><p class="page-sub">{{ ubah ? info.nomor_po : 'Nomor PO dibuat otomatis' }}</p></div>
    </div>
    <StateBox v-if="error" icon="alert" title="Tidak dapat dibuka" :text="error" action="Coba lagi" @action="load" />
    <div v-else-if="loading" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:120px" /></div>
    <template v-else>
      <p v-if="harga" class="warn">Perubahan harga. Volume, plat, terminal, vendor, dan catatan PO tidak dapat diubah di sini. Setelah disimpan, PO kembali ke verifikasi.</p>
      <p v-if="info.peringatan_accurate" class="warn">Data Accurate tidak terbaca: {{ info.peringatan_accurate }}. Kode item dan akun perlu diisi ulang.</p>
      <p v-if="info.catatan_cfo" class="warn"><b>Catatan pengembalian CFO:</b> {{ info.catatan_cfo.replace(/<br\s*\/?>/gi, ' ') }}</p>
      <p v-if="info.catatan_ceo" class="warn"><b>Catatan pengembalian CEO:</b> {{ info.catatan_ceo.replace(/<br\s*\/?>/gi, ' ') }}</p>

      <section class="card">
        <h2 class="sec">Data PO</h2>
        <div class="field"><label for="tg">Tanggal PO *</label><input id="tg" v-model="f.tanggal" type="date" class="inp" /></div>
        <div class="field"><label for="pr">Produk *</label><select id="pr" v-model="f.id_produk" class="inp" :disabled="ubah"><option value="">Pilih produk</option><option v-for="x in opsi.produk" :key="x.id" :value="String(x.id)">{{ x.label }}</option></select></div>
        <template v-if="opsi.accurate || f.kode_item"><AccuratePicker v-model="f.kode_item" :fetcher="barang" label="Kode item Accurate" required /><div class="field"><label for="k1">Keterangan item</label><input id="k1" v-model="f.keterangan_item1" class="inp" /></div></template>
        <div class="field"><label for="tm">Terminal *</label><select id="tm" v-model="f.id_terminal" class="inp" :disabled="harga"><option value="">Pilih terminal</option><option v-for="x in opsi.terminal" :key="x.id" :value="String(x.id)">{{ x.label }}</option></select></div>
        <div class="field"><label for="vd">Vendor *</label><select id="vd" v-model="f.id_vendor" class="inp" :disabled="ubah"><option value="">Pilih vendor</option><option v-for="x in opsi.vendor" :key="x.id" :value="String(x.id)">{{ x.label }}</option></select></div>
        <div class="field"><label>Jenis kirim *</label><div class="radio"><label><input v-model="f.jenis_kirim" type="radio" value="1" /> Truck</label><label><input v-model="f.jenis_kirim" type="radio" value="2" /> Ship</label></div></div>
        <div class="field"><label for="te">Terms *</label><select id="te" v-model="f.terms" class="inp"><option value="">Pilih</option><option value="COD">C.O.D</option><option value="NET">NET</option><option value="CBD">CBD</option></select></div>
        <div v-if="f.terms === 'NET'" class="field"><label for="td">Hari terms NET *</label><input id="td" v-model="f.terms_day" type="number" min="0" inputmode="numeric" class="inp" /></div>
        <div class="field"><label for="kt">Kode tax *</label><select id="kt" v-model="f.kd_tax" class="inp"><option value="">Pilih</option><option value="E">E</option><option value="EC">EC</option></select></div>
      </section>

      <section class="card">
        <h2 class="sec">Harga</h2>
        <div class="field"><label for="vo">Volume PO * (Liter)</label><input id="vo" v-model="f.volume_po" type="number" min="1" inputmode="numeric" class="inp" :disabled="harga" /></div>
        <div class="field"><label>Jenis harga PO *</label><div class="radio"><label><input v-model="f.jenis_harga" type="radio" value="1" /> Final</label><label><input v-model="f.jenis_harga" type="radio" value="2" /> Sementara</label></div></div>
        <div class="field"><label for="hd">Harga dasar *</label><input id="hd" v-model="f.harga_dasar" type="number" min="0" step="any" inputmode="decimal" class="inp" /></div>
        <div class="field"><label for="oa">Ongkos angkut</label><select id="oa" v-model="f.kategori_oa" class="inp"><option value="1">Tanpa OA</option><option value="2">Dengan OA</option></select></div>
        <template v-if="oa2">
          <div class="field"><label for="jo">Jenis OA *</label><select id="jo" v-model="f.jenis_oa" class="inp"><option value="">Pilih</option><option value="1">Sebagai Biaya</option><option value="0">Sebagai Kode Item</option></select></div>
          <template v-if="f.jenis_oa === '0' && (opsi.accurate || f.kode_item2)"><AccuratePicker v-model="f.kode_item2" :fetcher="barang" label="Kode OA Accurate" required /><div class="field"><label>Keterangan item OA</label><input v-model="f.keterangan_item2" class="inp" /></div></template>
          <div class="field"><label for="ok">Ongkos angkut * (per liter)</label><input id="ok" v-model="f.ongkos_angkut" type="number" min="0" step="any" inputmode="decimal" class="inp" /></div>
          <div class="field"><label for="pl">Kategori plat *</label><select id="pl" v-model="f.kategori_plat" class="inp" :disabled="harga"><option value="">Pilih</option><option value="Hitam">Hitam</option><option value="Kuning">Kuning</option></select></div>
          <template v-if="f.jenis_oa === '1' && (opsi.accurate || f.biaya_oa)">
            <AccuratePicker v-model="f.biaya_oa" :fetcher="akun" label="Akun OA Accurate" required /><div class="field"><label>Keterangan</label><input v-model="f.keterangan_biaya1" class="inp" /><label class="chk"><input v-model="f.alokasi_barang1" type="checkbox" /> Alokasikan ke harga barang</label></div>
            <AccuratePicker v-model="f.biaya_lain" :fetcher="akun" label="Akun biaya lain" required /><div class="field"><label>Jumlah biaya lain *</label><input v-model="f.jumlah_biaya" type="number" min="0" inputmode="numeric" class="inp" /></div>
            <div class="field"><label>Keterangan</label><input v-model="f.keterangan_biaya2" class="inp" /><label class="chk"><input v-model="f.alokasi_barang2" type="checkbox" /> Alokasikan ke harga barang</label></div>
          </template>
        </template>
        <dl class="kv"><dt>Sub total</dt><dd>{{ rp(hasil.subtotal) }}</dd><dt>PPN 11%</dt><dd>{{ rp(hasil.ppn_11) }}</dd><dt>DPP 11/12</dt><dd>{{ rp(hasil.dpp_11_12) }}</dd><dt>PPN 12%</dt><dd>{{ rp(hasil.ppn_12) }}</dd></dl>
      </section>

      <section class="card">
        <h2 class="sec">Pajak dan biaya</h2>
        <template v-if="f.kd_tax === 'EC'">
          <template v-if="opsi.accurate || f.kode_item3"><AccuratePicker v-model="f.kode_item3" :fetcher="akun" label="Akun PPH22 Accurate" required /><div class="field"><label>Keterangan</label><input v-model="f.keterangan_biaya3" class="inp" /><label class="chk"><input v-model="f.alokasi_barang3" type="checkbox" /> Alokasikan ke harga barang</label></div></template>
        </template>
        <dl class="kv"><dt>PPH 22</dt><dd>{{ rp(hasil.pph_22) }}</dd></dl>
        <div class="field"><label for="pb">PBBKB *</label><select id="pb" v-model="f.pbbkb_tawar" class="inp"><option value="">Pilih</option><option v-for="x in opsi.pbbkb" :key="x" :value="String(x)">{{ x }} %</option></select></div>
        <template v-if="Number(f.pbbkb_tawar) > 0 && (opsi.accurate || f.kode_biaya1)"><AccuratePicker v-model="f.kode_biaya1" :fetcher="akun" label="Akun PBBKB Accurate" required /><div class="field"><label>Keterangan</label><input v-model="f.keterangan_biaya4" class="inp" /><label class="chk"><input v-model="f.alokasi_barang4" type="checkbox" /> Alokasikan ke harga barang</label></div></template>
        <dl class="kv"><dt>PBBKB</dt><dd>{{ rp(hasil.pbbkb) }}</dd></dl>
        <div class="field"><label class="chk"><input v-model="f.iuran_migas" type="checkbox" /> PO ini ada iuran migas</label></div>
        <template v-if="f.iuran_migas">
          <div class="field"><label>Nominal iuran migas</label><input v-model="f.nominal_iuran" type="number" min="0" inputmode="numeric" class="inp" :placeholder="'Bawaan ' + angka(hasil.nominal_iuran)" /></div>
          <template v-if="opsi.accurate || f.kode_biaya2"><AccuratePicker v-model="f.kode_biaya2" :fetcher="akun" label="Akun Migas Accurate" required /><div class="field"><label>Keterangan</label><input v-model="f.keterangan_biaya5" class="inp" /><label class="chk"><input v-model="f.alokasi_barang5" type="checkbox" /> Alokasikan ke harga barang</label></div></template>
        </template>
        <dl class="kv total"><dt>Total order</dt><dd>{{ rp(hasil.total_order) }}</dd></dl>
      </section>

      <section class="card">
        <h2 class="sec">Catatan</h2>
        <div class="field"><label for="ct">Catatan PO *</label><textarea id="ct" v-model="f.ket" class="inp" rows="3" :disabled="harga" /></div>
        <div class="field"><label for="in">Internal notes</label><textarea id="in" v-model="f.internal_notes" class="inp" rows="2" :disabled="harga" /></div>
        <template v-if="info.perlu_pengajuan_ulang">
          <div class="field"><label for="rs">Catatan pengajuan ulang PO *</label><textarea id="rs" v-model="f.ket_resubmission" class="inp" rows="2" /></div>
          <p v-if="info.pengajuan_ulang_terakhir" class="muted">Pengajuan ulang terakhir: {{ info.pengajuan_ulang_terakhir }}</p>
        </template>
        <p v-if="formError" class="errmsg">{{ formError }}</p>
        <button class="btn btn-primary btn-block" @click="tinjau">Simpan</button>
      </section>
    </template>

    <div v-if="konfirmasi" class="sheet-back" @click.self="!busy && (konfirmasi = false)">
      <div class="sheet" role="dialog" aria-modal="true">
        <div class="grab" />
        <h2 class="sheet-t">Yakin simpan?</h2>
        <p class="muted" style="margin:6px 0 12px">{{ ubah ? 'PO akan kembali ke verifikasi.' : 'PO baru dikirim ke verifikasi CFO.' }} Total order {{ rp(hasil.total_order) }}.</p>
        <div class="sheet-act"><button class="btn btn-ghost" :disabled="busy" @click="konfirmasi = false">Batal</button><button class="btn btn-primary" :disabled="busy" @click="simpan">{{ busy ? 'Menyimpan…' : 'Simpan' }}</button></div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.card { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 14px; margin-bottom: 12px; }
.sec { font-size: 15px; margin: 0 0 10px; }
.inp { width: 100%; min-height: 46px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; box-sizing: border-box; }
textarea.inp { padding: 10px 12px; }
.radio { display: flex; gap: 18px; padding: 6px 0; } .radio label, .chk { display: inline-flex; align-items: center; gap: 6px; font-weight: 500; }
.chk { margin-top: 6px; }
.kv { display: grid; grid-template-columns: 1fr auto; gap: 6px 12px; margin: 10px 0; font-size: 14px; } .kv dt { color: var(--muted); } .kv dd { margin: 0; font-weight: 600; text-align: right; }
.kv.total { border-top: 1px dashed var(--line); padding-top: 10px; font-size: 16px; } .kv.total dt { color: var(--ink); font-weight: 700; }
.muted { color: var(--muted); font-size: 12.5px; }
.warn { background: #fff3cd; color: #856404; border-left: 4px solid #ffc107; padding: 10px 12px; border-radius: 8px; font-size: 13px; margin: 0 0 12px; }
.errmsg { color: var(--red); font-size: 13px; margin: 8px 0; }
</style>
