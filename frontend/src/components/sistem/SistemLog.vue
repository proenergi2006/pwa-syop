<script setup>
import { onMounted, reactive, watch } from 'vue'
import { t } from '../../i18n'
import StateBox from '../StateBox.vue'
import * as syop from '../../api/syop'
import { errorMessage } from '../../api/client'
import { tanggalJam } from '../../utils/format'

const JENIS = ['login', 'persetujuan', 'notifikasi']
const s = reactive({ tersedia: true, jenis: 'login', q: '', dari: '', sampai: '', gagal: false, data: [], meta: { page: 1, last: 1, total: 0 }, loading: true, more: false, error: '' })
async function load(page = 1) {
  page === 1 ? (s.loading = true) : (s.more = true)
  s.error = ''
  try {
    const r = await syop.sistemLog({ jenis: s.jenis, page, per_page: 30, q: s.q || undefined, dari: s.dari || undefined, sampai: s.sampai || undefined, status: s.jenis === 'login' && s.gagal ? 'gagal' : undefined })
    s.data = page === 1 ? r.data : [...s.data, ...r.data]
    s.meta = r.meta
    s.tersedia = r.tersedia !== false
  } catch (e) { s.error = errorMessage(e) } finally { s.loading = false; s.more = false }
}
let tm
watch(() => s.q, () => { clearTimeout(tm); tm = setTimeout(() => load(), 350) })
watch(() => [s.jenis, s.dari, s.sampai, s.gagal], () => load())
onMounted(() => load())
</script>

<template>
  <div class="jseg" role="tablist">
    <button v-for="j in JENIS" :key="j" role="tab" :aria-selected="s.jenis === j" :class="{ on: s.jenis === j }" @click="s.jenis = j">{{ t('sys.l.' + j) }}</button>
  </div>
  <input v-model="s.q" class="in" type="search" :placeholder="t('pg.d.search')" style="margin:10px 0" />
  <div class="dates">
    <label>{{ t('sys.l.from') }}<input v-model="s.dari" class="in sm" type="date" /></label>
    <label>{{ t('sys.l.to') }}<input v-model="s.sampai" class="in sm" type="date" /></label>
  </div>
  <div v-if="s.jenis === 'login'" class="chips" style="margin-bottom:12px">
    <button class="chip" :class="{ on: !s.gagal }" @click="s.gagal = false">{{ t('pg.d.f.all') }}</button>
    <button class="chip" :class="{ on: s.gagal }" @click="s.gagal = true">{{ t('sys.l.failedOnly') }}</button>
  </div>
  <StateBox v-if="s.error" icon="alert" :title="t('common.failedLoad')" :text="s.error" :action="t('common.retry')" @action="load()" />
  <div v-else-if="s.loading" class="stack"><div v-for="i in 5" :key="i" class="skel" style="height:64px" /></div>
  <StateBox v-else-if="!s.data.length" icon="history" :title="t('sys.l.none')" :text="s.tersedia ? '' : t('sys.l.unavailable')" />
  <template v-else>
    <p class="cnt">{{ t('sys.l.count', { n: s.meta.total }) }}</p>
    <div class="stack">
      <article v-for="(x, i) in s.data" :key="i" class="card lg" :class="{ bad: x.gagal }">
        <div class="l1"><b>{{ x.judul }}</b><small>{{ tanggalJam(x.waktu) }}</small></div>
        <span class="who">{{ x.pengguna }}</span>
        <small v-if="x.detail" class="dt">{{ x.detail }}</small>
      </article>
    </div>
    <button v-if="s.meta.page < s.meta.last" class="btn btn-ghost btn-block" style="margin-top:16px" :disabled="s.more" @click="load(s.meta.page + 1)">{{ s.more ? t('common.loading') : t('common.loadMore') }}</button>
  </template>
</template>

<style scoped>
.jseg { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
.jseg button { min-height: 44px; border-radius: 13px; border: 1.5px solid var(--line); background: var(--card); color: var(--ink); font-size: 12.5px; font-weight: 800; font-family: inherit; }
.jseg button.on { border-color: var(--accent); background: #e6eeff; color: var(--accent); }
.in { width: 100%; height: 48px; border-radius: 14px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; font-weight: 600; padding: 0 14px; font-family: inherit; }
.in.sm { height: 42px; font-size: 13.5px; margin-top: 4px; }
.dates { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 10px; }
.dates label { font-size: 11.5px; font-weight: 700; color: var(--muted); }
.cnt { margin: 0 0 8px; font-size: 12px; color: var(--muted); font-weight: 700; }
.lg { padding: 12px 14px; display: grid; gap: 3px; border: 1px solid var(--line); }
.lg.bad { box-shadow: inset 4px 0 0 var(--red); }
.l1 { display: flex; justify-content: space-between; gap: 10px; align-items: baseline; }
.l1 b { font-size: 13.5px; font-weight: 800; overflow-wrap: anywhere; }
.l1 small { flex: none; font-size: 11px; color: var(--muted); }
.who { font-size: 12.5px; color: var(--ink-2); font-weight: 600; }
.dt { font-size: 12px; color: var(--muted); overflow-wrap: anywhere; }
</style>
