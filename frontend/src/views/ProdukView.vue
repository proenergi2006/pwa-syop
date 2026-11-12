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
const tabs = [{ k: 'all', t: 'prd.tab.all' }, { k: 'active', t: 'prd.tab.active' }, { k: 'inactive', t: 'prd.tab.inactive' }]
const tab = ref('all')
const q = ref('')
const items = ref([])
const meta = ref({ page: 1, last: 1, total: 0 })
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
    const r = await syop.listProduk({ page, per_page: 20, status: tab.value, q: q.value.trim() || undefined })
    if (my !== seq) return
    items.value = page === 1 ? r.data : [...items.value, ...r.data]
    meta.value = r.meta
  } catch (e) { if (my === seq) error.value = errorMessage(e) } finally { if (my === seq) { loading.value = false; more.value = false } }
}
watch(q, () => { clearTimeout(timer); timer = setTimeout(() => load(1), 350) })
watch(tab, () => load(1))
onMounted(() => load(1))
</script>

<template>
  <div class="page">
    <div class="page-head" :class="{ stuck: scrolled }">
      <div>
        <h1 class="page-title">{{ t('prd.title') }}</h1>
        <p class="page-sub">{{ t('prd.sub') }}</p>
      </div>
      <router-link :to="{ name: 'profil' }" class="avatar" :aria-label="t('common.profile')"><UserAvatar /><i class="dot" /></router-link>
    </div>

    <button class="btn btn-primary btn-block" style="margin-bottom:12px" @click="router.push({ name: 'produk-form' })"><Icon name="plus" :size="18" /> {{ t('prd.add') }}</button>

    <div class="chips" role="tablist">
      <button v-for="tb in tabs" :key="tb.k" class="chip" :class="{ on: tab === tb.k }" role="tab" :aria-selected="tab === tb.k" @click="tab = tb.k">{{ t(tb.t) }}</button>
    </div>
    <label class="search" style="margin-bottom:14px"><Icon name="search" :size="20" /><input v-model="q" type="search" :aria-label="t('prd.search')" :placeholder="t('prd.search')" /></label>

    <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load(1)" />
    <div v-else-if="loading" class="stack"><div v-for="i in 4" :key="i" class="skel" style="height:84px" /></div>
    <StateBox v-else-if="!items.length" icon="box" :title="t('prd.none')" :text="t('prd.noneText')" />
    <template v-else>
      <p class="cnt">{{ t('prd.count', { n: meta.total }) }}</p>
      <div class="stack">
        <button v-for="(p, i) in items" :key="p.id" class="item rise" :style="{ '--i': i % 8 }" @click="router.push({ name: 'produk-detail', params: { id: p.id } })">
          <div class="item-top">
            <div class="item-who"><span class="ico-box tinted tone-teal"><Icon name="drop" :size="18" /></span><span>{{ p.jenis }}</span></div>
            <span class="pill" :class="p.aktif ? 'pill-ok' : 'pill-no'">{{ p.aktif ? t('prd.active') : t('prd.inactive') }}</span>
          </div>
          <div class="item-title">{{ p.merk }}</div>
          <div v-if="p.catatan" class="item-meta">{{ p.catatan }}</div>
        </button>
      </div>
      <button v-if="meta.page < meta.last" class="btn btn-ghost btn-block" style="margin-top:16px" :disabled="more" @click="load(meta.page + 1)">{{ more ? t('common.loading') : t('common.loadMore') }}</button>
    </template>
  </div>
</template>

<style scoped>
.cnt { margin: 0 0 10px; font-size: 12.5px; color: var(--muted); }
</style>
