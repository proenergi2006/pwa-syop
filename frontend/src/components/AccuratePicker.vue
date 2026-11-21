<script setup>
// Pemilih kode item/akun Accurate: cari lewat API; bila Accurate belum aktif, isi kode manual.
import { ref, watch } from 'vue'

const props = defineProps({ modelValue: { type: String, default: '' }, fetcher: { type: Function, required: true }, label: String, placeholder: { type: String, default: 'Cari kode atau nama…' }, required: Boolean, disabled: Boolean })
const emit = defineEmits(['update:modelValue'])
const q = ref('')
const items = ref([])
const info = ref('')
let timer
async function cari() {
  info.value = ''
  try {
    const r = await props.fetcher(q.value.trim())
    items.value = r.items || []
    if (r.accurate === false) info.value = 'Accurate belum aktif di server; isi kode manual.'
    else if (!items.value.length) info.value = 'Tidak ada hasil.'
  } catch (e) { info.value = e.response?.data?.message || 'Gagal memuat daftar Accurate.' }
}
watch(q, () => { clearTimeout(timer); timer = setTimeout(cari, 400) })
</script>

<template>
  <div class="field">
    <label>{{ label }}<template v-if="required"> *</template></label>
    <p v-if="modelValue" class="cur">Dipilih: <b>{{ modelValue }}</b> <button v-if="!disabled" type="button" class="x" aria-label="Hapus pilihan" @click="emit('update:modelValue', '')">×</button></p>
    <template v-if="!disabled">
      <input v-model="q" class="inp" :placeholder="placeholder" autocomplete="off" @focus="!items.length && cari()" />
      <p v-if="info" class="muted">{{ info }}</p>
      <select v-if="items.length" class="inp" style="margin-top:8px" :value="modelValue" @change="emit('update:modelValue', $event.target.value)"><option value="">Pilih…</option><option v-for="x in items" :key="x.id" :value="x.id">{{ x.text }}</option></select>
      <input v-else class="inp" style="margin-top:8px" :value="modelValue" placeholder="Kode (manual)" @input="emit('update:modelValue', $event.target.value)" />
    </template>
  </div>
</template>

<style scoped>
.inp { width: 100%; min-height: 46px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; box-sizing: border-box; }
.muted { color: var(--muted); font-size: 12.5px; margin: 4px 0 0; }
.cur { margin: 0 0 6px; font-size: 13.5px; } .x { border: 0; background: none; font-size: 18px; color: var(--red); }
</style>
