<script setup>
import { t } from '../i18n'
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import StateBox from '../components/StateBox.vue'
import * as syop from '../api/syop'
import { useAuth } from '../stores/auth'
import { useInbox } from '../stores/inbox'
import { errorCode, errorMessage } from '../api/client'
import { showToast } from '../composables/useToast'
import { openFile } from '../utils/openFile'

const route = useRoute()
const router = useRouter()
const id = Number(route.params.id)
const d = ref(null)
const loading = ref(true)
const error = ref('')
const tab = ref('verif')
const auth = useAuth()
const inbox = useInbox()
const result = ref('')
const summary = ref('')
const pwd = ref('')
const busy = ref(false)
const formError = ref('')
const needPassword = computed(() => auth.method === 'face')

async function submit() {
  formError.value = ''
  if (!summary.value.trim()) return (formError.value = t('vc.errSummary'))
  if (!result.value) return (formError.value = t('vc.errResult'))
  if (needPassword.value && !pwd.value) return (formError.value = t('det.errPwd'))
  if (!window.confirm(result.value === '1' ? t('lcr.q.approve') : t('lcr.q.reject'))) return
  busy.value = true
  try {
    const p = { result: Number(result.value), summary: summary.value.trim() }
    if (needPassword.value) p.password = pwd.value
    await syop.decideLcr(id, p)
    showToast(t('vc.saved'), 'ok')
    inbox.refresh()
    router.replace({ name: 'lcr' })
  } catch (e) {
    if (errorCode(e) === 'STEP_UP_REQUIRED') auth.method = 'face'
    formError.value = errorMessage(e)
  } finally { busy.value = false }
}

// Tabel pembongkaran: kolom per jenis (sama dengan __get_data_lcr.php).
const BLOCKS = [
  ['tangki', 'Tangki', { tipe: 'Tipe', kapasitas: 'Kapasitas', jumlah: 'Jumlah', produk: 'Produk', inlet: 'Inlet Pipa', ukuran: 'Ukuran' }],
  ['pendukung', 'Pendukung', { pompa: 'Pompa', aliran: 'Laju Alir', selang: 'P. Selang', valve: 'Vapour Valve', ground: 'Grounding', sinyal: 'Sinyal HP' }],
  ['quantity_tangki', 'Quantity', { alat: 'Alat Ukur', merk: 'Merk', tera: 'Tera', masa: 'Masa Berlaku', flowmeter: 'Flowmeter Tiap Pengiriman' }],
  ['quality_tangki', 'Quality', { spec: 'Min. Spec', lab: 'Uji Lab', coq: 'COQ Tiap Pengiriman' }],
  ['kapal', 'Kapal', { tipe: 'Tipe', kapasitas: 'Kapasitas', jumlah: 'Jumlah', inlet: 'Inlet Pipa', ukuran: 'Ukuran', metode: 'Metode' }],
  ['jetty', 'Jetty', { loa: 'LOA', pbl: 'PBL', lws: 'LWS', sandar: 'Sandar', izin: 'Izin', syarat: 'Syarat' }],
  ['quantity_kapal', 'Quantity (kapal)', { alat: 'Alat Ukur', merk: 'Merk', tera: 'Tera', masa: 'Masa Berlaku', flowmeter: 'Flowmeter Tiap Pengiriman' }],
  ['quality_kapal', 'Quality (kapal)', { spec: 'Min. Spec', lab: 'Uji Lab', coq: 'COQ Tiap Pengiriman' }],
]
const rowsOf = (key, cols) => (d.value.bongkar[key] || []).filter((r) => r && typeof r === 'object' && Object.keys(cols).some((c) => r[c]))

async function load() {
  loading.value = true
  error.value = ''
  try { d.value = (await syop.getLcr(id)).data } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'lcr' }))
onMounted(load)
</script>

<template>
  <div>
    <header class="bar">
      <button class="back" :aria-label="t('common.back')" @click="back"><Icon name="back" :size="20" /></button>
      <div class="bar-t"><b>{{ d ? d.kode : t('lcr.detail') }}</b></div>
      <span class="back" style="visibility:hidden" />
    </header>
    <div class="wrap">
      <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load" />
      <div v-else-if="loading" class="skel" style="height:300px" />
      <template v-else>
        <div class="card box">
          <h2 class="nm">{{ d.umum.perusahaan }}</h2>
          <span class="pill" :class="d.bisa_putus ? 'pill-wait' : 'pill-ok'">{{ d.status }}</span>
        </div>
        <div class="chips" role="tablist">
          <button class="chip" :class="{ on: tab === 'verif' }" @click="tab = 'verif'">{{ t('lcr.tab.verif') }}</button>
          <button class="chip" :class="{ on: tab === 'data' }" @click="tab = 'data'">{{ t('lcr.tab.data') }}</button>
        </div>

        <template v-if="tab === 'verif'">
          <div v-for="s in [['logistik', 'vc.s.logistik'], ['bm', 'vc.s.bm']]" :key="s[0]" class="card box">
            <h3>{{ t(s[1]) }}</h3>
            <template v-if="d[s[0]].hasil || d[s[0]].ringkasan">
              <p class="res">{{ d[s[0]].hasil || '-' }}</p>
              <p class="sum" v-html="d[s[0]].ringkasan || '-'" />
              <small v-if="d[s[0]].oleh" class="muted">{{ d[s[0]].oleh }} · {{ d[s[0]].tanggal }}</small>
            </template>
            <p v-else class="muted">{{ t('vc.notYet') }}</p>
          </div>
          <form v-if="d.bisa_putus" class="card box" novalidate @submit.prevent="submit">
            <h3>{{ t('vc.decision') }}</h3>
            <div class="field"><label for="ls">{{ t('lcr.noteBm') }} *</label><textarea id="ls" v-model="summary" class="sel" rows="4" maxlength="5000" /></div>
            <div class="field">
              <label class="rad"><input v-model="result" type="radio" value="1" /> {{ t('pm.yes') }}</label>
              <label class="rad"><input v-model="result" type="radio" value="2" /> {{ t('pm.no') }}</label>
            </div>
            <div v-if="needPassword" class="field">
              <label for="lpw">{{ t('det.yourPwd') }}</label>
              <div class="input"><Icon name="lock" :size="20" /><input id="lpw" v-model="pwd" type="password" autocomplete="current-password" :placeholder="t('det.confirmPwd')" /></div>
              <small class="muted">{{ t('det.faceNote') }}</small>
            </div>
            <p v-if="formError" class="err-text" role="alert">{{ formError }}</p>
            <button class="btn btn-primary btn-block" :disabled="busy">{{ busy ? t('det.processing') : t('rsv.save') }}</button>
          </form>
        </template>

        <template v-else>
          <div class="card box">
            <h3>1. General Information</h3>
            <dl>
              <dt>{{ t('lcr.company') }}</dt><dd>{{ d.umum.perusahaan }}</dd>
              <dt>{{ t('vc.code') }}</dt><dd>{{ d.umum.kode_pelanggan || '-' }}</dd>
              <dt>Marketing</dt><dd>{{ d.umum.marketing }}</dd>
              <dt>{{ t('lcr.site') }}</dt><dd>{{ d.umum.alamat }}</dd>
              <dt>{{ t('mst.f.telp') }}</dt><dd>{{ d.umum.telp || '-' }}</dd>
              <dt>Fax</dt><dd>{{ d.umum.fax || '-' }}</dd>
              <dt>Website</dt><dd>{{ d.umum.website || '-' }}</dd>
              <dt>{{ t('lcr.oaRegion') }}</dt><dd>{{ d.umum.wilayah_oa || '-' }}</dd>
              <dt>{{ t('lcr.surveyDate') }}</dt><dd>{{ d.umum.tgl_survey || '-' }}</dd>
              <dt>{{ t('lcr.bizType') }}</dt><dd>{{ d.umum.jenis_usaha || '-' }}</dd>
              <dt>{{ t('lcr.tolerance') }}</dt><dd>{{ d.umum.toleransi || '-' }}</dd>
              <dt>Surveyor</dt><dd>{{ d.umum.surveyor.join(', ') || '-' }}</dd>
              <dt>{{ t('lcr.competitor') }}</dt><dd>{{ d.umum.kompetitor.join(', ') || '-' }}</dd>
              <dt>{{ t('lcr.products') }}</dt><dd>{{ d.umum.hasil_produksi.join(', ') || '-' }}</dd>
              <dt>{{ t('lcr.monthly') }}</dt><dd>{{ d.umum.produksi_bulanan.join(', ') || '-' }}</dd>
              <dt>{{ t('lcr.hours') }}</dt><dd>{{ [d.umum.jam.senin_jumat && 'Sen-Jum ' + d.umum.jam.senin_jumat, d.umum.jam.sabtu && 'Sab ' + d.umum.jam.sabtu, d.umum.jam.minggu && 'Min ' + d.umum.jam.minggu].filter(Boolean).join(' · ') || '-' }}</dd>
            </dl>
            <h3 v-if="d.umum.penanggung_jawab.length">{{ t('lcr.pic') }}</h3>
            <div v-for="(p, i) in d.umum.penanggung_jawab" :key="i" class="pic"><b>{{ p.nama }}</b><span>{{ p.posisi }}</span><small>{{ p.telepon }}</small></div>
          </div>

          <div class="card box">
            <h3>2. Location Information</h3>
            <dl>
              <dt>Latitude</dt><dd>{{ d.lokasi.latitude || '-' }}</dd>
              <dt>Longitude</dt><dd>{{ d.lokasi.longitude || '-' }}</dd>
              <dt>{{ t('lcr.distance') }}</dt><dd>{{ d.lokasi.jarak_depot || '-' }}</dd>
              <dt>{{ t('lcr.maxTruck') }}</dt><dd>{{ d.lokasi.max_truk || '-' }}</dd>
              <dt>{{ t('lcr.coordFee') }}</dt><dd>{{ d.lokasi.biaya_koordinasi || '-' }}</dd>
              <dt>{{ t('lcr.minVol') }}</dt><dd>{{ d.lokasi.min_vol_kirim || '-' }}</dd>
              <dt>Link Google Maps</dt><dd><a v-if="d.lokasi.link_maps" :href="d.lokasi.link_maps" target="_blank" rel="noopener">{{ d.lokasi.link_maps }}</a><template v-else>-</template></dd>
            </dl>
            <p v-if="d.lokasi.latitude && d.lokasi.longitude"><a class="btn btn-ghost btn-sm" :href="`https://www.google.com/maps?q=${d.lokasi.latitude},${d.lokasi.longitude}`" target="_blank" rel="noopener">{{ t('lcr.openMap') }}</a></p>
            <div class="qa"><b>{{ t('lcr.route') }}</b><p class="sum" v-html="d.lokasi.rute || '-'" /></div>
            <div class="qa"><b>{{ t('rf.note') }}</b><p class="sum" v-html="d.lokasi.catatan || '-'" /></div>
            <div class="qa"><b>{{ t('lcr.unloadExplain') }}</b><p class="sum" v-html="d.lokasi.penjelasan_bongkar || '-'" /></div>
            <p class="files">
              <button v-if="d.lokasi.ada_peta" type="button" class="btn btn-ghost btn-sm" @click="openFile(`/lcr/${id}/gambar/peta`, 'peta')">{{ t('lcr.map') }}</button>
              <button v-if="d.lokasi.ada_layout" type="button" class="btn btn-ghost btn-sm" @click="openFile(`/lcr/${id}/gambar/layout`, 'layout')">{{ t('lcr.layout') }}</button>
            </p>
          </div>

          <div class="card box">
            <h3>3. Unloading Information</h3>
            <template v-for="b in BLOCKS" :key="b[0]">
              <template v-if="rowsOf(b[0], b[2]).length">
                <h4>{{ b[1] }}</h4>
                <div v-for="(r, i) in rowsOf(b[0], b[2])" :key="i" class="blk">
                  <template v-for="(label, c) in b[2]" :key="c"><span v-if="r[c]"><em>{{ label }}</em> {{ r[c] }}</span></template>
                </div>
              </template>
            </template>
            <div v-if="d.bongkar.catatan_tangki" class="qa"><b>{{ t('rf.note') }} (tangki)</b><p class="sum" v-html="d.bongkar.catatan_tangki" /></div>
            <div v-if="d.bongkar.catatan_kapal" class="qa"><b>{{ t('rf.note') }} (kapal)</b><p class="sum" v-html="d.bongkar.catatan_kapal" /></div>
          </div>

          <div v-if="d.gambar.length" class="card box">
            <h3>4. {{ t('lcr.pictures') }}</h3>
            <div v-for="g in d.gambar" :key="g.kunci" class="qa">
              <b>{{ g.label }}</b>
              <p v-for="f in g.items" :key="f.idx" class="row"><span class="grow">{{ f.deskripsi || `${t('lcr.picture')} ${f.idx + 1}` }}</span>
                <button type="button" class="btn btn-ghost btn-sm lnk" @click="openFile(`/lcr/${id}/gambar/${g.kunci}/${f.idx}`, 'gambar')">{{ t('vc.view') }}</button></p>
            </div>
          </div>
        </template>
      </template>
    </div>
  </div>
</template>

<style scoped>
.bar { position: sticky; top: 0; z-index: 10; display: flex; align-items: center; gap: 8px; padding: calc(var(--safe-top) + 8px) 12px 8px; background: var(--glass-bar); backdrop-filter: blur(12px); border-bottom: 1px solid var(--line); }
.bar-t { flex: 1; text-align: center; font-size: 14.5px; }
.back { width: 44px; height: 44px; border-radius: 13px; border: 1px solid var(--line); background: var(--card); color: var(--ink); display: grid; place-items: center; }
.wrap { padding: 12px 16px calc(var(--safe-bottom) + 32px); display: grid; gap: 12px; }
.box { padding: 14px 16px; display: grid; gap: 6px; min-width: 0; }
.nm { margin: 0; font-size: 18px; overflow-wrap: anywhere; }
h3 { margin: 6px 0 2px; font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
h4 { margin: 10px 0 2px; font-size: 14px; }
dl { display: grid; grid-template-columns: minmax(0, 38%) minmax(0, 1fr); gap: 6px 10px; margin: 0; font-size: 14px; }
dt { color: var(--muted); } dd { margin: 0; font-weight: 600; overflow-wrap: anywhere; }
.res { margin: 0; font-weight: 700; } .sum { margin: 0; font-size: 14px; overflow-wrap: anywhere; }
.qa { display: grid; gap: 2px; padding: 6px 0; border-top: 1px dashed var(--line); font-size: 13px; }
.qa b { font-weight: 600; color: var(--muted); }
.pic { display: grid; gap: 2px; padding: 6px 0; border-top: 1px dashed var(--line); font-size: 14px; }
.blk { display: flex; flex-wrap: wrap; gap: 4px 12px; padding: 6px 0; border-top: 1px dashed var(--line); font-size: 13px; }
.blk em { font-style: normal; color: var(--muted); margin-right: 4px; }
.row { margin: 0; display: flex; align-items: center; gap: 8px; font-size: 14px; }
.grow { flex: 1; min-width: 0; overflow-wrap: anywhere; }
.files { margin: 4px 0 0; display: flex; gap: 8px; flex-wrap: wrap; }
.rad { display: flex; align-items: center; gap: 10px; min-height: 44px; font-weight: 600; }
.sel { width: 100%; padding: 10px 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; font-family: inherit; box-sizing: border-box; }
.lnk { min-height: 32px; padding: 0 12px; font-size: 12px; }
</style>
