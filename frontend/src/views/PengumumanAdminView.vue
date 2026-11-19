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
const f = reactive({ open: false, id: null, jenis: 'info', judul: '', isi: '', semua: true, roles: [], busy: false, error: '', perkiraan: null, konfirmasi: false })
function baru() {
  Object.assign(f, { open: true, id: null, jenis: 'info', judul: '', isi: '', semua: true, roles: [], busy: false, error: '', perkiraan: null, konfirmasi: false })
  hitung()
}
function ubah(a) {
  Object.assign(f, { open: true, id: a.id, jenis: a.jenis, judul: a.judul, isi: a.isi, semua: !a.sasaran.length, roles: [...a.sasaran], busy: false, error: '', perkiraan: null, konfirmasi: false })
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
const payload = (kirim) => ({ judul: f.judul.trim(), isi: f.isi.trim(), jenis: f.jenis, sasaran: f.semua ? [] : f.roles, kirim })

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

    <button class="newbtn" @click="baru"><Icon name="plus" :size="20" /> {{ t('pg.a.new') }}</button>

    <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load()" />
    <div v-else-if="loading" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:150px" /></div>
    <StateBox v-else-if="!items.length" icon="bell" :title="t('pg.a.none')" :text="t('pg.a.noneText')" />
    <template v-else>
      <div class="stack">
        <article v-for="(a, i) in items" :key="a.id" class="card ad rise" :style="{ '--i': i % 8 }">
          <div class="ad-top">
            <span class="tg" :class="a.jenis"><Icon :name="ikon(a.jenis)" :size="14" /> {{ t('pg.type.' + a.jenis) }}</span>
            <span class="pill" :class="a.status === 'terkirim' ? 'pill-ok' : 'pill-draft'">{{ t('pg.a.st.' + a.status) }}</span>
          </div>
          <h3 class="ad-t">{{ a.judul }}</h3>
          <p class="ad-p">{{ a.isi }}</p>
          <small class="ad-m"><Icon name="user" :size="13" /> {{ sasaranTeks(a) }}</small>
          <template v-if="a.status === 'terkirim'">
            <div class="bar"><i :style="{ width: pct(a) + '%' }" /></div>
            <div class="stat"><span>{{ t('pg.a.read', { a: angka(a.dibaca), b: angka(a.dikirim_ke) }) }}</span><span>{{ t('pg.a.push', { n: angka(a.push_ke) }) }}</span></div>
            <small class="ad-m">{{ tanggalJam(a.dikirim_pada) }} · {{ a.dibuat_nama }}</small>
          </template>
          <div class="acts">
            <button v-if="a.status === 'draft'" class="mini ok solid" @click="act = { type: 'kirim', item: a }"><Icon name="check" :size="15" /> {{ t('pg.a.send') }}</button>
            <button v-if="a.status === 'draft'" class="mini" @click="ubah(a)"><Icon name="edit" :size="15" /> {{ t('pg.a.edit') }}</button>
            <button class="mini bad" @click="act = { type: 'hapus', item: a }"><Icon name="trash" :size="15" /> {{ t('common.delete') }}</button>
          </div>
        </article>
      </div>
      <button v-if="meta.page < meta.last" class="btn btn-ghost btn-block" style="margin-top:16px" :disabled="more" @click="load(meta.page + 1)">{{ more ? t('common.loading') : t('common.loadMore') }}</button>
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
          <button class="btn btn-ghost" :disabled="f.busy" @click="simpan(false)">{{ t('pg.a.draft') }}</button>
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
</style>
