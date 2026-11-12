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
import { angka, tanggal } from '../utils/format'

const scrolled = useScrolled()
const router = useRouter()
const route = useRoute()
const tabs = [{ k: 'wait', t: 'ship.tab.wait' }, { k: 'done', t: 'ship.tab.done' }, { k: 'all', t: 'ship.tab.all' }]
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
    const r = await syop.listShipping({ page, per_page: 15, tab: tab.value, q: q.value.trim() || undefined })
    if (my !== seq) return
    items.value = page === 1 ? r.data : [...items.value, ...r.data]
    meta.value = r.meta
    waiting.value = r.waiting
  } catch (e) { if (my === seq) error.value = errorMessage(e) } finally { if (my === seq) { loading.value = false; more.value = false } }
}
const status = (s) => {
  if (s.status == 0) return { t: 'ship.st.req', c: 'pill-wait' }
  if (s.status == 1) return { t: 'ship.st.cfo', c: 'pill-wait' }
  if (s.status == 2) return s.cfo_result == 1 ? { t: 'ship.st.ceo', c: 'pill-wait' } : { t: 'ship.st.cfoNo', c: 'pill-no' }
  return s.ceo_result == 1 ? { t: 'ship.st.ok', c: 'pill-ok' } : { t: 'ship.st.ceoNo', c: 'pill-no' }
}
let timer
watch(q, () => { clearTimeout(timer); timer = setTimeout(() => load(1), 350) })
watch(tab, () => load(1))
onMounted(() => load(1))
</script>

<template>
  <div class="page">
    <div class="page-head" :class="{ stuck: scrolled }">
      <div>
        <h1 class="page-title">{{ t('ship.title') }}</h1>
        <p class="page-sub">{{ t('ship.sub') }}</p>
      </div>
      <router-link :to="{ name: 'profil' }" class="avatar" :aria-label="t('common.profile')"><UserAvatar /><i class="dot" /></router-link>
    </div>

    <div class="chips" role="tablist">
      <button v-for="tb in tabs" :key="tb.k" class="chip" :class="{ on: tab === tb.k }" role="tab" :aria-selected="tab === tb.k" @click="tab = tb.k">
        {{ t(tb.t) }}<b v-if="tb.k === 'wait' && waiting" class="cnt">{{ waiting }}</b>
      </button>
    </div>
    <label class="search" style="margin-bottom:14px"><Icon name="search" :size="20" /><input v-model="q" type="search" :aria-label="t('ship.search')" :placeholder="t('ship.search')" /></label>

    <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load(1)" />
    <div v-else-if="loading" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:170px" /></div>
    <StateBox v-else-if="!items.length" icon="check" :title="tab === 'wait' ? t('ship.noneWait') : t('ship.none')" :text="t('ship.noneText')" />
    <template v-else>
      <div class="stack">
        <button v-for="(s, i) in items" :key="s.id" class="item rise" :style="{ '--i': i % 8 }" @click="router.push({ name: 'shipping-detail', params: { id: s.id } })">
          <div class="item-top">
            <div class="item-who"><span class="ico-box tinted tone-orange"><Icon name="ship" :size="18" /></span><span>{{ s.nomor_si || s.nomor_req }}</span></div>
            <span class="pill" :class="status(s).c">{{ t(status(s).t) }}</span>
          </div>
          <div class="item-title">{{ s.nama_vendor }}</div>
          <div class="item-meta">{{ s.nomor_po }} · {{ s.cargo_name }}<br />{{ s.vessel || '-' }}<template v-if="s.vessel_tb"> / {{ s.vessel_tb }}</template></div>
          <div class="item-meta" style="margin-top:4px">{{ s.loading_nama }} → {{ s.discharging_nama }}</div>
          <div class="item-foot">
            <div class="price">{{ angka(s.quantity) }} L</div>
            <span class="item-meta">ETA {{ tanggal(s.etl_date_first) }}</span>
          </div>
        </button>
      </div>
      <button v-if="meta.page < meta.last" class="btn btn-ghost btn-block" style="margin-top:16px" :disabled="more" @click="load(meta.page + 1)">{{ more ? t('common.loading') : t('common.loadMore') }}</button>
    </template>
  </div>
</template>

<style scoped>
.cnt { margin-left: 8px; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 10px; background: var(--red); color: #fff; font-size: 11px; display: inline-grid; place-items: center; }
</style>
