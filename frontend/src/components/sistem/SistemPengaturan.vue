<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { t } from '../../i18n'
import Icon from '../Icon.vue'
import StateBox from '../StateBox.vue'
import * as syop from '../../api/syop'
import { errorMessage } from '../../api/client'
import { showToast } from '../../composables/useToast'
import { tanggalJam } from '../../utils/format'

const loading = ref(true)
const error = ref('')
const aktifSaatIni = ref(false)
const f = reactive({ aktif: false, pesan: '', sampai: '', busy: false, error: '', konfirmasi: false })

async function load() {
  loading.value = true
  error.value = ''
  try {
    const r = await syop.sistemPengaturan()
    const m = r.pemeliharaan
    aktifSaatIni.value = m.aktif
    Object.assign(f, { aktif: m.aktif, pesan: m.pesan || '', sampai: m.sampai ? m.sampai.replace(' ', 'T').slice(0, 16) : '' })
  } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
onMounted(load)

const berubah = computed(() => f.aktif !== aktifSaatIni.value || f.aktif)
async function simpan() {
  f.busy = true
  f.error = ''
  try {
    const r = await syop.sistemSimpanPengaturan({ aktif: f.aktif, pesan: f.pesan.trim() || null, sampai: f.aktif && f.sampai ? f.sampai.replace('T', ' ') + ':00' : null })
    showToast(r.message, 'ok')
    f.konfirmasi = false
    await load()
  } catch (e) { f.error = errorMessage(e); f.konfirmasi = false } finally { f.busy = false }
}
const klik = () => (f.aktif && !aktifSaatIni.value ? (f.konfirmasi = true) : simpan())
</script>

<template>
  <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load" />
  <div v-else-if="loading" class="skel" style="height:240px" />
  <article v-else class="card mt">
    <div class="mh">
      <span class="mi" :class="{ on: aktifSaatIni }"><Icon name="lock" :size="20" /></span>
      <div><b>{{ t('sys.s.maint') }}</b><small>{{ aktifSaatIni ? t('sys.s.isOn') : t('sys.s.isOff') }}</small></div>
      <button class="sw" role="switch" :aria-checked="f.aktif" :class="{ on: f.aktif }" :aria-label="t('sys.s.maint')" @click="f.aktif = !f.aktif"><i /></button>
    </div>
    <p class="desc">{{ t('sys.s.desc') }}</p>
    <div class="field"><label for="mp">{{ t('sys.s.message') }}</label><textarea id="mp" v-model="f.pesan" class="plain" maxlength="300" :placeholder="t('sys.s.messagePh')" style="min-height:90px" /></div>
    <div class="field"><label for="ms">{{ t('sys.s.until') }}</label><input id="ms" v-model="f.sampai" class="in" type="datetime-local" /><small class="hn">{{ t('sys.s.untilHint') }}</small></div>
    <p v-if="f.error" class="err-text" role="alert">{{ f.error }}</p>
    <button class="btn btn-primary btn-block" :disabled="f.busy || !berubah" @click="klik">{{ f.busy ? t('det.processing') : t('sys.save') }}</button>
  </article>

  <div v-if="f.konfirmasi" class="sheet-back" @click.self="!f.busy && (f.konfirmasi = false)">
    <div class="sheet" role="dialog" aria-modal="true">
      <div class="grab" />
      <h2 class="sheet-t">{{ t('sys.s.confirmTitle') }}</h2>
      <p class="hint">{{ t('sys.s.confirmHint') }}</p>
      <div class="sheet-act">
        <button class="btn btn-ghost" :disabled="f.busy" @click="f.konfirmasi = false">{{ t('common.cancel') }}</button>
        <button class="btn btn-danger" :disabled="f.busy" @click="simpan">{{ f.busy ? t('det.processing') : t('sys.s.turnOn') }}</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.mt { padding: 16px; display: grid; gap: 14px; border: 1px solid var(--line); }
.mh { display: flex; align-items: center; gap: 12px; }
.mh div { flex: 1; display: grid; gap: 2px; }
.mh b { font-size: 15px; font-weight: 800; }
.mh small { font-size: 12px; color: var(--muted); font-weight: 700; }
.mi { flex: none; width: 42px; height: 42px; border-radius: 14px; display: grid; place-items: center; background: #e6eeff; color: var(--accent); }
.mi.on { background: #fff1d6; color: #9a6200; }
.sw { flex: none; width: 54px; height: 32px; border-radius: 99px; border: 0; background: var(--line); position: relative; transition: background 0.2s; }
.sw i { position: absolute; top: 3px; left: 3px; width: 26px; height: 26px; border-radius: 50%; background: #fff; box-shadow: 0 1px 4px rgba(0, 0, 0, 0.25); transition: transform 0.2s; }
.sw.on { background: #e8a317; }
.sw.on i { transform: translateX(22px); }
.desc { margin: 0; font-size: 13px; color: var(--ink-2); line-height: 1.5; }
.in { width: 100%; height: 48px; border-radius: 14px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; font-weight: 600; padding: 0 14px; font-family: inherit; }
.hn { display: block; margin-top: 4px; font-size: 11.5px; color: var(--muted); }
.hint { margin: 6px 0 14px; padding: 8px 10px; border-radius: 10px; background: #fff1d6; color: #8a5600; font-size: 12.5px; font-weight: 600; }
</style>
