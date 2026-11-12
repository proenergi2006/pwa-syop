<script setup>
import { t, ts } from '../i18n'
import { defineAsyncComponent, ref } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import { useAuth, lastUsername, faceHint } from '../stores/auth'
import { errorCode, errorMessage } from '../api/client'

// Kamera + model wajah dimuat malas: tidak membebani login dengan kata sandi.
const FaceScan = defineAsyncComponent(() => import('../components/FaceScan.vue'))

const router = useRouter()
const auth = useAuth()

const mode = ref(faceHint() && lastUsername() ? 'face' : 'password') // 'face' | 'password'
const step = ref('username') // face: 'username' -> 'scan'
const username = ref(lastUsername())
const password = ref('')
const show = ref(false)
const loading = ref(false)
const error = ref('')
const scanKey = ref(0)

const go = () => router.replace({ name: 'home' })

async function submitPassword() {
  if (loading.value) return
  error.value = ''
  if (!username.value.trim() || !password.value) {
    error.value = t('login.errRequired')
    return
  }
  loading.value = true
  try {
    await auth.login(username.value, password.value)
    go()
  } catch (e) {
    error.value = errorMessage(e)
    password.value = ''
  } finally {
    loading.value = false
  }
}

function startFace() {
  error.value = ''
  if (!username.value.trim()) { error.value = t('login.errUsername'); return }
  scanKey.value++
  step.value = 'scan'
}

async function onFace(descriptor) {
  loading.value = true
  try {
    await auth.loginFace(username.value, descriptor)
    go()
  } catch (e) {
    error.value = errorMessage(e)
    step.value = 'failed'
    if (errorCode(e) === 'FACE_LOCKED') step.value = 'locked'
  } finally {
    loading.value = false
  }
}

function onFaceFail(msg) { error.value = msg; step.value = 'failed' }

function toPassword() { mode.value = 'password'; step.value = 'username'; error.value = '' }
function toFace() { mode.value = 'face'; step.value = 'username'; error.value = '' }
</script>

<template>
  <main class="login hero">
    <span class="orb" style="width:220px;height:220px;right:-60px;top:80px;background:#2a4dd0;opacity:.45" />
    <span class="orb" style="width:150px;height:150px;right:-30px;top:-30px;background:#e50e30;opacity:.5;animation-delay:-2s" />
    <span class="orb" style="width:180px;height:180px;left:-60px;top:340px;background:#f08007;opacity:.55;animation-delay:-4s" />
    <header class="brand">
      <img src="/icons/logo.png" alt="" width="52" height="52" />
      <div>
        <div class="brand-name">ORCA X <span>MOBILE</span></div>
        <div class="brand-sub">PROENERGI OPERATIONS</div>
      </div>
    </header>

    <section class="intro" v-show="step !== 'scan'">
      <div class="eyebrow">PROENERGI · OPERATIONAL PLATFORM</div>
      <h1>{{ t('login.h1a') }}<br />{{ t('login.h1b') }}</h1>
    </section>

    <section class="sheet-card" aria-labelledby="login-h">
      <!-- ===== Mode wajah ===== -->
      <template v-if="mode === 'face'">
        <h2 id="login-h">{{ step === 'scan' ? t('login.scanTitle') : t('login.faceTitle') }}</h2>

        <template v-if="step === 'username'">
          <p class="sub">{{ t('login.faceSub') }}</p>
          <form @submit.prevent="startFace" novalidate>
            <div class="field">
              <label for="fu">{{ t('login.username') }}</label>
              <div class="input">
                <Icon name="user" :size="20" />
                <input id="fu" v-model="username" type="text" autocomplete="username" autocapitalize="none"
                       autocorrect="off" spellcheck="false" placeholder="Username" />
              </div>
            </div>
            <p v-if="error" class="err-text" role="alert">{{ error }}</p>
            <button class="btn btn-primary btn-block" type="submit"><Icon name="face" :size="20" /> {{ t('login.faceNext') }}</button>
          </form>
        </template>

        <template v-else-if="step === 'scan'">
          <p class="sub">{{ t('login.forUser', { name: '' }) }}<b>{{ username }}</b></p>
          <FaceScan :key="scanKey" @done="onFace" @fail="onFaceFail" />
          <p v-if="loading" class="note">{{ t('login.verifying') }}</p>
        </template>

        <template v-else>
          <div class="state-fail" role="alert">
            <Icon name="alert" :size="28" />
            <p>{{ error }}</p>
          </div>
          <button v-if="step === 'failed'" class="btn btn-primary btn-block" @click="startFace"><Icon name="refresh" :size="20" /> {{ t('login.tryAgain') }}</button>
        </template>

        <button class="btn btn-ghost btn-block alt" type="button" @click="toPassword">
          <Icon name="lock" :size="20" /> {{ t('login.withPassword') }}
        </button>
      </template>

      <!-- ===== Mode kata sandi ===== -->
      <template v-else>
        <h2 id="login-h">{{ t('login.welcome') }}</h2>
        <p class="sub">{{ t('login.withAccount') }}</p>

        <form @submit.prevent="submitPassword" novalidate>
          <div class="field">
            <label for="u">{{ t('login.username') }}</label>
            <div class="input">
              <Icon name="user" :size="20" />
              <input id="u" v-model="username" type="text" autocomplete="username" autocapitalize="none"
                     autocorrect="off" spellcheck="false" placeholder="Username" inputmode="text" />
            </div>
          </div>
          <div class="field">
            <label for="p">{{ t('login.password') }}</label>
            <div class="input">
              <Icon name="lock" :size="20" />
              <input id="p" v-model="password" :type="show ? 'text' : 'password'" autocomplete="current-password" :placeholder="t('login.password')" />
              <button type="button" class="eye" :aria-label="show ? t('login.hidePwd') : t('login.showPwd')" @click="show = !show">
                <Icon :name="show ? 'eyeoff' : 'eye'" :size="20" />
              </button>
            </div>
          </div>

          <p v-if="error" class="err-text" role="alert">{{ error }}</p>

          <button class="btn btn-primary btn-block" :disabled="loading" type="submit">
            {{ loading ? t('login.submitting') : t('login.submit') }}
          </button>
        </form>

        <button class="btn btn-ghost btn-block alt" type="button" @click="toFace">
          <Icon name="face" :size="20" /> {{ t('login.withFace') }}
        </button>
        <p class="note">{{ t('login.noFaceNote') }}</p>
        <p class="note">{{ t('login.forgot') }}</p>
      </template>
    </section>
  </main>
</template>

<style scoped>
.login::before { content: ''; position: absolute; inset: 0; pointer-events: none; background: url('../assets/login-art.svg') center top / cover no-repeat; }
.login > :not(.orb) { position: relative; }
.login { position: relative; overflow: hidden; min-height: 100dvh; padding: calc(var(--safe-top) + 22px) 18px calc(var(--safe-bottom) + 18px); display: flex; flex-direction: column; }
.brand { position: relative; display: flex; align-items: center; gap: 14px; animation: rise 0.6s both; }
.brand img { width: 56px; height: 56px; border-radius: 16px; box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3); }
.brand-name { font-weight: 700; font-size: 17px; letter-spacing: 0.02em; }
.brand-name span { color: #ffa94d; }
.brand-sub { font-size: 12px; letter-spacing: 0.2em; font-weight: 600; color: #c4d4ee; margin-top: 4px; }
.intro { position: relative; margin: 32px 0 22px; animation: rise 0.6s 0.1s both; }
.eyebrow { font-size: 12px; font-weight: 700; letter-spacing: 0.16em; color: #ffb36b; margin-bottom: 10px; }
h1 { font-size: 27px; line-height: 1.15; font-weight: 700; letter-spacing: -0.03em; }
.sheet-card {
  /* Kartu kaca: transparan dengan blur, teks putih. Token diganti di sini supaya semua isi kartu ikut. */
  --ink: #fff; --ink-2: rgba(255, 255, 255, 0.88); --muted: rgba(255, 255, 255, 0.78);
  --surface: rgba(255, 255, 255, 0.12); --line: rgba(255, 255, 255, 0.28); --red: #ffb4ab; --accent: #fff; --blue-50: rgba(255, 255, 255, 0.14);
  position: relative; animation: rise 0.6s 0.2s both; margin-top: auto; color: #fff; border-radius: 24px; padding: 20px 18px 18px;
  background: linear-gradient(160deg, rgba(255, 255, 255, 0.16), rgba(8, 20, 70, 0.42));
  -webkit-backdrop-filter: blur(20px) saturate(1.25); backdrop-filter: blur(20px) saturate(1.25);
  border: 1px solid rgba(255, 255, 255, 0.26);
  box-shadow: 0 24px 60px rgba(0, 0, 0, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.3);
}
.sheet-card :deep(.input input::placeholder) { color: rgba(255, 255, 255, 0.62); }
.sheet-card :deep(.input input:-webkit-autofill) { -webkit-text-fill-color: #fff; transition: background-color 9999s; box-shadow: inset 0 0 0 40px rgba(20, 40, 110, 0.6); caret-color: #fff; }
.sheet-card :deep(.btn-primary) { color: var(--navy-800); background: #fff; box-shadow: 0 8px 20px -8px rgba(0, 0, 0, 0.5); }
.sheet-card :deep(.btn-ghost) { color: #fff; background: rgba(255, 255, 255, 0.14); border: 1px solid rgba(255, 255, 255, 0.22); }
.sheet-card h2 { font-size: 20px; font-weight: 700; letter-spacing: -0.02em; }
.sub { color: var(--muted); font-size: 13.5px; margin: 2px 0 16px; }
.eye { border: 0; background: none; color: var(--muted); display: grid; place-items: center; min-width: 44px; min-height: 44px; margin-right: -12px; }
.err-text { margin: -2px 0 12px; }
.alt { margin-top: 10px; font-size: 14px; }
.note { text-align: center; color: var(--muted); font-size: 12px; margin-top: 10px; line-height: 1.45; }
.state-fail { display: grid; justify-items: center; gap: 8px; text-align: center; color: var(--red); font-weight: 700; margin: 6px 0 18px; line-height: 1.45; }
</style>
