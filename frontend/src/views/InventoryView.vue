<script setup>
import { t } from '../i18n'
import { useScrolled } from '../composables/useScrolled'
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import Icon from '../components/Icon.vue'
import UserAvatar from '../components/UserAvatar.vue'
import StateBox from '../components/StateBox.vue'
import OfflineNote from '../components/OfflineNote.vue'
import { readCache, writeCache } from '../composables/useListCache'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'
import { angka, tanggal } from '../utils/format'

const scrolled = useScrolled()
const KEYS = ['produk', 'cabang', 'vendor', 'terminal', 'kategori']
const f = reactive({ produk: '', cabang: '', vendor: '', terminal: '', kategori: '' })
const draft = reactive({ ...f })
const q = ref('')
const items = ref([])
const summary = ref({ liter: 0, po: 0, per_produk: [] })
const meta = ref({ page: 1, last: 1, total: 0 })
const opts = ref(null)
const sheet = ref(false)
const loading = ref(true)
const more = ref(false)
const error = ref('')
const staleAt = ref(0)
let seq = 0
let timer

const active = computed(() => KEYS.filter((k) => f[k]).length)
const cacheKey = () => 'stock_' + KEYS.map((k) => f[k]).join('_') + '_' + q.value.trim()
const kat = (k) => ({ 1: 'Terminal', 2: 'Dispenser', 3: 'Truck' }[k] || '')
const lokasi = (it) => [it.nama_terminal + (it.tanki_terminal ? ' - ' + it.tanki_terminal : ''), it.lokasi_terminal].filter(Boolean).join(', ')
const share = (l) => (summary.value.liter ? Math.max(4, Math.round((l / summary.value.liter) * 100)) : 0)

async function load(page = 1) {
  const my = ++seq
  page === 1 ? (loading.value = true) : (more.value = true)
  error.value = ''
  try {
    const params = { page, per_page: 15, q: q.value.trim() || undefined }
    KEYS.forEach((k) => { if (f[k]) params[k] = f[k] })
    const r = await syop.listStock(params)
    if (my !== seq) return
    items.value = page === 1 ? r.data : [...items.value, ...r.data]
    meta.value = r.meta
    summary.value = r.summary
    staleAt.value = 0
    if (page === 1) writeCache(cacheKey(), { items: r.data, meta: r.meta, summary: r.summary })
  } catch (e) {
    if (my !== seq) return
    const c = !e.response && page === 1 ? readCache(cacheKey()) : null
    if (c) { items.value = c.items; meta.value = c.meta; summary.value = c.summary; staleAt.value = c.t } else error.value = errorMessage(e)
  } finally {
    if (my === seq) { loading.value = false; more.value = false }
  }
}

async function openSheet() {
  Object.assign(draft, f)
  sheet.value = true
  if (!opts.value) { try { opts.value = await syop.stockFilters() } catch { opts.value = { produk: [], cabang: [], vendor: [], terminal: [], kategori: [] } } }
}
function apply() { Object.assign(f, draft); sheet.value = false; load(1) }
function reset() { KEYS.forEach((k) => { draft[k] = ''; f[k] = '' }); sheet.value = false; load(1) }
function pickProduk(id) { f.produk = f.produk === String(id) ? '' : String(id); load(1) }

watch(q, () => { clearTimeout(timer); timer = setTimeout(() => load(1), 350) })
const onOnline = () => { if (staleAt.value) load(1) }
onMounted(() => { load(1); window.addEventListener('online', onOnline) })
onBeforeUnmount(() => { clearTimeout(timer); window.removeEventListener('online', onOnline) })
</script>

<template>
  <div class="page">
    <div class="page-head" :class="{ stuck: scrolled }">
      <div>
        <h1 class="page-title">{{ t('inv.title') }}</h1>
        <p class="page-sub">{{ t('inv.sub') }}</p>
      </div>
      <router-link :to="{ name: 'profil' }" class="avatar" :aria-label="t('common.profile')"><UserAvatar /><i class="dot" /></router-link>
    </div>

    <section class="card sum" :aria-label="t('inv.total')">
      <div class="lbl">{{ t('inv.total') }}</div>
      <div class="big">{{ angka(summary.liter) }}<small>L</small></div>
      <div class="sub">{{ t('inv.poCount', { n: summary.po }) }}</div>
      <div v-if="summary.per_produk.length" class="bars">
        <button v-for="p in summary.per_produk" :key="p.id_produk" class="bar" :class="{ on: f.produk === String(p.id_produk) }" @click="pickProduk(p.id_produk)">
          <span class="bn">{{ p.produk }}</span>
          <span class="bt"><i :style="{ width: share(p.liter) + '%' }" /></span>
          <b>{{ angka(p.liter) }} L</b>
        </button>
      </div>
    </section>

    <div class="frow">
      <label class="search"><Icon name="search" :size="20" /><input v-model="q" type="search" :aria-label="t('inv.searchAria')" :placeholder="t('inv.search')" /></label>
      <button class="fbtn" :class="{ on: active }" :aria-label="t('inv.filter')" @click="openSheet">
        <Icon name="filter" :size="20" /><b v-if="active">{{ active }}</b>
      </button>
    </div>

    <OfflineNote v-if="staleAt" :at="staleAt" @retry="load(1)" />
    <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load(1)" />
    <div v-else-if="loading" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:150px" /></div>
    <StateBox v-else-if="!items.length" icon="box" :title="t('inv.empty')" :text="t('inv.emptyText')" :action="active || q ? t('inv.resetFilter') : t('common.reload')" @action="active || q ? (q = '', reset()) : load(1)" />
    <template v-else>
      <div class="stack">
        <article v-for="(it, i) in items" :key="it.id_po_receive" class="item stock rise" :style="{ '--i': i % 8 }">
          <div class="item-top">
            <div class="item-who">
              <span class="ico-box tinted tone-orange"><Icon name="box" :size="18" /></span>
              <span>{{ it.nomor_po }}</span>
            </div>
            <span v-if="kat(it.kategori_terminal)" class="pill pill-ok">{{ kat(it.kategori_terminal) }}</span>
          </div>
          <div class="lit">{{ angka(it.sisa_inven) }}<small>L</small></div>
          <div class="item-title">{{ it.jenis_produk }} · {{ it.merk_dagang }}</div>
          <div class="item-meta">{{ it.nama_vendor }}<br />{{ lokasi(it) }}</div>
          <div class="item-foot">
            <span class="item-meta">{{ it.nama_cabang }} · {{ tanggal(it.tgl_po) }}</span>
            <span class="hb">Rp {{ angka(it.harga_tebus) }}<small>/L</small></span>
          </div>
        </article>
      </div>
      <button v-if="meta.page < meta.last && !staleAt" class="btn btn-ghost btn-block" style="margin-top:16px" :disabled="more" @click="load(meta.page + 1)">
        {{ more ? t('common.loading') : t('common.loadMore') }}
      </button>
    </template>

    <div v-if="sheet" class="sheet-back" @click.self="sheet = false">
      <div class="sheet" role="dialog" aria-modal="true" :aria-label="t('inv.filter')">
        <div class="grab" />
        <h2 class="sheet-t" style="margin-bottom:14px">{{ t('inv.filter') }}</h2>
        <div v-for="k in KEYS" :key="k" class="field">
          <label :for="'f-' + k">{{ t('inv.f.' + k) }}</label>
          <select :id="'f-' + k" v-model="draft[k]" class="sel">
            <option value="">{{ t('inv.all') }}</option>
            <option v-for="o in (opts?.[k] || [])" :key="o.id" :value="String(o.id)">{{ o.label }}</option>
          </select>
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
.sum { padding: 16px 18px; margin-bottom: 14px; border-top: 3px solid transparent; background: linear-gradient(var(--card), var(--card)) padding-box, var(--brand-grad) border-box; }
.lbl { font-size: 12.5px; color: var(--muted); font-weight: 600; }
.big { font-size: 34px; font-weight: 800; letter-spacing: -0.03em; color: var(--accent); line-height: 1.15; }
.big small, .lit small { font-size: 15px; margin-left: 4px; color: var(--muted); font-weight: 700; }
.sub { font-size: 13px; color: var(--ink-2); }
.bars { display: grid; gap: 6px; margin-top: 12px; }
.bar { display: grid; grid-template-columns: 1fr auto; gap: 4px 10px; text-align: left; padding: 8px 10px; border-radius: 12px; border: 1px solid transparent; }
.bar.on { background: var(--blue-50); border-color: var(--line); }
.bn { font-size: 12.5px; font-weight: 600; color: var(--ink-2); min-width: 0; overflow-wrap: anywhere; }
.bar b { font-size: 12.5px; }
.bt { grid-column: 1 / -1; height: 6px; border-radius: 3px; background: var(--blue-50); overflow: hidden; }
.bt i { display: block; height: 100%; border-radius: 3px; background: var(--brand-grad); }
.frow { display: flex; gap: 10px; align-items: center; margin-bottom: 14px; }
.frow .search { flex: 1; min-width: 0; }
.fbtn { position: relative; flex: none; width: 48px; height: 48px; border-radius: 14px; background: var(--card); border: 1px solid var(--line); display: grid; place-items: center; color: var(--ink-2); }
.fbtn.on { color: var(--accent); border-color: var(--focus); }
.fbtn b { position: absolute; top: -6px; right: -6px; min-width: 20px; height: 20px; padding: 0 5px; border-radius: 10px; background: var(--red); color: #fff; font-size: 11px; display: grid; place-items: center; }
.stock { cursor: default; }
.lit { font-size: 26px; font-weight: 800; letter-spacing: -0.02em; color: var(--accent); margin: 2px 0 4px; }
.hb { font-weight: 700; font-size: 13px; }
.hb small { color: var(--muted); font-weight: 600; }
.sel { width: 100%; min-height: 46px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; }
</style>
