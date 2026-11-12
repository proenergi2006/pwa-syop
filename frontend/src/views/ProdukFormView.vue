<script setup>
import { t } from '../i18n'
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import StateBox from '../components/StateBox.vue'
import { useAuth } from '../stores/auth'
import * as syop from '../api/syop'
import { errorCode, errorMessage } from '../api/client'
import { showToast } from '../composables/useToast'

const route = useRoute()
const router = useRouter()
const auth = useAuth()
const id = route.query.id
const editing = !!id
const f = reactive({ jenis: '', merk: '', catatan: '', aktif: true })
const pwd = ref('')
const loading = ref(editing)
const error = ref('')
const busy = ref(false)
const formError = ref('')
const submitted = ref(false)
const needPassword = computed(() => auth.method === 'face')

onMounted(async () => {
  if (!editing) return
  try { const d = await syop.getProduk(id); Object.assign(f, { jenis: d.jenis, merk: d.merk, catatan: d.catatan, aktif: d.aktif }) } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
})
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'produk' }))

async function save() {
  submitted.value = true
  formError.value = ''
  if (!f.jenis.trim() || !f.merk.trim()) return (formError.value = t('prd.errReq'))
  if (needPassword.value && !pwd.value) return (formError.value = t('det.errPwd'))
  busy.value = true
  try {
    const p = { jenis: f.jenis.trim(), merk: f.merk.trim(), catatan: f.catatan.trim(), aktif: f.aktif }
    if (needPassword.value) p.password = pwd.value
    if (editing) { await syop.updateProduk(id, p); showToast(t('prd.saved'), 'ok'); back() } else {
      const r = await syop.createProduk(p)
      showToast(t('prd.saved'), 'ok')
      router.replace({ name: 'produk-detail', params: { id: r.id } })
    }
  } catch (e) {
    if (errorCode(e) === 'STEP_UP_REQUIRED') auth.method = 'face'
    formError.value = errorMessage(e)
  } finally { busy.value = false }
}
</script>

<template>
  <div>
    <header class="bar">
      <button class="back" :aria-label="t('common.back')" @click="back"><Icon name="back" :size="20" /></button>
      <div class="bar-t"><b>{{ editing ? t('prd.edit') : t('prd.addTitle') }}</b></div>
      <span class="back" style="visibility:hidden" />
    </header>

    <div class="wrap">
      <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" />
      <div v-else-if="loading" class="skel" style="height:300px" />
      <form v-else class="card box" novalidate @submit.prevent="save">
        <div class="field">
          <label for="pj">{{ t('prd.f.jenis') }} *</label>
          <input id="pj" v-model="f.jenis" class="sel" :class="{ bad: submitted && !f.jenis.trim() }" maxlength="25" autocomplete="off" />
        </div>
        <div class="field">
          <label for="pm">{{ t('prd.f.merk') }} *</label>
          <input id="pm" v-model="f.merk" class="sel" :class="{ bad: submitted && !f.merk.trim() }" maxlength="25" autocomplete="off" />
        </div>
        <div class="field">
          <label for="pc">{{ t('prd.f.note') }}</label>
          <textarea id="pc" v-model="f.catatan" class="plain" maxlength="1000" />
        </div>
        <label class="sw"><span>{{ t('prd.active') }}</span><input v-model="f.aktif" type="checkbox" role="switch" /><i /></label>
        <div v-if="needPassword" class="field">
          <label for="pp">{{ t('det.yourPwd') }}</label>
          <div class="input"><Icon name="lock" :size="20" /><input id="pp" v-model="pwd" type="password" autocomplete="current-password" :placeholder="t('det.confirmPwd')" /></div>
          <small class="muted">{{ t('det.faceNote') }}</small>
        </div>
        <p v-if="formError" class="err-text" role="alert">{{ formError }}</p>
        <button class="btn btn-primary btn-block" :disabled="busy">{{ busy ? t('det.processing') : t('rsv.save') }}</button>
      </form>
    </div>
  </div>
</template>

<style scoped>
.bar { position: sticky; top: 0; z-index: 10; display: flex; align-items: center; gap: 8px; padding: calc(var(--safe-top) + 8px) 12px 8px; background: var(--glass-bar); backdrop-filter: blur(12px); border-bottom: 1px solid var(--line); }
.bar-t { flex: 1; text-align: center; font-size: 14.5px; }
.back { width: 44px; height: 44px; border-radius: 13px; border: 1px solid var(--line); background: var(--card); color: var(--ink); display: grid; place-items: center; }
.wrap { padding: 12px 16px calc(var(--safe-bottom) + 32px); }
.box { padding: 14px 16px; display: grid; gap: 4px; }
.sel { width: 100%; min-height: 46px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; }
.sel.bad { border-color: var(--red); }
.sw { display: flex; align-items: center; justify-content: space-between; margin: 8px 0 12px; font-weight: 600; position: relative; }
.sw input { position: absolute; right: 0; width: 44px; height: 26px; opacity: 0; margin: 0; cursor: pointer; }
.sw i { width: 44px; height: 26px; border-radius: 13px; background: #c5cedd; position: relative; transition: background 0.2s; pointer-events: none; }
.sw i::after { content: ''; position: absolute; top: 3px; left: 3px; width: 20px; height: 20px; border-radius: 50%; background: #fff; transition: transform 0.2s; }
.sw input:checked + i { background: var(--accent); }
.sw input:checked + i::after { transform: translateX(18px); }
</style>
