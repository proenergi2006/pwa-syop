<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import BottomNav from './components/BottomNav.vue'
import { toast } from './composables/useToast'
import { navDir } from './composables/useNav'
import { useInbox } from './stores/inbox'
import { update, reloadApp, snooze } from './composables/useUpdate'
import { t } from './i18n'
import { tokenStore, setMaintenanceHandler } from './api/client'
import { maint, setMaintenance, cekStatus } from './composables/useMaintenance'
import { tanggalJam } from './utils/format'
import { useAuth } from './stores/auth'

setMaintenanceHandler(setMaintenance)
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
        <button class="upd-x" :aria-label="t('upd.later')" @click="snooze">×</button>
      </div>
    </Transition>
    <Transition name="upd">
      <div v-if="maint.aktif" class="maint" role="alertdialog" aria-modal="true" aria-live="assertive">
        <div class="maint-c">
          <img src="/icons/logo.png" alt="" />
          <h1>{{ t('sys.maint.title') }}</h1>
          <p>{{ maint.pesan || t('sys.maint.default') }}</p>
          <small v-if="maint.sampai">{{ t('sys.maint.until', { t: tanggalJam(maint.sampai) }) }}</small>
          <button class="maint-b" :disabled="maint.mengecek" @click="cekStatus">{{ maint.mengecek ? t('common.loading') : t('sys.maint.retry') }}</button>
        </div>
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
<style>
.maint { position: fixed; inset: 0; z-index: 400; display: grid; place-items: center; padding: 24px; background: var(--bg, #f4f6fb); }
.maint-c { max-width: 380px; width: 100%; display: grid; gap: 12px; justify-items: center; text-align: center; }
.maint-c img { width: 72px; height: 72px; border-radius: 20px; }
.maint-c h1 { margin: 4px 0 0; font-size: 22px; font-weight: 800; color: var(--ink); }
.maint-c p { margin: 0; font-size: 14.5px; line-height: 1.55; color: var(--ink-2); white-space: pre-line; }
.maint-c small { font-size: 12.5px; color: var(--muted); font-weight: 700; }
.maint-b { margin-top: 6px; min-height: 48px; padding: 0 28px; border: 0; border-radius: 14px; background: var(--accent); color: #fff; font-size: 15px; font-weight: 800; font-family: inherit; }
</style>
