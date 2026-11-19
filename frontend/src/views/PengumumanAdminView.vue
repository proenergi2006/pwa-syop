<script setup>
import { t } from '../i18n'
import { useScrolled } from '../composables/useScrolled'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import Icon from '../components/Icon.vue'
import UserAvatar from '../components/UserAvatar.vue'
import StateBox from '../components/StateBox.vue'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'
import { showToast } from '../composables/useToast'
import { angka, tanggalJam } from '../utils/format'

const tab = ref('pengumuman')

const scrolled = useScrolled()
const items = ref([])
const meta = ref({ page: 1, last: 1 })
const loading = ref(true)
const more = ref(false)
const error = ref('')

const ikon = (j) => (j === 'penting' ? 'alert' : j === 'pembaruan' ? 'refresh' : 'bell')
const JENIS = ['info', 'penting', 'pembaruan']
const PRESET = { pembaruan: { judul: 'Versi baru tersedia', isi: 'ORCA X Mobile versi baru siap dipakai. Buka aplikasi, lalu ketuk Perbarui sekarang (di Profil atau pita biru di atas layar).' } }

async function load(page = 1) {
  page === 1 ? (loading.value = true) : (more.value = true)
  error.value = ''
  try {
    const r = await syop.adminListPengumuman({ page, per_page: 15 })
    items.value = page === 1 ? r.data : [...items.value, ...r.data]
    meta.value = r.meta
  } catch (e) { error.value = errorMessage(e) } finally { loading.value = false; more.value = false }
}

// ---- formulir ----
const peran = ref([])
const f = reactive({ open: false, id: null, jenis: 'info', judul: '', isi: '', jadwal: '', semua: true, roles: [], busy: false, error: '', perkiraan: null, konfirmasi: false })
function baru() {
  Object.assign(f, { open: true, id: null, jenis: 'info', judul: '', isi: '', jadwal: '', semua: true, roles: [], busy: false, error: '', perkiraan: null, konfirmasi: false })
  hitung()
}
function ubah(a) {
  Object.assign(f, { open: true, id: a.id, jenis: a.jenis, judul: a.judul, isi: a.isi, jadwal: a.jadwal_pada ? a.jadwal_pada.replace(' ', 'T').slice(0, 16) : '', semua: !a.sasaran.length, roles: [...a.sasaran], busy: false, error: '', perkiraan: null, konfirmasi: false })
  hitung()
}
function pilihJenis(j) {
  f.jenis = j
  if (PRESET[j] && !f.id && !f.judul.trim() && !f.isi.trim()) { f.judul = PRESET[j].judul; f.isi = PRESET[j].isi }
}
function toggleRole(id) { f.semua = false; f.roles = f.roles.includes(id) ? f.roles.filter((x) => x !== id) : [...f.roles, id]; if (!f.roles.length) f.semua = true }
let timer
async function hitung() {
  clearTimeout(timer)
  timer = setTimeout(async () => {
    try { f.perkiraan = await syop.adminPerkiraanPengumuman(f.semua ? [] : f.roles) } catch { f.perkiraan = null }
  }, 250)
}
watch(() => [f.semua, f.roles.length], hitung)
const valid = computed(() => f.judul.trim() && f.isi.trim())
const payload = (kirim) => ({ judul: f.judul.trim(), isi: f.isi.trim(), jenis: f.jenis, sasaran: f.semua ? [] : f.roles, kirim, jadwal: !kirim && f.jadwal ? f.jadwal.replace('T', ' ') + ':00' : null })

async function simpan(kirim) {
  if (!valid.value) return (f.error = t('pg.a.required'))
  f.busy = true
  f.error = ''
  try {
    const r = await syop.adminSimpanPengumuman(payload(kirim), f.id)
    showToast(r.message, 'ok')
    f.open = false
    await load()
  } catch (e) { f.error = errorMessage(e); f.konfirmasi = false } finally { f.busy = false }
}

// ---- kirim / hapus dari daftar ----
const act = ref(null)
const aBusy = ref(false)
async function jalankan() {
  const a = act.value
  aBusy.value = true
  try {
    const r = a.type === 'kirim' ? await syop.adminKirimPengumuman(a.item.id) : await syop.adminHapusPengumuman(a.item.id)
    showToast(r.message, 'ok')
    act.value = null
    await load()
  } catch (e) { showToast(errorMessage(e), 'err'); act.value = null } finally { aBusy.value = false }
}
async function gandakan(a) {
  try {
    const r = await syop.adminGandakanPengumuman(a.id)
    showToast(r.message, 'ok')
    await load()
  } catch (e) { showToast(errorMessage(e), 'err') }
}

// ---- yang sudah / belum membaca ----
const pb = reactive({ open: false, item: null, status: 'belum', loading: false, data: [], total: 0, cut: false, error: '' })
async function bukaPembaca(a, status = 'belum') {
  Object.assign(pb, { open: true, item: a, status, loading: true, data: [], error: '' })
  try {
    const r = await syop.adminPembacaPengumuman(a.id, status)
    Object.assign(pb, { data: r.data, total: r.total, cut: r.dipotong })
  } catch (e) { pb.error = errorMessage(e) } finally { pb.loading = false }
}

// ---- status notifikasi pengguna ----
const dv = reactive({ loaded: false, loading: false, more: false, q: '', filter: '', ringkasan: null, data: [], meta: { page: 1, last: 1, total: 0 }, error: '' })
async function muatPerangkat(page = 1) {
  page === 1 ? (dv.loading = true) : (dv.more = true)
  dv.error = ''
  try {
    const r = await syop.adminPerangkat({ page, per_page: 30, q: dv.q || undefined, filter: dv.filter || undefined })
    dv.data = page === 1 ? r.data : [...dv.data, ...r.data]
    dv.meta = r.meta
    dv.ringkasan = r.ringkasan
    dv.loaded = true
  } catch (e) { dv.error = errorMessage(e) } finally { dv.loading = false; dv.more = false }
}
let dvTimer
watch(() => dv.q, () => { clearTimeout(dvTimer); dvTimer = setTimeout(() => muatPerangkat(), 350) })
watch(() => dv.filter, () => muatPerangkat())
watch(tab, (v) => { if (v === 'perangkat' && !dv.loaded) muatPerangkat() })
const sasaranTeks = (a) => (a.sasaran_nama ? a.sasaran_nama.join(', ') : t('pg.a.everyone'))
const pct = (a) => (a.dikirim_ke ? Math.min(100, Math.round((a.dibaca / a.dikirim_ke) * 100)) : 0)

onMounted(() => {
  load()
  syop.adminPeranPengumuman().then((r) => (peran.value = r)).catch(() => {})
})
</script>

<template>
  <div class="page">
    <div class="page-head" :class="{ stuck: scrolled }">
      <div>
        <h1 class="page-title">{{ t('pg.manage') }}</h1>
        <p class="page-sub">{{ t('pg.a.sub') }}</p>
      </div>
      <router-link :to="{ name: 'profil' }" class="avatar" :aria-label="t('common.profile')"><UserAvatar /><i class="dot" /></router-link>
    </div>

    <div class="tabs2" role="tablist">
      <button role="tab" :aria-selected="tab === 'pengumuman'" :class="{ on: tab === 'pengumuman' }" @click="tab = 'pengumuman'">{{ t('pg.title') }}</button>
      <button role="tab" :aria-selected="tab === 'perangkat'" :class="{ on: tab === 'perangkat' }" @click="tab = 'perangkat'">{{ t('pg.d.tab') }}</button>
    </div>

    <template v-if="tab === 'perangkat'">
      <StateBox v-if="dv.error" icon="alert" :title="t('common.failedLoad')" :text="dv.error" :action="t('common.retry')" @action="muatPerangkat()" />
      <template v-else>
        <div v-if="dv.ringkasan" class="sum">
          <div><b>{{ angka(dv.ringkasan.pengguna) }}</b><small>{{ t('pg.d.users') }}</small></div>
          <div class="g"><b>{{ angka(dv.ringkasan.push_aktif) }}</b><small>{{ t('pg.d.on') }}</small></div>
          <div class="r"><b>{{ angka(dv.ringkasan.push_belum) }}</b><small>{{ t('pg.d.off') }}</small></div>
        </div>
        <input v-model="dv.q" class="in" type="search" :placeholder="t('pg.d.search')" style="margin-bottom:10px" />
        <div class="chips wrapc" style="margin-bottom:12px">
          <button class="chip" :class="{ on: !dv.filter }" @click="dv.filter = ''">{{ t('pg.d.f.all') }}</button>
          <button class="chip" :class="{ on: dv.filter === 'belum' }" @click="dv.filter = 'belum'">{{ t('pg.d.f.off') }}</button>
          <button class="chip" :class="{ on: dv.filter === 'lama' }" @click="dv.filter = 'lama'">{{ t('pg.d.f.old') }}</button>
        </div>
        <div v-if="dv.loading" class="stack"><div v-for="i in 4" :key="i" class="skel" style="height:64px" /></div>
        <StateBox v-else-if="!dv.data.length" icon="user" :title="t('pg.d.none')" :text="''" />
        <div v-else class="stack">
          <div v-for="u in dv.data" :key="u.id" class="card urow">
            <div class="ut"><b>{{ u.nama }}</b><small>{{ u.peran }}</small></div>
            <div class="ub">
              <span class="pill" :class="u.perangkat ? 'pill-ok' : 'pill-draft'">{{ u.perangkat ? t('pg.d.devices', { n: u.perangkat }) : t('pg.d.noPush') }}</span>
              <span class="ver" :class="{ old: u.versi && !u.terbaru }">{{ u.versi ? (u.terbaru ? t('pg.d.latest') : t('pg.d.old')) : t('pg.d.unknown') }}</span>
            </div>
            <small v-if="u.terakhir_aktif" class="ad-m">{{ t('pg.d.last', { t: tanggalJam(u.terakhir_aktif) }) }}</small>
          </div>
        </div>
        <button v-if="dv.meta.page < dv.meta.last" class="btn btn-ghost btn-block" style="margin-top:16px" :disabled="dv.more" @click="muatPerangkat(dv.meta.page + 1)">{{ dv.more ? t('common.loading') : t('common.loadMore') }}</button>
      </template>
    </template>

    <template v-else>
    <button class="newbtn" @click="baru"><Icon name="plus" :size="20" /> {{ t('pg.a.new') }}</button>

    <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load()" />
    <div v-else-if="loading" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:150px" /></div>
    <StateBox v-else-if="!items.length" icon="bell" :title="t('pg.a.none')" :text="t('pg.a.noneText')" />
    <template v-else>
      <div class="stack">
        <article v-for="(a, i) in items" :key="a.id" class="card ad rise" :style="{ '--i': i % 8 }">
          <div class="ad-top">
            <span class="tg" :class="a.jenis"><Icon :name="ikon(a.jenis)" :size="14" /> {{ t('pg.type.' + a.jenis) }}</span>
            <span class="pill" :class="a.status === 'terkirim' ? 'pill-ok' : a.status === 'terjadwal' ? 'pill-sched' : 'pill-draft'">{{ t('pg.a.st.' + a.status) }}</span>
          </div>
          <h3 class="ad-t">{{ a.judul }}</h3>
          <p class="ad-p">{{ a.isi }}</p>
          <small class="ad-m"><Icon name="user" :size="13" /> {{ sasaranTeks(a) }}</small>
          <small v-if="a.status === 'terjadwal'" class="ad-m sched"><Icon name="clock" :size="13" /> {{ t('pg.a.willSend', { t: tanggalJam(a.jadwal_pada) }) }}</small>
          <template v-if="a.status === 'terkirim'">
            <div class="bar"><i :style="{ width: pct(a) + '%' }" /></div>
            <div class="stat"><span>{{ t('pg.a.read', { a: angka(a.dibaca), b: angka(a.dikirim_ke) }) }}</span><span>{{ t('pg.a.push', { n: angka(a.push_ke) }) }}</span></div>
            <small class="ad-m">{{ tanggalJam(a.dikirim_pada) }} · {{ a.dibuat_nama }}</small>
          </template>
          <div class="acts">
            <button v-if="a.status !== 'terkirim'" class="mini ok solid" @click="act = { type: 'kirim', item: a }"><Icon name="check" :size="15" /> {{ t('pg.a.send') }}</button>
            <button v-if="a.status !== 'terkirim'" class="mini" @click="ubah(a)"><Icon name="edit" :size="15" /> {{ t('pg.a.edit') }}</button>
            <button v-if="a.status === 'terkirim'" class="mini" @click="bukaPembaca(a)"><Icon name="user" :size="15" /> {{ t('pg.a.readers') }}</button>
            <button class="mini" @click="gandakan(a)"><Icon name="copy" :size="15" /> {{ t('pg.a.copy') }}</button>
            <button class="mini bad" @click="act = { type: 'hapus', item: a }"><Icon name="trash" :size="15" /> {{ t('common.delete') }}</button>
          </div>
        </article>
      </div>
      <button v-if="meta.page < meta.last" class="btn btn-ghost btn-block" style="margin-top:16px" :disabled="more" @click="load(meta.page + 1)">{{ more ? t('common.loading') : t('common.loadMore') }}</button>
    </template>
    </template>

    <!-- formulir -->
    <div v-if="f.open" class="sheet-back full" @click.self="!f.busy && (f.open = false)">
      <div class="sheet tall" role="dialog" aria-modal="true">
        <div class="grab" />
        <div class="fh"><h2 class="sheet-t">{{ f.id ? t('pg.a.editTitle') : t('pg.a.new') }}</h2><button class="x" :aria-label="t('common.close')" @click="f.open = false"><Icon name="x" :size="18" /></button></div>

        <div class="field">
          <label>{{ t('pg.a.type') }}</label>
          <div class="jseg" role="radiogroup">
            <button v-for="j in JENIS" :key="j" role="radio" :aria-checked="f.jenis === j" :class="[j, { on: f.jenis === j }]" @click="pilihJenis(j)"><Icon :name="ikon(j)" :size="16" />{{ t('pg.type.' + j) }}</button>
          </div>
        </div>
        <div class="field"><label for="pj">{{ t('pg.a.subject') }}</label><input id="pj" v-model="f.judul" class="in" maxlength="150" :placeholder="t('pg.a.subjectPh')" /></div>
        <div class="field"><label for="pi">{{ t('pg.a.body') }}</label><textarea id="pi" v-model="f.isi" class="plain tall-ta" maxlength="3000" :placeholder="t('pg.a.bodyPh')" /><small class="cnt">{{ f.isi.length }}/3000</small></div>

        <div class="field"><label for="pjw">{{ t('pg.a.schedule') }}</label><input id="pjw" v-model="f.jadwal" class="in" type="datetime-local" /><small class="cnt" style="text-align:left">{{ f.jadwal ? t('pg.a.scheduleHint') : t('pg.a.scheduleNone') }}</small></div>

        <div class="field">
          <label>{{ t('pg.a.audience') }}</label>
          <div class="chips wrapc">
            <button class="chip" :class="{ on: f.semua }" @click="f.semua = true; f.roles = []">{{ t('pg.a.everyone') }}</button>
            <button v-for="p in peran" :key="p.id" class="chip" :class="{ on: !f.semua && f.roles.includes(p.id) }" @click="toggleRole(p.id)">{{ p.nama }} <small>{{ p.pengguna }}</small></button>
          </div>
          <p v-if="f.perkiraan" class="est"><Icon name="bell" :size="15" /> {{ t('pg.a.estimate', { u: angka(f.perkiraan.pengguna), d: angka(f.perkiraan.perangkat) }) }}</p>
        </div>

        <div v-if="valid" class="prev"><small>{{ t('pg.a.preview') }}</small><div class="pv-card"><span class="ic" :class="f.jenis"><Icon :name="ikon(f.jenis)" :size="18" /></span><div><b>{{ f.judul }}</b><p>{{ f.isi }}</p></div></div></div>

        <p v-if="f.error" class="err-text" role="alert" style="margin-bottom:10px">{{ f.error }}</p>
        <div class="facts">
          <button class="btn btn-ghost" :disabled="f.busy" @click="simpan(false)">{{ f.jadwal ? t('pg.a.doSchedule') : t('pg.a.draft') }}</button>
          <button class="btn btn-primary" :disabled="f.busy || !valid" @click="f.konfirmasi = true"><Icon name="check" :size="18" /> {{ t('pg.a.sendNow') }}</button>
        </div>

        <div v-if="f.konfirmasi" class="sheet-back" @click.self="!f.busy && (f.konfirmasi = false)">
          <div class="sheet" role="dialog" aria-modal="true">
            <div class="grab" />
            <h2 class="sheet-t">{{ t('pg.a.confirmTitle') }}</h2>
            <p class="muted" style="margin:6px 0">{{ f.perkiraan ? t('pg.a.estimate', { u: angka(f.perkiraan.pengguna), d: angka(f.perkiraan.perangkat) }) : '' }}</p>
            <p class="hint">{{ t('pg.a.confirmHint') }}</p>
            <div class="sheet-act">
              <button class="btn btn-ghost" :disabled="f.busy" @click="f.konfirmasi = false">{{ t('common.cancel') }}</button>
              <button class="btn btn-primary" :disabled="f.busy" @click="simpan(true)">{{ f.busy ? t('det.processing') : t('pg.a.sendNow') }}</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- pembaca -->
    <div v-if="pb.open" class="sheet-back" @click.self="pb.open = false">
      <div class="sheet tall" role="dialog" aria-modal="true">
        <div class="grab" />
        <div class="fh"><h2 class="sheet-t">{{ t('pg.a.readers') }}</h2><button class="x" :aria-label="t('common.close')" @click="pb.open = false"><Icon name="x" :size="18" /></button></div>
        <p class="muted" style="margin:0 0 10px">{{ pb.item.judul }}</p>
        <div class="jseg two">
          <button :class="{ on: pb.status === 'belum' }" @click="bukaPembaca(pb.item, 'belum')">{{ t('pg.a.notRead') }}</button>
          <button :class="{ on: pb.status === 'sudah' }" @click="bukaPembaca(pb.item, 'sudah')">{{ t('pg.a.hasRead') }}</button>
        </div>
        <p v-if="pb.error" class="err-text" role="alert">{{ pb.error }}</p>
        <div v-else-if="pb.loading" class="stack" style="margin-top:12px"><div v-for="i in 4" :key="i" class="skel" style="height:44px" /></div>
        <template v-else>
          <p class="muted" style="margin:12px 0 6px">{{ t('pg.a.people', { n: angka(pb.total) }) }}<span v-if="pb.cut"> · {{ t('pg.a.cut') }}</span></p>
          <p v-if="!pb.data.length" class="muted">{{ t('pg.a.nobody') }}</p>
          <ul class="plist"><li v-for="u in pb.data" :key="u.id"><b>{{ u.nama }}</b><small>{{ u.peran }}</small></li></ul>
        </template>
      </div>
    </div>

    <!-- kirim / hapus dari daftar -->
    <div v-if="act" class="sheet-back" @click.self="!aBusy && (act = null)">
      <div class="sheet" role="dialog" aria-modal="true">
        <div class="grab" />
        <h2 class="sheet-t">{{ act.type === 'kirim' ? t('pg.a.confirmTitle') : t('pg.a.deleteTitle') }}</h2>
        <p class="muted" style="margin:6px 0">{{ act.item.judul }}</p>
        <p class="hint">{{ act.type === 'kirim' ? t('pg.a.confirmHint') : t('pg.a.deleteHint') }}</p>
        <div class="sheet-act">
          <button class="btn btn-ghost" :disabled="aBusy" @click="act = null">{{ t('common.cancel') }}</button>
          <button class="btn" :class="act.type === 'kirim' ? 'btn-primary' : 'btn-danger'" :disabled="aBusy" @click="jalankan">{{ aBusy ? t('det.processing') : act.type === 'kirim' ? t('pg.a.sendNow') : t('common.delete') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.newbtn { width: 100%; min-height: 52px; margin-bottom: 14px; border: 0; border-radius: 16px; background: var(--accent); color: #fff; font-size: 15px; font-weight: 800; display: flex; align-items: center; justify-content: center; gap: 8px; font-family: inherit; box-shadow: 0 8px 22px color-mix(in srgb, var(--accent) 30%, transparent); }
.ad { padding: 14px 16px; display: grid; gap: 8px; border: 1px solid var(--line); }
.ad-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.ad-t { margin: 0; font-size: 16px; font-weight: 800; letter-spacing: -0.01em; overflow-wrap: anywhere; }
.ad-p { margin: 0; font-size: 13px; color: var(--ink-2); display: -webkit-box; -webkit-line-clamp: 3; line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; white-space: pre-line; }
.ad-m { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--muted); }
.bar { height: 8px; border-radius: 99px; background: var(--line); overflow: hidden; }
.bar i { display: block; height: 100%; border-radius: 99px; background: linear-gradient(90deg, #2db3ad, #0f8b8d); transition: width 0.5s; }
.stat { display: flex; justify-content: space-between; gap: 8px; font-size: 12.5px; font-weight: 700; color: var(--ink-2); }
.pill-draft { background: var(--line); color: var(--muted); }
.acts { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 2px; }
.mini { display: inline-flex; align-items: center; gap: 5px; min-height: 40px; padding: 0 14px; border-radius: 11px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 12.5px; font-weight: 700; font-family: inherit; }
.mini.ok.solid { background: var(--green); color: #fff; border-color: var(--green); }
.mini.bad { color: #b4232a; border-color: color-mix(in srgb, var(--red) 40%, var(--line)); }
.tg { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 99px; background: #e6eeff; color: var(--accent); font-size: 12px; font-weight: 800; }
.tg.penting { background: #fdeaea; color: #b4232a; }
.tg.pembaruan { background: #e3f6f3; color: #0b7a6b; }
.sheet.tall { max-height: 94vh; overflow-y: auto; }
.fh { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.x { width: 40px; height: 40px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); display: grid; place-items: center; }
.jseg { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
.jseg button { min-height: 46px; border-radius: 13px; border: 1.5px solid var(--line); background: var(--card); color: var(--ink); font-size: 12.5px; font-weight: 800; display: flex; align-items: center; justify-content: center; gap: 6px; font-family: inherit; }
.jseg button.on { border-color: var(--accent); background: #e6eeff; color: var(--accent); }
.jseg button.penting.on { border-color: #d93a45; background: #fdeaea; color: #b4232a; }
.jseg button.pembaruan.on { border-color: #0e9f94; background: #e3f6f3; color: #0b7a6b; }
.in { width: 100%; height: 48px; border-radius: 14px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; font-weight: 600; padding: 0 14px; font-family: inherit; }
.tall-ta { min-height: 130px; }
.cnt { display: block; text-align: right; margin-top: 4px; font-size: 11px; color: var(--muted); }
.wrapc { flex-wrap: wrap; }
.chip small { margin-left: 6px; opacity: 0.7; font-size: 11px; }
.est { display: flex; align-items: center; gap: 8px; margin: 10px 0 0; padding: 9px 12px; border-radius: 12px; background: var(--blue-50); color: var(--ink-2); font-size: 12.5px; font-weight: 600; }
.prev { margin: 4px 0 14px; display: grid; gap: 6px; }
.prev small { font-size: 11.5px; color: var(--muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; }
.pv-card { display: flex; gap: 12px; padding: 12px 14px; border-radius: 16px; background: #1b2438; color: #fff; }
.pv-card .ic { flex: none; width: 36px; height: 36px; border-radius: 11px; display: grid; place-items: center; background: #2d4fa0; color: #fff; }
.pv-card .ic.penting { background: #b4232a; } .pv-card .ic.pembaruan { background: #0b7a6b; }
.pv-card b { font-size: 14px; }
.pv-card p { margin: 2px 0 0; font-size: 12.5px; color: #c8d0e4; display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.facts { display: grid; grid-template-columns: 1fr 1.4fr; gap: 10px; }
.hint { margin: 0 0 14px; padding: 8px 10px; border-radius: 10px; background: var(--blue-50); color: var(--ink-2); font-size: 12.5px; }

.tabs2 { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; padding: 4px; margin-bottom: 14px; border-radius: 14px; background: var(--line); }
.tabs2 button { min-height: 42px; border: 0; border-radius: 11px; background: transparent; color: var(--muted); font-size: 13.5px; font-weight: 800; font-family: inherit; }
.tabs2 button.on { background: var(--card); color: var(--accent); box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); }
.pill-sched { background: #fff1d6; color: #9a6200; }
.ad-m.sched { color: #9a6200; font-weight: 700; }
.sum { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin-bottom: 12px; }
.sum div { padding: 12px; border-radius: 16px; background: var(--card); border: 1px solid var(--line); display: grid; gap: 2px; text-align: center; }
.sum b { font-size: 22px; font-weight: 800; letter-spacing: -0.02em; }
.sum small { font-size: 11.5px; color: var(--muted); font-weight: 700; }
.sum .g b { color: var(--green); } .sum .r b { color: #b4232a; }
.urow { padding: 12px 14px; display: grid; gap: 6px; border: 1px solid var(--line); }
.ut { display: flex; flex-direction: column; gap: 1px; }
.ut small { font-size: 12px; color: var(--muted); }
.ub { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.ver { font-size: 12px; font-weight: 700; color: var(--green); }
.ver.old { color: #b4232a; }
.jseg.two { grid-template-columns: 1fr 1fr; }
.plist { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; max-height: 50vh; overflow-y: auto; }
.plist li { display: flex; justify-content: space-between; gap: 10px; padding: 10px 12px; border-radius: 12px; background: var(--blue-50); font-size: 13.5px; }
.plist small { color: var(--muted); font-size: 12px; }
</style>
