<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { PALET, ringkas } from '../../utils/chart'

// Donat kontribusi. items: [{ label, value }]; warna diambil dari palet berurutan.
const props = defineProps({ items: { type: Array, required: true }, center: { type: String, default: '' }, caption: { type: String, default: '' } })
const R = 52, C = 2 * Math.PI * R
const go = ref(false)
function run() { go.value = false; requestAnimationFrame(() => requestAnimationFrame(() => (go.value = true))) }
onMounted(run)
watch(() => props.items, run)

const total = computed(() => props.items.reduce((s, x) => s + x.value, 0) || 1)
const segs = computed(() => {
  let off = 0
  return props.items.map((x, i) => {
    const len = Math.max(0, (x.value / total.value) * C - (props.items.length > 1 ? 2.5 : 0))
    const s = { ...x, len, off, color: PALET[i % PALET.length], pct: (x.value / total.value) * 100 }
    off += (x.value / total.value) * C
    return s
  })
})
</script>

<template>
  <div class="dn">
    <svg viewBox="0 0 140 140" class="ring" role="img" :aria-label="caption">
      <circle cx="70" cy="70" :r="R" class="track" />
      <circle v-for="s in segs" :key="s.label" cx="70" cy="70" :r="R" class="seg" :stroke="s.color"
        :stroke-dasharray="`${go ? s.len : 0} ${C}`" :stroke-dashoffset="-s.off" transform="rotate(-90 70 70)" />
      <text x="70" y="68" text-anchor="middle" class="big">{{ center }}</text>
      <text x="70" y="84" text-anchor="middle" class="cap">{{ caption }}</text>
    </svg>
    <ul class="lg">
      <li v-for="s in segs" :key="s.label"><i :style="{ background: s.color }" /><span class="nm">{{ s.label }}</span><b>{{ s.pct.toFixed(s.pct < 10 ? 1 : 0) }}%</b></li>
    </ul>
  </div>
</template>

<style scoped>
.dn { display: grid; grid-template-columns: 138px minmax(0, 1fr); gap: 14px; align-items: center; }
.ring { width: 138px; height: 138px; }
.track { fill: none; stroke: var(--line); stroke-width: 15; }
.seg { fill: none; stroke-width: 15; transition: stroke-dasharray 0.9s cubic-bezier(0.2, 0.9, 0.3, 1); }
.big { fill: var(--ink); font-size: 17px; font-weight: 800; }
.cap { fill: var(--muted); font-size: 9px; }
.lg { list-style: none; margin: 0; padding: 0; display: grid; gap: 7px; min-width: 0; }
.lg li { display: grid; grid-template-columns: 10px minmax(0, 1fr) auto; gap: 8px; align-items: center; font-size: 12.5px; }
.lg i { width: 10px; height: 10px; border-radius: 3px; }
.nm { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--ink-2); }
@media (prefers-reduced-motion: reduce) { .seg { transition: none; } }
</style>
