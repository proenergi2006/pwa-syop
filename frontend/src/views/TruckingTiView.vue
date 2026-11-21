<script setup>
import { onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useScrolled } from '../composables/useScrolled'
import Icon from '../components/Icon.vue'
import UserAvatar from '../components/UserAvatar.vue'
import StateBox from '../components/StateBox.vue'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'
import { angka, tanggal } from '../utils/format'
import { t } from '../i18n'

const scrolled = useScrolled()
const router = useRouter()
const q = ref('')
const status = ref('')
const items = ref([])
const meta = ref({ page: 1, last: 1, total: 0 })
const loading = ref(true)
const more = ref(false)
const error = ref('')
let seq = 0
let timer
const STATUS = [['', 'Semua status'], ['0', 'Pengajuan'], ['1', 'Terverifikasi CEO'], ['belum_realisasi', 'Belum Realisasi'], ['7', 'Received'], ['6', 'Cancel']]

async function load(page = 1) {
  const my = ++seq
  page === 1 ? (loading.value = true) : (more.value = true)
  error.value = ''
  try {
    const r = await syop.tiList({ page, per_page: 20, q: q.value.trim() || undefined, status: status.value || undefined })
    if (my !== seq) return
    items.value = page === 1 ? r.data : [...items.value, ...r.data]
    meta.value = r.meta
  } catch (e) { if (my === seq) error.value = errorMessage(e) } finally { if (my === seq) { loading.value = false; more.value = false } }
}
watch(q, () => { clearTimeout(timer); timer = setTimeout(() => load(1), 350) })
watch(status, () => load(1))
onMounted(() => load(1))
</script>

<template>
  <div class="page">
    <div class="page-head" :class="{ stuck: scrolled }">
      <div><h1 class="page-title">Trucking Instruction</h1><p class="page-sub">Request Procurement</p></div>
      <router-link :to="{ name: 'profil' }" class="avatar" :aria-label="t('common.profile')"><UserAvatar /><i class="dot" /></router-link>
    </div>
    <label class="search" style="margin-bottom:10px"><Icon name="search" :size="20" /><input v-model="q" type="search" placeholder="Cari nomor TI atau terminal…" /></label>
    <select v-model="status" class="sel" aria-label="Status" style="margin-bottom:12px"><option v-for="s in STATUS" :key="s[0]" :value="s[0]">{{ s[1] }}</option></select>
    <button class="btn btn-primary btn-block" style="margin-bottom:14px" @click="router.push({ name: 'trucking-ti-baru' })"><Icon name="plus" :size="18" /> Tambah Trucking Instruction</button>

    <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load(1)" />
    <div v-else-if="loading" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:170px" /></div>
    <StateBox v-else-if="!items.length" icon="check" title="Tidak ada data" text="Tidak ada Trucking Instruction yang cocok." />
    <template v-else>
      <p class="total">{{ angka(meta.total) }} data</p>
      <div class="stack">
        <button v-for="(x, i) in items" :key="x.id" class="item rise" :style="{ '--i': i % 8 }" @click="router.push({ name: 'trucking-ti-form', params: { id: x.id } })">
          <div class="item-top">
            <div class="item-who"><span class="ico-box tinted tone-orange"><Icon name="truck" :size="18" /></span><span>{{ x.nomor_ti }}</span></div>
            <span v-if="x.cancel" class="pill pill-no">Cancel</span>
          </div>
          <div class="item-title">{{ x.load_port }}</div>
          <div class="item-meta">→ {{ x.discharge_port }}</div>
          <div class="item-meta">{{ tanggal(x.tanggal) }} · ETL {{ tanggal(x.etl) }} · {{ angka(x.volume) }} L</div>
          <div v-if="x.status" class="item-meta">{{ x.status }}</div>
          <div v-if="x.badge.length" class="tags"><span v-for="(b, k) in x.badge" :key="k" class="tag" :class="'tag-' + b.warna">{{ b.teks }}</span></div>
          <span v-if="x.bisa_terima" class="terima" role="button" @click.stop="router.push({ name: 'trucking-ti-terima', params: { id: x.id } })"><Icon name="truck" :size="16" /> Terima / realisasi</span>
        </button>
      </div>
      <button v-if="meta.page < meta.last" class="btn btn-ghost btn-block" style="margin-top:16px" :disabled="more" @click="load(meta.page + 1)">{{ more ? t('common.loading') : t('common.loadMore') }}</button>
    </template>
  </div>
</template>

<style scoped>
.stack, .stack > * { min-width: 0; max-width: 100%; }
.item { width: 100%; box-sizing: border-box; text-align: left; overflow: hidden; }
.item :deep(.item-top) { flex-wrap: wrap; gap: 6px 10px; }
.item-title, .item-meta { min-width: 0; overflow-wrap: anywhere; }
.total { margin: 0 0 8px; color: var(--muted); font-size: 12px; font-weight: 600; }
.sel { width: 100%; min-height: 46px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; }
.tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.tag { padding: 3px 9px; border-radius: 8px; font-size: 11.5px; font-weight: 600; }
.terima { display: inline-flex; align-items: center; gap: 6px; margin-top: 10px; padding: 7px 12px; border-radius: 10px; border: 1px solid var(--line); font-size: 13px; font-weight: 600; color: var(--accent); }
.tag-primary { background: #c6e9ff; color: #2459b6; } .tag-warning { background: #fdebc8; color: #8a5a00; }
</style>
