<script setup>
import { t, ts, lang, setLang } from '../i18n'
import { theme, setTheme } from '../composables/useTheme'
import { computed, defineAsyncComponent, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import UserAvatar from '../components/UserAvatar.vue'
import { useAuth } from '../stores/auth'
import { useInbox } from '../stores/inbox'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'
import { showToast } from '../composables/useToast'
import { usePush } from '../composables/usePush'
import { tanggal } from '../utils/format'
import { update, reloadApp, checkUpdate } from '../composables/useUpdate'

const FaceScan = defineAsyncComponent(() => import('../components/FaceScan.vue'))

const route = useRoute()
const router = useRouter()
const auth = useAuth()
const inbox = useInbox()
const info = ref(false)
const busy = ref(false)
const checking = ref(false)
async function cekPembaruan() {
  checking.value = true
  const ada = await checkUpdate()
  checking.value = false
  if (!ada) showToast(t('prof.upToDate'))
}

// --- Foto profil ---
const fileEl = ref(null)
const photoBusy = ref(false)

/** Potong persegi dari tengah lalu perkecil di perangkat (hemat kuota; server juga memotong & mengecek ulang). */
async function toSquareJpeg(file, size = 512) {
  const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' })
  const side = Math.min(bmp.width, bmp.height)
  const c = document.createElement('canvas')
  c.width = c.height = size
  const ctx = c.getContext('2d')
  ctx.fillStyle = '#fff'
  ctx.fillRect(0, 0, size, size)
  ctx.drawImage(bmp, (bmp.width - side) / 2, (bmp.height - side) / 2, side, side, 0, 0, size, size)
  bmp.close?.()
  return new Promise((res, rej) => c.toBlob((b) => (b ? res(b) : rej(new Error('gagal'))), 'image/jpeg', 0.85))
}
async function onPick(e) {
  const f = e.target.files?.[0]
  e.target.value = ''
  if (!f) return
  if (!f.type.startsWith('image/')) { showToast(t('prof.pickImage'), 'err'); return }
  photoBusy.value = true
  try {
    await auth.changePhoto(await toSquareJpeg(f))
    showToast(t('prof.photoUpdated'), 'ok')
  } catch (err) {
    showToast(err.response ? errorMessage(err) : t('prof.photoFail'), 'err')
  } finally { photoBusy.value = false }
}
async function delPhoto() {
  photoBusy.value = true
  try { await auth.removePhoto(); showToast(t('prof.photoRemoved'), 'ok') }
  catch (err) { showToast(errorMessage(err), 'err') }
  finally { photoBusy.value = false }
}

// --- Notifikasi (Web Push) ---
const push = usePush()
const pushLabel = computed(() => t({
  on: 'prof.pushOn', off: 'prof.pushOff', denied: 'prof.pushDenied',
  'need-install': 'prof.pushInstall', unavailable: 'prof.pushUnavail', unsupported: 'prof.pushUnsupported',
}[push.state.value]))
async function togglePush() {
  try {
    if (push.state.value === 'on') { await push.disable(); showToast(t('prof.pushDisabledToast'), 'ok') }
    else { await push.enable(); showToast(t('prof.pushEnabledToast'), 'ok') }
  } catch (e) { showToast(e.response ? errorMessage(e) : e.message, 'err') }
}
async function testPush() {
  try {
    const n = await push.sendTest()
    showToast(n ? t('prof.testSent') : t('prof.testNone'), n ? 'ok' : 'err')
  } catch (e) { showToast(errorMessage(e), 'err') }
}

// Uji lencana ikon: pasang angka 5 lalu laporkan hasilnya (menolak = izin/OS/launcher perangkat), supaya penyebab terlihat di perangkat.
async function testBadge() {
  const id = lang.value === 'id'
  if (!('setAppBadge' in navigator)) { showToast(id ? 'Perangkat/peramban ini tidak mendukung lencana ikon' : 'This device/browser does not support icon badges', 'err'); return }
  try {
    await navigator.setAppBadge(5)
    showToast(id ? 'Angka 5 dipasang di ikon. Tutup aplikasi lalu lihat ikonnya.' : 'Badge 5 set. Close the app and check the icon.', 'ok')
  } catch (e) { showToast((id ? 'Ditolak: ' : 'Rejected: ') + (e.name || '') + ' ' + (e.message || ''), 'err') }
}

// --- Face Recognition ---
const enrolledAt = ref(null)
const sheet = ref(null)      // 'enroll' | 'remove' | null
const stage = ref('form')    // 'form' | 'scan' | 'saving'
const pwd = ref('')
const consent = ref(false)
const showPwd = ref(false)
const faceError = ref('')
const scanKey = ref(0)

onMounted(async () => {
  push.refresh()
  try {
    const r = await syop.faceStatus()
    auth.setFaceEnrolled(r.enrolled)
    enrolledAt.value = r.enrolled_at
  } catch { /* status tidak kritis */ }
  // Datang dari ajakan di beranda: langsung buka formulir pendaftaran wajah.
  if (route.query.daftar === 'wajah' && !auth.user?.face_enrolled) openFace('enroll')
})

function openFace(kind) {
  sheet.value = kind
  stage.value = 'form'
  pwd.value = ''
  consent.value = false
  showPwd.value = false
  faceError.value = ''
}
const closeFace = () => { if (stage.value !== 'saving') sheet.value = null }

function toScan() {
  faceError.value = ''
  if (!consent.value) { faceError.value = t('prof.errConsent'); return }
  if (!pwd.value) { faceError.value = t('prof.errPwd'); return }
  scanKey.value++
  stage.value = 'scan'
}

async function onScanned(descriptor) {
  stage.value = 'saving'
  try {
    await syop.faceEnroll(pwd.value, descriptor)
    auth.setFaceEnrolled(true)
    enrolledAt.value = new Date().toISOString().slice(0, 10)
    sheet.value = null
    showToast(t('prof.faceEnrolled'), 'ok')
  } catch (e) {
    faceError.value = errorMessage(e)
    stage.value = 'form' // kembali ke formulir; pesan galat tampil di sana
  }
}

function onScanFail(msg) { faceError.value = msg; stage.value = 'form' }

async function removeFace() {
  if (!pwd.value) { faceError.value = 'Masukkan kata sandi Anda.'; return }
  stage.value = 'saving'
  faceError.value = ''
  try {
    await syop.faceRemove(pwd.value)
    auth.setFaceEnrolled(false)
    enrolledAt.value = null
    sheet.value = null
    showToast(t('prof.faceRemoved'), 'ok')
  } catch (e) {
    faceError.value = errorMessage(e)
    stage.value = 'form'
  }
}

async function logout() {
  if (busy.value) return
  busy.value = true
  await auth.logout()
  inbox.reset()
  router.replace({ name: 'login' })
}
</script>

<template>
  <div>
    <header class="top hero" />
    <div class="body">
      <section class="card who rise" style="--i: 0">
        <div class="av-wrap">
          <button class="av" :disabled="photoBusy" :aria-label="t('prof.changePhoto')" @click="fileEl.click()"><UserAvatar /></button>
          <span class="cam" aria-hidden="true"><Icon name="camera" :size="15" /></span>
          <input ref="fileEl" type="file" accept="image/*" hidden @change="onPick" />
        </div>
        <div class="photo-act">
          <button class="link-btn" :disabled="photoBusy" @click="fileEl.click()">{{ photoBusy ? t('prof.uploading') : (auth.photoUrl ? t('prof.replacePhoto') : t('prof.addPhoto')) }}</button>
          <template v-if="auth.photoUrl && !photoBusy"><span aria-hidden="true">·</span><button class="link-btn bad" @click="delPhoto">{{ t('common.delete') }}</button></template>
        </div>
        <h1>{{ auth.user?.fullname }}</h1>
        <p>{{ (auth.user?.role_name || '').replace(/^Role /, '') }}</p>
        <span class="pill pill-ok">● {{ t('common.active') }}</span>
      </section>

      <section class="card face rise" style="--i: 1">
        <div class="face-row">
          <span class="ico-box tinted tone-indigo"><Icon name="face" :size="20" /></span>
          <div class="grow">
            <b>Face Recognition</b>
            <p>{{ t('prof.faceText') }}</p>
            <small :class="auth.user?.face_enrolled ? 'on' : 'off'">
              {{ auth.user?.face_enrolled ? (enrolledAt ? t('prof.activeSince', { d: tanggal(enrolledAt) }) : t('common.active')) : t('prof.notSet') }}
            </small>
          </div>
        </div>
        <div class="face-act">
          <button class="btn btn-ghost" @click="openFace('enroll')">{{ auth.user?.face_enrolled ? t('prof.changeFace') : t('prof.enrollFace') }}</button>
          <button v-if="auth.user?.face_enrolled" class="btn btn-ghost bad" @click="openFace('remove')">{{ t('common.delete') }}</button>
        </div>
      </section>

      <section class="card face rise" style="--i: 2">
        <div class="face-row">
          <span class="ico-box tinted tone-amber"><Icon name="bell" :size="20" /></span>
          <div class="grow">
            <b>{{ t('prof.notifTitle') }}</b>
            <p>{{ t('prof.notifText') }}</p>
            <small :class="push.state.value === 'on' ? 'on' : 'off'">{{ pushLabel }}</small>
          </div>
        </div>
        <div v-if="['on', 'off'].includes(push.state.value)" class="face-act">
          <button class="btn btn-ghost" :disabled="push.busy.value" @click="togglePush">{{ push.state.value === 'on' ? t('prof.turnOff') : t('prof.turnOn') }}</button>
          <button v-if="push.state.value === 'on'" class="btn btn-ghost" @click="testPush">{{ t('prof.sendTest') }}</button>
          <button class="btn btn-ghost" @click="testBadge">{{ lang === 'id' ? 'Uji lencana' : 'Test badge' }}</button>
        </div>
      </section>

      <section class="card face rise" style="--i: 3">
        <div class="face-row">
          <span class="ico-box tinted tone-indigo"><Icon name="moon" :size="20" /></span>
          <div class="grow"><b>{{ t('prof.appearance') }}</b><p>{{ t('prof.appearanceText') }}</p></div>
        </div>
        <div class="seg" role="radiogroup" :aria-label="t('prof.appearance')">
          <button v-for="m in ['light', 'dark', 'system']" :key="m" role="radio" :aria-checked="theme === m" class="seg-b" :class="{ on: theme === m }" @click="setTheme(m)">
            <Icon :name="m === 'light' ? 'sun' : m === 'dark' ? 'moon' : 'phone'" :size="16" />{{ t('prof.theme.' + m) }}
          </button>
        </div>
      </section>

      <section class="card face rise" style="--i: 3">
        <div class="face-row">
          <span class="ico-box tinted tone-teal"><Icon name="lang" :size="20" /></span>
          <div class="grow"><b>{{ t('prof.language') }}</b><p>{{ t('prof.languageText') }}</p></div>
        </div>
        <div class="seg" role="radiogroup" :aria-label="t('prof.language')">
          <button role="radio" :aria-checked="lang === 'id'" class="seg-b" :class="{ on: lang === 'id' }" @click="setLang('id'); push.resync()">Bahasa Indonesia</button>
          <button role="radio" :aria-checked="lang === 'en'" class="seg-b" :class="{ on: lang === 'en' }" @click="setLang('en'); push.resync()">English</button>
        </div>
      </section>

      <section class="card face rise" style="--i: 3">
        <div class="face-row">
          <span class="ico-box tinted" :class="update.ready ? 'tone-rose' : 'tone-blue'"><Icon name="refresh" :size="20" /></span>
          <div class="grow"><b>{{ update.ready ? t('upd.title') : t('prof.updateTitle') }}</b><p>{{ update.ready ? t('upd.text') : t('prof.updateText') }}</p></div>
        </div>
        <div class="face-act">
          <button v-if="update.ready" class="btn btn-primary" @click="reloadApp">{{ t('prof.updateNow') }}</button>
          <button v-else class="btn btn-ghost" :disabled="checking" @click="cekPembaruan">{{ checking ? t('prof.checking') : t('prof.checkUpdate') }}</button>
        </div>
      </section>

      <section class="card list rise" style="--i: 3">
        <button class="row" :aria-expanded="info" @click="info = !info">
          <span class="ico-box tinted tone-blue"><Icon name="user2" :size="19" /></span><b>{{ t('prof.info') }}</b>
          <Icon name="chevron" :size="18" :style="{ transform: info ? 'rotate(90deg)' : 'none' }" />
        </button>
        <dl v-if="info" class="info">
          <div><dt>Username</dt><dd>{{ auth.user?.username }}</dd></div>
          <div><dt>Email</dt><dd>{{ auth.user?.email || '-' }}</dd></div>
          <div><dt>{{ t('prof.role') }}</dt><dd>{{ (auth.user?.role_name || '').replace(/^Role /, '') }}</dd></div>
        </dl>
        <div class="row static">
          <span class="ico-box tinted tone-teal"><Icon name="shield" :size="19" /></span><b>{{ t('prof.security') }}</b>
          <small>{{ t('prof.pwdWeb') }}</small>
        </div>
      </section>

      <button class="card out rise" style="--i: 4" :disabled="busy" @click="logout">
        <span class="ico-box tinted tone-rose"><Icon name="logout" :size="19" /></span><b>{{ busy ? t('home.loggingOut') : t('prof.logout') }}</b>
      </button>

      <p class="ver">{{ t('prof.version') }}</p>
    </div>

    <div v-if="sheet" class="sheet-back" @click.self="closeFace">
      <div class="sheet" role="dialog" aria-modal="true" :aria-label="sheet === 'enroll' ? t('prof.sheetEnroll') : t('prof.sheetRemove')">
        <div class="grab" />

        <!-- Daftarkan wajah -->
        <template v-if="sheet === 'enroll'">
          <template v-if="stage === 'scan'">
            <h2 class="sheet-t">{{ t('prof.sheetScan') }}</h2>
            <FaceScan :key="scanKey" :samples="5" @done="onScanned" @fail="onScanFail" />
            <button class="btn btn-ghost btn-block" style="margin-top:12px" @click="stage = 'form'">{{ t('common.cancel') }}</button>
          </template>
          <template v-else-if="stage === 'saving'">
            <h2 class="sheet-t">{{ t('prof.saving') }}</h2>
            <p class="muted">{{ t('prof.pleaseWait') }}</p>
          </template>
          <template v-else>
            <h2 class="sheet-t">{{ t('prof.sheetEnroll') }}</h2>
            <p class="muted" style="margin:6px 0 16px;line-height:1.5">
              {{ t('prof.privacy') }}
            </p>
            <div class="field">
              <label for="pw">{{ t('det.yourPwd') }}</label>
              <div class="input">
                <Icon name="lock" :size="20" />
                <input id="pw" v-model="pwd" :type="showPwd ? 'text' : 'password'" autocomplete="current-password" :placeholder="t('det.confirmPwd')" />
                <button type="button" class="eye" :aria-label="showPwd ? t('prof.hide') : t('prof.show')" @click="showPwd = !showPwd"><Icon :name="showPwd ? 'eyeoff' : 'eye'" :size="20" /></button>
              </div>
            </div>
            <label class="check">
              <input v-model="consent" type="checkbox" />
              <span>{{ t('prof.consent') }}</span>
            </label>
            <p v-if="faceError" class="err-text" role="alert" style="margin-bottom:12px">{{ faceError }}</p>
            <div class="sheet-act">
              <button class="btn btn-ghost" @click="closeFace">{{ t('common.cancel') }}</button>
              <button class="btn btn-primary" @click="toScan"><Icon name="face" :size="20" /> {{ t('prof.startScan') }}</button>
            </div>
          </template>
        </template>

        <!-- Hapus wajah -->
        <template v-else>
          <h2 class="sheet-t">{{ t('prof.removeTitle') }}</h2>
          <p class="muted" style="margin:6px 0 16px">{{ t('prof.removeText') }}</p>
          <div class="field">
            <label for="pw2">{{ t('det.yourPwd') }}</label>
            <div class="input">
              <Icon name="lock" :size="20" />
              <input id="pw2" v-model="pwd" type="password" autocomplete="current-password" :placeholder="t('det.confirmPwd')" />
            </div>
          </div>
          <p v-if="faceError" class="err-text" role="alert" style="margin-bottom:12px">{{ faceError }}</p>
          <div class="sheet-act">
            <button class="btn btn-ghost" :disabled="stage === 'saving'" @click="closeFace">{{ t('common.cancel') }}</button>
            <button class="btn btn-danger" :disabled="stage === 'saving'" @click="removeFace">{{ stage === 'saving' ? t('prof.removing') : t('prof.yesDelete') }}</button>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
.seg { display: flex; gap: 4px; padding: 4px; border-radius: 14px; background: var(--tabs-bg); }
.seg-b { flex: 1; min-height: 44px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 0 8px; border: 0; border-radius: 11px; background: transparent; color: var(--muted); font-size: 13.5px; font-weight: 600; transition: background 0.2s ease, color 0.2s ease, box-shadow 0.2s ease; }
.seg-b.on { background: var(--card); color: var(--accent); box-shadow: 0 2px 8px rgba(15, 31, 61, 0.14); }
.top { height: 120px; border-radius: 0 0 26px 26px; }
.body { padding: 0 16px calc(var(--nav-h) + var(--safe-bottom) + 24px); margin-top: -58px; position: relative; display: grid; gap: 12px; }
.who { text-align: center; padding-top: 0; }
.av-wrap { position: relative; width: 84px; height: 84px; margin: -42px auto 6px; }
.av { width: 100%; height: 100%; padding: 0; overflow: hidden; border-radius: 28px; background: var(--green-bg); color: var(--green); font-size: 26px; font-weight: 700; display: grid; place-items: center; border: 4px solid var(--card); box-shadow: 0 8px 18px -8px rgba(15, 31, 61, 0.4); }
.av:disabled { opacity: 0.6; }
.cam { position: absolute; right: -2px; bottom: -2px; width: 28px; height: 28px; border-radius: 50%; display: grid; place-items: center; background: var(--accent); color: #fff; border: 3px solid var(--card); pointer-events: none; }
.photo-act { display: flex; justify-content: center; align-items: center; gap: 2px; margin-bottom: 4px; color: var(--muted); }
.link-btn { min-height: 44px; padding: 0 12px; font-size: 13px; font-weight: 600; color: var(--accent); background: none; border: 0; cursor: pointer; }
.link-btn.bad { color: var(--red); }
.link-btn:disabled { opacity: 0.6; }
.who h1 { font-size: 18px; font-weight: 700; letter-spacing: -0.01em; }
.who p { color: var(--muted); font-size: 13px; margin: 2px 0 10px; }
.face { display: grid; gap: 12px; }
.face-row { display: flex; align-items: center; gap: 12px; }
.grow { flex: 1; min-width: 0; }
.grow p { color: var(--muted); font-size: 12.5px; margin: 2px 0; line-height: 1.4; }
.grow small.off { color: var(--amber); font-weight: 700; font-size: 12px; }
.grow small.on { color: var(--green); font-weight: 700; font-size: 12px; }
.face-act { display: flex; gap: 10px; }
.face-act .btn { flex: 1; min-height: 44px; font-size: 14px; }
.face-act .bad { color: var(--red); background: var(--red-bg); flex: 0 0 auto; }
.muted { color: var(--muted); font-size: 14px; }
.sheet-t { font-size: 19px; font-weight: 700; letter-spacing: -0.02em; }
.sheet-act { display: grid; grid-template-columns: 1fr 1.5fr; gap: 12px; }
.eye { border: 0; background: none; color: var(--muted); display: grid; place-items: center; min-width: 44px; min-height: 44px; margin-right: -12px; }
.check { display: flex; gap: 10px; align-items: flex-start; margin: 4px 0 14px; font-size: 13.5px; line-height: 1.45; color: var(--ink-2); }
.check input { width: 22px; height: 22px; margin-top: 1px; flex: none; accent-color: var(--blue-600); }
.list { padding: 2px 14px; }
.row { display: flex; align-items: center; gap: 12px; width: 100%; min-height: 54px; padding: 0; background: none; border: 0; border-bottom: 1px solid var(--line); text-align: left; color: var(--muted); }
.row:last-child { border-bottom: 0; }
.row b { flex: 1; color: var(--ink); font-size: 14.5px; font-weight: 600; }
.row small { color: var(--muted); font-size: 12px; max-width: 120px; text-align: right; }
.info { margin: 0; padding: 4px 4px 12px 54px; display: grid; gap: 8px; border-bottom: 1px solid var(--line); }
.info div { display: grid; grid-template-columns: 72px 1fr; font-size: 13px; }
.info dt { color: var(--muted); }
.info dd { margin: 0; font-weight: 700; overflow-wrap: anywhere; }
.out { display: flex; align-items: center; gap: 12px; width: 100%; text-align: left; min-height: 54px; }
.out b { color: var(--red); font-size: 14.5px; font-weight: 600; }
.ico-box.bad { background: var(--red-bg); color: var(--red); }
.ver { text-align: center; color: var(--muted); font-size: 12px; }
</style>
