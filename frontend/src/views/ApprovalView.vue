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
import { useAuth } from '../stores/auth'
import { useInbox } from '../stores/inbox'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'
import { readCache, writeCache } from '../composables/useListCache'
import { angka, rupiahRingkas, tanggal } from '../utils/format'

const scrolled = useScrolled()
const route = useRoute()
const router = useRouter()
const auth = useAuth()
const inbox = useInbox()

const has = (m) => !Array.isArray(auth.user?.modules) || auth.user.modules.includes(m)
const seg = ref(route.query.seg === 'pen' ? 'pen' : 'all') // 'all' = semua yang butuh approval, dikelompokkan per menu
const grp = ref({ po: [], si: [], ti: [], dr: [] })
const grpLoading = ref(false)
const items = ref([])
const meta = ref({ page: 1, last: 1, total: 0 })
const q = ref(String(route.query.q || ''))
// 'asc' = terlama dulu. OM (peran 6) default terlama dulu; peran lain terbaru dulu. Bisa diubah lewat chip.
const sort = ref(route.query.sort === 'asc' || (route.query.sort !== 'desc' && auth.user?.id_role === 6) ? 'asc' : 'desc')
const loading = ref(true)
const more = ref(false)
const error = ref('')
const staleAt = ref(0) // > 0 = sedang menampilkan salinan offline
let debounce
let seq = 0 // abaikan respons lama bila pengguna mengetik cepat

async function load(page = 1) {
  const my = ++seq
  page === 1 ? (loading.value = true) : (more.value = true)
  error.value = ''
  try {
    const r = await syop.listPending({ page, per_page: seg.value === 'all' ? 5 : 15, sort: sort.value, q: q.value.trim() || undefined })
    if (my !== seq) return
    items.value = page === 1 ? r.data : [...items.value, ...r.data]
    meta.value = r.meta
    staleAt.value = 0
    if (!q.value.trim()) inbox.total = r.meta.total
    if (page === 1 && seg.value === 'pen' && !q.value.trim()) writeCache(`pending_${sort.value}`, { items: r.data, meta: r.meta })
  } catch (e) {
    if (my !== seq) return
    // Tanpa koneksi: tampilkan salinan terakhir (hanya baca) bila ada.
    const c = !e.response && page === 1 && !q.value.trim() ? readCache(`pending_${sort.value}`) : null
    if (c) { items.value = c.items; meta.value = c.meta; staleAt.value = c.t } else error.value = errorMessage(e)
  } finally {
    if (my === seq) { loading.value = false; more.value = false }
  }
}

// Ringkasan "Semua": PO dan SI yang menunggu (maks. 5 teratas); penawaran memakai daftar utama di bawah.
async function loadGroups() {
  grpLoading.value = true
  const [po, si, ti, dr] = await Promise.all([
    has('po') ? syop.listPo({ tab: 'wait', per_page: 5 }).catch(() => null) : null,
    has('shipping') ? syop.listShipping({ tab: 'wait', per_page: 5 }).catch(() => null) : null,
    has('trucking') ? syop.listTrucking({ tab: 'wait', per_page: 5 }).catch(() => null) : null,
    has('dr') ? syop.listDr({ status: '3', per_page: 5 }).catch(() => null) : null,
  ])
  grp.value = { po: po?.data || [], si: si?.data || [], ti: ti?.data || [], dr: dr?.data || [], poN: po?.meta?.total ?? po?.waiting ?? 0, siN: si?.meta?.total ?? si?.waiting ?? 0 }
  if (po?.waiting != null) inbox.po = po.waiting
  if (si?.waiting != null) inbox.shipping = si.waiting
  if (ti?.waiting != null) inbox.trucking = ti.waiting
  if (dr?.waiting != null) inbox.dr = dr.waiting
  grpLoading.value = false
}
const setSeg = (v) => { seg.value = v; router.replace({ query: v === 'all' ? {} : { seg: v } }); if (v === 'pen') load(1) }
const everything = () => { load(1); if (seg.value === 'all') loadGroups() }

watch(q, () => {
  clearTimeout(debounce)
  debounce = setTimeout(() => load(1), 350)
})
watch(sort, () => load(1))

const onOnline = () => { if (staleAt.value) everything() } // koneksi kembali: segarkan otomatis
const open = (it) => router.push({ name: 'approval-detail', params: { id: it.id_penawaran } })
onMounted(() => { everything(); window.addEventListener('online', onOnline) })
onBeforeUnmount(() => window.removeEventListener('online', onOnline))
</script>

<template>
  <div class="page">
    <div class="page-head" :class="{ stuck: scrolled }">
      <div>
        <h1 class="page-title">{{ t('appr.title') }}</h1>
        <p class="page-sub">{{ t('appr.sub') }}</p>
      </div>
      <router-link :to="{ name: 'profil' }" class="avatar" :aria-label="t('common.profile')"><UserAvatar /><i class="dot" /></router-link>
    </div>

    <div class="chips" role="tablist">
      <button class="chip" :class="{ on: seg === 'all' }" role="tab" :aria-selected="seg === 'all'" @click="setSeg('all'); load(1); loadGroups()">{{ t('appr.all', { n: inbox.all }) }}</button>
      <button v-if="has('penawaran')" class="chip" :class="{ on: seg === 'pen' }" role="tab" :aria-selected="seg === 'pen'" @click="setSeg('pen')">{{ t('appr.chip', { n: inbox.total }) }}</button>
      <button v-if="has('po')" class="chip" @click="router.push({ name: 'po' })">PO ({{ inbox.po }})</button>
      <button v-if="has('shipping')" class="chip" @click="router.push({ name: 'shipping' })">SI ({{ inbox.shipping }})</button>
      <button v-if="has('trucking')" class="chip" @click="router.push({ name: 'trucking' })">TI ({{ inbox.trucking }})</button>
      <button v-if="has('dr')" class="chip" @click="router.push({ name: 'dr' })">DR ({{ inbox.dr }})</button>
      <button v-if="has('oa')" class="chip" @click="router.push({ name: 'oa' })">OA ({{ inbox.oa }})</button>
      <button v-if="has('forecast_bm')" class="chip" @click="router.push({ name: 'forecast-bm' })">Forecast ({{ inbox.forecast_bm }})</button>
      <button v-if="has('forecast_om')" class="chip" @click="router.push({ name: 'forecast-om' })">Forecast ({{ inbox.forecast_om + inbox.forecast_revisi }})</button>
      <button v-if="has('forecast')" class="chip" @click="router.push({ name: 'forecast' })">Forecast ({{ inbox.forecast + inbox.forecast_request }})</button>
      <button v-if="seg === 'pen'" class="chip" :class="{ on: sort === 'asc' }" :aria-pressed="sort === 'asc'" @click="sort = sort === 'asc' ? 'desc' : 'asc'">
        <Icon name="clock" :size="16" />&nbsp;{{ t('appr.oldest') }}
      </button>
    </div>

    <label v-if="seg === 'pen'" class="search"><Icon name="search" :size="20" /><input v-model="q" type="search" :aria-label="t('appr.searchAria')" :placeholder="t('appr.search')" /></label>

    <div style="height:16px" />

    <OfflineNote v-if="staleAt" :at="staleAt" @retry="load(1)" />

    <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load(1)" />

    <div v-else-if="loading" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:172px" /></div>

    <StateBox v-else-if="!items.length && (seg === 'pen' || (!grp.po.length && !grp.si.length && !grp.ti.length && !grp.dr.length && !grpLoading))" icon="check"
              :title="q.trim() ? t('appr.noResult') : t('appr.allDecided')"
              :text="q.trim() ? t('appr.tryOther') : t('home.nonePending')"
              :action="t('common.reload')" @action="load(1)" />

    <template v-else-if="items.length">
      <template v-if="seg === 'all'">
        <h2 v-if="has('penawaran') && items.length" class="gh">{{ t('home.m.penawaran') }} <small>{{ inbox.total }}</small></h2>
      </template>
      <div class="stack">
        <PenawaranItem v-for="(p, i) in items" :key="p.id_penawaran" :style="{ '--i': i % 8 }" :item="p" @open="open" />
      </div>
      <button v-if="seg === 'all' && meta.total > items.length" class="btn btn-ghost btn-block" style="margin-top:10px" @click="setSeg('pen')">{{ t('home.seeAll') }}</button>
      <button v-if="seg === 'pen' && meta.page < meta.last && !staleAt" class="btn btn-ghost btn-block" style="margin-top:16px" :disabled="more" @click="load(meta.page + 1)">
        {{ more ? t('common.loading') : t('common.loadMore') }}
      </button>
    </template>

    <template v-if="seg === 'all' && !loading && !error">
      <section v-if="grp.po.length" class="grp">
        <h2 class="gh">{{ t('home.m.po') }} <small>{{ inbox.po }}</small></h2>
        <div class="stack">
          <button v-for="(p, i) in grp.po" :key="'po' + p.id" class="item rise" :style="{ '--i': i }" @click="router.push({ name: 'po-detail', params: { id: p.id } })">
            <div class="item-top"><div class="item-who"><span class="ico-box tinted tone-rose"><Icon name="po" :size="18" /></span><span>{{ p.nomor_po }}</span></div></div>
            <div class="item-title">{{ p.nama_vendor }}</div>
            <div class="item-meta">{{ p.jenis_produk }} · {{ p.merk_dagang }}<br />{{ p.nama_terminal }}</div>
            <div class="item-foot"><div><div class="price">≈ {{ rupiahRingkas(p.total_order) }}</div><small class="unit">{{ angka(p.volume_po) }} L</small></div><span class="item-meta">{{ tanggal(p.tanggal) }}</span></div>
          </button>
        </div>
        <button v-if="inbox.po > grp.po.length" class="btn btn-ghost btn-block" style="margin-top:10px" @click="router.push({ name: 'po' })">{{ t('home.seeAll') }}</button>
      </section>
      <section v-if="grp.si.length" class="grp">
        <h2 class="gh">SI <small>{{ inbox.shipping }}</small></h2>
        <div class="stack">
          <button v-for="(x, i) in grp.si" :key="'si' + x.id" class="item rise" :style="{ '--i': i }" @click="router.push({ name: 'shipping-detail', params: { id: x.id } })">
            <div class="item-top"><div class="item-who"><span class="ico-box tinted tone-orange"><Icon name="ship" :size="18" /></span><span>{{ x.nomor_si || x.nomor_req }}</span></div></div>
            <div class="item-title">{{ x.nama_vendor }}</div>
            <div class="item-meta">{{ x.nomor_po }} · {{ x.cargo_name }}<br />{{ x.loading_nama }} → {{ x.discharging_nama }}</div>
            <div class="item-foot"><div class="price">{{ angka(x.quantity) }} L</div><span class="item-meta">ETA {{ tanggal(x.etl_date_first) }}</span></div>
          </button>
        </div>
        <button v-if="inbox.shipping > grp.si.length" class="btn btn-ghost btn-block" style="margin-top:10px" @click="router.push({ name: 'shipping' })">{{ t('home.seeAll') }}</button>
      </section>
      <section v-if="grp.ti.length" class="grp">
        <h2 class="gh">TI <small>{{ inbox.trucking }}</small></h2>
        <div class="stack">
          <button v-for="(x, i) in grp.ti" :key="'ti' + x.id" class="item rise" :style="{ '--i': i }" @click="router.push({ name: 'trucking-detail', params: { id: x.id } })">
            <div class="item-top"><div class="item-who"><span class="ico-box tinted tone-orange"><Icon name="truck" :size="18" /></span><span>{{ x.nomor_ti }}</span></div></div>
            <div class="item-title">{{ x.nama_transportir || '-' }}</div>
            <div class="item-meta">{{ x.load_port }}<br />→ {{ x.discharge_port }}</div>
            <div class="item-foot"><div class="price">{{ angka(x.volume) }} L</div><span class="item-meta">ETL {{ tanggal(x.etl_awal) }}</span></div>
          </button>
        </div>
        <button v-if="inbox.trucking > grp.ti.length" class="btn btn-ghost btn-block" style="margin-top:10px" @click="router.push({ name: 'trucking' })">{{ t('home.seeAll') }}</button>
      </section>
      <section v-if="grp.dr.length" class="grp">
        <h2 class="gh">DR <small>{{ inbox.dr }}</small></h2>
        <div class="stack">
          <button v-for="(x, i) in grp.dr" :key="'dr' + x.id" class="item rise" :style="{ '--i': i }" @click="router.push({ name: 'dr-detail', params: { id: x.id } })">
            <div class="item-top"><div class="item-who"><span class="ico-box tinted tone-violet"><Icon name="cart" :size="18" /></span><span>{{ x.nomor_pr }}</span></div></div>
            <div class="item-title">{{ x.customer.join(', ') || '-' }}</div>
            <div class="item-meta">{{ x.cabang }} · {{ tanggal(x.tanggal_pr) }}</div>
            <div class="item-foot"><div class="price">{{ angka(x.volume.reduce((s, v) => s + v, 0)) }} L</div><span class="item-meta">{{ x.status }}</span></div>
          </button>
        </div>
        <button v-if="inbox.dr > grp.dr.length" class="btn btn-ghost btn-block" style="margin-top:10px" @click="router.push({ name: 'dr' })">{{ t('home.seeAll') }}</button>
      </section>
    </template>
  </div>
</template>

<style scoped>
.gh { display: flex; align-items: center; gap: 8px; margin: 18px 0 10px; font-size: 14px; font-weight: 800; color: var(--ink-2); }
.gh small { min-width: 20px; height: 20px; padding: 0 6px; border-radius: 10px; background: var(--badge); color: #fff; font-size: 11px; display: inline-grid; place-items: center; }
</style>
