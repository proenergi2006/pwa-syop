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
import { clean } from '../utils/html'

const route = useRoute()
const router = useRouter()
const auth = useAuth()
const inbox = useInbox()
const id = Number(route.params.id)
const d = ref(null)
const loading = ref(true)
const error = ref('')
const tab = ref('form')
const result = ref('')
const summary = ref('')
const pwd = ref('')
const busy = ref(false)
const formError = ref('')
const needPassword = computed(() => false) // tidak ada konfirmasi password di modul ini (hanya di Profil untuk wajah)
const rp = (n) => 'Rp ' + angka(n || 0)

async function load() {
  loading.value = true
  error.value = ''
  try { d.value = (await syop.getSc(id)).data } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'sc' }))

async function submit() {
  formError.value = ''
  if (!summary.value.trim()) return (formError.value = t('vc.errSummary'))
  if (!result.value) return (formError.value = t('vc.errResult'))
  if (needPassword.value && !pwd.value) return (formError.value = t('det.errPwd'))
  if (!window.confirm(result.value === '1' ? t('sc.q.approve') : t('sc.q.reject'))) return
  busy.value = true
  try {
    const p = { result: Number(result.value), summary: summary.value.trim() }
    if (needPassword.value) p.password = pwd.value
    await syop.decideSc(id, p)
    showToast(t('vc.saved'), 'ok')
    inbox.refresh()
    router.replace({ name: 'sc' })
  } catch (e) {
    if (errorCode(e) === 'STEP_UP_REQUIRED') auth.method = 'face'
    formError.value = errorMessage(e)
  } finally { busy.value = false }
}
onMounted(load)
</script>

<template>
  <div>
    <header class="bar">
      <button class="back" :aria-label="t('common.back')" @click="back"><Icon name="back" :size="20" /></button>
      <div class="bar-t"><b>{{ t('sc.detail') }}</b></div>
      <span class="back" style="visibility:hidden" />
    </header>
    <div class="wrap">
      <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load" />
      <div v-else-if="loading" class="skel" style="height:300px" />
      <template v-else>
        <div class="card box">
          <h2 class="nm">{{ d.customer.nama }}</h2>
          <p v-if="d.customer.kode" class="muted">{{ d.customer.kode }}</p>
          <span class="pill" :class="d.bisa_putus ? 'pill-wait' : 'pill-ok'">{{ d.status }}</span>
        </div>
        <div class="chips" role="tablist">
          <button class="chip" :class="{ on: tab === 'form' }" @click="tab = 'form'">{{ t('sc.tab.form') }}</button>
          <button class="chip" :class="{ on: tab === 'po' }" @click="tab = 'po'">{{ t('sc.tab.po') }}</button>
        </div>

        <template v-if="tab === 'form'">
          <div class="card box">
            <dl>
              <dt>{{ t('sc.period') }}</dt><dd>{{ d.periode || '-' }}</dd>
              <dt>{{ t('sc.supplyDate') }}</dt><dd>{{ d.tgl_kirim || '-' }}</dd>
              <dt>TOP</dt><dd>{{ d.customer.top }}</dd>
              <dt>Credit Limit</dt><dd>{{ rp(d.customer.credit_limit) }}</dd>
              <dt>Credit Limit Temporary</dt><dd>{{ rp(d.customer.credit_limit_temp) }}</dd>
              <dt>{{ t('sc.business') }}</dt><dd>{{ d.customer.bisnis || '-' }}</dd>
              <dt>Marketing</dt><dd>{{ d.customer.marketing }}</dd>
            </dl>
          </div>
          <div class="card box">
            <h3>{{ t('sc.ar') }}</h3>
            <dl>
              <dt>Invoice not issued yet</dt><dd>{{ rp(d.ar.po_not_yet) }}</dd>
              <dt>AR Not yet</dt><dd>{{ rp(d.ar.not_yet) }}</dd>
              <dt>AR Overdue 1–7 days</dt><dd>{{ rp(d.ar.ov_07) }}</dd>
              <dt>AR Overdue 8–30 days</dt><dd>{{ rp(d.ar.ov_30) }}</dd>
              <dt>AR Overdue 31–60 days</dt><dd>{{ rp(d.ar.ov_60) }}</dd>
              <dt>AR Overdue 61–90 days</dt><dd>{{ rp(d.ar.ov_90) }}</dd>
              <dt>AR Overdue &gt; 90 days</dt><dd>{{ rp(d.ar.ov_up_90) }}</dd>
              <dt>Credit Limit Remaining</dt><dd>{{ rp(d.ar.sisa) }}</dd>
            </dl>
          </div>
          <div class="card box">
            <h3>PO</h3>
            <dl>
              <dt>PO Type</dt><dd>{{ d.po.jenis }}</dd>
              <dt>Volume</dt><dd>{{ angka(d.po.volume) }} L</dd>
              <dt>Amount</dt><dd>{{ rp(d.po.jumlah) }}</dd>
            </dl>
            <h3>{{ t('sc.payType') }}</h3>
            <dl>
              <dt>{{ t('sc.payType') }}</dt><dd>{{ d.pembayaran.tipe || '-' }}</dd>
              <template v-if="d.pembayaran.tipe === 'Customer Commitment'">
                <dt>{{ t('vc.date') }}</dt><dd>{{ d.pembayaran.tanggal || '-' }}</dd>
                <dt>Amount</dt><dd>{{ rp(d.pembayaran.jumlah) }}</dd>
              </template>
            </dl>
            <div v-for="(c, i) in d.pembayaran.agunan" :key="i" class="pic"><b>{{ c.item || '-' }}</b><span>{{ c.tanggal }} · {{ rp(c.jumlah) }}</span></div>
            <h3>Schedule Payment</h3>
            <dl>
              <dt>Status</dt><dd>{{ d.jadwal.diajukan ? 'Proposed' : 'Not Proposed' }}</dd>
              <template v-if="d.jadwal.diajukan">
                <dt>ADD TOP</dt><dd>{{ d.jadwal.add_top || '-' }}</dd>
                <dt>ADD CL</dt><dd>{{ rp(d.jadwal.add_cl) }}</dd>
                <dt>{{ t('sc.unblock') }}</dt>
                <dd><button v-if="d.jadwal.lampiran" type="button" class="btn btn-ghost btn-sm lnk" @click="openFile(`/sales-confirmation/${id}/berkas/unblock`, d.jadwal.lampiran.nama)">{{ t('vc.view') }}</button><template v-else>-</template></dd>
              </template>
            </dl>
          </div>
          <div v-for="s in [['finance', 'vc.s.finance'], ['bm', 'vc.s.bm']]" :key="s[0]" class="card box">
            <h3>{{ t(s[1]) }}</h3>
            <template v-if="d[s[0]].hasil || d[s[0]].ringkasan">
              <p class="res">{{ d[s[0]].hasil || '-' }}</p>
              <p class="sum" v-html="clean(d[s[0]].ringkasan) || '-'" />
              <small v-if="d[s[0]].oleh" class="muted">{{ d[s[0]].oleh }} · {{ d[s[0]].tanggal }} WIB</small>
            </template>
            <p v-else class="muted">{{ t('vc.notYet') }}</p>
          </div>

          <form v-if="d.bisa_putus" class="card box" novalidate @submit.prevent="submit">
            <h3>{{ t('vc.decision') }}</h3>
            <div class="field"><label for="ss">{{ t('sc.noteBm') }} *</label><textarea id="ss" v-model="summary" class="sel" rows="4" maxlength="5000" /></div>
            <div class="field">
              <label class="rad"><input v-model="result" type="radio" value="1" /> {{ t('pm.yes') }}</label>
              <label class="rad"><input v-model="result" type="radio" value="2" /> {{ t('pm.no') }}</label>
            </div>
            <div v-if="needPassword" class="field">
              <label for="spw">{{ t('det.yourPwd') }}</label>
              <div class="input"><Icon name="lock" :size="20" /><input id="spw" v-model="pwd" type="password" autocomplete="current-password" :placeholder="t('det.confirmPwd')" /></div>
              <small class="muted">{{ t('det.faceNote') }}</small>
            </div>
            <p v-if="formError" class="err-text" role="alert">{{ formError }}</p>
            <button class="btn btn-primary btn-block" :disabled="busy">{{ busy ? t('det.processing') : t('rsv.save') }}</button>
          </form>
        </template>

        <template v-else>
          <div class="card box">
            <h3>{{ t('sc.poData') }}</h3>
            <dl>
              <dt>{{ t('sc.poNo') }}</dt><dd>{{ d.po.nomor }}</dd>
              <dt>{{ t('sc.poDate') }}</dt><dd>{{ d.po.tanggal || '-' }}</dd>
              <dt>{{ t('sc.supplyDate') }}</dt><dd>{{ d.tgl_kirim || '-' }}</dd>
              <dt>{{ t('lcr.company') }}</dt><dd>{{ d.customer.nama }}</dd>
              <dt>{{ t('sc.product') }}</dt><dd>{{ d.po.produk || '-' }}</dd>
              <dt>{{ t('sc.price') }}</dt><dd>{{ rp(d.po.harga) }}</dd>
              <dt>Volume</dt><dd>{{ angka(d.po.volume) }} L</dd>
              <dt>{{ t('sc.totalOrder') }}</dt><dd>{{ rp(d.po.jumlah) }}</dd>
              <dt>Refund</dt><dd>{{ angka(d.po.refund_tawar) }}</dd>
              <dt>{{ t('rf.note') }}</dt><dd>{{ d.po.keterangan || '-' }}</dd>
              <dt>{{ t('pm.attach') }}</dt>
              <dd><template v-if="d.po.lampiran"><button type="button" class="btn btn-ghost btn-sm lnk" @click="openFile(`/sales-confirmation/${id}/berkas/po`, d.po.lampiran.nama)">{{ t('vc.view') }}</button></template><template v-else>-</template></dd>
            </dl>
          </div>
          <div class="card box">
            <h3>{{ t('sc.refundTo') }}</h3>
            <div v-for="(p, i) in d.po.penerima_refund" :key="i" class="pic"><b>{{ p.nama }}</b><span>{{ p.divisi }}</span><small>Rp {{ angka(p.refund) }} / L<template v-if="p.akhir"> · {{ t('sc.final') }} Rp {{ angka(p.akhir) }}</template></small></div>
            <p v-if="!d.po.penerima_refund.length" class="muted">-</p>
          </div>
          <div class="card box">
            <h3>{{ t('sc.offer') }}</h3>
            <dl>
              <dt>{{ t('sc.offerNo') }}</dt><dd>{{ d.po.penawaran.nomor || '-' }}</dd>
              <dt>Volume</dt><dd>{{ angka(d.po.penawaran.volume) }} L</dd>
              <dt>TOP</dt><dd>{{ d.po.penawaran.top || '-' }}</dd>
            </dl>
            <router-link v-if="d.po.penawaran.id" class="btn btn-ghost btn-sm" :to="{ name: 'approval-detail', params: { id: d.po.penawaran.id } }">{{ t('sc.openOffer') }}</router-link>
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
.box .pill { white-space: normal; text-align: left; line-height: 1.4; border-radius: 12px; justify-self: start; max-width: 100%; overflow-wrap: anywhere; }
.nm { margin: 0; font-size: 18px; overflow-wrap: anywhere; }
h3 { margin: 6px 0 2px; font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
dl { display: grid; grid-template-columns: minmax(0, 42%) minmax(0, 1fr); gap: 6px 10px; margin: 0; font-size: 14px; align-items: center; }
dt { color: var(--muted); } dd { margin: 0; font-weight: 600; overflow-wrap: anywhere; text-align: right; }
.res { margin: 0; font-weight: 700; } .sum { margin: 0; font-size: 14px; overflow-wrap: anywhere; }
.pic { display: grid; gap: 2px; padding: 6px 0; border-top: 1px dashed var(--line); font-size: 14px; }
.lnk { min-height: 32px; padding: 0 12px; font-size: 12px; }
.rad { display: flex; align-items: center; gap: 10px; min-height: 44px; font-weight: 600; }
.sel { width: 100%; padding: 10px 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; font-family: inherit; box-sizing: border-box; }
</style>
