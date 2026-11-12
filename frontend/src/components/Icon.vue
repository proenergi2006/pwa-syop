<script setup>
import { computed } from 'vue'
const props = defineProps({ name: String, size: { type: Number, default: 22 } })
// Ikon garis 24x24. Konten statis internal, bukan input pengguna.
const P = {
  home: '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
  grid: '<rect x="4" y="4" width="6.5" height="6.5" rx="1.5"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.5"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.5"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.5"/>',
  doc: '<path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5"/><path d="M10 13h6M10 17h6"/>',
  history: '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/>',
  user: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
  search: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
  bell: '<path d="M6 9a6 6 0 0 1 12 0c0 6 2.5 7.5 2.5 7.5h-17S6 15 6 9z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
  logout: '<path d="M9 4H5v16h4"/><path d="M16 8l4 4-4 4"/><path d="M20 12H9"/>',
  drop: '<path d="M12 3s6 6.5 6 11a6 6 0 0 1-12 0c0-4.5 6-11 6-11z"/>',
  truck: '<path d="M2 6h11v10H2z"/><path d="M13 9h5l4 4v3h-9"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
  chart: '<path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-7"/><path d="M22 20H2"/>',
  cart: '<path d="M3 4h2l2.4 11h10.2L20 7H6"/><circle cx="9" cy="19" r="1.5"/><circle cx="17" cy="19" r="1.5"/>',
  invoice: '<path d="M7 3h10v18l-2.5-1.7L12 21l-2.5-1.7L7 21z"/><path d="M10 8h4M10 12h4"/>',
  calendar: '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/>',
  headset: '<path d="M4 14v-2a8 8 0 0 1 16 0v2"/><rect x="3" y="14" width="4" height="6" rx="1.5"/><rect x="17" y="14" width="4" height="6" rx="1.5"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  trash: '<path d="M4 7h16M10 11v6M14 11v6"/><path d="M6 7l1 13h10l1-13M9 7V4h6v3"/>',
  edit: '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/>',
  car: '<path d="M5 16V12l2-5h10l2 5v4z"/><path d="M5 12h14"/><circle cx="8" cy="16" r="1.6"/><circle cx="16" cy="16" r="1.6"/><path d="M5 16v2h3v-2M16 16v2h3v-2"/>',
  tag: '<path d="M3 12V4h8l9 9-8 8z"/><circle cx="7.5" cy="8.5" r="1.3"/>',
  ship: '<path d="M3 15l2 4h14l2-4z"/><path d="M5 15V9h10l4 6"/><path d="M9 9V5h4v4"/>',
  filter: '<path d="M4 6h16M7 12h10M10 18h4"/>',
  box: '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/><path d="M4 7.5l8 4.5 8-4.5"/><path d="M12 12v9"/>',
  po: '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3h6v1"/><path d="M9 11h6M9 15h4"/>',
  refund: '<path d="M4 12a8 8 0 1 1 2.6 5.9"/><path d="M4 6v6h6"/><path d="M12 8.5v7"/><path d="M14.4 10.2c-.5-.7-1.3-1-2.3-1-1.2 0-2 .6-2 1.5 0 2.1 4.6.9 4.6 3.1 0 .9-.9 1.6-2.2 1.6-1 0-1.9-.4-2.5-1.1"/>',
  orca: '<g transform="translate(-5.6 -5.8) scale(0.07)"><path stroke-width="23" d="M92 292c8-62 64-104 128-104 36 0 66 12 92 30 18-6 34-22 40-48 16 18 22 40 16 62 20 8 36 22 48 42-26-4-46 0-62 8-22 40-72 66-130 66-70 0-140-18-132-56z"/><path stroke-width="23" d="M236 198c18-30 44-44 70-46"/><path stroke-width="23" d="M300 262c26 2 52 14 70 30"/><circle cx="176" cy="250" r="13" fill="currentColor" stroke="none"/></g>',
  camera: '<path d="M4 8h3l1.6-2.4h6.8L17 8h3v11H4z"/><circle cx="12" cy="13" r="3.4"/>',
  moon: '<path d="M20 14.5A8.5 8.5 0 1 1 9.5 4a6.8 6.8 0 0 0 10.5 10.5z"/>',
  sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2.2M12 19.3v2.2M2.5 12h2.2M19.3 12h2.2M5.3 5.3l1.6 1.6M17.1 17.1l1.6 1.6M18.7 5.3l-1.6 1.6M6.9 17.1l-1.6 1.6"/>',
  phone: '<rect x="7" y="3" width="10" height="18" rx="2.5"/><path d="M11 18h2"/>',
  chevron: '<path d="M9 6l6 6-6 6"/>',
  back: '<path d="M15 5l-7 7 7 7"/>',
  face: '<path d="M4 8V6a2 2 0 0 1 2-2h2M16 4h2a2 2 0 0 1 2 2v2M20 16v2a2 2 0 0 1-2 2h-2M8 20H6a2 2 0 0 1-2-2v-2"/><circle cx="12" cy="10" r="3"/><path d="M6.5 18c.8-2.6 3-4 5.5-4s4.7 1.4 5.5 4"/>',
  mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
  lock: '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
  eye: '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
  eyeoff: '<path d="M3 3l18 18"/><path d="M10.6 5.1A10 10 0 0 1 12 5c6 0 10 7 10 7a17 17 0 0 1-3.2 3.9"/><path d="M6.6 6.6C3.8 8.4 2 12 2 12s4 7 10 7a9.7 9.7 0 0 0 4.3-1"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
  check: '<path d="M5 12l5 5 9-10"/>',
  x: '<path d="M6 6l12 12M18 6L6 18"/>',
  alert: '<path d="M12 3l10 18H2z"/><path d="M12 10v5"/><path d="M12 18h.01"/>',
  refresh: '<path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 4v7h-7"/>',
  shield: '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
  lang: '<path d="M4 5h9M8.5 3v2M6 5c0 4 3 7 6 8M11 5c0 3-2.5 6.5-6 8"/><path d="M13 21l4-10 4 10M14.5 18h5"/>',
  info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  user2: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
}
// Lapisan isi lembut (duotone): bentuk tertutup diberi isi warna ikon berkeabuan 18%, garis tetap tegas di atasnya.
// Hanya ikon yang punya bentuk tertutup yang diberi isi; sisanya tetap garis murni.
const F = {
  tag: '<path d="M3 12V4h8l9 9-8 8z"/>',
  ship: '<path d="M3 15l2 4h14l2-4z"/>',
  home: '<path d="M5 10l7-6 7 6v10H5z"/>',
  camera: '<path d="M4 8h3l1.6-2.4h6.8L17 8h3v11H4z"/>',
  orca: '<g transform="translate(-5.6 -5.8) scale(0.07)"><path d="M92 292c8-62 64-104 128-104 36 0 66 12 92 30 18-6 34-22 40-48 16 18 22 40 16 62 20 8 36 22 48 42-26-4-46 0-62 8-22 40-72 66-130 66-70 0-140-18-132-56z"/></g>',
  box: '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/>',
  po: '<rect x="5" y="4" width="14" height="17" rx="2"/>',
  refund: '<circle cx="12" cy="12" r="6.5"/>',
  grid: '<rect x="4" y="4" width="6.5" height="6.5" rx="1.5"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.5"/>',
  doc: '<path d="M7 3h7l5 5v13H7z"/>',
  history: '<circle cx="12" cy="12" r="6"/>',
  user: '<circle cx="12" cy="8" r="4"/>',
  user2: '<circle cx="12" cy="8" r="4"/>',
  bell: '<path d="M6 9a6 6 0 0 1 12 0c0 6 2.5 7.5 2.5 7.5h-17S6 15 6 9z"/>',
  drop: '<path d="M12 3s6 6.5 6 11a6 6 0 0 1-12 0c0-4.5 6-11 6-11z"/>',
  truck: '<path d="M2 6h11v10H2z"/>',
  chart: '<rect x="3" y="14" width="4" height="6"/><rect x="9" y="4" width="4" height="16"/>',
  cart: '<path d="M6 7h14l-2.4 8H7.4z"/>',
  invoice: '<path d="M7 3h10v18l-2.5-1.7L12 21l-2.5-1.7L7 21z"/>',
  calendar: '<rect x="4" y="5" width="16" height="15" rx="2"/>',
  headset: '<rect x="3" y="14" width="4" height="6" rx="1.5"/><rect x="17" y="14" width="4" height="6" rx="1.5"/>',
  mail: '<rect x="3" y="5" width="18" height="14" rx="2"/>',
  lock: '<rect x="4" y="11" width="16" height="10" rx="2"/>',
  shield: '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
  info: '<circle cx="12" cy="12" r="9"/>',
  clock: '<circle cx="12" cy="12" r="9"/>',
  alert: '<path d="M12 3l10 18H2z"/>',
  face: '<circle cx="12" cy="10" r="3"/>',
}
const inner = computed(() => P[props.name] || '')
const fill = computed(() => F[props.name] || '')
</script>

<template>
  <svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
       stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <g v-if="fill" class="duo" fill="currentColor" fill-opacity="0.18" stroke="none" v-html="fill" />
    <g v-html="inner" />
  </svg>
</template>
