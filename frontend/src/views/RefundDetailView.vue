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
const catatan = ref('')
const pwd = ref('')
const busy = ref(false)
const formError = ref('')
const needPassword = computed(() => false) // tidak ada konfirmasi password di modul ini (hanya di Profil untuk wajah)
const label = (h) => (h === 1 ? t('rf.approved') : h === 2 ? t('rf.rejected') : t('rf.pending'))

async function load() {
  loading.value = true
  error.value = ''
  try { d.value = (await syop.getRefund(id)).data } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'refund-penerima' }))

async function submit() {
  formError.value = ''
  if (!result.value) return (formError.value = t('vc.errResult'))
  if (needPassword.value && !pwd.value) return (formError.value = t('det.errPwd'))
  if (!window.confirm(result.value === '1' ? t('rf.q.approve') : t('rf.q.reject'))) return
  busy.value = true
  try {
    const p = { result: Number(result.value), catatan: catatan.value.trim() }
    if (needPassword.value) p.password = pwd.value
    await syop.decideRefund(id, p)
    showToast(t('rf.saved'), 'ok')
    inbox.refresh()
    router.replace({ name: 'refund-penerima' })
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
      <div class="bar-t"><b>{{ t('rf.detail') }}</b></div>
      <span class="back" style="visibility:hidden" />
    </header>
    <div class="wrap">
      <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load" />
      <div v-else-if="loading" class="skel" style="height:300px" />
      <template v-else>
        <div class="card box">
          <h2 class="nm">{{ d.nama }}</h2>
          <p class="sum">{{ d.customer }}</p>
          <span class="pill" :class="d.bisa_putus ? 'pill-wait' : 'pill-ok'">{{ d.status }}</span>
        </div>
        <div class="card box">
          <dl>
            <dt>Marketing</dt><dd>{{ d.marketing }}</dd>
            <dt>{{ t('rf.division') }}</dt><dd>{{ d.divisi || '-' }}</dd>
            <dt>{{ t('rf.bank') }}</dt><dd>{{ d.bank || '-' }}</dd>
            <dt>{{ t('rf.account') }}</dt><dd>{{ d.no_rekening || '-' }}</dd>
            <dt>{{ t('rf.holder') }}</dt><dd>{{ d.atas_nama || '-' }}</dd>
            <dt>{{ t('rf.ktpNo') }}</dt><dd>{{ d.no_ktp || '-' }}</dd>
            <dt>{{ t('rf.ktp') }}</dt>
            <dd><button v-if="d.ada_ktp" type="button" class="btn btn-ghost btn-sm lnk" @click="openFile(`/refund-penerima/${id}/berkas/ktp`, 'ktp')">{{ t('rf.view') }}</button><span v-else class="muted">{{ t('rf.noPhoto') }}</span></dd>
            <dt>{{ t('rf.npwp') }}</dt>
            <dd><button v-if="d.ada_npwp" type="button" class="btn btn-ghost btn-sm lnk" @click="openFile(`/refund-penerima/${id}/berkas/npwp`, 'npwp')">{{ t('rf.view') }}</button><span v-else class="muted">{{ t('rf.noPhoto') }}</span></dd>
          </dl>
        </div>
        <div v-for="s in [['bm', 'rf.bm'], ['ceo', 'rf.ceo']]" :key="s[0]" class="card box">
          <h3>{{ t(s[1]) }}</h3>
          <p class="res">{{ label(d[s[0]].hasil) }}</p>
          <p v-if="d[s[0]].catatan" class="sum">{{ d[s[0]].catatan }}</p>
          <small v-if="d[s[0]].oleh" class="muted">{{ d[s[0]].oleh }} · {{ d[s[0]].tanggal }}</small>
        </div>

        <form v-if="d.bisa_putus" class="card box" novalidate @submit.prevent="submit">
          <h3>{{ t('vc.decision') }}</h3>
          <div class="field"><label for="rc">{{ t('rf.note') }}</label><textarea id="rc" v-model="catatan" class="sel" rows="3" maxlength="1000" /></div>
          <div class="field">
            <label class="rad"><input v-model="result" type="radio" value="1" /> {{ t('vc.yes') }}</label>
            <label class="rad"><input v-model="result" type="radio" value="2" /> {{ t('vc.no') }}</label>
          </div>
          <div v-if="needPassword" class="field">
            <label for="rpw">{{ t('det.yourPwd') }}</label>
            <div class="input"><Icon name="lock" :size="20" /><input id="rpw" v-model="pwd" type="password" autocomplete="current-password" :placeholder="t('det.confirmPwd')" /></div>
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
.box .pill { white-space: normal; text-align: left; line-height: 1.4; border-radius: 12px; justify-self: start; max-width: 100%; overflow-wrap: anywhere; }
.bar { position: sticky; top: 0; z-index: 10; display: flex; align-items: center; gap: 8px; padding: calc(var(--safe-top) + 8px) 12px 8px; background: var(--glass-bar); backdrop-filter: blur(12px); border-bottom: 1px solid var(--line); }
.bar-t { flex: 1; text-align: center; font-size: 14.5px; }
.back { width: 44px; height: 44px; border-radius: 13px; border: 1px solid var(--line); background: var(--card); color: var(--ink); display: grid; place-items: center; }
.wrap { padding: 12px 16px calc(var(--safe-bottom) + 32px); display: grid; gap: 12px; }
.box { padding: 14px 16px; display: grid; gap: 6px; min-width: 0; }
.nm { margin: 0; font-size: 18px; overflow-wrap: anywhere; }
h3 { margin: 6px 0 2px; font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
dl { display: grid; grid-template-columns: minmax(0, 40%) minmax(0, 1fr); gap: 6px 10px; margin: 0; font-size: 14px; align-items: center; }
dt { color: var(--muted); } dd { margin: 0; font-weight: 600; overflow-wrap: anywhere; }
.res { margin: 0; font-weight: 700; } .sum { margin: 0; font-size: 14px; overflow-wrap: anywhere; }
.lnk { min-height: 32px; padding: 0 12px; font-size: 12px; }
.rad { display: flex; align-items: center; gap: 10px; min-height: 44px; font-weight: 600; }
.sel { width: 100%; padding: 10px 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; font-family: inherit; box-sizing: border-box; }
</style>
