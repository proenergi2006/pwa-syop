<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { niceMax, ringkas } from '../../utils/chart'

// Batang sales harian (actual) + garis putus-putus forecast. Ketuk hari untuk memilih.
const props = defineProps({
  values: { type: Array, required: true },
  forecast: { type: Array, default: () => [] },
  weekend: { type: Array, default: () => [] },
  upto: { type: Number, default: 0 },
  sel: { type: Number, default: -1 },
  color: { type: String, default: '#e07400' },
  label: { type: String, default: '' },
})
const emit = defineEmits(['pick'])
const W = 360, H = 190, L = 46, R = 10, T = 14, B = 22
const go = ref(false)
function run() { go.value = false; requestAnimationFrame(() => requestAnimationFrame(() => (go.value = true))) }
onMounted(run)
watch(() => props.values, run)

const n = computed(() => props.values.length)
const max = computed(() => niceMax(Math.max(1, ...props.values, ...props.forecast)))
const ticks = computed(() => [0, 1, 2, 3, 4].map((i) => (max.value * i) / 4))
const iw = computed(() => (W - L - R) / Math.max(1, n.value))
const x = (i) => L + iw.value * i + iw.value / 2
const y = (v) => T + (H - T - B) * (1 - v / max.value)
const bw = computed(() => Math.max(3, Math.min(14, iw.value * 0.66)))
const line = computed(() => {
  const pts = props.forecast.map((v, i) => [i, v]).filter(([, v]) => v > 0)
  return pts.map(([i, v], k) => `${k ? 'L' : 'M'}${x(i).toFixed(1)} ${y(v).toFixed(1)}`).join(' ')
})
const uid = Math.random().toString(36).slice(2, 7)
</script>

<template>
  <svg :viewBox="`0 0 ${W} ${H}`" class="db" role="img" :aria-label="label">
    <defs>
      <linearGradient :id="`b${uid}`" x1="0" y1="0" x2="0" y2="1"><stop offset="0" :stop-color="color" /><stop offset="1" :stop-color="color" stop-opacity=".5" /></linearGradient>
    </defs>
    <rect v-for="(w, i) in weekend" :key="'w' + i" v-show="w" :x="L + iw * i" :y="T - 4" :width="iw" :height="H - T - B + 4" class="wk" />
    <g v-for="(tk, i) in ticks" :key="i">
      <line :x1="L" :x2="W - R" :y1="y(tk)" :y2="y(tk)" class="grid" />
      <text :x="L - 6" :y="y(tk) + 3.5" class="ax" text-anchor="end">{{ ringkas(tk, 1) }}</text>
    </g>
    <rect v-if="sel >= 0" :x="L + iw * sel" :y="T - 4" :width="iw" :height="H - T - B + 4" class="selbg" :style="{ '--c': color }" />
    <g v-for="(v, i) in values" :key="i">
      <rect v-if="v > 0" class="bar" :class="{ go }" :x="x(i) - bw / 2" :y="y(v)" :width="bw" :height="Math.max(2, y(0) - y(v))" rx="2.5" :fill="`url(#b${uid})`" :style="{ transitionDelay: i * 18 + 'ms' }" />
    </g>
    <path v-if="line" :d="line" fill="none" stroke="var(--muted)" stroke-width="1.6" stroke-dasharray="4 3" opacity=".9" />
    <circle v-for="(v, i) in forecast" v-show="v > 0" :key="'f' + i" :cx="x(i)" :cy="y(v)" r="2.2" class="fdot" />
    <g v-for="i in [0, 6, 13, 20, n - 1].filter((k, j, a) => k >= 0 && k < n && a.indexOf(k) === j)" :key="'t' + i">
      <text :x="x(i)" :y="H - 6" class="ax" text-anchor="middle">{{ i + 1 }}</text>
    </g>
    <rect v-for="i in n" :key="'h' + i" :x="L + iw * (i - 1)" :y="T - 4" :width="iw" :height="H - T - B + 4" fill="transparent" class="hit" tabindex="0" role="button" :aria-label="String(i)" @click="emit('pick', i - 1)" @keydown.enter="emit('pick', i - 1)" />
  </svg>
</template>

<style scoped>
.db { width: 100%; height: auto; display: block; overflow: visible; }
.grid { stroke: var(--line); stroke-width: 1; stroke-dasharray: 2 4; }
.ax { fill: var(--muted); font-size: 9.5px; font-weight: 500; }
.wk { fill: color-mix(in srgb, var(--red) 7%, transparent); }
.selbg { fill: color-mix(in srgb, var(--c) 12%, transparent); }
.bar { transform-box: fill-box; transform-origin: 50% 100%; transform: scaleY(0); transition: transform 0.6s cubic-bezier(0.2, 0.9, 0.3, 1); }
.bar.go { transform: scaleY(1); }
.fdot { fill: var(--card); stroke: var(--muted); stroke-width: 1.3; }
.hit { cursor: pointer; outline: none; }
@media (prefers-reduced-motion: reduce) { .bar { transition: none; transform: none; } }
</style>
