<script setup>
import { t } from '../i18n'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Icon from './Icon.vue'
import * as syop from '../api/syop'
import { lalu } from '../utils/format'

const router = useRouter()
const d = ref(null)
const failed = ref(false)
let timer

async function load() {
  try { d.value = await syop.sistemRingkasan(); failed.value = false } catch { failed.value = true }
}
onMounted(() => { load(); timer = setInterval(load, 60000) })
onBeforeUnmount(() => clearInterval(timer))

const level = computed(() => d.value?.status || 'hijau')
const masalah = computed(() => (d.value?.masalah || []).slice(0, 3))
const tiles = computed(() => {
  const a = d.value?.aktivitas
  if (!a) return []
  const delta = (k) => a.hari_ini[k] - a.kemarin[k]
  return [
    { key: 'pengguna', n: a.hari_ini.pengguna, dl: delta('pengguna'), good: 1, label: t('adm.act.users') },
    { key: 'gagal', n: a.hari_ini.gagal, dl: delta('gagal'), good: -1, label: t('adm.act.fail') },
    { key: 'tindakan_admin', n: a.hari_ini.tindakan_admin, dl: delta('tindakan_admin'), good: 0, label: t('adm.act.admin') },
  ]
})
const tone = (x) => (x.dl === 0 || x.good === 0 ? '' : (x.dl > 0) === (x.good > 0) ? 'up' : 'down')
const arrow = (x) => (x.dl === 0 ? '＝' : x.dl > 0 ? '▲' : '▼')
const p = computed(() => d.value?.pengumuman)
</script>

<template>
  <section class="adm" :aria-label="t('adm.aria')">
    <div v-if="!d && !failed" class="skel" style="height:150px" />
    <button v-else-if="failed && !d" class="card retry" @click="load">{{ t('adm.failed') }}</button>
    <template v-else>
      <!-- 1. status sistem -->
      <button class="card st rise" :class="level" style="--i: 0" @click="router.push({ name: 'sistem' })">
        <span class="orb"><Icon :name="level === 'hijau' ? 'check' : 'alert'" :size="22" /></span>
        <span class="tx">
          <b>{{ t('adm.st.' + level) }}</b>
          <small v-if="!masalah.length">{{ t('adm.st.okText', { n: d.sistem.perangkat_push, v: d.sistem.versi_terbaru, u: d.sistem.pengguna }) }}</small>
          <small v-for="m in masalah" :key="m.kode" class="is" :class="m.berat">• {{ t('adm.issue.' + m.kode, { n: m.n || 0 }) }}</small>
        </span>
        <Icon name="chevron" :size="18" />
      </button>

      <!-- 2. aktivitas hari ini -->
      <div class="card act rise" style="--i: 1">
        <div class="hd">{{ t('adm.act.title') }}</div>
        <div class="tl">
          <div v-for="x in tiles" :key="x.key" class="tile">
            <b>{{ x.n }}</b>
            <span>{{ x.label }}</span>
            <i :class="tone(x)">{{ arrow(x) }} {{ x.dl === 0 ? t('adm.act.same') : t('adm.act.vs', { n: Math.abs(x.dl) }) }}</i>
          </div>
        </div>
      </div>

      <!-- 5. pengumuman terakhir -->
      <div class="card pg rise" style="--i: 2">
        <div class="hd">{{ t('adm.pg.title') }}<em v-if="d.pengumuman_terjadwal" class="chip">{{ t('adm.pg.sched', { n: d.pengumuman_terjadwal }) }}</em></div>
        <template v-if="p">
          <router-link :to="{ name: 'pengumuman-admin' }" class="pt">{{ p.judul }}</router-link>
          <small class="mt">{{ lalu(p.dikirim_pada) }} · {{ t('adm.pg.sent', { n: p.dikirim_ke }) }}</small>
          <div class="bar" role="progressbar" :aria-valuenow="p.persen" aria-valuemin="0" aria-valuemax="100"><i :style="{ width: p.persen + '%' }" /></div>
          <small class="mt"><b>{{ p.persen }}%</b> {{ t('adm.pg.read', { n: p.dibaca, m: p.dikirim_ke }) }}</small>
        </template>
        <small v-else class="mt">{{ t('adm.pg.none') }}</small>
        <router-link :to="{ name: 'pengumuman-admin' }" class="btn btn-ghost btn-block" style="margin-top:12px"><Icon name="edit" :size="16" /> {{ t('adm.pg.create') }}</router-link>
      </div>
    </template>
  </section>
</template>

<style scoped>
.adm { display: grid; gap: 14px; margin-bottom: 14px; }
.retry { width: 100%; font-family: inherit; font-weight: 700; color: var(--accent); }
.hd { display: flex; align-items: center; justify-content: space-between; font-size: 13px; font-weight: 800; color: var(--ink-2); margin-bottom: 10px; letter-spacing: .02em; text-transform: uppercase; }
.chip { font-style: normal; text-transform: none; letter-spacing: 0; font-size: 11.5px; font-weight: 700; padding: 3px 10px; border-radius: 99px; background: #e6eeff; color: var(--accent); }
.st { display: flex; align-items: center; gap: 14px; width: 100%; text-align: left; font-family: inherit; color: var(--ink); border-width: 1.5px; }
.st .orb { flex: none; width: 46px; height: 46px; border-radius: 16px; display: grid; place-items: center; color: #fff; background: #12a37f; box-shadow: 0 8px 20px color-mix(in srgb, #12a37f 40%, transparent); }
.st.kuning .orb { background: #f08007; box-shadow: 0 8px 20px color-mix(in srgb, #f08007 40%, transparent); }
.st.merah .orb { background: #d93a45; box-shadow: 0 8px 20px color-mix(in srgb, #d93a45 40%, transparent); animation: pulse 1.6s infinite; }
.st.kuning { border-color: color-mix(in srgb, #f08007 45%, var(--line)); }
.st.merah { border-color: color-mix(in srgb, #d93a45 55%, var(--line)); }
.st .tx { flex: 1; min-width: 0; display: grid; gap: 3px; }
.st b { font-size: 16px; font-weight: 800; letter-spacing: -.01em; }
.st small { font-size: 12.5px; color: var(--muted); }
.st .is.merah { color: #b4232a; font-weight: 700; }
.st .is.kuning { color: #a65b00; font-weight: 600; }
@keyframes pulse { 50% { transform: scale(1.07); } }
.tl { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
.tile { display: grid; gap: 2px; padding: 10px 10px 8px; border-radius: 14px; background: var(--bg, #f3f6fd); border: 1px solid var(--line); }
.tile b { font-size: 24px; font-weight: 800; letter-spacing: -.02em; color: var(--ink); }
.tile span { font-size: 11.5px; color: var(--ink-2); font-weight: 600; line-height: 1.25; }
.tile i { font-style: normal; font-size: 10.5px; color: var(--muted); font-weight: 700; }
.tile i.up { color: #12a37f; }
.tile i.down { color: #d93a45; }
.pt { display: block; font-size: 16px; font-weight: 800; color: var(--ink); text-decoration: none; letter-spacing: -.01em; overflow-wrap: anywhere; }
.mt { display: block; font-size: 12px; color: var(--muted); margin-top: 3px; }
.bar { height: 8px; border-radius: 99px; background: #e6eaf3; overflow: hidden; margin: 10px 0 4px; }
.bar i { display: block; height: 100%; border-radius: 99px; background: linear-gradient(90deg, #1b4fd8, #2f6bff); }
</style>
