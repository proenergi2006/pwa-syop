<script setup>
import { t } from '../i18n'
import { useScrolled } from '../composables/useScrolled'
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import UserAvatar from '../components/UserAvatar.vue'
import StateBox from '../components/StateBox.vue'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'
import { angka, tanggal } from '../utils/format'

const scrolled = useScrolled()
const router = useRouter()
const f = reactive({ periode: '', area: '', produk: '' })
const draft = reactive({ ...f })
const items = ref([])
const meta = ref({ page: 1, last: 1, total: 0 })
const opts = ref(null)
const sheet = ref(false)
const loading = ref(true)
const more = ref(false)
const error = ref('')
let seq = 0

const active = computed(() => ['periode', 'area', 'produk'].filter((k) => f[k]).length)

async function load(page = 1) {
  const my = ++seq
  page === 1 ? (loading.value = true) : (more.value = true)
  error.value = ''
  try {
    const r = await syop.listHarga({ page, per_page: 15, periode: f.periode || undefined, area: f.area || undefined, produk: f.produk || undefined })
    if (my !== seq) return
    items.value = page === 1 ? r.data : [...items.value, ...r.data]
    meta.value = r.meta
  } catch (e) { if (my === seq) error.value = errorMessage(e) } finally { if (my === seq) { loading.value = false; more.value = false } }
}
async function openSheet() {
  Object.assign(draft, f)
  sheet.value = true
  if (!opts.value) { try { opts.value = await syop.hargaOptions() } catch { opts.value = { area: [], produk: [] } } }
}
const apply = () => { Object.assign(f, draft); sheet.value = false; load(1) }
const reset = () => { ['periode', 'area', 'produk'].forEach((k) => { draft[k] = ''; f[k] = '' }); sheet.value = false; load(1) }
const keyOf = (h) => ({ awal: h.periode_awal, akhir: h.periode_akhir, area: h.area, produk: h.produk })
onMounted(() => load(1))
</script>

<template>
  <div class="page">
    <div class="page-head" :class="{ stuck: scrolled }">
      <div>
        <h1 class="page-title">{{ t('hrg.title') }}</h1>
        <p class="page-sub">{{ t('hrg.sub') }}</p>
      </div>
      <router-link :to="{ name: 'profil' }" class="avatar" :aria-label="t('common.profile')"><UserAvatar /><i class="dot" /></router-link>
    </div>

    <div class="bar2">
      <button class="btn btn-primary" @click="router.push({ name: 'harga-form' })"><Icon name="plus" :size="18" /> {{ t('hrg.add') }}</button>
      <button class="fbtn" :class="{ on: active }" :aria-label="t('inv.filter')" @click="openSheet"><Icon name="filter" :size="20" /><b v-if="active">{{ active }}</b></button>
    </div>

    <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load(1)" />
    <div v-else-if="loading" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:150px" /></div>
    <StateBox v-else-if="!items.length" icon="box" :title="t('hrg.empty')" :text="t('hrg.emptyText')" />
    <template v-else>
      <p class="cnt">{{ t('hrg.count', { n: meta.total }) }}</p>
      <div class="stack">
        <button v-for="(h, i) in items" :key="h.periode_awal + h.area + h.produk" class="item rise" :style="{ '--i': i % 8 }" @click="router.push({ name: 'harga-detail', query: keyOf(h) })">
          <div class="item-top">
            <div class="item-who"><span class="ico-box tinted tone-orange"><Icon name="tag" :size="18" /></span><span>{{ tanggal(h.periode_awal) }} – {{ tanggal(h.periode_akhir) }}</span></div>
          </div>
          <div class="item-title">{{ h.nama_area }}</div>
          <div class="item-meta">{{ h.jenis_produk }} - {{ h.merk_dagang }}</div>
          <div class="base">Rp {{ angka(h.harga_normal) }}<small>/L · {{ t('hrg.base') }}</small></div>
          <div class="tiers">
            <span><small>BM</small>{{ angka(h.harga_sm) }}</span>
            <span><small>OM</small>{{ angka(h.harga_om) }}</span>
            <span><small>COO</small>{{ angka(h.harga_coo) }}</span>
            <span><small>CEO</small>{{ angka(h.harga_ceo) }}</span>
          </div>
        </button>
      </div>
      <button v-if="meta.page < meta.last" class="btn btn-ghost btn-block" style="margin-top:16px" :disabled="more" @click="load(meta.page + 1)">{{ more ? t('common.loading') : t('common.loadMore') }}</button>
    </template>

    <div v-if="sheet" class="sheet-back" @click.self="sheet = false">
      <div class="sheet" role="dialog" aria-modal="true" :aria-label="t('inv.filter')">
        <div class="grab" />
        <h2 class="sheet-t" style="margin-bottom:14px">{{ t('inv.filter') }}</h2>
        <div class="field"><label for="fp">{{ t('hrg.f.periode') }}</label><input id="fp" v-model="draft.periode" type="date" class="sel" /></div>
        <div class="field">
          <label for="fa">{{ t('inv.f.cabang') }}</label>
          <select id="fa" v-model="draft.area" class="sel"><option value="">{{ t('inv.all') }}</option><option v-for="o in opts?.area || []" :key="o.id" :value="String(o.id)">{{ o.label }}</option></select>
        </div>
        <div class="field">
          <label for="fr">{{ t('inv.f.produk') }}</label>
          <select id="fr" v-model="draft.produk" class="sel"><option value="">{{ t('inv.all') }}</option><option v-for="o in opts?.produk || []" :key="o.id" :value="String(o.id)">{{ o.label }}</option></select>
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
.bar2 { display: flex; gap: 10px; align-items: center; margin-bottom: 14px; }
.bar2 .btn { flex: 1; }
.fbtn { position: relative; flex: none; width: 48px; height: 48px; border-radius: 14px; background: var(--card); border: 1px solid var(--line); display: grid; place-items: center; color: var(--ink-2); }
.fbtn.on { color: var(--accent); border-color: var(--focus); }
.fbtn b { position: absolute; top: -6px; right: -6px; min-width: 20px; height: 20px; padding: 0 5px; border-radius: 10px; background: var(--red); color: #fff; font-size: 11px; display: grid; place-items: center; }
.cnt { margin: 0 0 10px; font-size: 12.5px; color: var(--muted); }
.base { font-size: 22px; font-weight: 800; letter-spacing: -0.02em; color: var(--accent); margin: 8px 0 8px; }
.base small { font-size: 12px; color: var(--muted); font-weight: 600; margin-left: 4px; }
.tiers { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; }
.tiers span { display: grid; padding: 6px 8px; border-radius: 10px; background: var(--blue-50); font-size: 12.5px; font-weight: 700; }
.tiers small { font-size: 10.5px; color: var(--muted); font-weight: 600; }
.sel { width: 100%; min-height: 46px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; }
</style>
