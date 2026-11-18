<script setup>
import { computed } from 'vue'
import { ringkas } from '../../utils/chart'

// Peta panas baris (terminal/cabang) x hari. Nilai negatif ditandai merah. Ketuk sel untuk rincian.
const props = defineProps({ rows: Array, weekend: { type: Array, default: () => [] }, color: { type: String, default: '#0a66e8' }, sel: { type: Number, default: -1 } })
const emit = defineEmits(['pick'])
const max = computed(() => Math.max(1, ...props.rows.flatMap((r) => r.vals.map((v) => Math.abs(v)))))
const pct = (v) => (v === 0 ? 0 : 14 + 76 * Math.sqrt(Math.abs(v) / max.value))
const n = computed(() => props.rows[0]?.vals.length || 0)
</script>

<template>
  <div class="dh" :style="{ '--hc': color, '--cols': n }">
    <div class="scroll">
      <div class="grid head">
        <span class="nm" />
        <span v-for="i in n" :key="i" class="mo" :class="{ we: weekend[i - 1], on: sel === i - 1 }">{{ i }}</span>
      </div>
      <div v-for="r in rows" :key="r.id" class="grid r">
        <span class="nm">{{ r.nama }}</span>
        <button v-for="(v, i) in r.vals" :key="i" class="c" :class="{ neg: v < 0, on: sel === i }" :disabled="v === 0"
          :style="{ background: v === 0 ? 'transparent' : `color-mix(in srgb, ${v < 0 ? 'var(--red)' : 'var(--hc)'} ${pct(v)}%, var(--card))`, color: pct(v) > 52 ? '#fff' : 'var(--ink)' }"
          :aria-label="`${r.nama} ${i + 1}`" @click="emit('pick', { id: r.id, i })">{{ v === 0 ? '' : ringkas(v, 0) }}</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.scroll { overflow-x: auto; margin: 0 -16px; padding: 0 16px 4px; -webkit-overflow-scrolling: touch; scrollbar-width: thin; }
.grid { display: grid; grid-template-columns: 112px repeat(var(--cols), 38px); gap: 3px; align-items: center; width: max-content; margin-bottom: 3px; }
.nm { position: sticky; left: 0; z-index: 2; background: var(--card); padding-right: 6px; font-size: 12px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-height: 32px; display: flex; align-items: center; }
.mo { font-size: 10.5px; color: var(--muted); text-align: center; font-weight: 600; } .mo.we { color: var(--red); } .mo.on { color: var(--ink); font-weight: 800; }
.c { border: 0; font-family: inherit; height: 32px; border-radius: 8px; font-size: 10px; font-weight: 700; display: grid; place-items: center; font-variant-numeric: tabular-nums; padding: 0; }
.c:not(:disabled) { cursor: pointer; } .c:not(:disabled):active { transform: scale(0.92); }
.c:disabled { cursor: default; background: color-mix(in srgb, var(--line) 35%, transparent); }
.c.on { outline: 2px solid var(--ink); outline-offset: -2px; }
</style>
