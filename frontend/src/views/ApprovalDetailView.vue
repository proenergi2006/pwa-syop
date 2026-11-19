<script setup>
import { t, ts } from '../i18n'
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import Collapse from '../components/Collapse.vue'
import StateBox from '../components/StateBox.vue'
import { useInbox } from '../stores/inbox'
import { useAuth } from '../stores/auth'
import * as syop from '../api/syop'
import { errorCode, errorMessage } from '../api/client'
import { showToast } from '../composables/useToast'
import { angka, tanggal, tanggalJam } from '../utils/format'

const props = defineProps({ id: String })
const router = useRouter()
const inbox = useInbox()
const auth = useAuth()

const data = ref(null)
const loading = ref(true)
const error = ref('')

// Lembar keputusan
const sheet = ref(null) // 'approve' | 'reject' | null
const catatan = ref('')
const teruskan = ref(false)
const submitting = ref(false)
const formError = ref('')
// Sesi hasil face login: server mewajibkan password untuk memutuskan penawaran.
const needPassword = computed(() => false) // tidak ada konfirmasi password di modul ini (hanya di Profil untuk wajah)
const pwd = ref('')

// Tab: isi detail dipecah supaya tidak perlu menggulir panjang.
const tab = ref('harga')
const TABS = [
  { k: 'harga', t: 'det.tab.price' },
  { k: 'customer', t: 'det.tab.customer' },
  { k: 'penawaran', t: 'det.tab.offer' },
  { k: 'riwayat', t: 'det.tab.history' },
]
const tabIdx = computed(() => TABS.findIndex((x) => x.k === tab.value))

const p = computed(() => data.value?.penawaran || {})
const form = computed(() => data.value?.decision_form)
const st = computed(() => data.value?.status_approval || {})
const tot = computed(() => data.value?.totals || {})
const pc = computed(() => data.value?.price_check || { items: [], ringkas: null })
const selisihTier = (label) => pc.value.items.find((i) => i.label === label)
const pill = computed(() => ({ ok: 'pill-ok', no: 'pill-no', wait: 'pill-wait' }[st.value.tone] || 'pill-wait'))

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await syop.getPenawaran(props.id)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}

function back() {
  window.history.length > 1 ? router.back() : router.replace({ name: 'approval' })
}

function openSheet(mode) {
  sheet.value = mode
  catatan.value = ''
  formError.value = ''
  pwd.value = ''
  teruskan.value = !!form.value?.forward_default
}

async function submit() {
  if (submitting.value) return
  formError.value = ''
  if (sheet.value === 'reject' && !catatan.value.trim()) {
    formError.value = t('det.errReason')
    return
  }
  if (needPassword.value && !pwd.value) {
    formError.value = t('det.errPwd')
    return
  }
  submitting.value = true
  try {
    const payload = { keputusan: sheet.value, catatan: catatan.value.trim() || undefined }
    if (needPassword.value) payload.password = pwd.value
    if (sheet.value === 'approve' && form.value?.can_forward) payload.teruskan = form.value.forward_locked ? true : teruskan.value
    const r = await syop.decide(props.id, payload)
    // Lanjut otomatis ke penawaran berikutnya yang menunggu (terlama dulu); bila habis, kembali ke daftar.
    let next = null
    try { next = (await syop.listPending({ per_page: 1, sort: 'asc' })).data[0] } catch { /* kembali ke daftar */ }
    inbox.refresh()
    if (next) {
      showToast(t('det.next', { msg: ts(r.message) + (r.accurate_manual ? ' ' + t('det.accurateManual') : '') }), 'ok', r.accurate_manual ? 7000 : undefined)
      router.replace({ name: 'approval-detail', params: { id: next.id_penawaran } })
    } else {
      showToast(t('det.allDone', { msg: ts(r.message) + (r.accurate_manual ? ' ' + t('det.accurateManual') : '') }), 'ok', r.accurate_manual ? 7000 : undefined)
      router.replace({ name: 'approval' })
    }
  } catch (e) {
    formError.value = errorMessage(e)
    if (errorCode(e) === 'STEP_UP_REQUIRED') auth.method = 'face'
    pwd.value = ''
    // Sudah diproses orang lain / bukan giliran: muat ulang supaya tampilan sesuai kondisi sebenarnya.
    if (['NOT_YOUR_TURN', 'HARGA_BARU_MISSING'].includes(errorCode(e))) {
      sheet.value = null
      showToast(formError.value, 'err', 4500)
      load()
    }
  } finally {
    submitting.value = false
  }
}

const openOther = (o) => router.push({ name: 'approval-detail', params: { id: o.id_penawaran } })
const periode = (a, b) => `${tanggal(a)} – ${tanggal(b)}`

// Format angka mengikuti web lama: PPN 2 desimal, komponen lain dibulatkan.
const biaya = (r) => (r.biaya === undefined || r.biaya === '' || isNaN(r.biaya) ? '-' : angka(r.biaya, r.rincian === 'PPN' ? 2 : 0))
const nomor = computed(() => p.value.nomor_surat || '…')

// Pengajuan perubahan harga: baris perbandingan lama vs baru.
const pch = computed(() => data.value?.perubahan_harga || null)
const pcRows = computed(() => {
  const a = pch.value?.lama, b = pch.value?.baru
  if (!b) return []
  const f = (x, k, d = 0) => (x ? angka(x[k], d) : '-')
  const row = (k, label, va, vb) => ({ k, label, lama: va, baru: vb, diff: va !== vb })
  return [
    row('hd', t('det.pc.perLiter'), f(a, 'harga_dasar', 2), f(b, 'harga_dasar', 2)),
    row('ht', t('det.pc.tierPrice'), f(a, 'harga_tier'), f(b, 'harga_tier')),
    row('t', t('det.tier'), a?.tier || '-', b.tier || '-'),
    row('rf', t('det.refund'), f(a, 'refund'), f(b, 'refund')),
    row('oc', t('det.pc.otherTotal'), f(a, 'other_cost'), f(b, 'other_cost')),
  ]
})

onMounted(load)
</script>

<template>
  <div class="detail">
    <header class="bar">
      <button class="back" :aria-label="t('common.back')" @click="back"><Icon name="back" :size="20" /></button>
      <div class="bar-t">
        <b>{{ t('det.title') }}</b>
        <small>{{ nomor }}</small>
      </div>
      <button class="back" :aria-label="t('det.refresh')" @click="load"><Icon name="refresh" :size="18" /></button>
    </header>

    <div v-if="loading" class="wrap"><div v-for="i in 4" :key="i" class="skel" style="height:120px;margin-bottom:12px" /></div>
    <div v-else-if="error" class="wrap"><StateBox icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load" /></div>

    <div v-else class="wrap">
      <!-- Ringkasan: hal yang dibutuhkan untuk memutuskan, selalu terlihat -->
      <section class="card head rise" style="--i: 0">
        <div class="badges">
          <span class="pill" :class="pill">{{ ts(st.text) }}</span>
          <span v-if="p.is_edited == 1" class="pill pill-ok">{{ t('det.edited') }}</span>
          <span v-if="data.perubahan_harga?.aktif" class="pill pill-info">{{ t('det.priceChange') }}</span>
        </div>
        <h1>{{ data.customer.nama_customer }}</h1>
        <p>{{ p.jenis_produk }} · {{ p.nama_cabang }} · {{ p.nama_area }}</p>
        <div class="hrow">
          <div class="price">{{ angka(p.harga_dasar, 2) }}<small> /L</small></div>
          <div class="vol">{{ angka(p.volume_tawar) }} L</div>
        </div>
        <div v-if="pc.ringkas" class="pcheck" :class="pc.ringkas.di_bawah ? 'low' : 'okp'">
          <Icon :name="pc.ringkas.di_bawah ? 'alert' : 'check'" :size="16" />
          <span>{{ t('det.basePrice') }} <b>{{ angka(pc.harga_dasar) }}</b> ·
            <b>{{ pc.ringkas.selisih > 0 ? '+' : '' }}{{ angka(pc.ringkas.selisih) }}</b>
            {{ pc.ringkas.di_bawah ? t('det.below') : t('det.above') }} {{ pc.ringkas.label.replace('Harga ', '') }} ({{ angka(pc.ringkas.nilai) }})</span>
        </div>
        <div class="chips2">
          <span><em>{{ t('det.tier') }}</em>{{ tot.tier || '-' }}</span>
          <span :class="{ bad: tot.refund_over }"><em>{{ t('det.refund') }}</em>{{ angka(tot.refund) }}</span>
          <span><em>{{ t('det.otherCost') }}</em>{{ angka(tot.other_cost) }}</span>
        </div>
        <div v-if="st.pic || st.alasan" class="stnote">
          <small v-if="st.pic">{{ st.pic }}<template v-if="st.tanggal"> · {{ tanggalJam(st.tanggal) }}</template></small>
          <p v-if="st.alasan"><b>{{ t('det.reason') }}</b> {{ st.alasan }}</p>
        </div>
      </section>

      <div v-if="data.warnings.length" class="alert rise" style="--i: 1" role="alert">
        <Icon name="alert" :size="20" />
        <div>
          <div v-for="w in data.warnings" :key="w.code">{{ ts(w.message) }}</div>
          <div v-if="form?.forward_locked" style="margin-top:4px">{{ t('det.mustForward') }}</div>
        </div>
      </div>

      <!-- Pengajuan perubahan harga: perbandingan harga lama dan baru -->
      <section v-if="pch?.aktif && pch.baru" class="card pch rise" style="--i: 1">
        <h2>{{ t('det.pc.title') }}</h2>
        <p class="pcm">
          <template v-if="pch.pengajuan_ke">{{ t('det.pc.nth', { n: pch.pengajuan_ke }) }}</template>
          <template v-if="pch.diajukan_oleh"> · {{ t('det.pc.by') }} <b>{{ pch.diajukan_oleh }}</b></template>
          <template v-if="pch.diajukan_pada"> · {{ tanggalJam(pch.diajukan_pada) }}</template>
          <template v-if="pch.posisi"> · {{ t('det.pc.pos') }} <b>{{ pch.posisi }}</b></template>
        </p>
        <div class="cmp">
          <div class="ch"><span /><b>{{ t('det.pc.old') }}</b><b class="nw">{{ t('det.pc.new') }}</b></div>
          <div v-for="r in pcRows" :key="r.k" class="cr" :class="{ diff: r.diff }">
            <span>{{ r.label }}</span><span>{{ r.lama }}</span><span class="nw">{{ r.baru }}</span>
          </div>
        </div>
        <div v-for="col in ['lama', 'baru']" :key="col" class="pcol">
          <h3>{{ col === 'lama' ? t('det.pc.old') : t('det.pc.new') }}</h3>
          <div v-for="(r, i) in pch[col]?.rincian || []" :key="i" class="kv sub"><span>{{ r.rincian }}{{ Number(r.nilai) ? ' ' + r.nilai + '%' : '' }}</span><span>{{ angka(r.biaya, 2) }}</span></div>
          <p v-if="!(pch[col]?.rincian || []).length" class="muted tiny">{{ t('det.pc.noRincian') }}</p>
          <div v-for="(o, i) in pch[col]?.other_costs || []" :key="'o' + i" class="kv sub"><span>{{ t('det.otherCost') }}: {{ o.keterangan }}</span><span>{{ angka(o.nominal) }}</span></div>
        </div>
        <p v-if="pch.baru.catatan" class="note2"><b>{{ t('det.mktNote') }}</b> {{ pch.baru.catatan }}</p>
      </section>
      <Collapse v-if="pch?.riwayat?.length" :title="t('det.pc.history')" :count="pch.riwayat.length" class="rise" style="--i: 1">
        <div v-for="rq in pch.riwayat" :key="rq.id" class="rq">
          <div class="rqh"><b>{{ t('det.pc.nth', { n: rq.pengajuan_ke }) }}</b> · {{ rq.tier }}<span class="pill" :class="rq.status === 'APPROVED' ? 'pill-ok' : rq.status === 'REJECTED' ? 'pill-no' : 'pill-wait'">{{ rq.status }}</span></div>
          <small>{{ rq.diajukan_oleh }} · {{ tanggalJam(rq.diajukan_pada) }}</small>
          <div v-if="rq.baru" class="rqp">{{ t('det.pc.perLiter') }}: {{ rq.lama ? angka(rq.lama.harga_dasar, 2) : '-' }} → <b>{{ angka(rq.baru.harga_dasar, 2) }}</b>
            <template v-if="rq.baru.other_costs?.length"> · {{ t('det.otherCost') }}: {{ rq.baru.other_costs.map((o) => o.keterangan + ' ' + angka(o.nominal)).join(', ') }}</template></div>
          <div v-for="(a, i) in rq.approvals" :key="i" class="ra"><span class="dot" :class="a.hasil === 1 ? 'ok' : 'no'" /><div><b>{{ a.level }}</b> · {{ a.nama }} <small>{{ tanggalJam(a.waktu) }}</small><p v-if="a.catatan">{{ a.catatan }}</p></div></div>
        </div>
      </Collapse>

      <!-- Catatan marketing ikut di atas: sering menentukan keputusan -->
      <section v-if="p.catatan" class="card note rise" style="--i: 2"><b>{{ t('det.mktNote') }}</b> {{ p.catatan }}</section>

      <!-- Tab -->
      <div class="tabs" role="tablist" :style="{ '--ti': tabIdx }">
        <span class="tab-ind" aria-hidden="true" />
        <button v-for="tb in TABS" :key="tb.k" role="tab" class="tab" :class="{ on: tab === tb.k }" :aria-selected="tab === tb.k" @click="tab = tb.k">
          {{ t(tb.t) }}<b v-if="tb.k === 'riwayat' && data.history_approval.length" class="tn">{{ data.history_approval.length }}</b>
        </button>
      </div>

      <Transition name="tabfade" mode="out-in">
        <div :key="tab" class="tabpane">
          <!-- ===== HARGA ===== -->
          <template v-if="tab === 'harga'">
            <section class="card">
              <h3 class="ct">{{ t('det.pricelist') }}</h3>
              <div class="kv"><span>{{ t('det.validity') }}</span><span>{{ periode(data.pricelist.masa_awal, data.pricelist.masa_akhir) }}</span></div>
              <div class="kv"><span>{{ t('det.recBase') }}</span><span>{{ angka(data.pricelist.rekomendasi_harga_dasar) }}</span></div>
              <div class="kv"><span>{{ t('det.recFreight') }}</span><span>{{ angka(data.pricelist.rekomendasi_oa) }}</span></div>
              <div v-for="tr in data.pricelist.tiers" :key="tr.label" class="kv"><span>{{ ts(tr.label) }}</span>
                <span>{{ angka(tr.nilai) }}<em v-if="selisihTier(tr.label)" class="sel" :class="selisihTier(tr.label).di_bawah ? 'bad' : 'good'">{{ selisihTier(tr.label).selisih > 0 ? '+' : '' }}{{ angka(selisihTier(tr.label).selisih) }}</em></span></div>
            </section>

            <section class="card">
              <h3 class="ct">{{ t('det.breakdown') }}</h3>
              <table class="rt">
                <thead><tr><th>{{ t('det.th.no') }}</th><th>{{ t('det.th.item') }}</th><th class="r">{{ t('det.th.value') }}</th><th class="r">{{ t('det.th.price') }}</th></tr></thead>
                <tbody>
                  <tr v-for="(r, i) in data.rincian" :key="'r' + i">
                    <td>{{ i + 1 }}</td><td>{{ ts(r.rincian) }}</td>
                    <td class="r">{{ r.nilai && Number(r.nilai) ? angka(r.nilai, 1).replace(/,0$/, '') + ' %' : '' }}</td>
                    <td class="r">{{ biaya(r) }}</td>
                  </tr>
                  <tr v-if="!data.rincian.length"><td colspan="4" class="muted">{{ t('det.noBreakdown') }}</td></tr>
                </tbody>
              </table>
              <p class="fn">{{ data.rincian_note.pembulatan ? t('det.fnRound') : t('det.fnNoRound') }}</p>
              <p class="fn">{{ data.rincian_note.gabung_oa ? t('det.fnOa') : t('det.fnNoOa') }}</p>

              <table class="rt rt2">
                <tbody>
                  <tr><td>1</td><td>{{ t('det.tierPrice') }}</td><td class="r">-</td><td class="r">{{ angka(tot.harga_tier) }}</td></tr>
                  <tr><td>2</td><td>{{ t('det.tier') }}</td><td class="r">{{ tot.tier || '-' }}</td><td class="r"></td></tr>
                  <tr><td>3</td><td>{{ t('det.totalRefund') }}</td><td class="r">{{ angka(tot.refund) }}</td>
                    <td class="r" :class="tot.refund_over ? 'bad' : 'good'">{{ angka(tot.refund_total) }}</td></tr>
                  <tr><td>4</td><td>{{ t('det.totalOther') }}</td><td class="r">{{ angka(tot.other_cost) }}</td>
                    <td class="r" :class="tot.other_over ? 'bad' : 'good'">{{ angka(tot.other_total) }}</td></tr>
                </tbody>
              </table>
            </section>
          </template>

          <!-- ===== CUSTOMER ===== -->
          <template v-else-if="tab === 'customer'">
            <section class="card">
              <div class="kv"><span>{{ t('det.cust.name') }}</span><span>{{ data.customer.nama_customer }}</span></div>
              <div class="kv"><span>{{ t('det.cust.invBranch') }}</span><span>{{ data.customer.cabang_invoice }}</span></div>
              <div class="kv"><span>{{ t('det.cust.marketing') }}</span><span>{{ data.customer.marketing }}</span></div>
              <div class="kv"><span>{{ t('det.cust.top') }}</span><span>{{ data.customer.top }}</span></div>
              <div class="kv"><span>{{ t('det.cust.pic') }}</span><span>{{ data.customer.pic }}</span></div>
              <div class="kv"><span>{{ t('det.cust.phone') }}</span><span>{{ data.customer.telepon }}</span></div>
              <div class="kv"><span>{{ t('det.cust.address') }}</span><span>{{ data.customer.alamat }}</span></div>
            </section>
          </template>

          <!-- ===== PENAWARAN ===== -->
          <template v-else-if="tab === 'penawaran'">
            <section class="card">
              <div class="kv"><span>{{ t('det.off.ref') }}</span><span>{{ p.nomor_surat }}</span></div>
              <div class="kv"><span>{{ t('det.off.type') }}</span><span>{{ p.tipe_penawaran || '-' }}</span></div>
              <div class="kv"><span>{{ t('det.off.product') }}</span><span>{{ p.merk_dagang }}</span></div>
              <div class="kv"><span>{{ t('det.off.volume') }}</span><span>{{ angka(p.volume_tawar) }} {{ t('common.liter') }}</span></div>
              <div class="kv"><span>{{ t('det.refund') }}</span><span>{{ Number(p.refund_tawar) ? angka(p.refund_tawar) : '-' }}</span></div>
              <div class="kv"><span>{{ t('det.otherCostF') }}</span><span>{{ Number(p.other_cost) ? angka(p.other_cost) : '-' }}</span></div>
              <div class="kv col2">
                <span>{{ t('det.off.ocNotes') }}</span>
                <span v-if="!data.other_costs.length">{{ t('det.off.noOc') }}</span>
                <span v-else class="oc">
                  <span v-for="(o, i) in data.other_costs" :key="i" class="oc-r"><em>{{ o.keterangan }}</em><b>{{ angka(o.nominal) }}</b></span>
                </span>
              </div>
              <div class="kv"><span>{{ t('det.off.method') }}</span><span>{{ t('det.off.daysBefore', { n: p.method_order }) }}</span></div>
              <div v-if="Number(p.perhitungan) === 1" class="kv"><span>{{ t('det.off.offerPrice') }}</span><span>{{ angka(p.harga_dasar, 2) }}</span></div>
              <div v-else class="kv"><span>{{ t('det.off.calc') }}</span><span>{{ t('det.off.formula') }}</span></div>
              <div class="kv"><span>{{ t('det.off.delivery') }}</span><span>{{ p.metode || '-' }}</span></div>
              <div class="kv"><span>{{ t('det.off.location') }}</span><span>{{ p.lok_kirim || '-' }}</span></div>
              <div class="kv"><span>{{ t('det.off.shrink') }}</span><span>{{ p.tol_susut || '-' }}</span></div>
              <div class="kv"><span>{{ t('det.off.gauge') }}</span><span>{{ p.alat_ukur || '-' }}</span></div>
              <div class="kv"><span>{{ t('det.off.created') }}</span><span>{{ tanggalJam(p.created_time) }}</span></div>
            </section>
          </template>

          <!-- ===== RIWAYAT ===== -->
          <template v-else>
            <div class="stack">
              <template v-if="data.history_approval.length">
                <Collapse v-for="(g, gi) in data.history_approval" :key="gi" :title="t('det.hist.n', { n: gi + 1 })" :count="g.approvals.length" :open="gi === data.history_approval.length - 1">
                  <div class="kv"><span>{{ t('det.off.volume') }}</span><span>{{ angka(g.harga.volume) }} {{ t('common.liter') }}</span></div>
                  <div class="kv"><span>{{ t('det.hist.basePrice') }}</span><span>{{ angka(g.harga.harga_dasar, 2) }}</span></div>
                  <div class="kv"><span>{{ t('det.tierPrice') }}</span><span>{{ angka(g.harga.harga_tier) }}</span></div>
                  <div class="kv"><span>{{ t('det.tier') }}</span><span>{{ g.harga.tier || '-' }}</span></div>
                  <div class="kv"><span>{{ t('det.refund') }}</span><span>{{ angka(g.harga.refund) }}</span></div>
                  <div class="kv"><span>{{ t('det.otherCostF') }}</span><span>{{ angka(g.harga.other_cost) }}</span></div>
                  <ol class="tl">
                    <li v-for="(a, ai) in g.approvals" :key="ai">
                      <span class="dot" :class="a.hasil === 'Disetujui' ? 'ok' : 'no'" />
                      <div>
                        <b>{{ ts(a.hasil) }}</b> {{ t('det.hist.by') }} {{ a.oleh }} <em>({{ a.peran }})</em>
                        <small>{{ tanggalJam(a.tanggal) }}</small>
                        <p v-if="a.catatan">{{ a.catatan }}</p>
                        <p v-if="a.keterangan_pengajuan" class="kp"><b>{{ t('det.hist.note') }}</b> {{ a.keterangan_pengajuan }}</p>
                      </div>
                    </li>
                  </ol>
                </Collapse>
              </template>
              <section v-else-if="data.timeline.length" class="card">
                <ol class="tl" style="margin-top:0">
                  <li v-for="(tl, i) in data.timeline" :key="i">
                    <span class="dot" :class="tl.hasil === 1 ? 'ok' : tl.hasil === 2 ? 'no' : ''" />
                    <div>
                      <b>{{ tl.hasil === 1 ? t('status.approved') : tl.hasil === 2 ? t('status.rejected') : t('status.waiting') }}</b> · {{ tl.tahap }}
                      <small>{{ tl.pic }} · {{ tanggalJam(tl.tanggal) }}</small>
                      <p v-if="tl.catatan">{{ tl.catatan }}</p>
                    </div>
                  </li>
                </ol>
              </section>
              <section v-else class="card muted center">{{ t('det.hist.none') }}</section>

              <Collapse v-if="data.other_penawaran.length" :title="t('det.hist.others')" :count="data.other_penawaran.length">
                <button v-for="o in data.other_penawaran" :key="o.id_penawaran" class="orow" @click="openOther(o)">
                  <span class="o-main">
                    <b>{{ o.nomor_surat }}</b>
                    <small>{{ tanggal(o.created_time) }} · {{ o.merk_dagang }}</small>
                    <small>{{ angka(o.harga_dasar, 2) }} /L · {{ angka(o.volume_tawar) }} L</small>
                    <span class="o-tags">
                      <span class="pill" :class="o.flag_approval == 1 ? 'pill-ok' : o.flag_approval == 2 ? 'pill-no' : 'pill-wait'">{{ ts(o.tahap) }}</span>
                      <span class="po" :class="o.status_po ? 'y' : 'n'">{{ t('det.hist.po', { v: o.status_po ? t('common.yes') : t('common.no') }) }}</span>
                      <span v-if="o.is_edit_harga == 1" class="pill pill-info">{{ t('det.hist.priceChange') }}</span>
                    </span>
                  </span>
                  <Icon name="chevron" :size="16" />
                </button>
              </Collapse>
            </div>
          </template>
        </div>
      </Transition>
    </div>

    <footer v-if="data?.can_act" class="actions">
      <button class="btn btn-danger" @click="openSheet('reject')"><Icon name="x" :size="18" /> {{ t('det.reject') }}</button>
      <button class="btn btn-primary" @click="openSheet('approve')"><Icon name="check" :size="18" /> {{ t('det.approve') }}</button>
    </footer>

    <div v-if="sheet" class="sheet-back" @click.self="!submitting && (sheet = null)">
      <div class="sheet" role="dialog" aria-modal="true" :aria-label="sheet === 'approve' ? t('det.q.approve') : t('det.q.reject')">
        <div class="grab" />
        <h2 class="sheet-t">{{ sheet === 'approve' ? t('det.q.approve') : t('det.q.reject') }}</h2>
        <p class="muted" style="margin:4px 0 16px">{{ p.nomor_surat }} · {{ data.customer.nama_customer }}</p>

        <div v-if="sheet === 'approve' && form?.can_forward" class="fwd">
          <label class="switch">
            <input v-model="teruskan" type="checkbox" :disabled="form.forward_locked" />
            <span class="trk" />
            <span>{{ t('det.forwardTo', { to: form.forward_to }) }}</span>
          </label>
          <small v-if="form.forward_locked" class="muted">{{ t('det.forwardLocked') }}</small>
          <small v-else class="muted">{{ teruskan ? t('det.forwardOn', { to: form.forward_to }) : t('det.forwardOff') }}</small>
        </div>

        <div class="field">
          <label for="cat">{{ sheet === 'reject' ? t('det.reasonReq') : t('det.noteOpt') }}</label>
          <textarea id="cat" v-model="catatan" class="plain" maxlength="2000" :placeholder="sheet === 'reject' ? t('det.reasonPh') : t('det.notePh')" />
        </div>
        <div v-if="needPassword" class="field">
          <label for="sp">{{ t('det.yourPwd') }}</label>
          <div class="input">
            <Icon name="lock" :size="20" />
            <input id="sp" v-model="pwd" type="password" autocomplete="current-password" :placeholder="t('det.confirmPwd')" />
          </div>
          <small class="muted">{{ t('det.faceNote') }}</small>
        </div>
        <p v-if="formError" class="err-text" role="alert" style="margin-bottom:12px">{{ formError }}</p>

        <div class="sheet-act">
          <button class="btn btn-ghost" :disabled="submitting" @click="sheet = null">{{ t('common.cancel') }}</button>
          <button class="btn" :class="sheet === 'approve' ? 'btn-primary' : 'btn-danger'" :disabled="submitting" @click="submit">
            {{ submitting ? t('det.processing') : sheet === 'approve' ? t('det.yesApprove') : t('det.yesReject') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.detail { min-height: 100dvh; }
.bar { position: sticky; top: 0; z-index: 10; display: flex; align-items: center; gap: 8px; padding: calc(var(--safe-top) + 8px) 12px 8px; background: var(--glass-bar); backdrop-filter: blur(12px); border-bottom: 1px solid var(--line); }
.bar-t { flex: 1; min-width: 0; text-align: center; display: grid; }
.bar-t b { font-size: 14.5px; font-weight: 700; }
.bar-t small { font-size: 12px; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.back { width: 44px; height: 44px; border-radius: 13px; border: 1px solid var(--line); background: var(--card); color: var(--ink); display: grid; place-items: center; }
.wrap { padding: 12px 16px calc(var(--safe-bottom) + 100px); }
.badges { display: flex; flex-wrap: wrap; gap: 6px; }
.pill-info { background: var(--info-bg); color: var(--info); }
.head h1 { font-size: 17px; font-weight: 700; letter-spacing: -0.02em; margin: 8px 0 2px; }
.head p { color: var(--muted); font-size: 13px; }
.hrow { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; margin: 8px 0 10px; }
.head .price { font-size: 24px; }
.vol { font-weight: 700; font-size: 14px; color: var(--ink-2); }
.pcheck { display: flex; align-items: flex-start; gap: 8px; margin: 0 0 10px; padding: 8px 10px; border-radius: 12px; font-size: 12.5px; line-height: 1.4; }
.pcheck svg { flex: none; margin-top: 1px; }
.pcheck.low { background: var(--red-bg); color: var(--red); }
.pcheck.okp { background: var(--green-bg); color: var(--green); }
.sel { font-style: normal; font-size: 12px; font-weight: 700; margin-left: 8px; }
.chips2 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
.chips2 span { display: grid; gap: 1px; padding: 8px 10px; border-radius: 12px; background: var(--bg); font-weight: 700; font-size: 14px; }
.chips2 em { font-style: normal; font-weight: 600; font-size: 12px; color: var(--muted); }
.chips2 .bad { background: var(--red-bg); color: var(--red); }

/* tab */
.tabs { position: sticky; top: calc(var(--safe-top) + 61px); z-index: 9; display: grid; grid-template-columns: repeat(4, 1fr); margin: 12px -4px 12px; padding: 4px; background: var(--tabs-bg); backdrop-filter: blur(10px); border-radius: 14px; }
.tab-ind { position: absolute; top: 4px; bottom: 4px; left: 4px; width: calc((100% - 8px) / 4); border-radius: 11px; background: var(--surface); box-shadow: 0 2px 8px rgba(15, 31, 61, 0.14); transform: translateX(calc(var(--ti, 0) * 100%)); transition: transform 0.35s cubic-bezier(0.3, 1.2, 0.4, 1); }
.tab { position: relative; z-index: 1; min-height: 44px; border: 0; background: none; font-weight: 600; font-size: 13px; color: var(--muted); display: flex; align-items: center; justify-content: center; gap: 5px; transition: color 0.2s; }
.tab.on { color: var(--accent); }
.tn { min-width: 17px; height: 17px; padding: 0 5px; border-radius: 9px; background: var(--blue-600); color: #fff; font-size: 11px; display: grid; place-items: center; }
.tabpane { display: grid; gap: 12px; }
.tabfade-enter-active, .tabfade-leave-active { transition: opacity 0.18s ease, transform 0.22s ease; }
.tabfade-enter-from { opacity: 0; transform: translateY(8px); }
.tabfade-leave-to { opacity: 0; }
.ct { font-size: 14px; font-weight: 700; margin-bottom: 6px; }
.stnote { margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--line); }
.stnote small { color: var(--muted); font-size: 12px; }
.stnote p { margin-top: 4px; font-size: 13px; color: var(--red); overflow-wrap: anywhere; }
.muted { color: var(--muted); font-size: 12.5px; }
.center { text-align: center; }
.alert { margin-top: 10px; }
.head + .note, .alert + .note, .note.card { margin-top: 10px; }
.note { white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.5; font-size: 13.5px; }

/* tabel rincian */
.rt { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
.rt td:nth-child(3), .rt th:nth-child(3) { width: 68px; }
.rt td:nth-child(4), .rt th:nth-child(4) { width: 96px; }
.rt th { text-align: left; font-size: 12px; letter-spacing: 0.04em; text-transform: uppercase; color: var(--muted); font-weight: 600; padding: 4px 0 8px; border-bottom: 1px solid var(--line); }
.rt td { padding: 8px 0; border-bottom: 1px solid var(--line); }
.rt td:first-child, .rt th:first-child { width: 26px; color: var(--muted); }
.r { text-align: right !important; font-variant-numeric: tabular-nums; }
.rt td.r { font-weight: 600; }
.rt2 { margin-top: 12px; }
.rt2 tr:last-child td { border-bottom: 0; }
.good { color: var(--green); }
.bad { color: var(--red); }
.fn { font-size: 12px; font-style: italic; color: var(--muted); margin-top: 6px; }

.kv.col2 { grid-template-columns: 1fr; }
.kv.col2 span:last-child { text-align: left; font-weight: 500; }
.oc { display: grid; gap: 4px; }
.oc-r { display: flex; justify-content: space-between; gap: 10px; font-weight: 500; }
.oc-r em { font-style: normal; color: var(--ink-2); }

.tl { list-style: none; margin: 12px 0 0; padding: 0; display: grid; gap: 14px; }
.tl li { display: flex; gap: 12px; font-size: 13px; line-height: 1.4; }
.tl .dot { flex: none; width: 12px; height: 12px; border-radius: 50%; margin-top: 4px; background: var(--dot); }
.tl .dot.ok { background: #20c07c; }
.tl .dot.no { background: var(--red); }
.tl em { font-style: normal; color: var(--muted); }
.tl small { display: block; color: var(--muted); font-size: 12px; margin-top: 2px; }
.tl p { margin-top: 4px; font-size: 13px; color: var(--ink-2); overflow-wrap: anywhere; }
.tl .kp { color: var(--muted); font-size: 12.5px; }

.orow { display: flex; align-items: center; gap: 10px; width: 100%; text-align: left; padding: 12px 0; background: none; border: 0; border-top: 1px solid var(--line); color: var(--muted); }
.orow:first-child { border-top: 0; }
.o-main { flex: 1; min-width: 0; display: grid; gap: 2px; }
.o-main b { color: var(--ink); font-size: 13.5px; font-weight: 600; overflow-wrap: anywhere; }
.o-main small { color: var(--muted); font-size: 12px; }
.o-tags { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 4px; }
.po { font-size: 12px; font-weight: 700; }
.po.y { color: var(--green); }
.po.n { color: var(--red); }

.actions { position: fixed; left: 50%; transform: translateX(-50%); bottom: 0; width: min(480px, 100%); display: grid; grid-template-columns: 1fr 1.4fr; gap: 10px; padding: 10px 16px calc(var(--safe-bottom) + 10px); background: var(--glass); backdrop-filter: blur(14px); border-top: 1px solid var(--line); z-index: 15; }
.sheet-t { font-size: 19px; font-weight: 700; letter-spacing: -0.02em; }
.sheet-act { display: grid; grid-template-columns: 1fr 1.5fr; gap: 10px; }
.fwd { display: grid; gap: 4px; padding: 12px 14px; margin-bottom: 14px; border-radius: 14px; background: var(--blue-50); }
.switch { display: flex; align-items: center; gap: 10px; font-weight: 600; font-size: 14px; min-height: 40px; }
.switch input { position: absolute; opacity: 0; width: 1px; height: 1px; }
.trk { position: relative; width: 50px; height: 30px; border-radius: 15px; background: #c3cfe2; flex: none; transition: background 0.2s; }
.trk::after { content: ''; position: absolute; top: 3px; left: 3px; width: 24px; height: 24px; border-radius: 50%; background: #fff; box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25); transition: transform 0.2s; }
.switch input:checked + .trk { background: var(--blue-600); }
.switch input:checked + .trk::after { transform: translateX(20px); }
.switch input:disabled + .trk { opacity: 0.6; }
.switch input:focus-visible + .trk { outline: 3px solid rgba(43, 140, 255, 0.5); outline-offset: 2px; }
.pch h2 { font-size: 15px; font-weight: 800; margin: 0 0 2px; }
.pch .pcm { margin: 0 0 10px; font-size: 12.5px; color: var(--muted); }
.cmp { display: grid; gap: 0; border: 1px solid var(--line); border-radius: 12px; overflow: hidden; margin-bottom: 12px; }
.ch, .cr { display: grid; grid-template-columns: 1.1fr 1fr 1fr; gap: 8px; padding: 8px 10px; font-size: 13px; align-items: center; }
.ch { background: var(--blue-50); font-size: 12px; }
.cr { border-top: 1px solid var(--line); }
.cr span:nth-child(2), .cr span:nth-child(3), .ch b { text-align: right; }
.cr .nw, .ch .nw { font-weight: 800; color: var(--accent); }
.cr.diff span:nth-child(3) { background: color-mix(in srgb, var(--accent) 10%, transparent); border-radius: 6px; padding: 1px 4px; }
.pcol { margin-top: 6px; }
.pcol h3 { font-size: 12.5px; font-weight: 800; margin: 8px 0 2px; color: var(--ink-2); }
.pch .kv.sub { padding-left: 8px; font-size: 12.5px; }
.note2 { margin: 10px 0 0; padding: 8px 10px; border-radius: 10px; background: var(--blue-50); font-size: 13px; }
.rq { padding: 8px 0; border-top: 1px dashed var(--line); }
.rq:first-child { border-top: 0; }
.rqh { display: flex; align-items: center; gap: 8px; justify-content: space-between; font-size: 13.5px; }
.rqp { font-size: 12.5px; margin-top: 4px; color: var(--ink-2); }
.ra { display: flex; gap: 8px; margin-top: 6px; font-size: 13px; }
.ra p { margin: 2px 0 0; color: var(--muted); }
</style>
