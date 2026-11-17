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
const result = ref('')
const summary = ref('')
const pwd = ref('')
const busy = ref(false)
const formError = ref('')
const needPassword = computed(() => auth.method === 'face')

async function load() {
  loading.value = true
  error.value = ''
  try { d.value = (await syop.getPermohonan(id)).data } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'permohonan' }))

async function submit() {
  formError.value = ''
  if (!summary.value.trim()) return (formError.value = t('vc.errSummary'))
  if (!result.value) return (formError.value = t('vc.errResult'))
  if (needPassword.value && !pwd.value) return (formError.value = t('det.errPwd'))
  if (!window.confirm(result.value === '1' ? t('pm.q.approve') : t('pm.q.reject'))) return
  busy.value = true
  try {
    const p = { result: Number(result.value), summary: summary.value.trim() }
    if (needPassword.value) p.password = pwd.value
    await syop.decidePermohonan(id, p)
    showToast(t('vc.saved'), 'ok')
    inbox.refresh()
    router.replace({ name: 'permohonan' })
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
      <div class="bar-t"><b>{{ t('pm.detail') }}</b></div>
      <span class="back" style="visibility:hidden" />
    </header>
    <div class="wrap">
      <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load" />
      <div v-else-if="loading" class="skel" style="height:300px" />
      <template v-else>
        <div class="card box">
          <h2 class="nm">{{ d.customer }}</h2>
          <p v-if="d.kode_pelanggan" class="muted">{{ d.kode_pelanggan }}</p>
          <span class="pill" :class="d.bisa_putus ? 'pill-wait' : 'pill-ok'">{{ d.status }}</span>
        </div>
        <div class="card box">
          <h3>{{ t('pm.data') }}</h3>
          <dl>
            <dt>{{ t('pm.category') }}</dt><dd>{{ d.kategori || '-' }}</dd>
            <dt>{{ t('pm.subject') }}</dt><dd>{{ d.judul }}</dd>
            <dt>{{ t('pm.message') }}</dt><dd>{{ d.pesan || '-' }}</dd>
            <dt>Marketing</dt><dd>{{ d.marketing }}</dd>
            <dt>{{ t('vc.date') }}</dt><dd>{{ d.tanggal }}</dd>
            <dt>{{ t('pm.attach') }}</dt>
            <dd><template v-if="d.lampiran"><span class="grow">{{ d.lampiran.nama }}</span> <button type="button" class="btn btn-ghost btn-sm lnk" @click="openFile(`/permohonan/${id}/berkas`, d.lampiran.nama)">{{ t('vc.view') }}</button></template><template v-else>-</template></dd>
          </dl>
        </div>
        <div v-for="s in [['finance', 'vc.s.finance'], ['bm', 'vc.s.bm']]" :key="s[0]" class="card box">
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
          <div class="field"><label for="ps">{{ t('pm.note') }} *</label><textarea id="ps" v-model="summary" class="sel" rows="4" maxlength="5000" /></div>
          <div class="field">
            <label class="rad"><input v-model="result" type="radio" value="1" /> {{ t('pm.yes') }}</label>
            <label class="rad"><input v-model="result" type="radio" value="2" /> {{ t('pm.no') }}</label>
          </div>
          <div v-if="needPassword" class="field">
            <label for="ppw">{{ t('det.yourPwd') }}</label>
            <div class="input"><Icon name="lock" :size="20" /><input id="ppw" v-model="pwd" type="password" autocomplete="current-password" :placeholder="t('det.confirmPwd')" /></div>
            <small class="muted">{{ t('det.faceNote') }}</small>
          </div>
          <p v-if="formError" class="err-text" role="alert">{{ formError }}</p>
          <button class="btn btn-primary btn-block" :disabled="busy">{{ busy ? t('det.processing') : t('rsv.save') }}</button>
        </form>
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
dl { display: grid; grid-template-columns: minmax(0, 36%) minmax(0, 1fr); gap: 6px 10px; margin: 0; font-size: 14px; align-items: center; }
dt { color: var(--muted); } dd { margin: 0; font-weight: 600; overflow-wrap: anywhere; }
.res { margin: 0; font-weight: 700; } .sum { margin: 0; font-size: 14px; overflow-wrap: anywhere; }
.lnk { min-height: 32px; padding: 0 12px; font-size: 12px; }
.rad { display: flex; align-items: center; gap: 10px; min-height: 44px; font-weight: 600; }
.sel { width: 100%; padding: 10px 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; font-family: inherit; box-sizing: border-box; }
</style>
