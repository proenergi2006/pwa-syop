<script setup>
import { t } from '../i18n'
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import StateBox from '../components/StateBox.vue'
import { useAuth } from '../stores/auth'
import { useInbox } from '../stores/inbox'
import * as syop from '../api/syop'
import { errorCode, errorMessage } from '../api/client'
import { showToast } from '../composables/useToast'
import { angka, tanggal, tanggalJam } from '../utils/format'

const props = defineProps({ id: String })
const router = useRouter()
const auth = useAuth()
const inbox = useInbox()
const d = ref(null)
const loading = ref(true)
const error = ref('')
const sheet = ref(null)
const catatan = ref('')
const pwd = ref('')
const busy = ref(false)
const formError = ref('')
const needPassword = computed(() => auth.method === 'face')

async function load() {
  loading.value = true
  error.value = ''
  try { d.value = await syop.getOa(props.id) } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'oa' }))
const open = (m) => { sheet.value = m; catatan.value = ''; pwd.value = ''; formError.value = '' }
const sign = (n) => (n > 0 ? '+' : '') + angka(n)
const rp = (n) => (n == null ? '-' : 'Rp ' + angka(n))
const pill = computed(() => ({ 1: 'pill-wait', 2: 'pill-wait', 3: 'pill-wait', 4: 'pill-ok', 5: 'pill-no' })[d.value?.status] || 'pill-wait')

async function submit() {
  formError.value = ''
  if (sheet.value === 'reject' && !catatan.value.trim()) return (formError.value = t('det.errReason'))
  if (needPassword.value && !pwd.value) return (formError.value = t('det.errPwd'))
  busy.value = true
  try {
    const payload = { keputusan: sheet.value, catatan: catatan.value.trim() || undefined }
    if (needPassword.value) payload.password = pwd.value
    const r = await syop.decideOa(props.id, payload)
    sheet.value = null
    showToast(r.message, 'ok')
    inbox.refresh()
    await load()
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
      <div class="bar-t"><b>{{ t('oa.detail') }}</b><small v-if="d">{{ d.no_do_syop || d.nomor_po }}</small></div>
      <button class="back" :aria-label="t('det.refresh')" @click="load"><Icon name="refresh" :size="18" /></button>
    </header>

    <div class="wrap">
      <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load" />
      <div v-else-if="loading && !d" class="stack"><div class="skel" style="height:170px" /><div class="skel" style="height:240px" /><div class="skel" style="height:160px" /></div>
      <template v-else-if="d">
        <!-- ringkas: OA lama -> baru -->
        <section class="hero">
          <span class="orb" />
          <div class="h-top"><span>{{ d.customer }}</span><span class="pill" :class="pill">{{ t('oa.st.' + d.status) }}</span></div>
          <div class="h-do">{{ d.no_do_syop || d.nomor_po }}</div>
          <div class="h-flow">
            <div class="h-c"><small>{{ t('oa.oaOld') }}</small><b>{{ rp(d.oa_lama) }}</b></div>
            <Icon name="chevron" :size="22" class="h-arrow" />
            <div class="h-c new"><small>{{ t('oa.oaNew') }}</small><b>{{ rp(d.oa_baru) }}</b></div>
          </div>
          <div v-if="d.selisih" class="h-diff" :class="d.selisih > 0 ? 'up' : 'down'">
            {{ t('oa.diff') }} {{ sign(d.selisih) }} {{ t('oa.perL') }}
          </div>
          <div v-else class="h-diff same">{{ t('oa.same') }}</div>
        </section>

        <!-- perubahan source -->
        <section class="card box">
          <h2>{{ t('oa.sec.source') }}</h2>
          <div v-for="(k, ix) in [['before', d.sebelum], ['after', d.sesudah]]" :key="k[0]" class="src" :class="k[0]">
            <div class="src-h"><span class="tag" :class="k[0]">{{ t('oa.' + k[0]) }}</span></div>
            <p class="depot">{{ k[1].depot || (ix ? t('oa.depotNone') : '-') }}</p>
            <div v-if="!k[1].items.length" class="nodata">{{ t('oa.noData') }}</div>
            <div v-for="(s, i) in k[1].items" v-else :key="i" class="po">
              <div class="po-n">{{ s.nomor_po || '-' }}</div>
              <div class="po-m"><span>{{ angka(s.volume) }} L</span><span>{{ rp(s.harga_beli) }}</span></div>
            </div>
          </div>
        </section>

        <!-- info pengiriman -->
        <section class="card box">
          <h2>{{ t('oa.sec.info') }}</h2>
          <div class="kv"><span>{{ t('oa.f.po') }}</span><span>{{ d.nomor_po || '-' }}</span></div>
          <div class="kv"><span>{{ t('oa.f.do') }}</span><span>{{ d.no_do_syop || '-' }}</span></div>
          <div class="kv"><span>{{ t('oa.f.lo') }}</span><span>{{ d.nomor_lo_pr || '-' }}</span></div>
          <div class="kv"><span>{{ t('oa.f.product') }}</span><span>{{ d.produk || '-' }}</span></div>
          <div class="kv"><span>{{ t('oa.f.area') }}</span><span>{{ d.area || '-' }}</span></div>
          <div class="kv"><span>{{ t('oa.f.region') }}</span><span>{{ d.wilayah_angkut || '-' }}</span></div>
          <div class="kv"><span>{{ t('oa.f.volume') }}</span><span>{{ angka(d.volume) }} L</span></div>
          <div class="kv"><span>{{ t('oa.f.load') }}</span><span>{{ d.tgl_loading ? tanggal(d.tgl_loading) : '-' }}</span></div>
          <div class="kv"><span>{{ t('oa.f.ship') }}</span><span>{{ d.tgl_kirim ? tanggal(d.tgl_kirim) : '-' }}</span></div>
        </section>

        <!-- alur -->
        <section class="card box">
          <h2>{{ t('oa.sec.flow') }}</h2>
          <p v-if="d.alasan" class="note"><b>{{ t('oa.reason') }}:</b> {{ d.alasan }}</p>
          <div class="step ok"><Icon name="check" :size="16" /><div><b>{{ t('oa.flow.req') }}</b><small>{{ d.dibuat_oleh || '-' }}<template v-if="d.dibuat_pada"> · {{ tanggalJam(d.dibuat_pada) }}</template></small></div></div>
          <div class="step" :class="d.procurement_pic ? 'ok' : ''"><Icon :name="d.procurement_pic ? 'check' : 'clock'" :size="16" /><div><b>{{ t('oa.flow.proc') }}</b><small>{{ d.procurement_pic || t('oa.waiting') }}<template v-if="d.procurement_tanggal"> · {{ tanggalJam(d.procurement_tanggal) }}</template></small></div></div>
          <div class="step" :class="d.logistik_pic ? 'ok' : ''"><Icon :name="d.logistik_pic ? 'check' : 'clock'" :size="16" /><div><b>{{ t('oa.flow.log') }}</b><small>{{ d.logistik_pic || t('oa.waiting') }}<template v-if="d.logistik_tanggal"> · {{ tanggalJam(d.logistik_tanggal) }}</template></small><small v-if="d.catatan_logistik" style="white-space:pre-line">{{ d.catatan_logistik }}</small></div></div>
          <div class="step" :class="d.ceo_result === 1 ? 'ok' : d.ceo_result === 2 ? 'no' : ''"><Icon :name="d.ceo_result === 1 ? 'check' : d.ceo_result === 2 ? 'x' : 'clock'" :size="16" /><div><b>{{ t('oa.flow.ceo') }}</b><small>{{ d.ceo_pic || t('oa.waiting') }}<template v-if="d.ceo_tanggal"> · {{ tanggalJam(d.ceo_tanggal) }}</template></small><small v-if="d.ceo_catatan" style="white-space:pre-line">{{ d.ceo_catatan }}</small></div></div>
        </section>
      </template>
    </div>

    <footer v-if="d?.can_decide" class="actions">
      <button class="btn btn-danger" @click="open('reject')"><Icon name="x" :size="18" /> {{ t('det.reject') }}</button>
      <button class="btn btn-primary" @click="open('approve')"><Icon name="check" :size="18" /> {{ t('det.approve') }}</button>
    </footer>

    <div v-if="sheet" class="sheet-back" @click.self="!busy && (sheet = null)">
      <div class="sheet" role="dialog" aria-modal="true">
        <div class="grab" />
        <h2 class="sheet-t">{{ sheet === 'approve' ? t('oa.q.approve') : t('oa.q.reject') }}</h2>
        <p class="muted" style="margin:4px 0 6px">{{ d.no_do_syop || d.nomor_po }} · {{ d.customer }}</p>
        <p class="hint">{{ sheet === 'approve' ? t('oa.hint.approve') : t('oa.hint.reject') }}</p>
        <div class="field">
          <label for="oc">{{ sheet === 'reject' ? t('det.reasonReq') : t('det.noteOpt') }}</label>
          <textarea id="oc" v-model="catatan" class="plain" maxlength="2000" :placeholder="sheet === 'reject' ? t('det.reasonPh') : t('det.notePh')" />
        </div>
        <div v-if="needPassword" class="field">
          <label for="op">{{ t('det.yourPwd') }}</label>
          <div class="input"><Icon name="lock" :size="20" /><input id="op" v-model="pwd" type="password" autocomplete="current-password" :placeholder="t('det.confirmPwd')" /></div>
          <small class="muted">{{ t('det.faceNote') }}</small>
        </div>
        <p v-if="formError" class="err-text" role="alert" style="margin-bottom:12px">{{ formError }}</p>
        <div class="sheet-act">
          <button class="btn btn-ghost" :disabled="busy" @click="sheet = null">{{ t('common.cancel') }}</button>
          <button class="btn" :class="sheet === 'approve' ? 'btn-primary' : 'btn-danger'" :disabled="busy" @click="submit">{{ busy ? t('det.processing') : sheet === 'approve' ? t('det.yesApprove') : t('det.yesReject') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.bar { position: sticky; top: 0; z-index: 10; display: flex; align-items: center; gap: 8px; padding: calc(var(--safe-top) + 8px) 12px 8px; background: var(--glass-bar); backdrop-filter: blur(12px); border-bottom: 1px solid var(--line); }
.bar-t { flex: 1; min-width: 0; text-align: center; display: grid; }
.bar-t b { font-size: 14.5px; font-weight: 700; }
.bar-t small { font-size: 12px; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.back { width: 44px; height: 44px; border-radius: 13px; border: 1px solid var(--line); background: var(--card); color: var(--ink); display: grid; place-items: center; }
.wrap { padding: 12px 16px calc(var(--safe-bottom) + 100px); display: grid; grid-template-columns: minmax(0, 1fr); gap: 12px; }
.hero { position: relative; overflow: hidden; border-radius: 22px; padding: 16px 18px 18px; color: #fff; background: linear-gradient(150deg, #0a1b52, #0b2a82 60%, #1b4fd8); box-shadow: 0 14px 34px rgba(11, 42, 130, 0.28); }
.orb { position: absolute; right: -60px; top: -70px; width: 220px; height: 220px; border-radius: 50%; background: radial-gradient(circle, rgba(120, 160, 255, 0.45), rgba(120, 160, 255, 0)); pointer-events: none; }
.h-top { position: relative; display: flex; align-items: center; justify-content: space-between; gap: 10px; font-size: 13px; color: #c8d3f5; }
.h-top > span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.h-do { position: relative; margin: 8px 0 14px; font-size: 20px; font-weight: 800; letter-spacing: -0.02em; overflow-wrap: anywhere; }
.h-flow { position: relative; display: flex; align-items: center; gap: 10px; }
.h-c { flex: 1; display: grid; gap: 2px; padding: 10px 12px; border-radius: 14px; background: rgba(255, 255, 255, 0.1); min-width: 0; }
.h-c.new { background: rgba(255, 255, 255, 0.2); }
.h-c small { font-size: 11px; color: #c8d3f5; }
.h-c b { font-size: 16px; font-weight: 800; letter-spacing: -0.01em; overflow-wrap: anywhere; }
.h-arrow { flex: none; color: #c8d3f5; }
.h-diff { position: relative; display: inline-block; margin-top: 12px; padding: 5px 12px; border-radius: 99px; font-size: 13px; font-weight: 800; }
.h-diff.up { background: #fdeaea; color: #b4232a; } .h-diff.down { background: #dff5ea; color: #0b7a4b; } .h-diff.same { background: rgba(255, 255, 255, 0.16); color: #fff; }
.box { padding: 14px 16px; }
.box h2 { font-size: 14px; font-weight: 700; margin: 0 0 8px; }
.src { padding: 10px 12px; border-radius: 14px; background: var(--bg); margin-bottom: 10px; }
.src.after { background: color-mix(in srgb, var(--green) 9%, var(--card)); }
.tag { display: inline-block; padding: 2px 10px; border-radius: 99px; font-size: 11px; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; background: var(--line); color: var(--muted); }
.tag.after { background: #dff5ea; color: #0b7a4b; }
.depot { margin: 6px 0 8px; font-size: 14px; font-weight: 700; overflow-wrap: anywhere; }
.nodata { font-size: 12.5px; color: var(--muted); }
.po { display: grid; gap: 2px; padding: 8px 0; border-top: 1px dashed var(--line); }
.po-n { font-size: 13px; font-weight: 700; overflow-wrap: anywhere; }
.po-m { display: flex; justify-content: space-between; gap: 10px; font-size: 12.5px; color: var(--muted); }
.kv span:last-child { text-align: right; font-weight: 600; overflow-wrap: anywhere; }
.step { display: flex; gap: 10px; padding: 8px 0; color: var(--muted); }
.step > svg { flex: none; margin-top: 2px; }
.step div { display: grid; gap: 1px; font-size: 13px; }
.step b { color: var(--ink); }
.step small { color: var(--muted); font-size: 12px; overflow-wrap: anywhere; }
.step.ok > svg { color: #14a36b; } .step.no > svg { color: var(--red); }
.note { padding: 8px 10px; border-radius: 10px; background: var(--blue-50); color: var(--ink); font-size: 13px; margin: 0 0 6px; white-space: pre-line; }
.hint { margin: 0 0 14px; padding: 8px 10px; border-radius: 10px; background: var(--blue-50); color: var(--ink-2); font-size: 12.5px; }
.actions { position: fixed; left: 50%; transform: translateX(-50%); bottom: 0; width: min(480px, 100%); display: grid; grid-template-columns: 1fr 1.4fr; gap: 10px; padding: 10px 16px calc(var(--safe-bottom) + 10px); background: var(--glass); backdrop-filter: blur(14px); border-top: 1px solid var(--line); z-index: 15; }
</style>
