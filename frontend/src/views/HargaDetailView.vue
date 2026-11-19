<script setup>
import { t } from '../i18n'
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import StateBox from '../components/StateBox.vue'
import { useAuth } from '../stores/auth'
import * as syop from '../api/syop'
import { errorCode, errorMessage } from '../api/client'
import { showToast } from '../composables/useToast'
import { angka, tanggal, tanggalJam } from '../utils/format'

const route = useRoute()
const router = useRouter()
const auth = useAuth()
const key = { awal: route.query.awal, akhir: route.query.akhir, area: route.query.area, produk: route.query.produk }
const d = ref(null)
const loading = ref(true)
const error = ref('')
const delOpen = ref(false)
const pwd = ref('')
const busy = ref(false)
const formError = ref('')
const needPassword = computed(() => false) // tidak ada konfirmasi password di modul ini (hanya di Profil untuk wajah)
// Catatan dari web berupa HTML (editor teks); tampilkan sebagai teks biasa.
const note = computed(() => (d.value?.note_jual || '').replace(/<br\s*\/?>|<\/p>/gi, '\n').replace(/<[^>]+>/g, '').replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').trim())

async function load() {
  loading.value = true
  error.value = ''
  try { d.value = await syop.getHarga(key) } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'harga' }))
async function remove() {
  formError.value = ''
  if (needPassword.value && !pwd.value) return (formError.value = t('det.errPwd'))
  busy.value = true
  try {
    const r = await syop.deleteHarga({ ...key, password: needPassword.value ? pwd.value : undefined })
    showToast(r.message, 'ok')
    router.replace({ name: 'harga' })
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
      <div class="bar-t"><b>{{ t('hrg.detail') }}</b></div>
      <button class="back" :aria-label="t('det.refresh')" @click="load"><Icon name="refresh" :size="18" /></button>
    </header>
    <div class="wrap">
      <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load" />
      <div v-else-if="loading" class="stack"><div class="skel" style="height:140px" /><div class="skel" style="height:220px" /></div>
      <template v-else-if="d">
        <section class="card box">
          <h1>{{ d.jenis_produk }} - {{ d.merk_dagang }}</h1>
          <p>{{ d.nama_area }} · {{ tanggal(d.periode_awal) }} – {{ tanggal(d.periode_akhir) }}</p>
          <div class="big">Rp {{ angka(d.harga_normal) }}<small>/L · {{ t('hrg.base') }}</small></div>
        </section>
        <section class="card box">
          <h2>{{ t('hrg.tiers') }}</h2>
          <div class="kv"><span>{{ t('hrg.t1') }}</span><span>Rp {{ angka(d.harga_sm) }}</span></div>
          <div class="kv"><span>{{ t('hrg.t2') }}</span><span>Rp {{ angka(d.harga_om) }}</span></div>
          <div class="kv"><span>{{ t('hrg.t3coo') }}</span><span>Rp {{ angka(d.harga_coo) }}</span></div>
          <div class="kv"><span>{{ t('hrg.t3ceo') }}</span><span>Rp {{ angka(d.harga_ceo) }}</span></div>
        </section>
        <section class="card box">
          <h2>{{ t('hrg.note') }}</h2>
          <p class="note">{{ note || '-' }}</p>
          <div class="aud" v-if="d.created_by"><Icon name="user" :size="14" /> {{ t('hrg.createdBy', { who: d.created_by, when: tanggalJam(d.created_time) }) }}</div>
          <div class="aud" v-if="d.lastupdate_by"><Icon name="refresh" :size="14" /> {{ t('hrg.updatedBy', { who: d.lastupdate_by, when: d.lastupdate_time ? tanggalJam(d.lastupdate_time) : '-' }) }}</div>
          <div class="aud" v-if="d.tanggal_persetujuan"><Icon name="check" :size="14" /> {{ t('hrg.approvedOn', { when: tanggalJam(d.tanggal_persetujuan) }) }}</div>
        </section>
        <div v-if="d.dipakai" class="chg"><Icon name="alert" :size="16" /> {{ t('hrg.inUse') }}</div>
      </template>
    </div>

    <footer v-if="d" class="actions" :class="{ one: d.dipakai }">
      <button v-if="!d.dipakai" class="btn btn-ghost danger" @click="delOpen = true; pwd = ''; formError = ''"><Icon name="trash" :size="18" /> {{ t('hrg.delete') }}</button>
      <button class="btn btn-primary" @click="router.push({ name: 'harga-form', query: key })"><Icon name="edit" :size="18" /> {{ t('hrg.edit') }}</button>
    </footer>

    <div v-if="delOpen" class="sheet-back" @click.self="!busy && (delOpen = false)">
      <div class="sheet" role="dialog" aria-modal="true">
        <div class="grab" />
        <h2 class="sheet-t">{{ t('hrg.q.delete') }}</h2>
        <p class="muted" style="margin:4px 0 16px">{{ d.nama_area }} · {{ d.jenis_produk }} - {{ d.merk_dagang }} · {{ tanggal(d.periode_awal) }}</p>
        <div v-if="needPassword" class="field">
          <label for="dp">{{ t('det.yourPwd') }}</label>
          <div class="input"><Icon name="lock" :size="20" /><input id="dp" v-model="pwd" type="password" autocomplete="current-password" :placeholder="t('det.confirmPwd')" /></div>
        </div>
        <p v-if="formError" class="err-text" role="alert" style="margin-bottom:12px">{{ formError }}</p>
        <div class="sheet-act">
          <button class="btn btn-ghost" :disabled="busy" @click="delOpen = false">{{ t('common.cancel') }}</button>
          <button class="btn btn-danger" :disabled="busy" @click="remove">{{ busy ? t('det.processing') : t('hrg.yesDelete') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.bar { position: sticky; top: 0; z-index: 10; display: flex; align-items: center; gap: 8px; padding: calc(var(--safe-top) + 8px) 12px 8px; background: var(--glass-bar); backdrop-filter: blur(12px); border-bottom: 1px solid var(--line); }
.bar-t { flex: 1; text-align: center; font-size: 14.5px; }
.back { width: 44px; height: 44px; border-radius: 13px; border: 1px solid var(--line); background: var(--card); color: var(--ink); display: grid; place-items: center; }
.wrap { padding: 12px 16px calc(var(--safe-bottom) + 100px); display: grid; gap: 12px; }
.box { padding: 14px 16px; }
.box h1 { font-size: 17px; font-weight: 700; letter-spacing: -0.02em; margin: 0; }
.box h2 { font-size: 14px; font-weight: 700; margin: 0 0 6px; }
.box p { color: var(--muted); font-size: 13px; margin: 2px 0 8px; }
.big { font-size: 28px; font-weight: 800; letter-spacing: -0.03em; color: var(--accent); }
.big small { font-size: 12px; margin-left: 4px; color: var(--muted); font-weight: 600; }
.kv span:last-child { text-align: right; font-weight: 700; }
.note { white-space: pre-line; color: var(--ink-2) !important; }
.aud { display: flex; gap: 6px; align-items: center; font-size: 12px; color: var(--muted); margin-top: 4px; }
.chg { display: flex; align-items: center; gap: 8px; padding: 10px 12px; border-radius: 12px; background: var(--amber-bg); color: var(--late); font-size: 13px; font-weight: 600; }
.actions { position: fixed; left: 50%; transform: translateX(-50%); bottom: 0; width: min(480px, 100%); display: grid; grid-template-columns: 1fr 1.4fr; gap: 10px; padding: 10px 16px calc(var(--safe-bottom) + 10px); background: var(--glass); backdrop-filter: blur(14px); border-top: 1px solid var(--line); z-index: 15; }
.actions.one { grid-template-columns: 1fr; }
.danger { color: var(--red); }
</style>
