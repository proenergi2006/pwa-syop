<script setup>
import { t } from '../i18n'
import { useScrolled } from '../composables/useScrolled'
import { onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import UserAvatar from '../components/UserAvatar.vue'
import StateBox from '../components/StateBox.vue'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'
import { angka, tanggalJam } from '../utils/format'

const scrolled = useScrolled()
const router = useRouter()
const route = useRoute()
const tabs = [{ k: 'wait', t: 'oa.tab.wait' }, { k: 'done', t: 'oa.tab.done' }, { k: 'all', t: 'oa.tab.all' }]
const tab = ref(['wait', 'done', 'all'].includes(route.query.tab) ? route.query.tab : 'wait')
const items = ref([])
const meta = ref({ page: 1, last: 1 })
const waiting = ref(0)
const loading = ref(true)
const more = ref(false)
const error = ref('')
const q = ref('')
let seq = 0

async function load(page = 1) {
  const my = ++seq
  page === 1 ? (loading.value = true) : (more.value = true)
  error.value = ''
  try {
    const r = await syop.listOa({ page, per_page: 15, tab: tab.value, q: q.value.trim() || undefined })
    if (my !== seq) return
    items.value = page === 1 ? r.data : [...items.value, ...r.data]
    meta.value = r.meta
    waiting.value = r.waiting
  } catch (e) { if (my === seq) error.value = errorMessage(e) } finally { if (my === seq) { loading.value = false; more.value = false } }
}
const pill = (s) => ({ 1: 'pill-wait', 2: 'pill-wait', 3: 'pill-wait', 4: 'pill-ok', 5: 'pill-no' })[s] || 'pill-wait'
const sign = (n) => (n > 0 ? '+' : '') + angka(n)
let timer
watch(q, () => { clearTimeout(timer); timer = setTimeout(() => load(1), 350) })
watch(tab, () => load(1))
onMounted(() => load(1))
</script>

<template>
  <div class="page">
    <div class="page-head" :class="{ stuck: scrolled }">
      <div>
        <h1 class="page-title">{{ t('oa.title') }}</h1>
        <p class="page-sub">{{ t('oa.sub') }}</p>
      </div>
      <router-link :to="{ name: 'profil' }" class="avatar" :aria-label="t('common.profile')"><UserAvatar /><i class="dot" /></router-link>
    </div>

    <div class="chips" role="tablist">
      <button v-for="tb in tabs" :key="tb.k" class="chip" :class="{ on: tab === tb.k }" role="tab" :aria-selected="tab === tb.k" @click="tab = tb.k">
        {{ t(tb.t) }}<b v-if="tb.k === 'wait' && waiting" class="cnt">{{ waiting }}</b>
      </button>
    </div>
    <label class="search" style="margin-bottom:14px"><Icon name="search" :size="20" /><input v-model="q" type="search" :aria-label="t('oa.search')" :placeholder="t('oa.search')" /></label>

    <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load(1)" />
    <div v-else-if="loading" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:190px" /></div>
    <StateBox v-else-if="!items.length" icon="check" :title="tab === 'wait' ? t('oa.noneWait') : t('oa.none')" :text="t('oa.noneText')" />
    <template v-else>
      <div class="stack">
        <button v-for="(s, i) in items" :key="s.id" class="item rise" :class="{ hot: s.status === 3 }" :style="{ '--i': i % 8 }" @click="router.push({ name: 'oa-detail', params: { id: s.id } })">
          <div class="item-top">
            <div class="item-who"><span class="ico-box tinted tone-violet"><Icon name="invoice" :size="18" /></span><span>{{ s.no_do_syop || s.nomor_po }}</span></div>
            <span class="pill" :class="pill(s.status)">{{ t('oa.st.' + s.status) }}</span>
          </div>
          <div class="item-title">{{ s.kode_pelanggan ? s.kode_pelanggan + ' - ' : '' }}{{ s.nama_customer }}</div>
          <div class="item-meta">{{ t('oa.f.po') }} {{ s.nomor_po }}</div>
          <div v-if="s.depot_baru.length" class="depots">
            <span v-for="(d, k) in s.depot_baru" :key="k" class="dp"><Icon name="box" :size="13" />{{ d.depot }} · {{ angka(d.volume) }} L</span>
          </div>
          <div class="oa">
            <div class="oa-c"><small>{{ t('oa.oaOld') }}</small><b>Rp {{ angka(s.oa_lama) }}</b></div>
            <Icon name="chevron" :size="16" class="arrow" />
            <div class="oa-c"><small>{{ t('oa.oaNew') }}</small><b>{{ s.oa_baru != null ? 'Rp ' + angka(s.oa_baru) : '-' }}</b></div>
            <span v-if="s.selisih" class="diff" :class="s.selisih > 0 ? 'up' : 'down'">{{ sign(s.selisih) }}</span>
          </div>
          <div class="item-foot"><span class="item-meta">{{ s.created_by }}</span><span class="item-meta">{{ tanggalJam(s.created_time) }}</span></div>
        </button>
      </div>
      <button v-if="meta.page < meta.last" class="btn btn-ghost btn-block" style="margin-top:16px" :disabled="more" @click="load(meta.page + 1)">{{ more ? t('common.loading') : t('common.loadMore') }}</button>
    </template>
  </div>
</template>

<style scoped>
.cnt { margin-left: 8px; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 10px; background: var(--red); color: #fff; font-size: 11px; display: inline-grid; place-items: center; }
.item.hot { border-color: color-mix(in srgb, var(--accent) 45%, var(--line)); box-shadow: 0 6px 20px color-mix(in srgb, var(--accent) 12%, transparent); }
.depots { display: flex; flex-wrap: wrap; gap: 6px; margin: 8px 0 2px; }
.dp { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 99px; background: var(--blue-50); color: var(--accent); font-size: 12px; font-weight: 600; }
.oa { display: flex; align-items: center; gap: 10px; margin: 10px 0 8px; padding: 10px 12px; border-radius: 12px; background: var(--bg); }
.oa-c { display: grid; gap: 1px; min-width: 0; }
.oa-c small { font-size: 11px; color: var(--muted); }
.oa-c b { font-size: 14px; font-weight: 800; letter-spacing: -0.01em; }
.arrow { color: var(--muted); flex: none; }
.diff { margin-left: auto; padding: 4px 10px; border-radius: 99px; font-size: 12.5px; font-weight: 800; }
.diff.up { background: #fdeaea; color: #b4232a; }
.diff.down { background: #e4f6ee; color: #0b7a4b; }
</style>
