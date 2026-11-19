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
const needPassword = computed(() => false) // tidak ada konfirmasi password di modul ini (hanya di Profil untuk wajah)

async function load() {
  loading.value = true
  error.value = ''
  try { d.value = await syop.getTrucking(props.id) } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'trucking' }))
const open = (m) => { sheet.value = m; catatan.value = ''; pwd.value = ''; formError.value = '' }
const lokasi = (n, tk, l) => [n + (tk ? ' - ' + tk : ''), l].filter(Boolean).join(', ')

async function submit() {
  formError.value = ''
  if (sheet.value === 'reject' && !catatan.value.trim()) return (formError.value = t('det.errReason'))
  if (needPassword.value && !pwd.value) return (formError.value = t('det.errPwd'))
  busy.value = true
  try {
    const payload = { keputusan: sheet.value, catatan: catatan.value.trim() || undefined }
    if (needPassword.value) payload.password = pwd.value
    const r = await syop.decideTrucking(props.id, payload)
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
      <div class="bar-t"><b>{{ t('tru.detail') }}</b><small v-if="d">{{ d.nomor_ti }}</small></div>
      <button class="back" :aria-label="t('det.refresh')" @click="load"><Icon name="refresh" :size="18" /></button>
    </header>

    <div class="wrap">
      <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load" />
      <div v-else-if="loading" class="stack"><div class="skel" style="height:150px" /><div class="skel" style="height:220px" /></div>
      <template v-else-if="d">
        <section class="card box">
          <h1>{{ d.nomor_ti }}</h1>
          <p>{{ d.nama_transportir || '-' }}</p>
          <div class="big">{{ angka(d.volume) }}<small>L</small></div>
          <span v-if="d.is_cancel == 1 || d.status == 6" class="tag no">{{ t('tru.st.cancel') }}</span>
          <div class="kv"><span>{{ t('tru.f.date') }}</span><span>{{ tanggal(d.tanggal_pengajuan) }}</span></div>
          <div class="kv"><span>{{ t('tru.f.region') }}</span><span>{{ d.nama_cabang || '-' }}</span></div>
          <div class="kv"><span>{{ t('tru.f.etl') }}</span><span>{{ tanggal(d.etl_awal) }}</span></div>
        </section>

        <section class="card box">
          <h2>{{ t('tru.sec.route') }}</h2>
          <div class="kv col"><span>{{ t('tru.f.loading') }}</span><span>{{ d.load_port || '-' }}</span></div>
          <div class="kv col"><span>{{ t('tru.f.discharging') }}</span><span>{{ d.discharge_port || '-' }}</span></div>
        </section>

        <section class="card box">
          <h2>{{ t('tru.sec.cost') }}</h2>
          <div class="kv"><span>{{ t('tru.f.freight') }}</span><span>Rp {{ angka(d.ongkos_angkut) }} /L</span></div>
          <div class="kv"><span>{{ t('tru.f.total') }}</span><span>≈ Rp {{ angka(d.ongkos_angkut * d.volume) }}</span></div>
          <p v-if="d.remarks" class="note"><b>{{ t('tru.f.remarks') }}:</b> {{ d.remarks }}</p>
        </section>

        <section class="card box">
          <h2>{{ t('tru.sec.flow') }}</h2>
          <div class="step ok"><Icon name="check" :size="16" /><div><b>{{ t('tru.flow.req') }}</b><small>{{ d.created_by || '-' }}<template v-if="d.created_at"> · {{ tanggalJam(d.created_at) }}</template></small></div></div>
          <div class="step" :class="d.ceo_result == 1 ? 'ok' : d.ceo_result == 2 ? 'no' : ''"><Icon :name="d.ceo_result == 1 ? 'check' : d.ceo_result == 2 ? 'x' : 'clock'" :size="16" /><div><b>CEO</b><small>{{ d.ceo_pic || t('tru.waiting') }}<template v-if="d.ceo_tanggal"> · {{ tanggalJam(d.ceo_tanggal) }}</template></small><small v-if="d.ceo_summary" style="white-space:pre-line">{{ d.ceo_summary }}</small></div></div>
          <p v-if="d.ket_cancel" class="note"><b>{{ t('tru.f.cancel') }}:</b> {{ d.ket_cancel }}</p>
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
        <h2 class="sheet-t">{{ sheet === 'approve' ? t('tru.q.approve') : t('tru.q.reject') }}</h2>
        <p class="muted" style="margin:4px 0 16px">{{ d.nomor_ti }} · {{ d.nama_transportir }}</p>
        <div class="field">
          <label for="sc">{{ sheet === 'reject' ? t('det.reasonReq') : t('det.noteOpt') }}</label>
          <textarea id="sc" v-model="catatan" class="plain" maxlength="2000" :placeholder="sheet === 'reject' ? t('det.reasonPh') : t('det.notePh')" />
        </div>
        <div v-if="needPassword" class="field">
          <label for="sp">{{ t('det.yourPwd') }}</label>
          <div class="input"><Icon name="lock" :size="20" /><input id="sp" v-model="pwd" type="password" autocomplete="current-password" :placeholder="t('det.confirmPwd')" /></div>
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
.wrap { padding: 12px 16px calc(var(--safe-bottom) + 100px); display: grid; gap: 12px; }
.box { padding: 14px 16px; }
.box h1 { font-size: 17px; font-weight: 700; letter-spacing: -0.02em; margin: 0; }
.box h2 { font-size: 14px; font-weight: 700; margin: 0 0 6px; }
.box p { color: var(--muted); font-size: 13px; margin: 2px 0 8px; }
.big { font-size: 28px; font-weight: 800; letter-spacing: -0.03em; color: var(--accent); margin-bottom: 6px; }
.big small { font-size: 14px; margin-left: 4px; color: var(--muted); }
.kv span:last-child { text-align: right; font-weight: 600; overflow-wrap: anywhere; }
.kv.col { grid-template-columns: 1fr; gap: 2px; }
.kv.col span:last-child { text-align: left; }
.step { display: flex; gap: 10px; padding: 8px 0; color: var(--muted); }
.step > svg { flex: none; margin-top: 2px; }
.step div { display: grid; gap: 1px; font-size: 13px; }
.step b { color: var(--ink); }
.step small { color: var(--muted); font-size: 12px; overflow-wrap: anywhere; }
.step.ok > svg { color: #14a36b; } .step.no > svg { color: var(--red); }
.tag.no { display: inline-block; margin: 0 0 8px; padding: 3px 10px; border-radius: 99px; background: #fdeaea; color: #b4232a; font-size: 12px; font-weight: 700; }
.note { padding: 8px 10px; border-radius: 10px; background: var(--blue-50); }
.actions { position: fixed; left: 50%; transform: translateX(-50%); bottom: 0; width: min(480px, 100%); display: grid; grid-template-columns: 1fr 1.4fr; gap: 10px; padding: 10px 16px calc(var(--safe-bottom) + 10px); background: var(--glass); backdrop-filter: blur(14px); border-top: 1px solid var(--line); z-index: 15; }
</style>
