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
const f = reactive({ nama: '', kode: '', inisial: '', aktif: true })
const info = ref(null)
const last = ref({ loaded: false, kode: null, message: '', accurate: true })
const lastBusy = ref(false)
const pwd = ref('')
const loading = ref(editing)
const error = ref('')
const busy = ref(false)
const formError = ref('')
const submitted = ref(false)
const needPassword = computed(() => false) // tidak ada konfirmasi password di modul ini (hanya di Profil untuk wajah)
const locked = computed(() => !!info.value?.inisial_terkunci)

onMounted(async () => {
  if (!editing) return
  try { const d = await syop.getVendor(id); Object.assign(f, { nama: d.nama, kode: d.kode, inisial: d.inisial || '', aktif: d.aktif }); info.value = d } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
})
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'master-vendor' }))
async function checkLast() {
  lastBusy.value = true
  try { last.value = { loaded: true, message: '', ...(await syop.vendorLastCode()) } } catch (e) { last.value = { loaded: true, kode: null, message: errorMessage(e), accurate: true } } finally { lastBusy.value = false }
}

async function save() {
  submitted.value = true
  formError.value = ''
  if (!f.nama.trim() || !f.kode.trim() || !f.inisial.trim()) return (formError.value = t('mst.errReq'))
  if (needPassword.value && !pwd.value) return (formError.value = t('det.errPwd'))
  busy.value = true
  try {
    const p = { nama: f.nama.trim(), kode: f.kode.trim(), inisial: f.inisial.trim().toUpperCase(), aktif: f.aktif }
    if (needPassword.value) p.password = pwd.value
    if (editing) await syop.updateVendor(id, p)
    else await syop.createVendor(p)
    showToast(t('mst.saved'), 'ok')
    router.replace({ name: 'master-vendor' })
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
      <div class="bar-t"><b>{{ editing ? t('mst.v.edit') : t('mst.v.add') }}</b></div>
      <span class="back" style="visibility:hidden" />
    </header>
    <div class="wrap">
      <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" />
      <div v-else-if="loading" class="skel" style="height:260px" />
      <form v-else class="card box" novalidate @submit.prevent="save">
        <div class="field"><label for="vn">{{ t('mst.f.nama') }} *</label><input id="vn" v-model="f.nama" class="sel" :class="{ bad: submitted && !f.nama.trim() }" maxlength="80" autocomplete="off" /></div>
        <div class="field">
          <label>{{ t('mst.v.last') }}</label>
          <div class="lastrow">
            <span class="sel ro">{{ last.loaded ? (last.kode || (last.accurate === false ? t('mst.v.lastNone') : '-')) : '—' }}</span>
            <button type="button" class="btn btn-ghost btn-sm" :disabled="lastBusy" @click="checkLast">{{ lastBusy ? t('common.loading') : t('mst.v.lastLoad') }}</button>
          </div>
          <small v-if="last.message" class="err-text">{{ last.message }}</small>
        </div>
        <div class="field"><label for="vk">{{ t('mst.v.kode') }} *</label>
          <div class="pre"><span>V-</span><input id="vk" v-model="f.kode" class="sel" :class="{ bad: submitted && !f.kode.trim() }" maxlength="40" autocomplete="off" /></div></div>
        <div class="field"><label for="vi">{{ t('mst.v.initial') }} *</label>
          <input id="vi" v-model="f.inisial" class="sel" :class="{ bad: submitted && !f.inisial.trim() }" :disabled="locked" maxlength="100" autocomplete="off" style="text-transform:uppercase" />
          <small v-if="locked" class="muted">{{ t('mst.v.locked') }}</small></div>
        <p v-if="editing && info" class="muted tiny">{{ info.accurate ? t('mst.v.accurate', { n: info.accurate }) : t('mst.v.noAccurate') }}</p>
        <p class="muted tiny">{{ t('mst.v.syncNote') }}</p>
        <label class="sw"><span>{{ t('mst.active') }}</span><input v-model="f.aktif" type="checkbox" role="switch" /><i /></label>
        <div v-if="needPassword" class="field">
          <label for="vpw">{{ t('det.yourPwd') }}</label>
          <div class="input"><Icon name="lock" :size="20" /><input id="vpw" v-model="pwd" type="password" autocomplete="current-password" :placeholder="t('det.confirmPwd')" /></div>
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
.lastrow { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; align-items: center; }
.sel.ro { display: flex; align-items: center; color: var(--muted); background: var(--blue-50); }
.pre { display: grid; grid-template-columns: auto minmax(0, 1fr); align-items: center; gap: 8px; font-weight: 700; }
.tiny { font-size: 12px; margin: 2px 0 6px; }
.btn-sm { padding: 0 14px; min-height: 46px; font-size: 13px; }
</style>
