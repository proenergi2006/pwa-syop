<script setup>
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
const q = ref('')
const f = reactive({ bulan: '', tahun: '', produk: '', vendor: '', terminal: '' })
const draft = reactive({ ...f })
const sheet = ref(false)
const opsi = ref({ produk: [], terminal: [], vendor: [] })
const items = ref([])
const meta = ref({ page: 1, last: 1, total: 0 })
const loading = ref(true)
const more = ref(false)
const error = ref('')
let seq = 0
let timer

const BULAN = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember']
const tahunIni = new Date().getFullYear()
const TAHUN = Array.from({ length: 6 }, (_, i) => String(tahunIni - i))
const active = computed(() => ['produk', 'vendor', 'terminal'].filter((k) => f[k]).length + (f.bulan && f.tahun ? 1 : 0))

async function load(page = 1) {
  const my = ++seq
  page === 1 ? (loading.value = true) : (more.value = true)
  error.value = ''
  try {
    const periode = f.bulan && f.tahun
    const r = await syop.pbsList({ page, per_page: 20, q: q.value.trim() || undefined, produk: f.produk || undefined, vendor: f.vendor || undefined, terminal: f.terminal || undefined, bulan: periode ? f.bulan : undefined, tahun: periode ? f.tahun : undefined })
    if (my !== seq) return
    items.value = page === 1 ? r.data : [...items.value, ...r.data]
    meta.value = r.meta
  } catch (e) { if (my === seq) error.value = errorMessage(e) } finally { if (my === seq) { loading.value = false; more.value = false } }
}
const apply = () => { Object.assign(f, draft); sheet.value = false; load(1) }
const reset = () => { Object.keys(draft).forEach((k) => { draft[k] = ''; f[k] = '' }); sheet.value = false; load(1) }
const pill = (p) => (p.is_cancel || p.is_close || /Ditolak/.test(p.status) ? 'pill-no' : p.status === 'Terverifikasi' ? 'pill-ok' : 'pill-wait')
watch(q, () => { clearTimeout(timer); timer = setTimeout(() => load(1), 350) })
onMounted(() => { load(1); syop.pbsOpsi().then((o) => { opsi.value = o }).catch(() => {}) })
</script>

<template>
  <div class="page">
    <div class="page-head" :class="{ stuck: scrolled }">
      <div>
        <h1 class="page-title">PO Supplier Batu</h1>
        <p class="page-sub">PO Supplier Crushed Stone</p>
      </div>
      <router-link :to="{ name: 'profil' }" class="avatar" aria-label="Profil"><UserAvatar /><i class="dot" /></router-link>
    </div>

    <div class="bar2">
      <label class="search" style="flex:1;margin:0"><Icon name="search" :size="20" /><input v-model="q" type="search" aria-label="Cari nomor PO" placeholder="Cari nomor PO…" /></label>
      <button class="fbtn" :class="{ on: active }" aria-label="Filter" @click="Object.assign(draft, f); sheet = true"><Icon name="filter" :size="20" /><b v-if="active">{{ active }}</b></button>
    </div>

    <button class="btn btn-primary btn-block" style="margin-bottom:14px" @click="router.push({ name: 'po-batu-supplier-baru' })"><Icon name="plus" :size="18" /> Tambah PO</button>

    <StateBox v-if="error" icon="alert" title="Gagal memuat" :text="error" action="Coba lagi" @action="load(1)" />
    <div v-else-if="loading" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:190px" /></div>
    <StateBox v-else-if="!items.length" icon="check" title="Tidak ada PO" text="Tidak ada PO Supplier Batu yang cocok." />
    <template v-else>
      <p class="total">{{ angka(meta.total) }} data</p>
      <div class="stack">
        <div v-for="(p, i) in items" :key="p.id" class="item rise" :style="{ '--i': i % 8 }">
          <button class="item-main" @click="router.push({ name: 'po-batu-supplier-detail', params: { id: p.id } })">
            <div class="item-top">
              <div class="item-who"><span class="ico-box tinted tone-indigo"><Icon name="cart" :size="18" /></span><span>{{ p.nomor_po }}</span></div>
              <span v-if="p.is_cancel" class="pill pill-no">Cancel</span>
              <span v-else-if="p.is_close" class="pill pill-no">Close</span>
              <span v-else-if="p.status" class="pill" :class="pill(p)">{{ p.status }}</span>
            </div>
            <div class="item-title">{{ p.vendor }}</div>
            <div class="item-meta">{{ p.terminal }}</div>
            <div class="item-meta">{{ p.produk }} · {{ tanggal(p.tanggal) }}</div>
            <p v-if="p.is_cancel && p.keterangan_cancel" class="warnline">{{ p.keterangan_cancel }}</p>
            <div class="vols">
              <span>PO <b>{{ angka(p.volume_po) }}</b></span><span>BL <b>{{ angka(p.vol_bl) }}</b></span><span>RI <b>{{ angka(p.vol_terima) }}</b></span><span class="muted">m³</span>
              <span v-if="p.is_close" class="red">Close PO <b>{{ angka(p.volume_close) }}</b></span>
            </div>
            <div class="item-meta">Harga PO Rp {{ angka(p.harga_tebus) }} /m³</div>
            <small v-if="p.status_tgl" class="item-meta">{{ p.status_tgl }}</small>
          </button>
          <button v-if="p.aksi.includes('cetak')" class="mini" @click="router.push({ name: 'po-batu-supplier-cetak', params: { id: p.id } })"><Icon name="doc" :size="16" /> Cetak PO</button>
        </div>
      </div>
      <button v-if="meta.page < meta.last" class="btn btn-ghost btn-block" style="margin-top:16px" :disabled="more" @click="load(meta.page + 1)">{{ more ? 'Memuat…' : 'Muat lebih' }}</button>
    </template>

    <div v-if="sheet" class="sheet-back" @click.self="sheet = false">
      <div class="sheet" role="dialog" aria-modal="true" aria-label="Filter" style="max-height:85vh;overflow:auto">
        <div class="grab" />
        <h2 class="sheet-t" style="margin-bottom:14px">Filter</h2>
        <div class="two">
          <div class="field"><label for="fb">Bulan</label><select id="fb" v-model="draft.bulan" class="sel"><option value="">Semua</option><option v-for="(b, i) in BULAN" :key="i" :value="String(i + 1)">{{ b }}</option></select></div>
          <div class="field"><label for="ft">Tahun</label><select id="ft" v-model="draft.tahun" class="sel"><option value="">Semua</option><option v-for="y in TAHUN" :key="y" :value="y">{{ y }}</option></select></div>
        </div>
        <div class="field"><label for="fp">Produk</label><select id="fp" v-model="draft.produk" class="sel"><option value="">Semua</option><option v-for="x in opsi.produk" :key="x.id" :value="String(x.id)">{{ x.label }}</option></select></div>
        <div class="field"><label for="fv">Vendor</label><select id="fv" v-model="draft.vendor" class="sel"><option value="">Semua</option><option v-for="x in opsi.vendor" :key="x.id" :value="String(x.id)">{{ x.label }}</option></select></div>
        <div class="field"><label for="fm">Terminal</label><select id="fm" v-model="draft.terminal" class="sel"><option value="">Semua</option><option v-for="x in opsi.terminal" :key="x.id" :value="String(x.id)">{{ x.label }}</option></select></div>
        <p class="muted">Bulan dan tahun dipakai bersamaan.</p>
        <div class="sheet-act" style="margin-top:6px"><button class="btn btn-ghost" @click="reset">Reset</button><button class="btn btn-primary" @click="apply">Terapkan</button></div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.bar2 { display: flex; gap: 10px; align-items: center; margin-bottom: 14px; }
.fbtn { position: relative; flex: none; width: 48px; height: 48px; border-radius: 14px; background: var(--card); border: 1px solid var(--line); display: grid; place-items: center; color: var(--ink-2); }
.fbtn.on { color: var(--accent); border-color: var(--focus); }
.fbtn b { position: absolute; top: -6px; right: -6px; min-width: 20px; height: 20px; padding: 0 5px; border-radius: 10px; background: var(--red); color: #fff; font-size: 11px; display: grid; place-items: center; }
.total { margin: 0 0 8px; color: var(--muted); font-size: 12px; font-weight: 600; }
.stack, .stack > * { min-width: 0; max-width: 100%; }
.item { width: 100%; box-sizing: border-box; text-align: left; overflow: hidden; }
.item-main { display: block; width: 100%; text-align: left; background: none; border: 0; padding: 0; color: inherit; font: inherit; }
.item :deep(.item-top) { flex-wrap: wrap; gap: 6px 10px; }
.item-title, .item-meta { min-width: 0; overflow-wrap: anywhere; }
.vols { display: flex; flex-wrap: wrap; gap: 4px 14px; margin: 8px 0 4px; padding-top: 8px; border-top: 1px dashed var(--line); font-size: 13px; color: var(--muted); }
.vols b { color: var(--ink); } .vols .red, .vols .red b { color: var(--red); }
.warnline { margin: 6px 0 0; color: var(--red); font-size: 12.5px; }
.mini { display: inline-flex; align-items: center; gap: 6px; min-height: 36px; padding: 0 12px; margin-top: 10px; border-radius: 10px; border: 1px solid var(--line); background: var(--card); color: var(--ink-2); font-size: 13px; font-weight: 600; }
.two { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 10px; }
.muted { color: var(--muted); font-size: 12.5px; }
.sel { width: 100%; min-height: 46px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; }
</style>
