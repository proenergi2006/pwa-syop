<script setup>
import { t, ts } from '../i18n'
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import Icon from './Icon.vue'
import { useInbox } from '../stores/inbox'
import { useAuth } from '../stores/auth'
import { update } from '../composables/useUpdate'

const inbox = useInbox()
const route = useRoute()

// Urutan tab menentukan posisi pil penanda yang meluncur.
const auth = useAuth()
const pen = computed(() => !Array.isArray(auth.user?.modules) || auth.user.modules.includes('penawaran'))
const ORDER = computed(() => pen.value
  ? { home: 0, menu: 1, report: 1, 'report-marketing': 1, 'report-rssp': 1, approval: 2, 'approval-detail': 2, riwayat: 3, profil: 4, shipping: 0, 'shipping-detail': 0, trucking: 0, 'trucking-detail': 0, oa: 0, 'oa-detail': 0, forecast: 0, 'forecast-cabang': 0, 'forecast-om': 0, dr: 0, 'dr-detail': 0, 'trucking-dr': 0, 'master-terminal': 0, 'master-terminal-form': 0, 'master-vendor': 0, 'master-vendor-form': 0, 'trucking-dr': 0, 'trucking-dr-detail': 0, po: 0, 'po-detail': 0, harga: 0, 'harga-detail': 0, 'harga-form': 0, produk: 0, 'produk-detail': 0, 'produk-form': 0 }
  : { home: 0, menu: 1, report: 1, 'report-marketing': 1, 'report-rssp': 1, inventory: 1, profil: 2, shipping: 0, 'shipping-detail': 0, trucking: 0, 'trucking-detail': 0, oa: 0, 'oa-detail': 0, forecast: 0, 'forecast-cabang': 0, 'forecast-om': 0, dr: 0, 'dr-detail': 0, 'trucking-dr': 0, 'master-terminal': 0, 'master-terminal-form': 0, 'master-vendor': 0, 'master-vendor-form': 0, 'trucking-dr': 0, 'trucking-dr-detail': 0, po: 0, 'po-detail': 0, harga: 0, 'harga-detail': 0, 'harga-form': 0, produk: 0, 'produk-detail': 0, 'produk-form': 0 })
const idx = computed(() => ORDER.value[route.name] ?? 0)
</script>

<template>
  <nav class="nav" :aria-label="t('nav.aria')" :style="{ '--i': idx, '--n': pen ? 5 : 3 }">
    <span class="pill-ind" aria-hidden="true" />
    <router-link :to="{ name: 'home' }"><Icon name="home" :size="21" /><span>{{ t('nav.home') }}</span></router-link>
    <router-link :to="{ name: 'menu' }"><Icon name="grid" :size="21" /><span>{{ t('nav.menu') }}</span></router-link>
    <router-link v-if="pen" :to="{ name: 'approval' }" :class="{ on: route.name === 'approval-detail' }">
      <Icon name="doc" :size="21" /><span>{{ t('nav.approval') }}</span>
      <b v-if="inbox.all" :key="inbox.all" class="badge">{{ inbox.all > 99 ? '99+' : inbox.all }}</b>
    </router-link>
    <router-link v-if="pen" :to="{ name: 'riwayat' }"><Icon name="history" :size="21" /><span>{{ t('nav.history') }}</span></router-link>
    <router-link :to="{ name: 'profil' }"><Icon name="user" :size="21" /><span>{{ t('nav.profile') }}</span><b v-if="update.ready" class="badge" aria-hidden="true">!</b></router-link>
  </nav>
</template>
