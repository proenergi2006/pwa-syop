<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { bulanPendek, niceMax, ringkas } from '../../utils/chart'

// Batang bulanan (tahun ini) + garis putus-putus pembanding (tahun lalu). Ketuk kolom untuk memilih bulan.
const props = defineProps({
  values: { type: Array, required: true },
  prev: { type: Array, default: null },
  color: { type: String, default: '#0a66e8' },
  upto: { type: Number, default: 12 }, // jumlah bulan yang sudah berjalan
  sel: { type: Number, default: -1 },
  label: { type: String, default: '' },
})
const emit = defineEmits(['pick'])

const W = 360, H = 212, L = 42, R = 8, T = 22, B = 26
const iw = (W - L - R) / 12
const go = ref(false)
function run() { go.value = false; requestAnimationFrame(() => requestAnimationFrame(() => (go.value = true))) }
onMounted(run)
watch(() => props.values, run)

const max = computed(() => niceMax(Math.max(1, ...props.values, ...(props.prev || []))))
const ticks = computed(() => [0, 1, 2, 3, 4].map((i) => (max.value * i) / 4))
const x = (i) => L + iw * i + iw / 2
const y = (v) => T + (H - T - B) * (1 - v / max.value)
const bw = Math.min(20, iw * 0.62)
const peak = computed(() => {
  let k = -1, m = 0
  props.values.forEach((v, i) => { if (v > m) { m = v; k = i } })
  return k
})
const line = computed(() => (props.prev ? props.prev.map((v, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)} ${y(v).toFixed(1)}`).join(' ') : ''))
const uid = Math.random().toString(36).slice(2, 7)
</script>

<template>
  <svg :viewBox="`0 0 ${W} ${H}`" class="mb" role="img" :aria-label="label">
    <defs>
      <linearGradient :id="`g${uid}`" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0" :stop-color="color" />
        <stop offset="1" :stop-color="color" stop-opacity=".55" />
      </linearGradient>
    </defs>
    <g v-for="(tk, i) in ticks" :key="i">
      <line :x1="L" :x2="W - R" :y1="y(tk)" :y2="y(tk)" class="grid" />
      <text :x="L - 6" :y="y(tk) + 3.5" class="ax" text-anchor="end">{{ ringkas(tk, 1) }}</text>
    </g>
    <rect v-if="sel >= 0" :x="L + iw * sel + 1" :y="T - 6" :width="iw - 2" :height="H - T - B + 6" rx="6" class="selbg" :style="{ '--c': color }" />
    <g v-for="(v, i) in values" :key="i">
      <rect v-if="v > 0" class="bar" :class="{ go }" :x="x(i) - bw / 2" :y="y(v)" :width="bw" :height="Math.max(2, y(0) - y(v))" rx="4"
        :fill="`url(#g${uid})`" :opacity="sel < 0 || sel === i ? 1 : 0.45" :style="{ transitionDelay: i * 35 + 'ms' }" />
      <text v-if="i === peak && v > 0" :x="x(i)" :y="y(v) - 6" class="pk" text-anchor="middle" :class="{ go }">{{ ringkas(v) }}</text>
    </g>
    <path v-if="line" :d="line" class="prev" />
    <circle v-for="(v, i) in (prev || [])" :key="'p' + i" :cx="x(i)" :cy="y(v)" r="2.4" class="prevdot" />
    <g v-for="i in 12" :key="'m' + i">
      <text :x="x(i - 1)" :y="H - 8" class="ax" :class="{ dim: i > upto, on: sel === i - 1 }" text-anchor="middle">{{ bulanPendek(i - 1).slice(0, 3) }}</text>
      <rect :x="L + iw * (i - 1)" :y="T - 6" :width="iw" :height="H - T - B + 6 + 24" fill="transparent" class="hit" tabindex="0" role="button"
        :aria-label="bulanPendek(i - 1)" @click="emit('pick', i - 1)" @keydown.enter="emit('pick', i - 1)" />
    </g>
  </svg>
</template>

<style scoped>
.mb { width: 100%; height: auto; display: block; overflow: visible; }
.grid { stroke: var(--line); stroke-width: 1; stroke-dasharray: 2 4; }
.ax { fill: var(--muted); font-size: 9.5px; font-weight: 500; }
.ax.dim { opacity: 0.45; }
.ax.on { fill: var(--ink); font-weight: 700; }
.selbg { fill: color-mix(in srgb, var(--c) 10%, transparent); }
.bar { transform-box: fill-box; transform-origin: 50% 100%; transform: scaleY(0); transition: transform 0.6s cubic-bezier(0.2, 0.9, 0.3, 1), opacity 0.2s; }
.bar.go { transform: scaleY(1); }
.pk { fill: var(--ink); font-size: 10px; font-weight: 700; opacity: 0; transition: opacity 0.4s 0.5s; }
.pk.go { opacity: 1; }
.prev { fill: none; stroke: var(--muted); stroke-width: 1.6; stroke-dasharray: 4 3; opacity: 0.85; }
.prevdot { fill: var(--card); stroke: var(--muted); stroke-width: 1.4; }
.hit { cursor: pointer; outline: none; }
@media (prefers-reduced-motion: reduce) { .bar { transition: none; transform: none; } }
</style>
