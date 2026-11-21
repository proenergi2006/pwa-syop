<script setup>
import { computed, ref } from 'vue'
import { bulanPendek, ringkas } from '../../utils/chart'
import { t } from '../../i18n'
import ZoomTable from '../ZoomTable.vue'

// Peta panas marketing x bulan. Ketuk sel berisi untuk membuka rincian.
const props = defineProps({ rows: Array, color: { type: String, default: '#0a66e8' }, limit: { type: Number, default: 12 }, sel: { type: Number, default: 0 } })
const emit = defineEmits(['pick'])
const all = ref(false)
const max = computed(() => Math.max(1, ...props.rows.flatMap((r) => r.bulan)))
const shown = computed(() => (all.value ? props.rows : props.rows.slice(0, props.limit)))
const pct = (v) => (v > 0 ? 14 + 76 * Math.sqrt(v / max.value) : 0)
const tot = computed(() => Array.from({ length: 12 }, (_, i) => props.rows.reduce((s, r) => s + r.bulan[i], 0)))
</script>

<template>
  <div class="hg" :style="{ '--hc': color }">
    <ZoomTable max-height="none">
      <div class="grid head">
        <span class="nm" />
        <span v-for="i in 12" :key="i" class="mo">{{ bulanPendek(i - 1).slice(0, 3) }}</span>
        <span class="tt">Σ</span>
      </div>
      <div v-for="r in shown" :key="r.id" class="grid r" :class="{ on: sel === r.id }">
        <span class="nm">{{ r.nama }}</span>
        <button v-for="(v, i) in r.bulan" :key="i" class="c" :disabled="v <= 0" :aria-label="`${r.nama} ${bulanPendek(i)}`"
          :style="{ background: v > 0 ? `color-mix(in srgb, var(--hc) ${pct(v)}%, var(--card))` : 'transparent', color: pct(v) > 52 ? '#fff' : 'var(--ink)' }"
          @click="emit('pick', { id: r.id, month: i })">{{ v > 0 ? ringkas(v, 0) : '' }}</button>
        <b class="tt">{{ ringkas(r.total, 0) }}</b>
      </div>
      <div class="grid r sum">
        <span class="nm">Total</span>
        <span v-for="(v, i) in tot" :key="i" class="c s">{{ v > 0 ? ringkas(v, 0) : '' }}</span>
        <b class="tt">{{ ringkas(tot.reduce((a, b) => a + b, 0), 0) }}</b>
      </div>
    </ZoomTable>
    <button v-if="rows.length > limit" class="btn btn-ghost btn-block" style="margin-top:8px" @click="all = !all">{{ all ? t('rm.less') : t('rm.more', { n: rows.length }) }}</button>
  </div>
</template>

<style scoped>
.hg { min-width: 0; max-width: 100%; }
.scroll { overflow-x: auto; margin: 0 -16px; padding: 0 16px 4px; -webkit-overflow-scrolling: touch; scrollbar-width: thin; }
.grid { display: grid; grid-template-columns: 104px repeat(12, 40px) 54px; gap: 3px; align-items: center; width: max-content; margin-bottom: 3px; }
.nm { position: sticky; left: 0; z-index: 2; background: var(--card); padding-right: 6px; font-size: 12px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-height: 32px; display: flex; align-items: center; }
.head .mo, .head .tt { font-size: 10.5px; color: var(--muted); text-align: center; font-weight: 600; }
.c { border: 0; font-family: inherit; height: 32px; border-radius: 8px; font-size: 10px; font-weight: 700; display: grid; place-items: center; font-variant-numeric: tabular-nums; padding: 0; }
.c:not(:disabled) { cursor: pointer; }
.c:not(:disabled):active { transform: scale(0.92); }
.c:disabled { cursor: default; background: color-mix(in srgb, var(--line) 35%, transparent); }
.c.s { background: none; color: var(--muted); font-weight: 700; cursor: default; }
.tt { font-size: 11px; text-align: center; font-variant-numeric: tabular-nums; }
.r.on .nm { color: var(--accent); }
.sum { border-top: 1px solid var(--line); padding-top: 4px; }
.sum .nm { font-weight: 800; }
</style>
