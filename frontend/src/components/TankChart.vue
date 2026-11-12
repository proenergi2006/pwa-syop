<script setup>
import { t } from '../i18n'
import { computed, onMounted, ref } from 'vue'
import { angka, rupiah, rupiahRingkas } from '../utils/format'
import * as syop from '../api/syop'

const data = ref([])
const sum = ref(null)
const cogs = ref(false) // COGS hanya untuk CEO
const scope = ref('semua') // BM: hanya cabangnya
const loading = ref(true)
const failed = ref(false)
const go = ref(false) // memicu animasi isi tangki

const cab = ref('') // filter cabang ('' = semua)
const cabangs = computed(() => [...new Set(data.value.map((d) => d.cabang))].sort())
const shown = computed(() => (cab.value ? data.value.filter((d) => d.cabang === cab.value) : data.value))
// Ringkasan mengikuti filter; COGS rata-rata = nilai stok / total stok.
const tot = computed(() => {
  const stok = shown.value.reduce((a, d) => a + d.sisa, 0)
  const nilai = shown.value.reduce((a, d) => a + (d.nilai || 0), 0)
  return { stok, terminal: shown.value.length, wavg: stok ? nilai / stok : 0 }
})
const maxSisa = computed(() => Math.max(1, ...shown.value.map((d) => d.sisa)))
const uid = Math.random().toString(36).slice(2, 7)

// Tinggi isi: terhadap batas atas (kapasitas) bila ada; bila belum diatur, terhadap stok terbesar.
const level = (d) => Math.min(1, Math.max(0.05, d.sisa / (d.batas_atas > 0 ? d.batas_atas : maxSisa.value)))
const pct = (d) => (d.batas_atas > 0 ? Math.round((d.sisa / d.batas_atas) * 100) : null)
const state = (d) => (d.batas_bawah > 0 && d.sisa <= d.batas_bawah ? 'low' : d.batas_atas > 0 && d.sisa / d.batas_atas >= 0.9 ? 'high' : 'ok')
const short = (s) => (s || '').replace(/\s+/g, ' ').trim()

async function load() {
  loading.value = true
  failed.value = false
  try {
    const r = await syop.stockTerminal()
    data.value = r.data
    sum.value = r.summary
    cogs.value = !!r.cogs
    scope.value = r.scope
    requestAnimationFrame(() => setTimeout(() => (go.value = true), 60))
  } catch { failed.value = true } finally { loading.value = false }
}
onMounted(load)
</script>

<template>
  <section class="tk" :aria-label="t('tank.title')">
    <div class="section-head"><h2>{{ t('tank.title') }}</h2><router-link v-if="cogs" :to="{ name: 'inventory' }" class="link">{{ t('tank.detail') }}</router-link></div>

    <div v-if="loading" class="skel" style="height:250px" />
    <div v-else-if="failed" class="card empty">{{ t('common.failedLoadData') }} <button class="link" @click="load">{{ t('common.retry') }}</button></div>
    <template v-else-if="sum">
      <div v-if="scope === 'semua' && cabangs.length > 1" class="chips tk-f" role="tablist" :aria-label="t('tank.branch')">
        <button class="chip" :class="{ on: !cab }" role="tab" :aria-selected="!cab" @click="cab = ''">{{ t('tank.allBranch') }}</button>
        <button v-for="c in cabangs" :key="c" class="chip" :class="{ on: cab === c }" role="tab" :aria-selected="cab === c" @click="cab = c">{{ c }}</button>
      </div>
      <div class="card tk-sum">
        <div><small>{{ t('tank.total') }}</small><b>{{ angka(tot.stok) }}<i>L</i></b></div>
        <div v-if="scope === 'cabang' && data[0]"><small>{{ t('tank.branchOnly') }}</small><b>{{ data[0].cabang }}</b></div>
        <div><small>{{ t('tank.terminals') }}</small><b>{{ tot.terminal }}</b></div>
        <div v-if="cogs"><small>{{ cab ? t('tank.avgBranch') : t('tank.avg') }}</small><b>{{ rupiah(tot.wavg) }}<i>/L</i></b></div>
      </div>

      <div class="tk-row" role="list">
        <article v-for="(d, i) in shown" :key="d.id" class="tk-c card" :class="'is-' + state(d)" role="listitem" :style="{ '--i': i }">
          <svg viewBox="0 0 100 124" class="tk-svg" aria-hidden="true">
            <defs>
              <linearGradient :id="`l${uid}${d.id}`" x1="0" x2="0" y1="0" y2="1">
                <stop offset="0" class="s1" /><stop offset="1" class="s2" />
              </linearGradient>
              <linearGradient :id="`b${uid}${d.id}`" x1="0" x2="1">
                <stop offset="0" stop-color="#fff" stop-opacity=".55" /><stop offset=".35" stop-color="#fff" stop-opacity="0" /><stop offset="1" stop-color="#000" stop-opacity=".12" />
              </linearGradient>
              <clipPath :id="`c${uid}${d.id}`"><path d="M10 20 a40 9 0 0 1 80 0 V100 a40 9 0 0 1 -80 0 Z" /></clipPath>
            </defs>
            <!-- badan tangki -->
            <path d="M10 20 a40 9 0 0 1 80 0 V100 a40 9 0 0 1 -80 0 Z" class="shell" />
            <g :clip-path="`url(#c${uid}${d.id})`">
              <g class="liq" :style="{ transform: `translateY(${go ? (1 - level(d)) * 80 : 80}px)`, transitionDelay: i * 70 + 'ms' }">
                <rect x="0" y="20" width="100" height="100" :fill="`url(#l${uid}${d.id})`" />
                <path class="wave" d="M-100 20 q25 -6 50 0 t50 0 t50 0 t50 0 t50 0 V30 H-100 Z" :fill="`url(#l${uid}${d.id})`" />
                <path class="wave w2" d="M-100 21 q25 5 50 0 t50 0 t50 0 t50 0 t50 0 V30 H-100 Z" fill="#fff" opacity=".22" />
              </g>
              <rect x="10" y="12" width="80" height="100" :fill="`url(#b${uid}${d.id})`" />
              <path d="M10 46 h80 M10 72 h80" class="ring" />
            </g>
            <ellipse cx="50" cy="20" rx="40" ry="9" class="lid" />
            <path d="M10 20 a40 9 0 0 1 80 0 V100 a40 9 0 0 1 -80 0 Z" class="edge" />
            <text x="50" y="66" text-anchor="middle" class="pc">{{ pct(d) !== null ? pct(d) + '%' : '' }}</text>
            <rect x="22" y="112" width="8" height="10" rx="2" class="leg" /><rect x="70" y="112" width="8" height="10" rx="2" class="leg" />
          </svg>
          <div class="tk-n">{{ short(d.nama) }}</div>
          <div class="tk-m">{{ d.cabang }}<template v-if="d.tanki"> · {{ d.tanki }}</template></div>
          <div class="tk-v">{{ angka(d.sisa) }}<i>L</i></div>
          <div v-if="cogs" class="tk-cg"><small>{{ t('tank.cogs') }}</small><b>{{ rupiah(d.wavg) }}<i>/L</i></b></div>
          <div v-if="cogs" class="tk-p">{{ t('tank.value') }} <b>{{ rupiahRingkas(d.nilai) }}</b></div>
          <span v-if="state(d) === 'low'" class="tk-tag low">{{ t('tank.low') }}</span>
          <span v-else-if="state(d) === 'high'" class="tk-tag high">{{ t('tank.high') }}</span>
        </article>
      </div>
      <p class="tk-note">{{ cogs ? t('tank.note') : t('tank.note2') }}</p>
    </template>
  </section>
</template>

<style scoped>
.tk { margin-top: 4px; }
.tk-sum { display: flex; justify-content: space-between; gap: 14px; flex-wrap: wrap; gap: 8px; padding: 14px 16px; margin-bottom: 12px; border-top: 3px solid transparent; background: linear-gradient(var(--card), var(--card)) padding-box, var(--brand-grad) border-box; }
.tk-sum small { display: block; font-size: 11.5px; color: var(--muted); font-weight: 600; }
.tk-sum b { font-size: 17px; font-weight: 800; letter-spacing: -0.02em; color: var(--accent); }
.tk-sum i, .tk-v i { font-style: normal; font-size: 11px; color: var(--muted); font-weight: 700; margin-left: 2px; }
.tk-row { display: flex; gap: 12px; overflow-x: auto; scroll-snap-type: x mandatory; padding: 2px 2px 12px; margin: 0 -2px; scrollbar-width: none; }
.tk-row::-webkit-scrollbar { display: none; }
.tk-c { position: relative; flex: none; width: 158px; scroll-snap-align: start; padding: 12px 12px 14px; text-align: center; --t1: #2f6bff; --t2: #04247a; }
.tk-c.is-low { --t1: #ff6b7f; --t2: #c8102e; }
.tk-c.is-high { --t1: #ffb45a; --t2: #e07400; }
.tk-svg { width: 100%; height: 124px; display: block; overflow: visible; filter: drop-shadow(0 8px 10px color-mix(in srgb, var(--t2) 28%, transparent)); }
.s1 { stop-color: var(--t1); } .s2 { stop-color: var(--t2); }
.shell { fill: var(--blue-50); }
.lid { fill: color-mix(in srgb, var(--t1) 22%, var(--card)); stroke: color-mix(in srgb, var(--t2) 45%, transparent); stroke-width: 1; }
.edge { fill: none; stroke: color-mix(in srgb, var(--t2) 45%, transparent); stroke-width: 1.4; }
.ring { stroke: rgba(255, 255, 255, .35); stroke-width: 1; fill: none; }
.leg { fill: color-mix(in srgb, var(--t2) 55%, #888); }
.pc { font-size: 17px; font-weight: 800; fill: #fff; paint-order: stroke; stroke: rgba(4, 36, 122, .6); stroke-width: 3px; }
.liq { transition: transform 1.1s cubic-bezier(.2, .8, .2, 1); }
.wave { animation: wv 4s linear infinite; }
.wave.w2 { animation-duration: 6s; animation-direction: reverse; }
@keyframes wv { to { transform: translateX(50px); } }
.tk-n { margin-top: 6px; font-weight: 700; font-size: 13px; line-height: 1.25; min-height: 33px; display: grid; align-items: center; overflow-wrap: anywhere; }
.tk-m { font-size: 11.5px; color: var(--muted); margin-bottom: 4px; }
.tk-v { font-size: 18px; font-weight: 800; letter-spacing: -0.02em; color: var(--ink); }
.tk-p { display: flex; justify-content: space-between; gap: 6px; font-size: 11.5px; color: var(--muted); margin-top: 3px; white-space: nowrap; }
.tk-cg { margin-top: 6px; padding: 5px 8px; border-radius: 10px; background: var(--blue-50); display: grid; }
.tk-cg small { font-size: 10.5px; color: var(--muted); font-weight: 600; }
.tk-cg b { font-size: 14px; color: var(--accent); font-weight: 800; }
.tk-cg i { font-style: normal; font-size: 10.5px; color: var(--muted); margin-left: 2px; }
.tk-f { padding-bottom: 10px; }
.tk-p b { color: var(--ink-2); }
.tk-tag { position: absolute; top: 8px; left: 8px; font-size: 10.5px; font-weight: 800; padding: 2px 8px; border-radius: 999px; }
.tk-tag.low { background: var(--red-bg); color: var(--red); }
.tk-tag.high { background: var(--amber-bg); color: var(--late); }
.tk-note { font-size: 11.5px; color: var(--muted); margin: 0 0 8px; }
@media (prefers-reduced-motion: reduce) { .wave, .liq { animation: none; transition: none; } }
</style>
