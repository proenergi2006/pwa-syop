<script setup>
import { t } from '../i18n'
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import StateBox from '../components/StateBox.vue'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'
import { tanggalJam } from '../utils/format'

const props = defineProps({ id: String })
const router = useRouter()
const d = ref(null)
const loading = ref(true)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try { d.value = await syop.getProduk(props.id) } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'produk' }))
onMounted(load)
</script>

<template>
  <div>
    <header class="bar">
      <button class="back" :aria-label="t('common.back')" @click="back"><Icon name="back" :size="20" /></button>
      <div class="bar-t"><b>{{ t('prd.detail') }}</b></div>
      <button v-if="d" class="back" :aria-label="t('prd.edit')" @click="router.push({ name: 'produk-form', query: { id: d.id } })"><Icon name="edit" :size="18" /></button>
      <span v-else class="back" style="visibility:hidden" />
    </header>

    <div class="wrap">
      <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load" />
      <div v-else-if="loading" class="skel" style="height:260px" />
      <template v-else-if="d">
        <section class="card box">
          <h1>{{ d.jenis }}</h1>
          <p>{{ d.merk }}</p>
          <span class="pill" :class="d.aktif ? 'pill-ok' : 'pill-no'">{{ d.aktif ? t('prd.active') : t('prd.inactive') }}</span>
          <div class="kv"><span>{{ t('prd.f.jenis') }}</span><span>{{ d.jenis }}</span></div>
          <div class="kv"><span>{{ t('prd.f.merk') }}</span><span>{{ d.merk }}</span></div>
          <div class="kv col"><span>{{ t('prd.f.note') }}</span><span class="pre">{{ d.catatan || '-' }}</span></div>
        </section>
        <section class="card box">
          <h2>{{ t('prd.history') }}</h2>
          <div class="kv"><span>{{ t('prd.f.created') }}</span><span>{{ d.dibuat_oleh }}<small v-if="d.dibuat_pada"> · {{ tanggalJam(d.dibuat_pada) }}</small></span></div>
          <div class="kv"><span>{{ t('prd.f.updated') }}</span><span>{{ d.diubah_pada ? d.diubah_oleh : '-' }}<small v-if="d.diubah_pada"> · {{ tanggalJam(d.diubah_pada) }}</small></span></div>
        </section>
      </template>
    </div>
  </div>
</template>

<style scoped>
.bar { position: sticky; top: 0; z-index: 10; display: flex; align-items: center; gap: 8px; padding: calc(var(--safe-top) + 8px) 12px 8px; background: var(--glass-bar); backdrop-filter: blur(12px); border-bottom: 1px solid var(--line); }
.bar-t { flex: 1; min-width: 0; text-align: center; font-size: 14.5px; }
.back { width: 44px; height: 44px; border-radius: 13px; border: 1px solid var(--line); background: var(--card); color: var(--ink); display: grid; place-items: center; }
.wrap { padding: 12px 16px calc(var(--safe-bottom) + 32px); display: grid; gap: 12px; }
.box { padding: 14px 16px; }
.box h1 { font-size: 20px; font-weight: 800; letter-spacing: -0.02em; margin: 0; }
.box h2 { font-size: 14px; font-weight: 700; margin: 0 0 6px; }
.box p { color: var(--muted); font-size: 14px; margin: 2px 0 10px; }
.kv span:last-child { text-align: right; font-weight: 600; overflow-wrap: anywhere; }
.kv.col { grid-template-columns: 1fr; gap: 2px; }
.kv.col span:last-child { text-align: left; }
.kv small { color: var(--muted); font-weight: 500; }
.pre { white-space: pre-line; }
</style>
