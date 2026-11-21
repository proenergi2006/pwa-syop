<script setup>
import { onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useScrolled } from '../composables/useScrolled'
import { showToast } from '../composables/useToast'
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
const tgl = ref('')
const jenis = ref('')
const items = ref([])
const meta = ref({ page: 1, last: 1, total: 0 })
const loading = ref(true)
const more = ref(false)
const error = ref('')
const hapus = ref(null) // item yang akan dihapus
const busy = ref(false)
const hapusErr = ref('')
let seq = 0
let timer
const JENIS = [['', 'Semua jenis'], ['3', 'Adjustment'], ['4', 'Transfer Stock'], ['5', 'Purchase Return']]
const TONE = { 3: 'tone-blue', 4: 'tone-teal', 5: 'tone-orange' }

async function load(page = 1) {
  const my = ++seq
  page === 1 ? (loading.value = true) : (more.value = true)
  error.value = ''
  try {
    const r = await syop.adjList({ page, per_page: 20, q: q.value.trim() || undefined, tanggal: tgl.value || undefined, jenis: jenis.value || undefined })
    if (my !== seq) return
    items.value = page === 1 ? r.data : [...items.value, ...r.data]
    meta.value = r.meta
  } catch (e) { if (my === seq) error.value = errorMessage(e) } finally { if (my === seq) { loading.value = false; more.value = false } }
}
async function doHapus() {
  busy.value = true
  hapusErr.value = ''
  try {
    await syop.adjHapus(hapus.value.id)
    hapus.value = null
    showToast('Data berhasil dihapus.', 'success')
    await load(1)
  } catch (e) { hapusErr.value = errorMessage(e) } finally { busy.value = false }
}
watch(q, () => { clearTimeout(timer); timer = setTimeout(() => load(1), 350) })
watch([tgl, jenis], () => load(1))
onMounted(() => load(1))
</script>

<template>
  <div class="page">
    <div class="page-head" :class="{ stuck: scrolled }">
      <div><h1 class="page-title">Adjustment Stock</h1><p class="page-sub">Adjustment, Transfer Stock, Purchase Return</p></div>
      <router-link :to="{ name: 'profil' }" class="avatar" :aria-label="t('common.profile')"><UserAvatar /><i class="dot" /></router-link>
    </div>
    <button class="btn btn-primary btn-block" style="margin-bottom:14px" @click="router.push({ name: 'adjustment-stock-baru' })"><Icon name="plus" :size="18" /> Tambah Adjustment Stock</button>
    <label class="search" style="margin-bottom:10px"><Icon name="search" :size="20" /><input v-model="q" type="search" placeholder="Cari terminal, keterangan, atau nilai…" /></label>
    <div class="filters">
      <select v-model="jenis" class="sel" aria-label="Jenis"><option v-for="s in JENIS" :key="s[0]" :value="s[0]">{{ s[1] }}</option></select>
      <input v-model="tgl" type="date" class="sel" aria-label="Tanggal" />
    </div>

    <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load(1)" />
    <div v-else-if="loading" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:140px" /></div>
    <StateBox v-else-if="!items.length" icon="check" title="Tidak ada data" text="Tidak ada Adjustment Stock yang cocok." />
    <template v-else>
      <p class="total">{{ angka(meta.total) }} data</p>
      <div class="stack">
        <div v-for="(x, i) in items" :key="x.id" class="item rise" :style="{ '--i': i % 8 }" role="button" tabindex="0" @click="router.push({ name: 'adjustment-stock-detail', params: { id: x.id } })" @keyup.enter="router.push({ name: 'adjustment-stock-detail', params: { id: x.id } })">
          <div class="item-top">
            <div class="item-who"><span class="ico-box tinted" :class="TONE[x.id_jenis]"><Icon name="box" :size="18" /></span><span>{{ x.jenis }}</span></div>
            <span class="nilai" :class="{ min: x.nilai < 0 }">{{ angka(x.nilai, 2) }} L</span>
          </div>
          <div class="item-title">{{ x.produk }}</div>
          <div v-if="x.terminal_ke" class="item-meta">Dari {{ x.terminal }}<br>Ke {{ x.terminal_ke }}</div>
          <div v-else class="item-meta">{{ x.terminal }}</div>
          <div class="item-meta">{{ tanggal(x.tanggal) }}</div>
          <div v-if="x.keterangan" class="item-meta ket">{{ x.keterangan }}</div>
          <div class="acts">
            <span v-if="x.di_accurate" class="tag">Terkirim ke Accurate</span>
            <button type="button" class="del" aria-label="Hapus" @click.stop="hapus = x; hapusErr = ''"><Icon name="trash" :size="16" /> Hapus</button>
          </div>
        </div>
      </div>
      <button v-if="meta.page < meta.last" class="btn btn-ghost btn-block" style="margin-top:16px" :disabled="more" @click="load(meta.page + 1)">{{ more ? t('common.loading') : t('common.loadMore') }}</button>
    </template>

    <div v-if="hapus" class="sheet-back" @click.self="!busy && (hapus = null)">
      <div class="sheet" role="dialog" aria-modal="true"><div class="grab" />
        <h2 class="sheet-t">Hapus data ini?</h2>
        <p class="muted" style="margin:6px 0 4px">{{ hapus.jenis }} · {{ tanggal(hapus.tanggal) }} · {{ angka(hapus.nilai, 2) }} L</p>
        <p v-if="hapus.di_accurate" class="muted" style="margin:0 0 10px">Dokumennya di Accurate ikut dihapus. Bila Accurate menolak, data tidak jadi dihapus.</p>
        <p v-if="hapusErr" class="err" role="alert">{{ hapusErr }}</p>
        <div class="sheet-act"><button class="btn btn-ghost" :disabled="busy" @click="hapus = null">Batal</button><button class="btn btn-primary" :disabled="busy" @click="doHapus">{{ busy ? 'Menghapus…' : 'Ya, hapus' }}</button></div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.stack, .stack > * { min-width: 0; max-width: 100%; }
.item { width: 100%; box-sizing: border-box; text-align: left; overflow: hidden; cursor: pointer; }
.item-title, .item-meta { min-width: 0; overflow-wrap: anywhere; }
.ket { font-style: italic; }
.total { margin: 0 0 8px; color: var(--muted); font-size: 12px; font-weight: 600; }
.filters { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px; }
.sel { width: 100%; min-width: 0; min-height: 46px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; box-sizing: border-box; }
.nilai { font-weight: 800; font-variant-numeric: tabular-nums; color: #0b7a4b; } .nilai.min { color: var(--red); }
.acts { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 10px; }
.tag { padding: 3px 9px; border-radius: 8px; font-size: 11.5px; font-weight: 600; background: #c6e9ff; color: #2459b6; }
.del { margin-left: auto; display: inline-flex; align-items: center; gap: 6px; min-height: 40px; padding: 0 12px; border-radius: 10px; border: 1px solid var(--line); background: var(--card); color: var(--red); font-size: 13px; font-weight: 600; }
.err { color: var(--red); font-size: 13.5px; margin: 0 0 10px; }
</style>
