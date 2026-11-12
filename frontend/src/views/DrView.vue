<script setup>
import { t } from '../i18n'
import { useScrolled } from '../composables/useScrolled'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import UserAvatar from '../components/UserAvatar.vue'
import StateBox from '../components/StateBox.vue'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'
import { angka, tanggal } from '../utils/format'

const scrolled = useScrolled()
const router = useRouter()
const STATUS = [['1', 'Admin Finance'], ['1.5', 'Pending Due AR'], ['2', 'Verifikasi BM'], ['3', 'Verifikasi Purchasing'], ['4', 'Verifikasi COO'], ['5', 'Verifikasi CEO'], ['6', 'Terverifikasi'], ['7', 'Purchase Order'], ['8', 'Closed']]
const tab = ref('wait')
const q = ref('')
const f = reactive({ status: '', cabang: '', from: '', to: '' })
const draft = reactive({ ...f })
const sheet = ref(false)
const items = ref([])
const cabang = ref([])
const meta = ref({ page: 1, last: 1, total: 0 })
const waiting = ref(0)
const loading = ref(true)
const more = ref(false)
const error = ref('')
let seq = 0
let timer

const active = computed(() => ['status', 'cabang', 'from', 'to'].filter((k) => f[k]).length)

async function load(page = 1) {
  const my = ++seq
  page === 1 ? (loading.value = true) : (more.value = true)
  error.value = ''
  try {
    const status = tab.value === 'wait' ? '3' : f.status || undefined
    const r = await syop.listDr({ page, per_page: 15, q: q.value.trim() || undefined, status, cabang: f.cabang || undefined, from: f.from || undefined, to: f.to || undefined })
    if (my !== seq) return
    items.value = page === 1 ? r.data : [...items.value, ...r.data]
    meta.value = r.meta
    waiting.value = r.waiting
    cabang.value = r.cabang
  } catch (e) { if (my === seq) error.value = errorMessage(e) } finally { if (my === seq) { loading.value = false; more.value = false } }
}
const apply = () => { Object.assign(f, draft); sheet.value = false; load(1) }
const reset = () => { Object.keys(draft).forEach((k) => { draft[k] = ''; f[k] = '' }); sheet.value = false; load(1) }
const pill = (s) => (s.disposisi === 8 ? 'pill-no' : s.disposisi >= 6 ? 'pill-ok' : 'pill-wait')
watch(q, () => { clearTimeout(timer); timer = setTimeout(() => load(1), 350) })
watch(tab, () => load(1))
onMounted(() => load(1))
</script>

<template>
  <div class="page">
    <div class="page-head" :class="{ stuck: scrolled }">
      <div>
        <h1 class="page-title">{{ t('dr.title') }}</h1>
        <p class="page-sub">{{ t('dr.sub') }}</p>
      </div>
      <router-link :to="{ name: 'profil' }" class="avatar" :aria-label="t('common.profile')"><UserAvatar /><i class="dot" /></router-link>
    </div>

    <div class="bar2">
      <div class="chips" role="tablist" style="margin:0;flex:1">
        <button class="chip" :class="{ on: tab === 'wait' }" role="tab" :aria-selected="tab === 'wait'" @click="tab = 'wait'">{{ t('dr.tab.wait') }}<b v-if="waiting" class="cnt">{{ waiting }}</b></button>
        <button class="chip" :class="{ on: tab === 'all' }" role="tab" :aria-selected="tab === 'all'" @click="tab = 'all'">{{ t('dr.tab.all') }}</button>
      </div>
      <button v-if="tab === 'all'" class="fbtn" :class="{ on: active }" :aria-label="t('inv.filter')" @click="Object.assign(draft, f); sheet = true"><Icon name="filter" :size="20" /><b v-if="active">{{ active }}</b></button>
    </div>
    <label class="search" style="margin-bottom:14px"><Icon name="search" :size="20" /><input v-model="q" type="search" :aria-label="t('dr.search')" :placeholder="t('dr.search')" /></label>

    <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load(1)" />
    <div v-else-if="loading" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:190px" /></div>
    <StateBox v-else-if="!items.length" icon="check" :title="t('dr.none')" :text="t('dr.noneText')" />
    <template v-else>
      <div class="stack">
        <button v-for="(d, i) in items" :key="d.id" class="item rise" :class="{ hot: d.menunggu }" :style="{ '--i': i % 8 }" @click="router.push({ name: 'dr-detail', params: { id: d.id } })">
          <div class="item-top">
            <div class="item-who"><span class="ico-box tinted tone-violet"><Icon name="cart" :size="18" /></span><span>{{ d.nomor_pr }}</span></div>
            <span class="pill" :class="pill(d)">{{ d.status }}</span>
          </div>
          <div class="item-title">{{ d.customer.join(', ') || '-' }}</div>
          <div class="item-meta">{{ d.cabang }} · {{ tanggal(d.tanggal_pr) }}</div>
          <div v-for="(po, k) in d.nomor_po" :key="k" class="line">
            <span>{{ po }}</span><span>{{ angka(d.volume[k]) }} L</span>
            <small>{{ t('dr.l.etl') }} {{ tanggal(d.tgl_loading[k]) }} · {{ t('dr.l.ship') }} {{ tanggal(d.tgl_kirim[k]) }}</small>
          </div>
        </button>
      </div>
      <button v-if="meta.page < meta.last" class="btn btn-ghost btn-block" style="margin-top:16px" :disabled="more" @click="load(meta.page + 1)">{{ more ? t('common.loading') : t('common.loadMore') }}</button>
    </template>

    <div v-if="sheet" class="sheet-back" @click.self="sheet = false">
      <div class="sheet" role="dialog" aria-modal="true" :aria-label="t('inv.filter')">
        <div class="grab" />
        <h2 class="sheet-t" style="margin-bottom:14px">{{ t('inv.filter') }}</h2>
        <div class="field"><label for="ds">{{ t('dr.f.status') }}</label>
          <select id="ds" v-model="draft.status" class="sel"><option value="">{{ t('inv.all') }}</option><option v-for="s in STATUS" :key="s[0]" :value="s[0]">{{ s[1] }}</option></select></div>
        <div class="field"><label for="dc">{{ t('dr.f.cabang') }}</label>
          <select id="dc" v-model="draft.cabang" class="sel"><option value="">{{ t('inv.all') }}</option><option v-for="c in cabang" :key="c.id" :value="String(c.id)">{{ c.nama }}</option></select></div>
        <div class="two">
          <div class="field"><label for="df">{{ t('dr.f.from') }}</label><input id="df" v-model="draft.from" type="date" class="sel" /></div>
          <div class="field"><label for="dt">{{ t('dr.f.to') }}</label><input id="dt" v-model="draft.to" type="date" class="sel" /></div>
        </div>
        <div class="sheet-act" style="margin-top:6px">
          <button class="btn btn-ghost" @click="reset">{{ t('inv.reset') }}</button>
          <button class="btn btn-primary" @click="apply">{{ t('inv.apply') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.bar2 { display: flex; gap: 10px; align-items: center; margin-bottom: 12px; }
.fbtn { position: relative; flex: none; width: 48px; height: 48px; border-radius: 14px; background: var(--card); border: 1px solid var(--line); display: grid; place-items: center; color: var(--ink-2); }
.fbtn.on { color: var(--accent); border-color: var(--focus); }
.fbtn b { position: absolute; top: -6px; right: -6px; min-width: 20px; height: 20px; padding: 0 5px; border-radius: 10px; background: var(--red); color: #fff; font-size: 11px; display: grid; place-items: center; }
.cnt { margin-left: 8px; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 10px; background: var(--red); color: #fff; font-size: 11px; display: inline-grid; place-items: center; }
.item.hot { border-color: var(--focus); box-shadow: 0 0 0 1px var(--focus), var(--shadow); }
.stack, .stack > * { min-width: 0; max-width: 100%; }
.item { width: 100%; box-sizing: border-box; text-align: left; overflow: hidden; }
.item :deep(.item-top) { flex-wrap: wrap; gap: 6px 10px; }
.item :deep(.item-who) { min-width: 0; flex: 1 1 auto; }
.item :deep(.item-who span:last-child), .item-title, .item-meta { min-width: 0; overflow-wrap: anywhere; word-break: break-word; }
.item :deep(.pill) { white-space: normal; text-align: center; }
.line > span { min-width: 0; overflow-wrap: anywhere; word-break: break-word; }
.line > span:nth-child(2) { white-space: nowrap; }
.line { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 0 10px; margin-top: 8px; padding-top: 8px; border-top: 1px dashed var(--line); font-size: 13px; font-weight: 600; }
.line small { grid-column: 1 / -1; color: var(--muted); font-weight: 500; font-size: 12px; }
.two { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 10px; }
.sel { width: 100%; min-height: 46px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; }
</style>
