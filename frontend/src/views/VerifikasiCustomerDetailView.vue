<script setup>
import { t } from '../i18n'
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import StateBox from '../components/StateBox.vue'
import { useAuth } from '../stores/auth'
import { useInbox } from '../stores/inbox'
import * as syop from '../api/syop'
import { errorCode, errorMessage } from '../api/client'
import { angka } from '../utils/format'
import { showToast } from '../composables/useToast'
import { openFile } from '../utils/openFile'

const route = useRoute()
const router = useRouter()
const auth = useAuth()
const inbox = useInbox()
const id = Number(route.params.id)
const d = ref(null)
const loading = ref(true)
const error = ref('')
const tab = ref('eval')
const result = ref('')
const summary = ref('')
const pwd = ref('')
const busy = ref(false)
const formError = ref('')
const c = computed(() => d.value.customer)
const r = computed(() => d.value.review)
const needPassword = computed(() => auth.method === 'face')

async function load() {
  loading.value = true
  error.value = ''
  try { d.value = (await syop.getVerifikasiCustomer(id)).data } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'verifikasi-customer' }))

async function submit() {
  formError.value = ''
  if (!result.value) return (formError.value = t('vc.errResult'))
  if (!summary.value.trim()) return (formError.value = t('vc.errSummary'))
  if (needPassword.value && !pwd.value) return (formError.value = t('det.errPwd'))
  if (!window.confirm(result.value === '1' ? t('vc.q.approve') : t('vc.q.reject'))) return
  busy.value = true
  try {
    const p = { result: Number(result.value), summary: summary.value.trim() }
    if (needPassword.value) p.password = pwd.value
    await syop.decideVerifikasiCustomer(id, p)
    showToast(t('vc.saved'), 'ok')
    inbox.refresh()
    router.replace({ name: 'verifikasi-customer' })
  } catch (e) {
    if (errorCode(e) === 'STEP_UP_REQUIRED') auth.method = 'face'
    formError.value = errorMessage(e)
  } finally { busy.value = false }
}
const rp = (n) => 'Rp ' + angka(n || 0)
onMounted(load)
</script>

<template>
  <div>
    <header class="bar">
      <button class="back" :aria-label="t('common.back')" @click="back"><Icon name="back" :size="20" /></button>
      <div class="bar-t"><b>{{ d ? d.kode : t('vc.detail') }}</b></div>
      <span class="back" style="visibility:hidden" />
    </header>
    <div class="wrap">
      <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load" />
      <div v-else-if="loading" class="skel" style="height:300px" />
      <template v-else>
        <div class="card box">
          <h2 class="nm">{{ d.evaluasi.customer }}</h2>
          <span class="pill" :class="d.bisa_putus ? 'pill-wait' : 'pill-ok'">{{ d.status }}</span>
        </div>

        <div class="chips" role="tablist">
          <button class="chip" :class="{ on: tab === 'eval' }" @click="tab = 'eval'">{{ t('vc.tab.eval') }}</button>
          <button class="chip" :class="{ on: tab === 'cust' }" @click="tab = 'cust'">{{ t('vc.tab.cust') }}</button>
          <button class="chip" :class="{ on: tab === 'review' }" @click="tab = 'review'">{{ t('vc.tab.review') }}</button>
        </div>

        <template v-if="tab === 'eval'">
          <div class="card box">
            <h3>{{ t('vc.apply') }}</h3>
            <dl>
              <dt>{{ t('vc.bizType') }}</dt><dd>{{ d.evaluasi.tipe_bisnis }}</dd>
              <dt>TOP</dt><dd>{{ d.evaluasi.top_diajukan }}</dd>
              <dt>{{ t('vc.volume') }}</dt><dd>{{ angka(d.evaluasi.volume_potensial || 0) }} L</dd>
              <dt>{{ t('vc.limitReq') }}</dt><dd>{{ rp(d.evaluasi.kredit_diajukan) }}</dd>
            </dl>
            <h3>{{ t('vc.approved') }}</h3>
            <dl>
              <dt>{{ t('vc.limitOk') }}</dt><dd>{{ rp(d.evaluasi.kredit_disetujui) }}</dd>
              <dt>{{ t('vc.payType') }}</dt><dd>{{ d.evaluasi.tipe_pembayaran || '-' }}</dd>
              <template v-if="d.evaluasi.tipe_pembayaran === 'CREDIT'"><dt>TOP</dt><dd>{{ d.evaluasi.top_hari }} {{ t('vc.days') }} · {{ d.evaluasi.jenis_net || '-' }}</dd></template>
            </dl>
          </div>
          <div v-if="d.evaluasi.kyc.length" class="card box">
            <h3>{{ t('vc.kyc') }}</h3>
            <p v-for="k in d.evaluasi.kyc" :key="k.idx" class="row"><Icon name="doc" :size="16" /> <span class="grow">{{ k.nama }}<small v-if="k.file"> · {{ k.file }}</small></span>
              <button v-if="k.file" type="button" class="btn btn-ghost btn-sm" @click="openFile(`/verifikasi-customer/${id}/berkas/kyc/${k.idx}`, k.file)">{{ t('vc.view') }}</button></p>
          </div>
          <div v-for="s in [['finance', 'vc.s.finance'], ['logistik', 'vc.s.logistik'], ['bm', 'vc.s.bm'], ['om', 'vc.s.om']]" :key="s[0]" class="card box">
            <h3>{{ t(s[1]) }}</h3>
            <template v-if="d.evaluasi[s[0]].hasil || d.evaluasi[s[0]].ringkasan">
              <p class="res">{{ d.evaluasi[s[0]].hasil || '-' }}</p>
              <p class="sum" v-html="d.evaluasi[s[0]].ringkasan || '-'" />
              <small class="muted" v-if="d.evaluasi[s[0]].oleh">{{ d.evaluasi[s[0]].oleh }} · {{ d.evaluasi[s[0]].tanggal }}</small>
            </template>
            <p v-else class="muted">{{ t('vc.notYet') }}</p>
          </div>

          <form v-if="d.bisa_putus" class="card box" novalidate @submit.prevent="submit">
            <h3>{{ t('vc.decision') }}</h3>
            <div class="field"><label for="vs">{{ t('vc.summary') }} *</label><textarea id="vs" v-model="summary" class="sel" rows="4" maxlength="5000" /></div>
            <div class="field">
              <label>{{ t('vc.result') }} *</label>
              <label class="rad"><input v-model="result" type="radio" value="1" /> {{ t('vc.yes') }}</label>
              <label class="rad"><input v-model="result" type="radio" value="2" /> {{ t('vc.no') }}</label>
            </div>
            <div v-if="needPassword" class="field">
              <label for="vpw">{{ t('det.yourPwd') }}</label>
              <div class="input"><Icon name="lock" :size="20" /><input id="vpw" v-model="pwd" type="password" autocomplete="current-password" :placeholder="t('det.confirmPwd')" /></div>
              <small class="muted">{{ t('det.faceNote') }}</small>
            </div>
            <p v-if="formError" class="err-text" role="alert">{{ formError }}</p>
            <button class="btn btn-primary btn-block" :disabled="busy">{{ busy ? t('det.processing') : t('rsv.save') }}</button>
          </form>
        </template>

        <template v-else-if="tab === 'cust'">
          <div class="card box">
            <h3>1. Corporate Details</h3>
            <dl>
              <dt>{{ t('vc.code') }}</dt><dd>{{ c.kode_pelanggan || '-' }}</dd>
              <dt>{{ t('vc.branch') }}</dt><dd>{{ c.wilayah || '-' }}</dd>
              <dt>Marketing</dt><dd>{{ c.marketing || '-' }}</dd>
              <dt>1.1 {{ t('vc.coName') }}</dt><dd>{{ c.perusahaan.nama }}</dd>
              <dt>1.2 Holding</dt><dd>{{ c.perusahaan.induk || '-' }}</dd>
              <dt>{{ t('vc.bizType') }}</dt><dd>{{ c.perusahaan.tipe_bisnis }}</dd>
              <dt>{{ t('vc.ownership') }}</dt><dd>{{ c.perusahaan.kepemilikan }}</dd>
            </dl>
          </div>
          <div v-for="a in [['1.3 ' + t('vc.addrNpwp'), c.alamat_npwp], ['1.4 ' + t('vc.addrHo'), c.alamat_pusat]]" :key="a[0]" class="card box">
            <h3>{{ a[0] }}</h3>
            <dl>
              <dt>{{ t('mst.f.alamat') }}</dt><dd>{{ a[1].alamat || '-' }}</dd>
              <dt>Kelurahan</dt><dd>{{ a[1].kelurahan || '-' }}</dd>
              <dt>Kecamatan</dt><dd>{{ a[1].kecamatan || '-' }}</dd>
              <dt>{{ t('vc.city') }}</dt><dd>{{ a[1].kota || '-' }}</dd>
              <dt>{{ t('vc.province') }}</dt><dd>{{ a[1].provinsi || '-' }}</dd>
              <dt>{{ t('vc.country') }}</dt><dd>{{ a[1].negara }}</dd>
              <dt>{{ t('vc.postcode') }}</dt><dd>{{ a[1].kode_pos || '-' }}</dd>
              <dt>{{ t('mst.f.telp') }}</dt><dd>{{ a[1].telp || '-' }}</dd>
              <dt>Fax</dt><dd>{{ a[1].fax || '-' }}</dd>
            </dl>
          </div>
          <div class="card box">
            <h3>1.5 {{ t('vc.addrShip') }}</h3>
            <p v-for="(x, i) in c.alamat_kirim" :key="i" class="sum">{{ i + 1 }}. {{ x }}</p>
            <p v-if="!c.alamat_kirim.length" class="muted">-</p>
            <h3>1.6 {{ t('vc.addrInv') }}</h3>
            <p class="sum">{{ c.alamat_invoice || '-' }}</p>
            <dl>
              <dt>PIC</dt><dd>{{ c.pic_invoice.nama || '-' }}</dd>
              <dt>{{ t('vc.mobile') }}</dt><dd>{{ c.pic_invoice.hp || '-' }}</dd>
              <dt>{{ t('vc.division') }}</dt><dd>{{ c.pic_invoice.jabatan || '-' }}</dd>
              <dt>Email</dt><dd>{{ c.pic_invoice.email || '-' }}</dd>
            </dl>
          </div>
          <div class="card box">
            <h3>{{ t('vc.docs') }}</h3>
            <dl>
              <template v-for="x in c.dokumen" :key="x.kunci"><dt>{{ x.nama }}</dt><dd>{{ x.nomor || '-' }}
                <button v-if="x.file" type="button" class="btn btn-ghost btn-sm lnk" @click="openFile(`/verifikasi-customer/${id}/berkas/legal/${x.kunci}`, x.file)">{{ t('vc.view') }}</button></dd></template>
            </dl>
          </div>
          <div class="card box">
            <h3>2. Person In Charge</h3>
            <div v-for="(p, k) in c.pic" :key="k" class="pic">
              <b>{{ k }}</b><span>{{ p.nama || '-' }}<template v-if="p.jabatan"> ({{ p.jabatan }})</template></span>
              <small>{{ [p.hp, p.email].filter(Boolean).join(' · ') }}</small>
            </div>
          </div>
          <div class="card box">
            <h3>3. {{ t('vc.billing') }}</h3>
            <dl>
              <dt>3.1 {{ t('vc.priceMethod') }}</dt><dd>{{ c.pembayaran.metode_harga || '-' }}</dd>
              <dt>3.2 {{ t('vc.payMethod') }}</dt><dd>{{ c.pembayaran.cara_bayar || '-' }}</dd>
              <dt>3.3 TOP</dt><dd>{{ c.pembayaran.top || '-' }}</dd>
              <dt>Bank</dt><dd>{{ c.pembayaran.bank || '-' }}</dd>
              <dt>{{ t('vc.currency') }}</dt><dd>{{ c.pembayaran.mata_uang || '-' }}</dd>
              <dt>{{ t('vc.bankAddr') }}</dt><dd>{{ c.pembayaran.alamat_bank || '-' }}</dd>
              <dt>{{ t('vc.account') }}</dt><dd>{{ c.pembayaran.rekening || '-' }}</dd>
              <dt>{{ t('vc.creditFac') }}</dt><dd>{{ c.pembayaran.fasilitas_kredit }}</dd>
              <dt>{{ t('vc.creditor') }}</dt><dd>{{ c.pembayaran.kreditor || '-' }}</dd>
            </dl>
          </div>
          <div class="card box">
            <h3>4. {{ t('vc.supply') }}</h3>
            <dl>
              <dt>{{ t('vc.scheme') }}</dt><dd>{{ c.pasokan.skema }}</dd>
              <dt>{{ t('vc.product') }}</dt><dd>{{ c.pasokan.produk || '-' }}</dd>
              <dt>{{ t('vc.volMonth') }}</dt><dd>{{ c.pasokan.volume_bulanan || '-' }}</dd>
              <dt>{{ t('vc.hours') }}</dt><dd>{{ c.pasokan.jam }}</dd>
              <dt>{{ t('vc.handover') }}</dt><dd>{{ c.pasokan.penyerahan }}</dd>
            </dl>
          </div>
        </template>

        <template v-else>
          <div class="card box">
            <h3>1. {{ t('vc.custDetail') }}</h3>
            <dl>
              <dt>Customer</dt><dd>{{ r.customer }}</dd>
              <dt>Company Status</dt><dd>{{ r.status_perusahaan }}</dd>
              <dt>Company Type</dt><dd>{{ r.tipe_perusahaan }}</dd>
              <dt>{{ t('mst.f.alamat') }}</dt><dd>{{ r.alamat }}</dd>
            </dl>
          </div>
          <div class="card box">
            <h3>2. {{ t('vc.general') }}</h3>
            <div v-for="x in r.umum" :key="x.q" class="qa"><b>{{ x.q }}</b><p class="sum" v-html="x.a || '-'" /></div>
          </div>
          <div class="card box">
            <h3>3. {{ t('vc.oppRisk') }}</h3>
            <dl><dt>Credit Limit Proposed</dt><dd>{{ rp(r.kredit_diajukan) }}</dd></dl>
            <div class="qa"><b>{{ t('vc.mktNote') }}</b><p class="sum" v-html="r.catatan_marketing || '-'" /></div>
          </div>
          <div class="card box">
            <h3>4. {{ t('vc.oldData') }}</h3>
            <div v-for="x in r.lama" :key="x.q" class="qa"><b>{{ x.q }}</b><p class="sum" v-html="x.a || '-'" /></div>
          </div>
          <div class="card box">
            <h3>5. {{ t('vc.attach') }}</h3>
            <p v-for="f in r.lampiran" :key="f.no" class="row"><Icon name="doc" :size="16" /> <span class="grow">{{ f.nama }}</span>
              <button type="button" class="btn btn-ghost btn-sm" @click="openFile(`/verifikasi-customer/${id}/berkas/review/${f.no}`, f.nama)">{{ t('vc.view') }}</button></p>
            <p v-if="!r.lampiran.length" class="muted">-</p>
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
.nm { margin: 0 0 4px; font-size: 18px; overflow-wrap: anywhere; }
h3 { margin: 6px 0 2px; font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
dl { display: grid; grid-template-columns: minmax(0, 40%) minmax(0, 1fr); gap: 6px 10px; margin: 0; font-size: 14px; }
dt { color: var(--muted); } dd { margin: 0; font-weight: 600; overflow-wrap: anywhere; }
.res { margin: 0; font-weight: 700; } .sum { margin: 0; font-size: 14px; overflow-wrap: anywhere; }
.row { margin: 0; display: flex; align-items: center; gap: 8px; font-size: 14px; overflow-wrap: anywhere; }
.pic { display: grid; gap: 2px; padding: 6px 0; border-top: 1px dashed var(--line); font-size: 14px; }
.qa { display: grid; gap: 2px; padding: 6px 0; border-top: 1px dashed var(--line); font-size: 13px; }
.qa b { font-weight: 600; color: var(--muted); }
.grow { flex: 1; min-width: 0; }
.lnk { margin-left: 8px; min-height: 32px; padding: 0 12px; font-size: 12px; }
.rad { display: flex; align-items: center; gap: 10px; min-height: 44px; font-weight: 600; }
.sel { width: 100%; padding: 10px 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; font-family: inherit; box-sizing: border-box; }
</style>
