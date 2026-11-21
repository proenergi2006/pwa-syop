<script setup>
// Pembungkus tabel lebar: tombol zoom (- / + / pas), cubitan dua jari, dan mode layar penuh.
// Isi tabel (slot) tidak diubah; hanya diperbesar dengan CSS zoom, sehingga header dan kolom label yang menempel tetap berfungsi.
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import Icon from './Icon.vue'

const props = defineProps({ minZoom: { type: Number, default: 0.6 }, maxZoom: { type: Number, default: 3 }, fullStart: { type: Number, default: 1.4 } })
const LANGKAH = [0.6, 0.75, 0.9, 1, 1.25, 1.5, 2, 2.5, 3]
const zInline = ref(1)
const zFull = ref(props.fullStart)
const full = ref(false)
const z = computed({ get: () => (full.value ? zFull.value : zInline.value), set: (v) => { const n = Math.min(props.maxZoom, Math.max(props.minZoom, v)); full.value ? (zFull.value = n) : (zInline.value = n) } })
const persen = computed(() => Math.round(z.value * 100) + '%')

function ubah(arah) {
  const sekarang = z.value
  const langkah = arah > 0 ? LANGKAH.find((x) => x > sekarang + 0.001) ?? props.maxZoom : [...LANGKAH].reverse().find((x) => x < sekarang - 0.001) ?? props.minZoom
  zoomDi(langkah, null)
}

// Ubah zoom dengan mempertahankan titik tengah tampilan (atau titik cubitan) di tempat yang sama.
let wadah = null
function zoomDi(baru, titik) {
  const lama = z.value
  const el = wadah
  z.value = baru
  if (!el) return
  const cx = titik ? titik.x : el.clientWidth / 2
  const cy = titik ? titik.y : el.clientHeight / 2
  const isiX = (el.scrollLeft + cx) / lama
  const isiY = (el.scrollTop + cy) / lama
  nextTick(() => { el.scrollLeft = isiX * z.value - cx; el.scrollTop = isiY * z.value - cy })
}

// Cubitan dua jari pada wadah gulir.
function pasangCubit(el) {
  if (!el || el.__cubit) return
  el.__cubit = true
  let jarak0 = 0
  let z0 = 1
  const jarak = (t) => Math.hypot(t[0].clientX - t[1].clientX, t[0].clientY - t[1].clientY)
  el.addEventListener('touchstart', (e) => { if (e.touches.length === 2) { jarak0 = jarak(e.touches); z0 = z.value } }, { passive: true })
  el.addEventListener('touchmove', (e) => {
    if (e.touches.length !== 2 || !jarak0) return
    e.preventDefault()
    const r = el.getBoundingClientRect()
    const titik = { x: (e.touches[0].clientX + e.touches[1].clientX) / 2 - r.left, y: (e.touches[0].clientY + e.touches[1].clientY) / 2 - r.top }
    zoomDi(Math.min(props.maxZoom, Math.max(props.minZoom, z0 * (jarak(e.touches) / jarak0))), titik)
  }, { passive: false })
  el.addEventListener('touchend', (e) => { if (e.touches.length < 2) jarak0 = 0 }, { passive: true })
}
const ikat = (el) => { wadah = el; if (el) pasangCubit(el) }

function bukaPenuh() { full.value = true }
function tutupPenuh() { full.value = false }
watch(full, (v) => { document.documentElement.style.overflow = v ? 'hidden' : '' })
onBeforeUnmount(() => { document.documentElement.style.overflow = '' })
</script>

<template>
  <div class="zt-root">
    <template v-if="!full">
      <div class="zbar">
        <button type="button" class="zb" :disabled="z <= minZoom" aria-label="Perkecil" @click="ubah(-1)">−</button>
        <button type="button" class="zp" aria-label="Kembali ke 100%" @click="zoomDi(1, null)">{{ persen }}</button>
        <button type="button" class="zb" :disabled="z >= maxZoom" aria-label="Perbesar" @click="ubah(1)">+</button>
        <button type="button" class="zb zfull" aria-label="Layar penuh" @click="bukaPenuh"><Icon name="grid" :size="16" /> Layar penuh</button>
      </div>
      <div :ref="ikat" class="zscroll"><div class="zisi" :style="{ zoom: z }"><slot /></div></div>
      <p class="zhint">Cubit dengan dua jari atau pakai tombol + / − untuk memperbesar.</p>
    </template>
    <Teleport v-else to="body">
      <div class="zover" role="dialog" aria-modal="true" aria-label="Tabel layar penuh">
        <div class="zbar zbar-full">
          <button type="button" class="zb" :disabled="z <= minZoom" aria-label="Perkecil" @click="ubah(-1)">−</button>
          <button type="button" class="zp" aria-label="Kembali ke 100%" @click="zoomDi(1, null)">{{ persen }}</button>
          <button type="button" class="zb" :disabled="z >= maxZoom" aria-label="Perbesar" @click="ubah(1)">+</button>
          <button type="button" class="zb zfull" aria-label="Tutup layar penuh" @click="tutupPenuh"><Icon name="x" :size="16" /> Tutup</button>
        </div>
        <div :ref="ikat" class="zscroll zscroll-full"><div class="zisi" :style="{ zoom: z }"><slot /></div></div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.zt-root { min-width: 0; }
.zbar { display: flex; align-items: center; gap: 8px; padding: 8px 0; }
.zb, .zp { min-width: 44px; height: 44px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 20px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 6px; touch-action: manipulation; -webkit-tap-highlight-color: transparent; }
.zb:disabled { opacity: 0.4; }
.zp { font-size: 14px; min-width: 64px; font-variant-numeric: tabular-nums; }
.zfull { margin-left: auto; font-size: 13px; font-weight: 600; }
.zscroll { overflow: auto; max-height: 560px; margin: 0 -16px; padding: 0 16px; border-top: 1px solid var(--line); -webkit-overflow-scrolling: touch; overscroll-behavior: contain; }
.zhint { margin: 6px 0 0; font-size: 11.5px; color: var(--muted); }
.zover { position: fixed; inset: 0; z-index: 9000; display: flex; flex-direction: column; background: var(--bg); color: var(--ink); }
.zbar-full { padding: calc(env(safe-area-inset-top, 0px) + 8px) calc(env(safe-area-inset-right, 0px) + 12px) 8px calc(env(safe-area-inset-left, 0px) + 12px); background: var(--card); border-bottom: 1px solid var(--line); }
.zscroll-full { flex: 1; max-height: none; margin: 0; padding: 0 0 env(safe-area-inset-bottom, 0px); border: 0; }
</style>
