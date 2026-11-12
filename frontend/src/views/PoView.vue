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
import { angka, rupiahRingkas, tanggal } from '../utils/format'

const scrolled = useScrolled()
const router = useRouter()
const route = useRoute()
const tabs = [{ k: 'wait', t: 'po.tab.wait' }, { k: 'done', t: 'po.tab.done' }, { k: 'all', t: 'po.tab.all' }]
const tab = ref(['wait', 'done', 'all'].includes(route.query.tab) ? route.query.tab : 'wait')
const items = ref([])
const meta = ref({ page: 1, last: 1 })
const waiting = ref(0)
const loading = ref(true)
const more = ref(false)
const error = ref('')
const q = ref('')
let seq = 0
let timer

async function load(page = 1) {
  const my = ++seq
  page === 1 ? (loading.value = true) : (more.value = true)
  error.value = ''
  try {
    const r = await syop.listPo({ page, per_page: 15, tab: tab.value, q: q.value.trim() || undefined })
    if (my !== seq) return
    items.value = page === 1 ? r.data : [...items.value, ...r.data]
    meta.value = r.meta
    waiting.value = r.waiting
  } catch (e) { if (my === seq) error.value = errorMessage(e) } finally { if (my === seq) { loading.value = false; more.value = false } }
}
const status = (p) => {
  if (p.disposisi_po == 2) return p.revert_cfo == 1 ? { t: 'po.st.cfoBack', c: 'pill-no' } : { t: 'po.st.ceo', c: 'pill-wait' }
  if (p.disposisi_po == 4) return { t: 'po.st.ok', c: 'pill-ok' }
  if (p.disposisi_po == 5) return { t: 'po.st.back', c: 'pill-no' }
  return { t: 'po.st.cfo', c: 'pill-wait' }
}
watch(q, () => { clearTimeout(timer); timer = setTimeout(() => load(1), 350) })
watch(tab, () => load(1))
onMounted(() => load(1))
</script>

<template>
  <div class="page">
    <div class="page-head" :class="{ stuck: scrolled }">
      <div>
        <h1 class="page-title">{{ t('po.title') }}</h1>
        <p class="page-sub">{{ t('po.sub') }}</p>
      </div>
      <router-link :to="{ name: 'profil' }" class="avatar" :aria-label="t('common.profile')"><UserAvatar /><i class="dot" /></router-link>
    </div>

    <div class="chips" role="tablist">
      <button v-for="tb in tabs" :key="tb.k" class="chip" :class="{ on: tab === tb.k }" role="tab" :aria-selected="tab === tb.k" @click="tab = tb.k">
        {{ t(tb.t) }}<b v-if="tb.k === 'wait' && waiting" class="cnt">{{ waiting }}</b>
      </button>
    </div>
    <label class="search" style="margin-bottom:14px"><Icon name="search" :size="20" /><input v-model="q" type="search" :aria-label="t('po.search')" :placeholder="t('po.search')" /></label>

    <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load(1)" />
    <div v-else-if="loading" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:170px" /></div>
    <StateBox v-else-if="!items.length" icon="check" :title="tab === 'wait' ? t('po.noneWait') : t('po.none')" :text="t('po.noneText')" />
    <template v-else>
      <div class="stack">
        <button v-for="(p, i) in items" :key="p.id" class="item rise" :style="{ '--i': i % 8 }" @click="router.push({ name: 'po-detail', params: { id: p.id } })">
          <div class="item-top">
            <div class="item-who"><span class="ico-box tinted tone-rose"><Icon name="po" :size="18" /></span><span>{{ p.nomor_po }}</span></div>
            <span class="pill" :class="status(p).c">{{ t(status(p).t) }}</span>
          </div>
          <div class="item-title">{{ p.nama_vendor }}</div>
          <div class="item-meta">{{ p.jenis_produk }} · {{ p.merk_dagang }}<br />{{ p.nama_terminal }}<template v-if="p.tanki_terminal"> - {{ p.tanki_terminal }}</template> · {{ p.jenis_kirim == 1 ? 'Truck' : 'Ship' }}</div>
          <div class="item-foot">
            <div>
              <div class="price">≈ {{ rupiahRingkas(p.total_order) }}</div>
              <small class="unit">Rp {{ angka(p.harga_tebus) }} /L · {{ angka(p.volume_po) }} L</small>
            </div>
            <span class="item-meta">{{ tanggal(p.tanggal) }}</span>
          </div>
        </button>
      </div>
      <button v-if="meta.page < meta.last" class="btn btn-ghost btn-block" style="margin-top:16px" :disabled="more" @click="load(meta.page + 1)">{{ more ? t('common.loading') : t('common.loadMore') }}</button>
    </template>
  </div>
</template>

<style scoped>
.cnt { margin-left: 8px; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 10px; background: var(--red); color: #fff; font-size: 11px; display: inline-grid; place-items: center; }
.unit { display: block; color: var(--muted); font-size: 12px; margin-top: 1px; }
</style>
