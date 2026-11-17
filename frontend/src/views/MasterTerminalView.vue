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
const tabs = [{ k: '1', t: 'mst.tab.active' }, { k: '0', t: 'mst.tab.inactive' }, { k: 'all', t: 'mst.tab.all' }]
const tab = ref('1')
const q = ref('')
const cabang = ref('')
const branches = ref([])
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
    const r = await syop.listTerminal({ page, per_page: 20, status: tab.value, q: q.value.trim() || undefined, cabang: cabang.value || undefined })
    if (my !== seq) return
    items.value = page === 1 ? r.data : [...items.value, ...r.data]
    meta.value = r.meta
  } catch (e) { if (my === seq) error.value = errorMessage(e) } finally { if (my === seq) { loading.value = false; more.value = false } }
}
watch(q, () => { clearTimeout(timer); timer = setTimeout(() => load(1), 350) })
watch([tab, cabang], () => load(1))
onMounted(() => { syop.terminalOptions().then((o) => (branches.value = o.cabang)).catch(() => {})
  load(1) })
</script>

<template>
  <div class="page">
    <div class="page-head" :class="{ stuck: scrolled }">
      <div>
        <h1 class="page-title">{{ t('mst.t.title') }}</h1>
        <p class="page-sub">{{ t('mst.sub') }}</p>
      </div>
      <router-link :to="{ name: 'profil' }" class="avatar" :aria-label="t('common.profile')"><UserAvatar /><i class="dot" /></router-link>
    </div>

    <button class="btn btn-primary btn-block" style="margin-bottom:12px" @click="router.push({ name: 'master-terminal-form' })"><Icon name="plus" :size="18" /> {{ t('mst.t.add') }}</button>

    <div class="chips" role="tablist">
      <button v-for="tb in tabs" :key="tb.k" class="chip" :class="{ on: tab === tb.k }" role="tab" :aria-selected="tab === tb.k" @click="tab = tb.k">{{ t(tb.t) }}</button>
    </div>
    <label class="search" style="margin-bottom:10px"><Icon name="search" :size="20" /><input v-model="q" type="search" :aria-label="t('mst.t.search')" :placeholder="t('mst.t.search')" /></label>
    <select v-model="cabang" class="sel" :aria-label="t('mst.f.cabang')"><option value="">{{ t('mst.allBranch') }}</option><option v-for="c in branches" :key="c.id" :value="String(c.id)">{{ c.nama }}</option></select>
    <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load(1)" />
    <div v-else-if="loading" class="stack"><div v-for="i in 4" :key="i" class="skel" style="height:84px" /></div>
    <StateBox v-else-if="!items.length" icon="box" :title="t('mst.t.none')" :text="t('mst.t.noneText')" />
    <template v-else>
      <p class="cnt">{{ t('mst.count', { n: meta.total }) }}</p>
      <div class="stack">
        <button v-for="(p, i) in items" :key="p.id" class="item rise" :style="{ '--i': i % 8 }" @click="router.push({ name: 'master-terminal-form', query: { id: p.id } })">
          <div class="item-top">
            <div class="item-who"><span class="ico-box tinted tone-teal"><Icon name="box" :size="18" /></span><span>{{ p.initial ? '(' + p.initial + ') ' : '' }}{{ p.nama }}</span></div>
            <span class="pill" :class="p.aktif ? 'pill-ok' : 'pill-no'">{{ p.aktif ? t('mst.active') : t('mst.tab.inactive') }}</span>
          </div>
          <div class="item-title">{{ p.tanki }} · {{ p.kategori }}</div>
          <div class="item-meta">{{ p.cabang || '-' }} · {{ p.lokasi }}</div>
          <div class="item-meta">{{ new Intl.NumberFormat('id-ID').format(p.batas_bawah) }} – {{ new Intl.NumberFormat('id-ID').format(p.batas_atas) }} L</div>
        </button>
      </div>
      <button v-if="meta.page < meta.last" class="btn btn-ghost btn-block" style="margin-top:16px" :disabled="more" @click="load(meta.page + 1)">{{ more ? t('common.loading') : t('common.loadMore') }}</button>
    </template>
  </div>
</template>

<style scoped>
.cnt { margin: 0 0 10px; font-size: 12.5px; color: var(--muted); }
.sel { width: 100%; min-height: 46px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; margin-bottom: 14px; }
.item { text-align: left; overflow: hidden; width: 100%; box-sizing: border-box; }
.item-title, .item-meta { overflow-wrap: anywhere; }
</style>
