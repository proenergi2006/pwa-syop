<script setup>
import { t, ts } from '../i18n'
import { useScrolled } from '../composables/useScrolled'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import UserAvatar from '../components/UserAvatar.vue'
import PenawaranItem from '../components/PenawaranItem.vue'
import StateBox from '../components/StateBox.vue'
import OfflineNote from '../components/OfflineNote.vue'
import { readCache, writeCache } from '../composables/useListCache'
import { useAuth } from '../stores/auth'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'

const scrolled = useScrolled()
const route = useRoute()
const router = useRouter()
const auth = useAuth()

const tabs = [{ k: '', t: 'hist.all' }, { k: '1', t: 'status.approved' }, { k: '2', t: 'status.rejected' }]
const tab = ref(['1', '2'].includes(String(route.query.k)) ? String(route.query.k) : '')
const items = ref([])
const meta = ref({ page: 1, last: 1 })
const loading = ref(true)
const more = ref(false)
const error = ref('')
const staleAt = ref(0)
let seq = 0

async function load(page = 1) {
  const my = ++seq
  page === 1 ? (loading.value = true) : (more.value = true)
  error.value = ''
  try {
    const r = await syop.listHistory({ page, per_page: 15, keputusan: tab.value || undefined })
    if (my !== seq) return
    items.value = page === 1 ? r.data : [...items.value, ...r.data]
    meta.value = r.meta
    staleAt.value = 0
    if (page === 1) writeCache(`history_${tab.value || 'all'}`, { items: r.data, meta: r.meta })
  } catch (e) {
    if (my !== seq) return
    const c = !e.response && page === 1 ? readCache(`history_${tab.value || 'all'}`) : null
    if (c) { items.value = c.items; meta.value = c.meta; staleAt.value = c.t } else error.value = errorMessage(e)
  } finally {
    if (my === seq) { loading.value = false; more.value = false }
  }
}

watch(tab, () => load(1))
const onOnline = () => { if (staleAt.value) load(1) }
const open = (it) => router.push({ name: 'approval-detail', params: { id: it.id_penawaran } })
onMounted(() => { load(1); window.addEventListener('online', onOnline) })
onBeforeUnmount(() => window.removeEventListener('online', onOnline))
</script>

<template>
  <div class="page">
    <div class="page-head" :class="{ stuck: scrolled }">
      <div>
        <h1 class="page-title">{{ t('hist.title') }}</h1>
        <p class="page-sub">{{ t('hist.sub') }}</p>
      </div>
      <router-link :to="{ name: 'profil' }" class="avatar" :aria-label="t('common.profile')"><UserAvatar /><i class="dot" /></router-link>
    </div>

    <div class="chips" role="tablist">
      <button v-for="tb in tabs" :key="tb.k" class="chip" :class="{ on: tab === tb.k }" role="tab" :aria-selected="tab === tb.k" @click="tab = tb.k">{{ t(tb.t) }}</button>
    </div>

    <OfflineNote v-if="staleAt" :at="staleAt" @retry="load(1)" />
    <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load(1)" />
    <div v-else-if="loading" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:150px" /></div>
    <StateBox v-else-if="!items.length" icon="history" :title="t('hist.empty')" :text="t('hist.emptyText')" />
    <template v-else>
      <div class="stack">
        <PenawaranItem v-for="(p, i) in items" :key="p.id_penawaran + '-' + p.tgl_keputusan" :style="{ '--i': i % 8 }" :item="p" mode="history" @open="open" />
      </div>
      <button v-if="meta.page < meta.last && !staleAt" class="btn btn-ghost btn-block" style="margin-top:16px" :disabled="more" @click="load(meta.page + 1)">
        {{ more ? t('common.loading') : t('common.loadMore') }}
      </button>
    </template>
  </div>
</template>
