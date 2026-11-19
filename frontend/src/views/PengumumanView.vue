<script setup>
import { t } from '../i18n'
import { useScrolled } from '../composables/useScrolled'
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import UserAvatar from '../components/UserAvatar.vue'
import StateBox from '../components/StateBox.vue'
import { useInbox } from '../stores/inbox'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'
import { showToast } from '../composables/useToast'
import { tanggalJam } from '../utils/format'
import { update, reloadApp, checkUpdate } from '../composables/useUpdate'

const scrolled = useScrolled()
const route = useRoute()
const router = useRouter()
const inbox = useInbox()
const items = ref([])
const meta = ref({ page: 1, last: 1 })
const belum = ref(0)
const loading = ref(true)
const more = ref(false)
const error = ref('')
const open = ref(null)
const checking = ref(false)

const ikon = (j) => (j === 'penting' ? 'alert' : j === 'pembaruan' ? 'refresh' : 'bell')

async function load(page = 1) {
  page === 1 ? (loading.value = true) : (more.value = true)
  error.value = ''
  try {
    const r = await syop.listPengumuman({ page, per_page: 15 })
    items.value = page === 1 ? r.data : [...items.value, ...r.data]
    meta.value = r.meta
    belum.value = r.belum_dibaca
    inbox.pengumuman = r.belum_dibaca
  } catch (e) { error.value = errorMessage(e) } finally { loading.value = false; more.value = false }
}

async function buka(a) {
  open.value = a
  if (!a.dibaca) {
    try {
      const r = await syop.getPengumuman(a.id)
      a.dibaca = true
      belum.value = r.belum_dibaca
      inbox.pengumuman = r.belum_dibaca
    } catch { /* tetap tampil */ }
  }
}

async function bacaSemua() {
  try {
    await syop.bacaSemuaPengumuman()
    items.value.forEach((a) => (a.dibaca = true))
    belum.value = 0
    inbox.pengumuman = 0
  } catch (e) { showToast(errorMessage(e), 'err') }
}

async function perbarui() {
  if (update.ready) return reloadApp()
  checking.value = true
  const ada = await checkUpdate()
  checking.value = false
  if (ada) reloadApp()
  else showToast(t('prof.upToDate'))
}

onMounted(async () => {
  await load()
  const id = Number(route.query.buka)
  if (id) {
    const a = items.value.find((x) => x.id === id)
    if (a) buka(a)
    else { try { const r = await syop.getPengumuman(id); open.value = { ...r, dibaca: true }; inbox.pengumuman = r.belum_dibaca } catch { /* bukan sasaran / sudah dihapus */ } }
    router.replace({ query: {} })
  }
})
</script>

<template>
  <div class="page">
    <div class="page-head" :class="{ stuck: scrolled }">
      <div>
        <h1 class="page-title">{{ t('pg.title') }}</h1>
        <p class="page-sub">{{ belum ? t('pg.unread', { n: belum }) : t('pg.allRead') }}</p>
      </div>
      <router-link :to="{ name: 'profil' }" class="avatar" :aria-label="t('common.profile')"><UserAvatar /><i class="dot" /></router-link>
    </div>

    <button v-if="belum" class="readall" @click="bacaSemua"><Icon name="check" :size="16" /> {{ t('pg.readAll') }}</button>

    <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load()" />
    <div v-else-if="loading" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:104px" /></div>
    <StateBox v-else-if="!items.length" icon="bell" :title="t('pg.none')" :text="t('pg.noneText')" />
    <template v-else>
      <div class="stack">
        <button v-for="(a, i) in items" :key="a.id" class="pg rise" :class="[a.jenis, { unread: !a.dibaca }]" :style="{ '--i': i % 8 }" @click="buka(a)">
          <span class="ic"><Icon :name="ikon(a.jenis)" :size="20" /></span>
          <span class="bd">
            <span class="top"><b class="jd">{{ a.judul }}</b><i v-if="!a.dibaca" class="dot" :aria-label="t('pg.new')" /></span>
            <span class="pv">{{ a.isi }}</span>
            <small>{{ t('pg.type.' + a.jenis) }} · {{ tanggalJam(a.dikirim_pada) }}</small>
          </span>
        </button>
      </div>
      <button v-if="meta.page < meta.last" class="btn btn-ghost btn-block" style="margin-top:16px" :disabled="more" @click="load(meta.page + 1)">{{ more ? t('common.loading') : t('common.loadMore') }}</button>
    </template>

    <div v-if="open" class="sheet-back" @click.self="open = null">
      <div class="sheet" role="dialog" aria-modal="true">
        <div class="grab" />
        <div class="sh-h">
          <span class="tg" :class="open.jenis"><Icon :name="ikon(open.jenis)" :size="14" /> {{ t('pg.type.' + open.jenis) }}</span>
          <button class="x" :aria-label="t('common.close')" @click="open = null"><Icon name="x" :size="18" /></button>
        </div>
        <h2 class="sheet-t" style="margin-top:10px">{{ open.judul }}</h2>
        <small class="when">{{ tanggalJam(open.dikirim_pada) }}</small>
        <p class="isi">{{ open.isi }}</p>
        <button v-if="open.jenis === 'pembaruan'" class="btn btn-primary btn-block" :disabled="checking" @click="perbarui"><Icon name="refresh" :size="18" /> {{ checking ? t('prof.checking') : t('prof.updateNow') }}</button>
        <button v-else class="btn btn-ghost btn-block" @click="open = null">{{ t('common.close') }}</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.readall { display: inline-flex; align-items: center; gap: 6px; margin: 0 0 12px; min-height: 40px; padding: 0 14px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--accent); font-size: 13px; font-weight: 700; font-family: inherit; }
.pg { display: flex; gap: 12px; align-items: flex-start; width: 100%; padding: 14px 16px; border-radius: 18px; border: 1px solid var(--line); background: var(--card); text-align: left; font-family: inherit; color: var(--ink); }
.pg.unread { border-color: color-mix(in srgb, var(--accent) 45%, var(--line)); background: color-mix(in srgb, var(--accent) 5%, var(--card)); box-shadow: 0 6px 18px color-mix(in srgb, var(--accent) 10%, transparent); }
.ic { flex: none; width: 42px; height: 42px; border-radius: 14px; display: grid; place-items: center; background: #e6eeff; color: var(--accent); }
.pg.penting .ic { background: #fdeaea; color: #b4232a; }
.pg.pembaruan .ic { background: #e3f6f3; color: #0b7a6b; }
.bd { display: grid; gap: 3px; min-width: 0; flex: 1; }
.top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.jd { font-size: 15px; font-weight: 800; letter-spacing: -0.01em; overflow-wrap: anywhere; }
.pg:not(.unread) .jd { font-weight: 700; }
.top .dot { flex: none; width: 10px; height: 10px; border-radius: 50%; background: var(--red); }
.pv { font-size: 13px; color: var(--ink-2); display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; white-space: pre-line; }
.bd small { font-size: 11.5px; color: var(--muted); }
.sh-h { display: flex; align-items: center; justify-content: space-between; }
.tg { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 99px; background: #e6eeff; color: var(--accent); font-size: 12px; font-weight: 800; }
.tg.penting { background: #fdeaea; color: #b4232a; }
.tg.pembaruan { background: #e3f6f3; color: #0b7a6b; }
.x { width: 40px; height: 40px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); display: grid; place-items: center; }
.when { display: block; margin: 2px 0 12px; font-size: 12px; color: var(--muted); }
.isi { margin: 0 0 16px; font-size: 14.5px; line-height: 1.55; color: var(--ink); white-space: pre-line; overflow-wrap: anywhere; max-height: 50vh; overflow-y: auto; }
</style>
