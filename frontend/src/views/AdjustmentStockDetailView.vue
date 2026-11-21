<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import StateBox from '../components/StateBox.vue'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'
import { angka, tanggal } from '../utils/format'
import { t } from '../i18n'

const props = defineProps({ id: { type: [String, Number], required: true } })
const router = useRouter()
const d = ref(null)
const error = ref('')
const loading = ref(true)

async function load() {
  loading.value = true
  error.value = ''
  try { d.value = await syop.adjShow(props.id) } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'adjustment-stock' }))
onMounted(load)
</script>

<template>
  <div class="page">
    <div class="page-head">
      <button class="icon-btn" :aria-label="t('common.back')" @click="back"><Icon name="back" :size="22" /></button>
      <div style="flex:1;min-width:0"><h1 class="page-title">{{ d ? d.jenis : 'Adjustment Stock' }}</h1><p class="page-sub">Detail</p></div>
    </div>
    <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load" />
    <div v-else-if="loading" class="skel" style="height:260px" />
    <template v-else-if="d">
      <div class="card">
        <div class="kv"><span>Jenis</span><span>{{ d.jenis }}</span></div>
        <div class="kv"><span>Produk</span><span>{{ d.produk }}</span></div>
        <div class="kv"><span>Tanggal</span><span>{{ tanggal(d.tanggal) }}</span></div>
        <template v-if="d.id_jenis === 4">
          <div class="kv"><span>Dari terminal / depot</span><span>{{ d.terminal_dari }}</span></div>
          <div class="kv"><span>Ke terminal / depot</span><span>{{ d.terminal_ke }}</span></div>
          <div class="kv"><span>Tanggal penerimaan</span><span>{{ tanggal(d.tanggal_terima) }}</span></div>
          <div v-if="d.harga_transfer" class="kv"><span>Harga</span><span>Rp {{ angka(d.harga_transfer, 2) }}</span></div>
        </template>
        <template v-else>
          <div class="kv"><span>Terminal / depot</span><span>{{ d.terminal }}</span></div>
          <div class="kv"><span>Nomor PO</span><span>{{ d.nomor_po || '-' }}</span></div>
          <div v-if="d.tgl_po" class="kv"><span>Tanggal PO</span><span>{{ tanggal(d.tgl_po) }}</span></div>
          <div class="kv"><span>Volume</span><span>{{ angka(d.volume, 2) }} Liter</span></div>
          <div v-if="d.harga" class="kv"><span>Harga</span><span>Rp {{ angka(d.harga, 2) }}</span></div>
        </template>
        <div v-if="d.keterangan" class="kv ket"><span>Keterangan</span><span>{{ d.keterangan }}</span></div>
        <div v-if="d.di_accurate" class="kv"><span>Accurate</span><span>Terkirim</span></div>
      </div>

      <div v-if="d.id_jenis === 4" class="card" style="margin-top:12px">
        <h3 class="ct">Rincian PO yang dipindahkan</h3>
        <p v-if="!d.rincian.length" class="muted">Data tidak ditemukan.</p>
        <div v-for="(r, i) in d.rincian" :key="i" class="rinci">
          <div class="r1"><b>{{ r.nomor_po || '-' }}</b><span>{{ angka(r.volume) }} L</span></div>
          <div class="r2">PO {{ tanggal(r.tgl_po) }} · Transfer {{ tanggal(r.tgl_transfer) }}</div>
        </div>
        <div class="kv tot"><span>Grand total</span><span>{{ angka(d.total) }} Liter</span></div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.ct { margin: 0 0 8px; font-size: 14px; }
.rinci { padding: 9px 0; border-bottom: 1px solid var(--line); }
.r1 { display: flex; justify-content: space-between; gap: 10px; font-size: 14px; } .r1 span { font-variant-numeric: tabular-nums; font-weight: 700; }
.r2 { color: var(--muted); font-size: 12.5px; margin-top: 2px; }
.tot { font-weight: 800; border-bottom: 0; }
.ket span:last-child { white-space: pre-wrap; text-align: right; }
</style>
