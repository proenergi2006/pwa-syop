<script setup>
import { t } from '../i18n'
import { useScrolled } from '../composables/useScrolled'
import { onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import UserAvatar from '../components/UserAvatar.vue'
import StateBox from '../components/StateBox.vue'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'

const scrolled = useScrolled()
const router = useRouter()
const tab = ref('wait')
const q = ref('')
const items = ref([])
const meta = ref({ page: 1, last_page: 1, total: 0 })
const loading = ref(true)
const more = ref(false)
const error = ref('')
let seq = 0
let timer

async function load(page = 1) {
  const my = ++seq
  page === 1 ? (loading.value = true) : (more.value = true)
  error.value = ''
  try {
    const r = await syop.listRefund({ page, per_page: 15, q: q.value.trim() || undefined, filter: tab.value === 'wait' ? 'menunggu' : undefined })
    if (my !== seq) return
    items.value = page === 1 ? r.data : [...items.value, ...r.data]
    meta.value = r.meta
  } catch (e) { if (my === seq) error.value = errorMessage(e) } finally { if (my === seq) { loading.value = false; more.value = false } }
}
const pill = (d) => (d.is_bm === 2 || d.is_ceo === 2 ? 'pill-bad' : d.is_bm === 1 && d.is_ceo === 1 ? 'pill-ok' : 'pill-wait')
watch(q, () => { clearTimeout(timer); timer = setTimeout(() => load(1), 350) })
watch(tab, () => load(1))
onMounted(() => load(1))
</script>

<template>
  <div class="page">
    <div class="page-head" :class="{ stuck: scrolled }">
      <div>
        <h1 class="page-title">{{ t('rf.title') }}</h1>
        <p class="page-sub">{{ t('rf.sub') }}</p>
      </div>
      <router-link :to="{ name: 'profil' }" class="avatar" :aria-label="t('common.profile')"><UserAvatar /><i class="dot" /></router-link>
    </div>

    <div class="chips" role="tablist">
      <button class="chip" :class="{ on: tab === 'wait' }" role="tab" :aria-selected="tab === 'wait'" @click="tab = 'wait'">{{ t('dr.tab.wait') }}</button>
      <button class="chip" :class="{ on: tab === 'all' }" role="tab" :aria-selected="tab === 'all'" @click="tab = 'all'">{{ t('dr.tab.all') }}</button>
    </div>
    <label class="search" style="margin-bottom:14px"><Icon name="search" :size="20" /><input v-model="q" type="search" :aria-label="t('rf.search')" :placeholder="t('rf.search')" /></label>

    <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load(1)" />
    <div v-else-if="loading" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:120px" /></div>
    <StateBox v-else-if="!items.length" icon="check" :title="t('rf.none')" :text="t('rf.noneText')" />
    <template v-else>
      <div class="stack">
        <button v-for="(d, i) in items" :key="d.id" class="item rise" :class="{ hot: d.menunggu }" :style="{ '--i': i % 8 }" @click="router.push({ name: 'refund-penerima-detail', params: { id: d.id } })">
          <div class="item-top">
            <div class="item-who"><span class="ico-box tinted tone-green"><Icon name="refund" :size="18" /></span><span>{{ d.nama }}</span></div>
            <span class="pill" :class="pill(d)">{{ d.status }}</span>
          </div>
          <div class="item-title">{{ d.customer }}</div>
          <div class="item-meta">{{ d.marketing }} · {{ d.divisi }} · {{ d.aktif ? t('rf.active') : t('rf.inactive') }}</div>
        </button>
      </div>
      <button v-if="meta.page < meta.last_page" class="btn btn-ghost btn-block" style="margin-top:16px" :disabled="more" @click="load(meta.page + 1)">{{ more ? t('common.loading') : t('common.loadMore') }}</button>
    </template>
  </div>
</template>

<style scoped>
.item.hot { border-color: var(--focus); box-shadow: 0 0 0 1px var(--focus), var(--shadow); }
.stack, .stack > * { min-width: 0; max-width: 100%; }
.item { width: 100%; box-sizing: border-box; text-align: left; overflow: hidden; }
.item :deep(.item-top) { flex-wrap: wrap; gap: 6px 10px; }
.item :deep(.pill) { white-space: normal; text-align: center; }
.item-title, .item-meta { min-width: 0; overflow-wrap: anywhere; word-break: break-word; }
.item-title { font-weight: 700; margin-top: 6px; }
</style>
