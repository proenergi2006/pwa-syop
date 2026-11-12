<script setup>
import { t, locale } from '../i18n'
import Icon from './Icon.vue'
import { jamSekarang } from '../utils/format'
defineProps({ at: Number })
defineEmits(['retry'])
const jam = (ms) => new Date(ms).toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'Asia/Jakarta' }).replace('.', ':')
</script>

<template>
  <div class="off" role="status">
    <Icon name="alert" :size="18" />
    <span>{{ t('offline.text', { time: at ? ` (${jam(at)} WIB)` : '' }) }}</span>
    <button class="rt" @click="$emit('retry')">{{ t('common.reload') }}</button>
  </div>
</template>

<style scoped>
.off { display: flex; align-items: center; gap: 10px; margin: 0 0 12px; padding: 10px 12px; border-radius: 14px; background: var(--amber-bg); color: var(--amber); font-size: 12.5px; font-weight: 600; line-height: 1.35; }
.off span { flex: 1; }
.rt { min-height: 44px; padding: 0 6px; border: 0; background: none; color: var(--accent); font-weight: 700; font-size: 13px; }
</style>
