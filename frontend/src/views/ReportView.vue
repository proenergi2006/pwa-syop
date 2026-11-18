<script setup>
import { t } from '../i18n'
import { useScrolled } from '../composables/useScrolled'
import { useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import UserAvatar from '../components/UserAvatar.vue'

const scrolled = useScrolled()
const router = useRouter()
// Daftar report. Tambahkan report baru di sini; saat ini baru Report Marketing.
const reports = [
  { key: 'marketing', to: 'report-marketing', icon: 'chart', title: 'report.marketing', sub: 'report.marketingSub', tags: ['rm.tab.vol', 'rm.tab.act', 'rm.tab.new', 'rm.tab.keg'], c: '#0a66e8' },
]
</script>

<template>
  <div class="page">
    <div class="page-head" :class="{ stuck: scrolled }">
      <div>
        <h1 class="page-title">{{ t('report.title') }}</h1>
        <p class="page-sub">{{ t('report.sub') }}</p>
      </div>
      <router-link :to="{ name: 'profil' }" class="avatar" :aria-label="t('common.profile')"><UserAvatar /><i class="dot" /></router-link>
    </div>

    <div class="stack">
      <button v-for="(r, i) in reports" :key="r.key" class="card rep rise" :style="{ '--i': i, '--c': r.c }" @click="router.push({ name: r.to })">
        <span class="art" aria-hidden="true">
          <svg viewBox="0 0 120 64" preserveAspectRatio="none">
            <defs><linearGradient id="rg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff" stop-opacity=".35" /><stop offset="1" stop-color="#fff" stop-opacity="0" /></linearGradient></defs>
            <path d="M0 48 C14 40 22 54 36 42 S58 20 72 30 98 12 120 8 L120 64 L0 64Z" fill="url(#rg)" />
            <path d="M0 48 C14 40 22 54 36 42 S58 20 72 30 98 12 120 8" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" />
            <g fill="#fff" opacity=".9"><rect x="14" y="40" width="7" height="18" rx="2" /><rect x="30" y="30" width="7" height="28" rx="2" /><rect x="46" y="36" width="7" height="22" rx="2" /><rect x="62" y="22" width="7" height="36" rx="2" /><rect x="78" y="28" width="7" height="30" rx="2" /><rect x="94" y="14" width="7" height="44" rx="2" /></g>
          </svg>
        </span>
        <span class="info">
          <span class="ttl"><Icon :name="r.icon" :size="18" /> {{ t(r.title) }}</span>
          <span class="sub">{{ t(r.sub) }}</span>
          <span class="tags"><b v-for="x in r.tags" :key="x">{{ t(x) }}</b></span>
        </span>
        <Icon name="chevron" :size="22" class="go" />
      </button>
    </div>
    <p class="note">{{ t('report.more') }}</p>
  </div>
</template>

<style scoped>
.stack { display: grid; gap: 12px; }
.rep { width: 100%; box-sizing: border-box; text-align: left; display: grid; grid-template-columns: 96px minmax(0, 1fr) auto; gap: 14px; align-items: center; padding: 14px; overflow: hidden; border-color: color-mix(in srgb, var(--c) 28%, var(--line)); }
.art { height: 72px; border-radius: 16px; background: linear-gradient(145deg, var(--c), color-mix(in srgb, var(--c) 55%, #04247a)); display: block; overflow: hidden; box-shadow: 0 10px 20px -10px var(--c); }
.art svg { width: 100%; height: 100%; display: block; }
.info { display: grid; gap: 4px; min-width: 0; }
.ttl { display: flex; align-items: center; gap: 7px; font-weight: 800; font-size: 16px; color: var(--ink); }
.sub { font-size: 12.5px; color: var(--muted); line-height: 1.35; }
.tags { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 2px; }
.tags b { font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 999px; background: color-mix(in srgb, var(--c) 12%, var(--card)); color: color-mix(in srgb, var(--c) 82%, var(--ink)); }
.go { color: var(--muted); }
.note { margin: 18px 4px 0; text-align: center; font-size: 12.5px; color: var(--muted); }
</style>
