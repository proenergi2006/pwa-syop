<script setup>
import { computed } from 'vue'
import { angka } from '../utils/format'

// Input angka rupiah: menyimpan digit murni, menampilkan dengan pemisah ribuan.
const props = defineProps({ modelValue: { type: [String, Number], default: '' }, label: String, hint: String, invalid: Boolean, id: String })
const emit = defineEmits(['update:modelValue'])
const shown = computed(() => (props.modelValue === '' || props.modelValue == null ? '' : angka(props.modelValue)))
function onInput(e) {
  const digits = e.target.value.replace(/\D/g, '').slice(0, 10)
  emit('update:modelValue', digits)
  e.target.value = digits ? angka(digits) : ''
}
</script>

<template>
  <label class="pi" :class="{ bad: invalid }">
    <span class="pl">{{ label }}<small v-if="hint">{{ hint }}</small></span>
    <span class="pw"><i>Rp</i><input :id="id" :value="shown" inputmode="numeric" autocomplete="off" placeholder="0" @input="onInput" /></span>
  </label>
</template>

<style scoped>
.pi { display: grid; gap: 4px; min-width: 0; }
.pl { font-size: 12.5px; font-weight: 600; color: var(--ink-2); display: flex; justify-content: space-between; gap: 6px; }
.pl small { color: var(--muted); font-weight: 500; }
.pw { display: flex; align-items: center; gap: 8px; min-height: 46px; padding: 0 12px; border: 1px solid var(--line); border-radius: 12px; background: var(--surface); }
.pw:focus-within { border-color: var(--focus); box-shadow: 0 0 0 4px rgba(240, 128, 7, 0.18); }
.pw i { font-style: normal; color: var(--muted); font-size: 13px; }
.pw input { flex: 1; min-width: 0; width: 100%; border: 0; outline: 0; background: transparent; font-size: 16px; font-weight: 700; text-align: right; color: var(--ink); }
.bad .pw { border-color: var(--red); }
</style>
