<script setup>
import { t } from '../i18n'
import { computed } from 'vue'
import Icon from './Icon.vue'
import { angka, lamaMenunggu, rupiahRingkas, tanggal } from '../utils/format'

const props = defineProps({ item: Object, mode: { type: String, default: 'pending' } })
defineEmits(['open'])

const decided = computed(() => props.item.keputusan != null)
const age = computed(() => lamaMenunggu(props.item.created_time))
const nilai = computed(() => Number(props.item.harga_dasar) * Number(props.item.volume_tawar))
const ageLabel = computed(() => t('item.waiting', { t: age.value.teks }))
</script>

<template>
  <button class="item" @click="$emit('open', item)">
    <div class="item-top">
      <div class="item-who">
        <span class="ico-box tinted" :class="mode === 'history' && decided ? (item.keputusan == 1 ? 'tone-green' : 'tone-rose') : 'tone-blue'">
          <Icon :name="mode === 'history' && decided ? (item.keputusan == 1 ? 'check' : 'x') : 'doc'" :size="18" />
        </span>
        <span>{{ item.marketing }}</span>
      </div>
      <span v-if="mode === 'history'" class="pill" :class="item.keputusan == 1 ? 'pill-ok' : 'pill-no'">
        {{ item.keputusan == 1 ? t('status.approved') : t('status.rejected') }}
      </span>
      <span v-else class="age" :class="age.level" :title="ageLabel">
        <Icon name="clock" :size="14" />{{ age.teks }}
      </span>
    </div>

    <div class="item-title">{{ item.nama_customer }}</div>
    <div class="item-meta">{{ item.nomor_surat }}<br />{{ item.jenis_produk }} · {{ item.nama_cabang }} · {{ item.nama_area }}</div>

    <div v-if="mode !== 'history' && item.peringatan" class="warn"><Icon name="alert" :size="14" /> {{ t('item.warn') }}</div>

    <div class="item-foot">
      <div>
        <div class="price">≈ {{ rupiahRingkas(nilai) }}</div>
        <small class="unit">{{ angka(item.harga_dasar, 2) }} /L · {{ angka(item.volume_tawar) }} L</small>
      </div>
      <span v-if="mode === 'history'" class="item-meta">{{ tanggal(item.tgl_keputusan) }}</span>
      <span v-else class="mini-btn">{{ t('item.detail') }} <Icon name="chevron" :size="16" /></span>
    </div>
  </button>
</template>

<style scoped>
.age { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; background: var(--blue-50); color: var(--accent); }
.age.late { background: var(--amber-bg); color: var(--late); }
.age.urgent { background: var(--red-bg); color: var(--red); }
.warn { display: flex; align-items: center; gap: 6px; margin-top: 8px; padding: 6px 10px; border-radius: 10px; background: var(--red-bg); color: var(--red); font-size: 12px; font-weight: 600; }
.unit { display: block; color: var(--muted); font-size: 12px; margin-top: 1px; }
</style>
