<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { t } from '../../i18n'
import Icon from '../Icon.vue'
import StateBox from '../StateBox.vue'
import * as syop from '../../api/syop'
import { errorMessage } from '../../api/client'
import { angka, tanggalJam } from '../../utils/format'

const d = ref(null)
const loading = ref(true)
const error = ref('')
let timer

async function load(quiet = false) {
  if (!quiet) loading.value = true
  error.value = ''
  try { d.value = await syop.sistemKesehatan() } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
onMounted(() => { load(); timer = setInterval(() => load(true), 30000) })
onBeforeUnmount(() => clearInterval(timer))

// status: ok | warn | bad | off
const rows = computed(() => {
  const x = d.value
  if (!x) return []
  const pctBebas = x.penyimpanan.total_gb ? (x.penyimpanan.bebas_gb / x.penyimpanan.total_gb) * 100 : 100
  return [
    { k: 'sch', icon: 'refresh', s: x.scheduler.ok ? 'ok' : 'bad', title: t('sys.h.scheduler'), val: x.scheduler.ok ? t('sys.h.running', { n: x.scheduler.detik }) : (x.scheduler.terakhir ? t('sys.h.stopped', { t: tanggalJam(x.scheduler.terakhir) }) : t('sys.h.never')), note: x.scheduler.ok ? '' : t('sys.h.schedulerHint') },
    { k: 'db', icon: 'box', s: x.database.ok ? (x.database.ms > 500 ? 'warn' : 'ok') : 'bad', title: t('sys.h.db'), val: x.database.ok ? t('sys.h.dbOk', { n: x.database.ms }) : t('sys.h.dbBad') },
    { k: 'push', icon: 'bell', s: !x.push.siap ? 'bad' : x.push.gagal_24j > 0 ? 'warn' : 'ok', title: t('sys.h.push'),
      val: !x.push.siap ? t('sys.h.vapidMissing') : t('sys.h.pushVal', { a: angka(x.push.perangkat), b: angka(x.push.terkirim_24j), c: angka(x.push.gagal_24j) }),
      note: x.push.gagal_terakhir ? t('sys.h.lastErr', { t: tanggalJam(x.push.gagal_terakhir.waktu), m: x.push.gagal_terakhir.pesan }) : '' },
    { k: 'ann', icon: 'calendar', s: x.pengumuman.terlambat ? 'bad' : 'ok', title: t('sys.h.ann'), val: t('sys.h.annVal', { n: x.pengumuman.terjadwal }), note: x.pengumuman.terlambat ? t('sys.h.annLate', { n: x.pengumuman.terlambat }) : '' },
    { k: 'ver', icon: 'phone', s: x.versi.lama > 0 ? 'warn' : 'ok', title: t('sys.h.version'), val: x.versi.rilis ? t('sys.h.verVal', { v: x.versi.rilis, a: angka(x.versi.terbaru), b: angka(x.versi.lama), c: angka(x.versi.belum_lapor) }) : t('sys.h.verNone') },
    { k: 'acc', icon: 'invoice', s: x.accurate.aktif ? 'ok' : 'off', title: 'Accurate', val: x.accurate.aktif ? t('sys.h.accOn') : t('sys.h.accOff') },
    { k: 'disk', icon: 'box', s: pctBebas < 10 ? 'bad' : pctBebas < 20 ? 'warn' : 'ok', title: t('sys.h.disk'), val: t('sys.h.diskVal', { a: x.penyimpanan.bebas_gb, b: x.penyimpanan.total_gb }) },
    { k: 'mt', icon: 'lock', s: x.pemeliharaan.aktif ? 'warn' : 'ok', title: t('sys.h.maint'), val: x.pemeliharaan.aktif ? t('sys.h.maintOn') : t('sys.h.maintOff') },
    { k: 'env', icon: 'info', s: x.lingkungan.env === 'production' && x.lingkungan.debug ? 'warn' : 'ok', title: t('sys.h.env'), val: `${x.lingkungan.env} · PHP ${x.lingkungan.php}`, note: x.lingkungan.env === 'production' && x.lingkungan.debug ? t('sys.h.debugOn') : '' },
  ]
})
const bad = computed(() => rows.value.filter((r) => r.s === 'bad').length)
const warn = computed(() => rows.value.filter((r) => r.s === 'warn').length)
</script>

<template>
  <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load()" />
  <div v-else-if="loading" class="stack"><div v-for="i in 5" :key="i" class="skel" style="height:78px" /></div>
  <template v-else>
    <div class="sum2" :class="bad ? 'bad' : warn ? 'warn' : 'ok'">
      <Icon :name="bad || warn ? 'alert' : 'check'" :size="22" />
      <div><b>{{ bad ? t('sys.h.sumBad', { n: bad }) : warn ? t('sys.h.sumWarn', { n: warn }) : t('sys.h.sumOk') }}</b><small>{{ t('sys.h.updated', { t: tanggalJam(d.waktu) }) }}</small></div>
      <button class="rf" :aria-label="t('sys.h.refresh')" @click="load()"><Icon name="refresh" :size="18" /></button>
    </div>
    <div class="stack">
      <article v-for="r in rows" :key="r.k" class="card hrow" :class="r.s">
        <span class="hi"><Icon :name="r.icon" :size="19" /></span>
        <div class="hb"><b>{{ r.title }}</b><span>{{ r.val }}</span><small v-if="r.note">{{ r.note }}</small></div>
        <i class="dotS" :class="r.s" :aria-label="r.s" />
      </article>
    </div>
  </template>
</template>

<style scoped>
.sum2 { display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-radius: 18px; margin-bottom: 14px; }
.sum2 div { flex: 1; display: grid; gap: 2px; }
.sum2 b { font-size: 15px; font-weight: 800; }
.sum2 small { font-size: 12px; opacity: 0.8; }
.sum2.ok { background: #e3f6ec; color: #11683d; }
.sum2.warn { background: #fff1d6; color: #8a5600; }
.sum2.bad { background: #fdeaea; color: #a1202a; }
.rf { width: 40px; height: 40px; border-radius: 12px; border: 0; background: rgba(255, 255, 255, 0.6); color: inherit; display: grid; place-items: center; }
.hrow { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border: 1px solid var(--line); }
.hi { flex: none; width: 40px; height: 40px; border-radius: 13px; display: grid; place-items: center; background: #e6eeff; color: var(--accent); }
.hrow.bad .hi { background: #fdeaea; color: #b4232a; }
.hrow.warn .hi { background: #fff1d6; color: #9a6200; }
.hb { flex: 1; min-width: 0; display: grid; gap: 2px; }
.hb b { font-size: 14px; font-weight: 800; }
.hb span { font-size: 13px; color: var(--ink-2); overflow-wrap: anywhere; }
.hb small { font-size: 11.5px; color: var(--muted); overflow-wrap: anywhere; }
.dotS { flex: none; width: 12px; height: 12px; border-radius: 50%; background: var(--muted); }
.dotS.ok { background: var(--green); } .dotS.warn { background: #e8a317; } .dotS.bad { background: var(--red); }
</style>
