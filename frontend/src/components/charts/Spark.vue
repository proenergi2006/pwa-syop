<script setup>
import { computed } from 'vue'

// Garis mini + area untuk kartu ringkasan.
const props = defineProps({ values: Array, color: { type: String, default: '#0a66e8' }, upto: { type: Number, default: 12 } })
const W = 100, H = 34
const uid = Math.random().toString(36).slice(2, 7)
const pts = computed(() => {
  const v = props.values.slice(0, Math.max(2, props.upto))
  const m = Math.max(1, ...v)
  return v.map((x, i) => [(i / Math.max(1, v.length - 1)) * W, H - 3 - (x / m) * (H - 8)])
})
const d = computed(() => pts.value.map((p, i) => `${i ? 'L' : 'M'}${p[0].toFixed(1)} ${p[1].toFixed(1)}`).join(' '))
const area = computed(() => `${d.value} L${W} ${H} L0 ${H} Z`)
</script>

<template>
  <svg :viewBox="`0 0 ${W} ${H}`" class="sp" preserveAspectRatio="none" aria-hidden="true">
    <defs>
      <linearGradient :id="`s${uid}`" x1="0" y1="0" x2="0" y2="1"><stop offset="0" :stop-color="color" stop-opacity=".28" /><stop offset="1" :stop-color="color" stop-opacity="0" /></linearGradient>
    </defs>
    <path :d="area" :fill="`url(#s${uid})`" />
    <path :d="d" fill="none" :stroke="color" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
  </svg>
</template>

<style scoped>
.sp { width: 100%; height: 34px; display: block; }
</style>
