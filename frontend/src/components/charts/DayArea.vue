<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { ringkas } from '../../utils/chart'

// Garis/area harian: bagian "actual" (hari yang sudah lewat) tegas, bagian proyeksi putus-putus. Ketuk hari untuk memilih.
const props = defineProps({
  values: { type: Array, required: true },
  weekend: { type: Array, default: () => [] },
  upto: { type: Number, default: 0 }, // jumlah hari actual (0 = semua proyeksi)
  sel: { type: Number, default: -1 },
  color: { type: String, default: '#0a66e8' },
  label: { type: String, default: '' },
})
const emit = defineEmits(['pick'])
const W = 360, H = 200, L = 46, R = 10, T = 14, B = 22
const go = ref(false)
function run() { go.value = false; requestAnimationFrame(() => requestAnimationFrame(() => (go.value = true))) }
onMounted(run)
watch(() => props.values, run)

const n = computed(() => props.values.length)
const lo = computed(() => Math.min(0, ...props.values))
const hi = computed(() => Math.max(1, ...props.values))
const span = computed(() => Math.max(1, hi.value - lo.value))
const ticks = computed(() => [0, 1, 2, 3, 4].map((i) => lo.value + (span.value * i) / 4))
const iw = computed(() => (W - L - R) / Math.max(1, n.value))
const x = (i) => L + iw.value * i + iw.value / 2
const y = (v) => T + (H - T - B) * (1 - (v - lo.value) / span.value)
const cut = computed(() => (props.upto > 0 ? Math.min(props.upto, n.value) : 0))
const pt = (i) => `${x(i).toFixed(1)} ${y(props.values[i]).toFixed(1)}`
const solid = computed(() => (cut.value > 0 ? Array.from({ length: cut.value }, (_, i) => `${i ? 'L' : 'M'}${pt(i)}`).join(' ') : ''))
const dashed = computed(() => {
  const from = Math.max(0, cut.value - 1)
  return n.value - from > 1 ? Array.from({ length: n.value - from }, (_, k) => `${k ? 'L' : 'M'}${pt(from + k)}`).join(' ') : ''
})
const area = computed(() => (cut.value > 1 ? `${solid.value} L${x(cut.value - 1).toFixed(1)} ${y(Math.max(0, lo.value)).toFixed(1)} L${x(0).toFixed(1)} ${y(Math.max(0, lo.value)).toFixed(1)} Z` : ''))
const low = computed(() => {
  let k = -1, m = Infinity
  props.values.forEach((v, i) => { if (v < m) { m = v; k = i } })
  return k
})
const uid = Math.random().toString(36).slice(2, 7)
</script>

<template>
  <svg :viewBox="`0 0 ${W} ${H}`" class="da" role="img" :aria-label="label">
    <defs>
      <linearGradient :id="`a${uid}`" x1="0" y1="0" x2="0" y2="1"><stop offset="0" :stop-color="color" stop-opacity=".32" /><stop offset="1" :stop-color="color" stop-opacity="0" /></linearGradient>
    </defs>
    <rect v-for="(w, i) in weekend" :key="'w' + i" v-show="w" :x="L + iw * i" :y="T - 4" :width="iw" :height="H - T - B + 4" class="wk" />
    <g v-for="(tk, i) in ticks" :key="i">
      <line :x1="L" :x2="W - R" :y1="y(tk)" :y2="y(tk)" class="grid" :class="{ zero: Math.abs(tk) < span / 1000 }" />
      <text :x="L - 6" :y="y(tk) + 3.5" class="ax" text-anchor="end">{{ ringkas(tk, 1) }}</text>
    </g>
    <path v-if="area" :d="area" :fill="`url(#a${uid})`" class="fade" :class="{ go }" />
    <path v-if="solid" :d="solid" fill="none" :stroke="color" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" class="draw" :class="{ go }" pathLength="1" />
    <path v-if="dashed" :d="dashed" fill="none" :stroke="color" stroke-width="2" stroke-dasharray="4 3" stroke-linecap="round" opacity=".75" class="fade" :class="{ go }" />
    <g v-if="low >= 0 && values[low] < hi * 0.6">
      <circle :cx="x(low)" :cy="y(values[low])" r="4" class="lowdot" />
    </g>
    <g v-if="sel >= 0 && sel < n">
      <line :x1="x(sel)" :x2="x(sel)" :y1="T - 4" :y2="H - B" :stroke="color" stroke-width="1.2" stroke-dasharray="2 3" />
      <circle :cx="x(sel)" :cy="y(values[sel])" r="5" :fill="color" class="seldot" />
    </g>
    <g v-for="i in [0, 6, 13, 20, n - 1].filter((k, j, a) => k >= 0 && k < n && a.indexOf(k) === j)" :key="'t' + i">
      <text :x="x(i)" :y="H - 6" class="ax" text-anchor="middle">{{ i + 1 }}</text>
    </g>
    <rect v-for="i in n" :key="'h' + i" :x="L + iw * (i - 1)" :y="T - 4" :width="iw" :height="H - T - B + 4" fill="transparent" class="hit" tabindex="0" role="button" :aria-label="String(i)" @click="emit('pick', i - 1)" @keydown.enter="emit('pick', i - 1)" />
  </svg>
</template>

<style scoped>
.da { width: 100%; height: auto; display: block; overflow: visible; }
.grid { stroke: var(--line); stroke-width: 1; stroke-dasharray: 2 4; }
.grid.zero { stroke: var(--muted); stroke-dasharray: none; opacity: 0.5; }
.ax { fill: var(--muted); font-size: 9.5px; font-weight: 500; }
.wk { fill: color-mix(in srgb, var(--red) 7%, transparent); }
.draw { stroke-dasharray: 1; stroke-dashoffset: 1; transition: stroke-dashoffset 1.1s ease; }
.draw.go { stroke-dashoffset: 0; }
.fade { opacity: 0; transition: opacity 0.8s ease 0.3s; }
.fade.go { opacity: 1; }
.lowdot { fill: var(--card); stroke: var(--red); stroke-width: 2; }
.seldot { stroke: var(--card); stroke-width: 2; }
.hit { cursor: pointer; outline: none; }
@media (prefers-reduced-motion: reduce) { .draw, .fade { transition: none; stroke-dashoffset: 0; opacity: 1; } }
</style>
