<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { t } from '../../i18n'
import Icon from '../Icon.vue'
import StateBox from '../StateBox.vue'
import * as syop from '../../api/syop'
import { errorMessage } from '../../api/client'
import { showToast } from '../../composables/useToast'
import { angka, tanggalJam } from '../../utils/format'

const s = reactive({ q: '', status: '1', role: '', data: [], meta: { page: 1, last: 1, total: 0 }, loading: true, more: false, error: '' })
const opsi = ref({ peran: [], cabang: [], group: [], transportir: [], terminal: [], om: [] })

async function load(page = 1) {
  page === 1 ? (s.loading = true) : (s.more = true)
  s.error = ''
  try {
    const r = await syop.penggunaList({ page, per_page: 25, q: s.q || undefined, status: s.status, role: s.role || undefined })
    s.data = page === 1 ? r.data : [...s.data, ...r.data]
    s.meta = r.meta
  } catch (e) { s.error = errorMessage(e) } finally { s.loading = false; s.more = false }
}
let tm
watch(() => s.q, () => { clearTimeout(tm); tm = setTimeout(() => load(), 350) })
watch(() => [s.status, s.role], () => load())
onMounted(async () => {
  load()
  try { opsi.value = await syop.penggunaOpsi() } catch { /* form akan kosong; daftar tetap jalan */ }
})

// ---- aksi pada satu pengguna: reset password, aktif/nonaktif
const target = ref(null) // { u, aksi: 'reset' | 'nonaktif' | 'aktif' | 'peran' | 'hapus' }
const peranBaru = ref('')
function pilihAksi(u, aksi) { peranBaru.value = ''; target.value = { u, aksi } }
const busy = ref(false)
async function jalankan() {
  const { u, aksi } = target.value
  busy.value = true
  try {
    const r = aksi === 'reset' ? await syop.penggunaResetSandi(u.id)
      : aksi === 'peran' ? await syop.penggunaUbahPeran(u.id, Number(peranBaru.value))
        : aksi === 'hapus' ? await syop.penggunaHapus(u.id)
          : await syop.penggunaUbah(u.id, { aktif: aksi === 'aktif', id_om: u.id_om || undefined })
    showToast(r.message, 'ok')
    target.value = null
    await load()
  } catch (e) { showToast(errorMessage(e), 'err'); target.value = null } finally { busy.value = false }
}
const judulAksi = computed(() => (!target.value ? '' : t({ reset: 'usr.resetTitle', nonaktif: 'usr.deactTitle', aktif: 'usr.actTitle', peran: 'usr.roleTitle', hapus: 'usr.delTitle' }[target.value.aksi])))
const petunjuk = computed(() => (!target.value ? '' : target.value.aksi === 'reset' ? t('usr.resetHint') : target.value.aksi === 'nonaktif' ? t('usr.deactHint') : target.value.aksi === 'peran' ? t('usr.roleHint') : target.value.aksi === 'hapus' ? t('usr.delHint') : ''))
const labelAksi = computed(() => ({ reset: 'usr.reset', nonaktif: 'usr.deact', aktif: 'usr.activate', peran: 'usr.role', hapus: 'usr.del' }[target.value?.aksi]))

// ---- atur password manual
const sandi = ref(null)
const sb = reactive({ busy: false, error: '' })
function bukaSandi(u) { sandi.value = { u, password: '', password_confirmation: '' }; sb.error = '' }
async function simpanSandi() {
  const x = sandi.value
  if (!x.password) { sb.error = t('usr.errReq'); return }
  if (x.password !== x.password_confirmation) { sb.error = t('usr.mismatch'); return }
  sb.busy = true
  sb.error = ''
  try {
    const r = await syop.penggunaAturSandi(x.u.id, { password: x.password, password_confirmation: x.password_confirmation })
    showToast(r.message, 'ok')
    sandi.value = null
  } catch (e) { sb.error = errorMessage(e) } finally { sb.busy = false }
}

// ---- tambah pengguna
const kosong = () => ({ username: '', fullname: '', email: '', telepon: '', password: '', password_confirmation: '', id_role: '', id_wilayah: '', cakupan: '1', id_transportir: '', id_terminal: '', id_om: '' })
const form = ref(null)
const fb = reactive({ busy: false, error: '' })
function buka() { form.value = kosong(); fb.error = '' }
const role = computed(() => Number(form.value?.id_role) || 0)
const pilihanWilayah = computed(() => (role.value === 6 || (role.value === 18 && form.value.cakupan === '2') ? opsi.value.group : opsi.value.cabang))
const perluWilayah = computed(() => role.value && ![12, 13, 17].includes(role.value) && !(role.value === 18 && form.value.cakupan === '3'))
watch(() => [form.value?.id_role, form.value?.cakupan], () => { if (form.value) form.value.id_wilayah = '' })

async function simpan() {
  const f = form.value
  const wajib = [f.username.trim(), f.fullname.trim(), f.email.trim(), f.telepon.trim(), f.password, f.id_role]
  if (wajib.some((x) => !x) || (perluWilayah.value && !f.id_wilayah) || (role.value === 12 && !f.id_transportir) || (role.value === 13 && !f.id_terminal) || (role.value === 17 && !f.id_om)) {
    fb.error = t('usr.errReq'); return
  }
  fb.busy = true
  fb.error = ''
  try {
    const r = await syop.penggunaTambah({
      ...f, username: f.username.trim(), fullname: f.fullname.trim(), email: f.email.trim(), telepon: f.telepon.trim(),
      id_role: Number(f.id_role), id_wilayah: f.id_wilayah ? Number(f.id_wilayah) : null, cakupan: role.value === 18 ? f.cakupan : null,
      id_transportir: f.id_transportir ? Number(f.id_transportir) : null, id_terminal: f.id_terminal ? Number(f.id_terminal) : null, id_om: f.id_om ? Number(f.id_om) : null,
    })
    showToast(r.message || t('usr.created'), 'ok')
    form.value = null
    await load()
  } catch (e) { fb.error = errorMessage(e) } finally { fb.busy = false }
}
</script>

<template>
  <button class="btn btn-primary btn-block" style="margin-bottom:12px" @click="buka"><Icon name="plus" :size="18" /> {{ t('usr.add') }}</button>
  <input v-model="s.q" class="in" type="search" :placeholder="t('usr.search')" style="margin-bottom:10px" />
  <div class="chips wrapc" style="margin-bottom:10px">
    <button class="chip" :class="{ on: s.status === '1' }" @click="s.status = '1'">{{ t('usr.f.active') }}</button>
    <button class="chip" :class="{ on: s.status === '0' }" @click="s.status = '0'">{{ t('usr.f.inactive') }}</button>
    <button class="chip" :class="{ on: s.status === '2' }" @click="s.status = '2'">{{ t('usr.f.all') }}</button>
  </div>
  <select v-model="s.role" class="in" style="margin-bottom:12px" :aria-label="t('usr.f.role')">
    <option value="">{{ t('usr.allRoles') }}</option>
    <option v-for="p in opsi.peran" :key="p.id" :value="p.id">{{ p.nama }}</option>
  </select>
  <p v-if="!s.loading && !s.error" class="cnt">{{ t('mst.count', { n: angka(s.meta.total) }) }}</p>

  <StateBox v-if="s.error" icon="alert" :title="t('common.failedLoad')" :text="s.error" :action="t('common.retry')" @action="load()" />
  <div v-else-if="s.loading" class="stack"><div v-for="i in 4" :key="i" class="skel" style="height:96px" /></div>
  <StateBox v-else-if="!s.data.length" icon="user" :title="t('usr.none')" :text="t('usr.noneText')" />
  <div v-else class="stack">
    <article v-for="u in s.data" :key="u.id" class="card urow">
      <div class="top">
        <div class="ut"><b>{{ u.nama }}</b><small>{{ u.username }}<template v-if="u.email"> · {{ u.email }}</template></small></div>
        <span class="pill" :class="u.aktif ? 'pill-ok' : 'pill-draft'">{{ u.aktif ? t('usr.act') : t('usr.inact') }}</span>
      </div>
      <small class="ad-m">{{ u.peran }}<template v-if="u.wilayah"> · {{ u.wilayah }}</template></small>
      <small class="ad-m">{{ u.login_terakhir ? t('usr.last', { t: tanggalJam(u.login_terakhir) }) : t('usr.never') }}</small>
      <div class="acts">
        <button class="mini" @click="pilihAksi(u, 'reset')"><Icon name="lock" :size="15" /> {{ t('usr.reset') }}</button>
        <button class="mini" @click="bukaSandi(u)"><Icon name="lock" :size="15" /> {{ t('usr.setPass') }}</button>
        <button class="mini" @click="pilihAksi(u, 'peran')">{{ t('usr.role') }}</button>
        <button v-if="u.aktif" class="mini bad" @click="pilihAksi(u, 'nonaktif')">{{ t('usr.deact') }}</button>
        <button v-else class="mini" @click="pilihAksi(u, 'aktif')">{{ t('usr.activate') }}</button>
        <button v-if="!u.login_terakhir" class="mini bad" @click="pilihAksi(u, 'hapus')">{{ t('usr.del') }}</button>
      </div>
    </article>
  </div>
  <button v-if="s.meta.page < s.meta.last" class="btn btn-ghost btn-block" style="margin-top:16px" :disabled="s.more" @click="load(s.meta.page + 1)">{{ s.more ? t('common.loading') : t('common.loadMore') }}</button>

  <div v-if="target" class="sheet-back" @click.self="!busy && (target = null)">
    <div class="sheet" role="dialog" aria-modal="true">
      <div class="grab" />
      <h2 class="sheet-t">{{ judulAksi }}</h2>
      <p class="muted" style="margin:6px 0">{{ target.u.nama }} ({{ target.u.username }})</p>
      <p v-if="petunjuk" class="hint">{{ petunjuk }}</p>
      <div v-if="target.aksi === 'peran'" class="field">
        <label for="pr">{{ t('usr.newRole') }} *</label>
        <select id="pr" v-model="peranBaru" class="in"><option value="">{{ t('usr.f.pick') }}</option><option v-for="p in opsi.peran" :key="p.id" :value="p.id" :disabled="p.id === target.u.id_role">{{ p.nama }}</option></select>
      </div>
      <div class="sheet-act">
        <button class="btn btn-ghost" :disabled="busy" @click="target = null">{{ t('common.cancel') }}</button>
        <button class="btn" :class="['aktif', 'peran'].includes(target.aksi) ? 'btn-primary' : 'btn-danger'" :disabled="busy || (target.aksi === 'peran' && !peranBaru)" @click="jalankan">{{ busy ? t('det.processing') : t(labelAksi) }}</button>
      </div>
    </div>
  </div>

  <div v-if="sandi" class="sheet-back" @click.self="!sb.busy && (sandi = null)">
    <div class="sheet" role="dialog" aria-modal="true">
      <div class="grab" />
      <h2 class="sheet-t">{{ t('usr.setTitle') }}</h2>
      <p class="muted" style="margin:6px 0">{{ sandi.u.nama }} ({{ sandi.u.username }})</p>
      <p class="hint">{{ t('usr.setHint') }}</p>
      <div class="field"><label for="s1">{{ t('usr.new1') }} *</label><input id="s1" v-model="sandi.password" class="in" type="password" autocomplete="new-password" maxlength="40" /></div>
      <div class="field"><label for="s2">{{ t('usr.new2') }} *</label><input id="s2" v-model="sandi.password_confirmation" class="in" type="password" autocomplete="new-password" maxlength="40" /></div>
      <p v-if="sb.error" class="err-text" role="alert">{{ sb.error }}</p>
      <div class="sheet-act">
        <button class="btn btn-ghost" :disabled="sb.busy" @click="sandi = null">{{ t('common.cancel') }}</button>
        <button class="btn btn-primary" :disabled="sb.busy" @click="simpanSandi">{{ sb.busy ? t('det.processing') : t('sys.save') }}</button>
      </div>
    </div>
  </div>

  <div v-if="form" class="sheet-back" @click.self="!fb.busy && (form = null)">
    <div class="sheet tall" role="dialog" aria-modal="true">
      <div class="grab" />
      <h2 class="sheet-t">{{ t('usr.new') }}</h2>
      <div class="field"><label for="u1">{{ t('usr.f.username') }} *</label><input id="u1" v-model="form.username" class="in" autocomplete="off" autocapitalize="off" maxlength="50" /></div>
      <div class="field"><label for="u2">{{ t('usr.f.fullname') }} *</label><input id="u2" v-model="form.fullname" class="in" autocomplete="off" maxlength="80" /></div>
      <div class="field"><label for="u3">{{ t('usr.f.email') }} *</label><input id="u3" v-model="form.email" class="in" type="email" autocomplete="off" maxlength="60" /></div>
      <div class="field"><label for="u4">{{ t('usr.f.phone') }} *</label><input id="u4" v-model="form.telepon" class="in" type="tel" autocomplete="off" maxlength="20" /></div>
      <div class="field"><label for="u5">{{ t('usr.f.pass') }} *</label><input id="u5" v-model="form.password" class="in" type="password" autocomplete="new-password" maxlength="40" /></div>
      <div class="field"><label for="u6">{{ t('usr.f.pass2') }} *</label><input id="u6" v-model="form.password_confirmation" class="in" type="password" autocomplete="new-password" maxlength="40" /></div>
      <div class="field">
        <label for="u7">{{ t('usr.f.role') }} *</label>
        <select id="u7" v-model="form.id_role" class="in"><option value="">{{ t('usr.f.pick') }}</option><option v-for="p in opsi.peran" :key="p.id" :value="p.id">{{ p.nama }}</option></select>
      </div>
      <div v-if="role === 18" class="field">
        <label>{{ t('usr.f.scope') }}</label>
        <div class="chips"><button v-for="k in ['1', '2', '3']" :key="k" type="button" class="chip" :class="{ on: form.cakupan === k }" @click="form.cakupan = k">{{ t('usr.f.scope' + k) }}</button></div>
      </div>
      <div v-if="perluWilayah" class="field">
        <label for="u8">{{ (role === 6 || (role === 18 && form.cakupan === '2')) ? t('usr.f.group') : t('usr.f.region') }} *</label>
        <select id="u8" v-model="form.id_wilayah" class="in"><option value="">{{ t('usr.f.pick') }}</option><option v-for="c in pilihanWilayah" :key="c.id" :value="c.id">{{ c.nama }}</option></select>
      </div>
      <div v-if="role === 12" class="field">
        <label for="u9">{{ t('usr.f.trans') }} *</label>
        <select id="u9" v-model="form.id_transportir" class="in"><option value="">{{ t('usr.f.pick') }}</option><option v-for="c in opsi.transportir" :key="c.id" :value="c.id">{{ c.nama }}</option></select>
      </div>
      <div v-if="role === 13" class="field">
        <label for="u10">{{ t('usr.f.term') }} *</label>
        <select id="u10" v-model="form.id_terminal" class="in"><option value="">{{ t('usr.f.pick') }}</option><option v-for="c in opsi.terminal" :key="c.id" :value="c.id">{{ c.nama }}</option></select>
      </div>
      <div v-if="role === 17" class="field">
        <label for="u11">{{ t('usr.f.om') }} *</label>
        <select id="u11" v-model="form.id_om" class="in"><option value="">{{ t('usr.f.pick') }}</option><option v-for="c in opsi.om" :key="c.id" :value="c.id">{{ c.nama }}</option></select>
      </div>
      <p v-if="fb.error" class="err-text" role="alert">{{ fb.error }}</p>
      <div class="sheet-act">
        <button class="btn btn-ghost" :disabled="fb.busy" @click="form = null">{{ t('common.cancel') }}</button>
        <button class="btn btn-primary" :disabled="fb.busy" @click="simpan">{{ fb.busy ? t('det.processing') : t('sys.save') }}</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.in { width: 100%; height: 48px; border-radius: 14px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 16px; font-weight: 600; padding: 0 14px; font-family: inherit; }
.wrapc { flex-wrap: wrap; }
.cnt { margin: 0 0 8px; font-size: 12.5px; color: var(--muted); font-weight: 700; }
.urow { padding: 12px 14px; display: grid; gap: 6px; border: 1px solid var(--line); }
.top { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.ut { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.ut b { overflow-wrap: anywhere; }
.ut small { font-size: 12px; color: var(--muted); overflow-wrap: anywhere; }
.pill-draft { background: var(--line); color: var(--muted); }
.ad-m { font-size: 12px; color: var(--muted); overflow-wrap: anywhere; }
.acts { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 2px; }
.mini { display: inline-flex; align-items: center; gap: 5px; min-height: 40px; padding: 0 14px; border-radius: 11px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 12.5px; font-weight: 700; font-family: inherit; }
.mini.bad { color: #b4232a; border-color: color-mix(in srgb, var(--red) 40%, var(--line)); }
.hint { margin: 0 0 14px; padding: 8px 10px; border-radius: 10px; background: var(--blue-50); color: var(--ink-2); font-size: 12.5px; }
.tall { max-height: 88vh; overflow-y: auto; }
</style>
