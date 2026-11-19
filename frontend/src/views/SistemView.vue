<script setup>
import { t } from '../i18n'
import { useScrolled } from '../composables/useScrolled'
import { ref } from 'vue'
import UserAvatar from '../components/UserAvatar.vue'
import SistemKesehatan from '../components/sistem/SistemKesehatan.vue'
import SistemWajah from '../components/sistem/SistemWajah.vue'
import SistemLog from '../components/sistem/SistemLog.vue'
import SistemAkses from '../components/sistem/SistemAkses.vue'
import SistemPengaturan from '../components/sistem/SistemPengaturan.vue'

const scrolled = useScrolled()
const TABS = [
  ['kesehatan', SistemKesehatan], ['wajah', SistemWajah], ['log', SistemLog], ['akses', SistemAkses], ['pengaturan', SistemPengaturan],
]
let saved = 'kesehatan'
try { saved = sessionStorage.getItem('orcax_sistem_tab') || saved } catch { /* abaikan */ }
const tab = ref(TABS.some(([k]) => k === saved) ? saved : 'kesehatan')
function pilih(k) {
  tab.value = k
  try { sessionStorage.setItem('orcax_sistem_tab', k) } catch { /* abaikan */ }
}
</script>

<template>
  <div class="page">
    <div class="page-head" :class="{ stuck: scrolled }">
      <div>
        <h1 class="page-title">{{ t('sys.title') }}</h1>
        <p class="page-sub">{{ t('sys.sub') }}</p>
      </div>
      <router-link :to="{ name: 'profil' }" class="avatar" :aria-label="t('common.profile')"><UserAvatar /><i class="dot" /></router-link>
    </div>

    <div class="stabs" role="tablist">
      <button v-for="[k] in TABS" :key="k" role="tab" :aria-selected="tab === k" :class="{ on: tab === k }" @click="pilih(k)">{{ t('sys.tab.' + k) }}</button>
    </div>

    <template v-for="[k, C] in TABS" :key="k"><component :is="C" v-if="tab === k" /></template>
  </div>
</template>

<style scoped>
.stabs { display: flex; gap: 8px; overflow-x: auto; padding: 2px 0 12px; margin: 0 -2px 6px; scrollbar-width: none; }
.stabs::-webkit-scrollbar { display: none; }
.stabs button { flex: none; min-height: 40px; padding: 0 16px; border-radius: 99px; border: 1.5px solid var(--line); background: var(--card); color: var(--ink-2); font-size: 13px; font-weight: 800; font-family: inherit; }
.stabs button.on { border-color: var(--accent); background: var(--accent); color: #fff; }
</style>
