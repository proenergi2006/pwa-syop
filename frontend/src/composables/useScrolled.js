import { onBeforeUnmount, onMounted, ref } from 'vue'

/** true setelah halaman digulir lebih dari `px` (untuk header yang menempel). */
export function useScrolled(px = 8) {
  const scrolled = ref(false)
  let raf = 0
  const check = () => { raf = 0; scrolled.value = window.scrollY > px }
  const onScroll = () => { if (!raf) raf = requestAnimationFrame(check) }
  onMounted(() => { check(); window.addEventListener('scroll', onScroll, { passive: true }) })
  onBeforeUnmount(() => { window.removeEventListener('scroll', onScroll); if (raf) cancelAnimationFrame(raf) })
  return scrolled
}
