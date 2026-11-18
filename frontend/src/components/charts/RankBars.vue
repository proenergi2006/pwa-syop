<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { ringkas } from '../../utils/chart'
import { t } from '../../i18n'

// Peringkat horizontal. items: [{ id, nama, value }] sudah terurut; ketuk baris untuk memilih marketing.
const props = defineProps({ items: Array, color: { type: String, default: '#0a66e8' }, sel: { type: Number, default: 0 }, limit: { type: Number, default: 8 } })
const emit = defineEmits(['pick'])
const all = ref(false)
const go = ref(false)
function run() { go.value = false; requestAnimationFrame(() => requestAnimationFrame(() => (go.value = true))) }
onMounted(run)
watch(() => props.items, run)
const max = computed(() => Math.max(1, ...props.items.map((x) => x.value)))
const total = computed(() => props.items.reduce((s, x) => s + x.value, 0) || 1)
const shown = computed(() => (all.value ? props.items : props.items.slice(0, props.limit)))
</script>

<template>
  <div class="rk">
    <button v-for="(x, i) in shown" :key="x.id" class="row" :class="{ on: sel === x.id, dim: sel && sel !== x.id }" @click="emit('pick', x.id)">
      <span class="no" :class="{ hi: i < 3 }">{{ i + 1 }}</span>
      <span class="body">
        <span class="top"><span class="nm">{{ x.nama }}</span><b>{{ ringkas(x.value) }}</b></span>
        <span class="track"><i :style="{ width: go ? (x.value / max) * 100 + '%' : '0%', background: color, transitionDelay: i * 40 + 'ms' }" /></span>
      </span>
      <span class="pc">{{ ((x.value / total) * 100).toFixed(0) }}%</span>
    </button>
    <button v-if="items.length > limit" class="btn btn-ghost btn-block more" @click="all = !all">{{ all ? t('rm.less') : t('rm.more', { n: items.length }) }}</button>
  </div>
</template>

<style scoped>
.rk { display: grid; gap: 2px; }
.row { border: 0; background: none; font: inherit; color: inherit; display: grid; grid-template-columns: 24px minmax(0, 1fr) 36px; gap: 10px; align-items: center; padding: 8px 6px; border-radius: 12px; text-align: left; min-height: 44px; transition: background 0.15s, opacity 0.2s; }
.row.on { background: var(--blue-50); }
.row.dim { opacity: 0.5; }
.no { width: 24px; height: 24px; border-radius: 8px; display: grid; place-items: center; font-size: 11.5px; font-weight: 700; background: var(--blue-50); color: var(--muted); }
.no.hi { background: var(--accent); color: var(--card); }
.body { display: grid; gap: 5px; min-width: 0; }
.top { display: flex; justify-content: space-between; gap: 8px; font-size: 13px; }
.nm { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; }
.top b { font-variant-numeric: tabular-nums; }
.track { display: block; height: 7px; border-radius: 4px; background: var(--line); overflow: hidden; }
.track i { display: block; height: 100%; border-radius: 4px; transition: width 0.7s cubic-bezier(0.2, 0.9, 0.3, 1); }
.pc { font-size: 11.5px; color: var(--muted); text-align: right; font-variant-numeric: tabular-nums; }
.more { margin-top: 6px; }
@media (prefers-reduced-motion: reduce) { .track i { transition: none; } }
</style>
