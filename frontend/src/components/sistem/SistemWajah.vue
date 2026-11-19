<script setup>
import { onMounted, reactive, ref, watch } from 'vue'
import { t } from '../../i18n'
import Icon from '../Icon.vue'
import StateBox from '../StateBox.vue'
import * as syop from '../../api/syop'
import { errorMessage } from '../../api/client'
import { showToast } from '../../composables/useToast'
import { angka, tanggal, tanggalJam } from '../../utils/format'

const s = reactive({ q: '', filter: '', ringkasan: null, data: [], meta: { page: 1, last: 1 }, loading: true, more: false, error: '' })
async function load(page = 1) {
  page === 1 ? (s.loading = true) : (s.more = true)
  s.error = ''
  try {
    const r = await syop.sistemWajah({ page, per_page: 30, q: s.q || undefined, filter: s.filter || undefined })
    s.data = page === 1 ? r.data : [...s.data, ...r.data]
    s.meta = r.meta
    s.ringkasan = r.ringkasan
  } catch (e) { s.error = errorMessage(e) } finally { s.loading = false; s.more = false }
}
let tm
watch(() => s.q, () => { clearTimeout(tm); tm = setTimeout(() => load(), 350) })
watch(() => s.filter, () => load())
onMounted(() => load())

const target = ref(null)
const busy = ref(false)
async function reset() {
  busy.value = true
  try {
    const r = await syop.sistemResetWajah(target.value.id)
    showToast(r.message, 'ok')
    target.value = null
    await load()
  } catch (e) { showToast(errorMessage(e), 'err'); target.value = null } finally { busy.value = false }
}
</script>

<template>
  <div v-if="s.ringkasan" class="sum">
    <div><b>{{ angka(s.ringkasan.pengguna) }}</b><small>{{ t('pg.d.users') }}</small></div>
    <div class="g"><b>{{ angka(s.ringkasan.terdaftar) }}</b><small>{{ t('sys.f.enrolled') }}</small></div>
    <div class="r"><b>{{ angka(s.ringkasan.belum) }}</b><small>{{ t('sys.f.not') }}</small></div>
  </div>
  <input v-model="s.q" class="in" type="search" :placeholder="t('pg.d.search')" style="margin-bottom:10px" />
  <div class="chips wrapc" style="margin-bottom:12px">
    <button class="chip" :class="{ on: !s.filter }" @click="s.filter = ''">{{ t('pg.d.f.all') }}</button>
    <button class="chip" :class="{ on: s.filter === 'terdaftar' }" @click="s.filter = 'terdaftar'">{{ t('sys.f.enrolled') }}</button>
    <button class="chip" :class="{ on: s.filter === 'belum' }" @click="s.filter = 'belum'">{{ t('sys.f.not') }}</button>
    <button class="chip" :class="{ on: s.filter === 'bermasalah' }" @click="s.filter = 'bermasalah'">{{ t('sys.f.problem') }}</button>
  </div>
  <StateBox v-if="s.error" icon="alert" :title="t('common.failedLoad')" :text="s.error" :action="t('common.retry')" @action="load()" />
  <div v-else-if="s.loading" class="stack"><div v-for="i in 4" :key="i" class="skel" style="height:72px" /></div>
  <StateBox v-else-if="!s.data.length" icon="face" :title="t('pg.d.none')" :text="''" />
  <div v-else class="stack">
    <article v-for="u in s.data" :key="u.id" class="card urow">
      <div class="ut"><b>{{ u.nama }}</b><small>{{ u.peran }}</small></div>
      <div class="ub">
        <span class="pill" :class="u.terdaftar ? 'pill-ok' : 'pill-draft'"><Icon name="face" :size="12" /> {{ u.terdaftar ? t('sys.f.enrolled') : t('sys.f.not') }}</span>
        <span v-if="u.gagal_30h" class="warnp">{{ t('sys.f.fails', { n: u.gagal_30h }) }}</span>
      </div>
      <small v-if="u.terdaftar" class="ad-m">{{ t('sys.f.since', { a: tanggal(u.didaftarkan) }) }}<template v-if="u.terakhir_dipakai"> · {{ t('sys.f.used', { t: tanggalJam(u.terakhir_dipakai) }) }}</template></small>
      <small v-if="u.perangkat" class="ad-m">{{ u.perangkat }}</small>
      <button v-if="u.terdaftar" class="mini bad" @click="target = u"><Icon name="refresh" :size="15" /> {{ t('sys.f.reset') }}</button>
    </article>
  </div>
  <button v-if="s.meta.page < s.meta.last" class="btn btn-ghost btn-block" style="margin-top:16px" :disabled="s.more" @click="load(s.meta.page + 1)">{{ s.more ? t('common.loading') : t('common.loadMore') }}</button>

  <div v-if="target" class="sheet-back" @click.self="!busy && (target = null)">
    <div class="sheet" role="dialog" aria-modal="true">
      <div class="grab" />
      <h2 class="sheet-t">{{ t('sys.f.resetTitle') }}</h2>
      <p class="muted" style="margin:6px 0">{{ target.nama }}</p>
      <p class="hint">{{ t('sys.f.resetHint') }}</p>
      <div class="sheet-act">
        <button class="btn btn-ghost" :disabled="busy" @click="target = null">{{ t('common.cancel') }}</button>
        <button class="btn btn-danger" :disabled="busy" @click="reset">{{ busy ? t('det.processing') : t('sys.f.reset') }}</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.sum { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin-bottom: 12px; }
.sum div { padding: 12px; border-radius: 16px; background: var(--card); border: 1px solid var(--line); display: grid; gap: 2px; text-align: center; }
.sum b { font-size: 22px; font-weight: 800; letter-spacing: -0.02em; }
.sum small { font-size: 11.5px; color: var(--muted); font-weight: 700; }
.sum .g b { color: var(--green); } .sum .r b { color: #b4232a; }
.in { width: 100%; height: 48px; border-radius: 14px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; font-weight: 600; padding: 0 14px; font-family: inherit; }
.wrapc { flex-wrap: wrap; }
.urow { padding: 12px 14px; display: grid; gap: 6px; border: 1px solid var(--line); }
.ut { display: flex; flex-direction: column; gap: 1px; }
.ut small { font-size: 12px; color: var(--muted); }
.ub { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.pill { display: inline-flex; align-items: center; gap: 5px; }
.pill-draft { background: var(--line); color: var(--muted); }
.warnp { font-size: 12px; font-weight: 800; color: #b4232a; }
.ad-m { font-size: 12px; color: var(--muted); overflow-wrap: anywhere; }
.mini { justify-self: start; display: inline-flex; align-items: center; gap: 5px; min-height: 40px; padding: 0 14px; border-radius: 11px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 12.5px; font-weight: 700; font-family: inherit; }
.mini.bad { color: #b4232a; border-color: color-mix(in srgb, var(--red) 40%, var(--line)); }
.hint { margin: 0 0 14px; padding: 8px 10px; border-radius: 10px; background: var(--blue-50); color: var(--ink-2); font-size: 12.5px; }
</style>
