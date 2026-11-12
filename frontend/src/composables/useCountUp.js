import { ref, watch } from 'vue'

const reduced = () => typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches

/** Angka yang menghitung naik dengan halus saat nilainya berubah. */
export function useCountUp(source, ms = 800) {
  const shown = ref(0)
  let raf = 0
  watch(source, (to) => {
    cancelAnimationFrame(raf)
    const from = shown.value
    if (reduced() || from === to) { shown.value = to; return }
    const t0 = performance.now()
    const step = (now) => {
      const p = Math.min(1, (now - t0) / ms)
      const e = 1 - Math.pow(1 - p, 3) // easeOutCubic
      shown.value = Math.round(from + (to - from) * e)
      if (p < 1) raf = requestAnimationFrame(step)
    }
    raf = requestAnimationFrame(step)
  }, { immediate: true })
  return shown
}
