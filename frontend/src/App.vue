<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import BottomNav from './components/BottomNav.vue'
import { toast } from './composables/useToast'
import { navDir } from './composables/useNav'
import { useInbox } from './stores/inbox'
import { update, reloadApp } from './composables/useUpdate'
import { t } from './i18n'
import { tokenStore } from './api/client'
import { useAuth } from './stores/auth'

const route = useRoute()
const inbox = useInbox()
const auth = useAuth()
const showNav = computed(() => !!route.meta.nav)

// Layar pembuka: sekali per sesi (bukan tiap pindah halaman), dilewati bila gerak dikurangi.
const reduced = typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches
let seen = false
try { seen = sessionStorage.getItem('orcax_splash') === '1' } catch { /* abaikan */ }
const splash = ref(!seen && !reduced)
onMounted(() => {
  if (tokenStore.get()) auth.refresh().catch(() => auth.loadPhoto()) // data profil + foto terbaru
  if (!splash.value) return
  try { sessionStorage.setItem('orcax_splash', '1') } catch { /* abaikan */ }
  setTimeout(() => (splash.value = false), 650)
})

// Segarkan lencana setiap kali pindah halaman utama (data bisa berubah dari web).
watch(() => route.fullPath, () => { if (tokenStore.get() && route.meta.nav) inbox.refresh() })
// Pemuatan pertama (rute belum terbaca saat pengamat jalan) dan penyegaran berkala selama aplikasi terbuka.
// Angka di ikon aplikasi terpasang (Badging API): total yang menunggu keputusan; hilang saat 0 atau logout.
watch(() => inbox.all, (n) => {
  try {
    if (!('setAppBadge' in navigator)) return
    if (n > 0) navigator.setAppBadge(n).catch(() => {})
    else navigator.clearAppBadge().catch(() => {})
  } catch { /* tidak didukung */ }
}, { immediate: true })
const tick = () => { if (tokenStore.get() && !document.hidden) inbox.refresh() }
let badgeTimer
onMounted(() => { tick(); badgeTimer = setInterval(tick, 60000); document.addEventListener('visibilitychange', tick) })
onBeforeUnmount(() => { clearInterval(badgeTimer); document.removeEventListener('visibilitychange', tick) })
</script>

<template>
  <div class="shell">
    <router-view v-slot="{ Component, route: r }">
      <Transition :name="navDir" mode="out-in">
        <component :is="Component" :key="r.path" />
      </Transition>
    </router-view>
    <BottomNav v-if="showNav" />
    <Transition name="upd">
      <div v-if="update.ready && !update.dismissed" class="upd" role="status" aria-live="polite">
        <span class="upd-i" aria-hidden="true">↻</span>
        <span class="upd-t"><b>{{ t('upd.title') }}</b><small>{{ t('upd.text') }}</small></span>
        <button class="upd-b" @click="reloadApp">{{ t('upd.reload') }}</button>
        <button class="upd-x" :aria-label="t('upd.later')" @click="update.dismissed = true">×</button>
      </div>
    </Transition>
    <div v-if="toast.msg" class="toast" :class="toast.kind" role="status" aria-live="polite">{{ toast.msg }}</div>

    <Transition name="splash">
      <div v-if="splash" class="splash" aria-hidden="true">
        <div>
          <img src="/icons/logo.png" alt="" />
          <b>ORCA X <span>MOBILE</span></b>
        </div>
      </div>
    </Transition>
  </div>
</template>
