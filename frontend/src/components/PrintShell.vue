<script setup>
// Kerangka halaman cetak. Isinya dipindahkan ke <body> (di luar #app) supaya:
//  1) saat dicetak cukup menyembunyikan #app; kertas tetap tampil (sebelumnya semua ikut tersembunyi -> PDF kosong),
//  2) bilah tombol tidak terkurung transisi/overflow pembungkus aplikasi dan tidak tertutup area aman iPhone (notch / status bar).
import { onBeforeUnmount, watch } from 'vue'

const props = defineProps({ ready: Boolean, error: String, hint: String, judul: String })
const emit = defineEmits(['back', 'print'])
// Nama berkas PDF yang disarankan browser = judul halaman. Karakter yang tidak boleh di nama berkas (/ \\ : * ? " < > |) diganti "-".
const judulAsli = typeof document !== 'undefined' ? document.title : ''
const bersih = (t) => String(t || '').replace(/[\\/:*?"<>|\u0000-\u001f]+/g, '-').replace(/\s+/g, ' ').replace(/-{2,}/g, '-').trim()
watch(() => props.judul, (t) => { if (t) document.title = bersih(t) }, { immediate: true })
onBeforeUnmount(() => { document.title = judulAsli })
const iosPwa = typeof navigator !== 'undefined' && /iP(hone|ad|od)/.test(navigator.userAgent) && navigator.standalone === true
</script>

<template>
  <Teleport to="body">
    <div class="pshell">
      <div class="pbar no-print">
        <button type="button" class="btn btn-ghost" @click="emit('back')">Kembali</button>
        <button type="button" class="btn btn-primary" :disabled="!ready" @click="emit('print')">Cetak / Simpan PDF</button>
      </div>
      <p v-if="hint" class="pnote no-print">{{ hint }}</p>
      <p v-if="iosPwa" class="pnote no-print">Di iPhone, bila dialog cetak tidak muncul, buka aplikasi lewat Safari (bukan ikon layar utama), lalu tekan Cetak dan pilih "Simpan ke Berkas".</p>
      <p v-if="error" class="pnote perr no-print">{{ error }}</p>
      <p v-else-if="!ready" class="pnote no-print">Memuat…</p>
      <slot />
    </div>
  </Teleport>
</template>

<style>
.pshell { position: fixed; inset: 0; z-index: 10000; overflow: auto; -webkit-overflow-scrolling: touch; background: #e9e9ee; color: #000; }
.pbar { position: sticky; top: 0; z-index: 2; display: flex; gap: 10px; justify-content: space-between; padding: calc(env(safe-area-inset-top, 0px) + 10px) calc(env(safe-area-inset-right, 0px) + 12px) 10px calc(env(safe-area-inset-left, 0px) + 12px); background: #e9e9ee; }
.pbar .btn { min-height: 48px; touch-action: manipulation; -webkit-tap-highlight-color: transparent; }
.pnote { margin: 0 12px 8px; font-size: 12.5px; color: #555; }
.perr { color: #b00020; }
@media print {
  #app { display: none !important; }
  html, body { height: auto !important; min-height: 0 !important; overflow: visible !important; background: #fff !important; }
  .pshell { position: static !important; inset: auto !important; overflow: visible !important; background: #fff !important; }
  .no-print { display: none !important; }
}
</style>
