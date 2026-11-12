<script setup>
import { t } from '../i18n'
import { ref, onMounted, onBeforeUnmount } from 'vue'
import Icon from './Icon.vue'
import { loadFace, analyse, describe, averageDescriptor, FACE_MOCK } from '../utils/face'

/**
 * Pindai wajah dengan cek "hidup": sistem meminta kedipkan mata ATAU menoleh (acak), lalu mengambil
 * embedding saat wajah menghadap lurus. Foto/layar diam tidak bisa menjawab perintah acak.
 * Emit `done(descriptor)` (128 angka) atau `fail(pesan)`. Tidak ada foto yang disimpan atau dikirim;
 * hanya 128 angka yang dikirim ke server.
 *
 * Catatan keamanan: pengecekan hidup ini berjalan di perangkat, jadi bukan jaminan mutlak.
 * Karena itu server membatasi sesi face login (menyetujui penawaran tetap minta password).
 */
const props = defineProps({ samples: { type: Number, default: 1 } })
const emit = defineEmits(['done', 'fail'])

const video = ref(null)
const msg = ref('Menyiapkan kamera…'), tone = ref('info'), pct = ref(0), phase = ref('load')
let stream = null, stopped = false, faceapi = null

function stop() { stopped = true; stream?.getTracks().forEach((t) => t.stop()); stream = null }
onBeforeUnmount(stop)
const say = (m, t = 'info') => { msg.value = m; tone.value = t }
const wait = (ms) => new Promise((r) => setTimeout(r, ms))

async function run() {
  if (FACE_MOCK) { phase.value = 'find'; say(t('face.mock')); return }
  if (!navigator.mediaDevices?.getUserMedia) {
    return emit('fail', 'Kamera tidak tersedia. Face Recognition memerlukan koneksi aman (HTTPS).')
  }
  try {
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 640 } }, audio: false })
  } catch (e) {
    return emit('fail', e?.name === 'NotAllowedError' ? t('face.denied') : t('face.noCam'))
  }
  video.value.srcObject = stream
  await video.value.play().catch(() => {})
  say('Memuat pengenal wajah…')
  try { faceapi = await loadFace() } catch { stop(); return emit('fail', 'Gagal memuat pengenal wajah. Periksa koneksi lalu coba lagi.') }
  if (stopped) return
  loop()
}

async function loop() {
  phase.value = 'find'
  const challenge = Math.random() < 0.5 ? 'blink' : 'turn' // acak agar rekaman tidak bisa diputar ulang
  let step = 'center', sawOpen = false, t0 = performance.now(), held = [], lastSeen = 0, maxEar = 0
  say(t('face.position'))
  while (!stopped) {
    const r = await analyse(faceapi, video.value).catch(() => ({ n: 0 }))
    if (stopped) return
    // Saat mata terpejam atau kepala menoleh, detektor sering kehilangan wajah sesaat: bagian dari tantangan.
    if (r.n === 0 && (step === 'challenge' || step === 'back') && performance.now() - lastSeen < 1200) {
      if (step === 'challenge' && challenge === 'blink' && sawOpen) { step = 'back'; say(t('face.good'), 'ok') }
      await wait(100); continue
    }
    if (r.n === 0) { say('Wajah tidak terdeteksi. Cari tempat lebih terang.', 'warn'); step = 'center'; held = []; pct.value = 0; phase.value = 'find'; await wait(150); continue }
    if (r.n === 1) lastSeen = performance.now()
    if (r.n > 1) { say('Hanya satu wajah yang boleh terlihat.', 'warn'); await wait(150); continue }
    if (r.box.width < r.vw * 0.28) { say(t('face.closer'), 'warn'); await wait(150); continue }
    const frontal = r.yaw > 0.4 && r.yaw < 0.6 && r.ear > 0.2

    if (step === 'center') {
      if (!frontal) { say(t('face.straight')); await wait(120); continue }
      step = 'challenge'; t0 = performance.now(); sawOpen = false; maxEar = r.ear; phase.value = 'challenge'
    }
    if (step === 'challenge') {
      say(challenge === 'blink' ? t('face.blink') : t('face.turn'), 'act')
      if (challenge === 'blink') {
        if (r.ear > 0.25) sawOpen = true
        maxEar = Math.max(maxEar, r.ear)
        if (sawOpen && (r.ear < 0.19 || r.ear < maxEar * 0.7)) { step = 'back'; say(t('face.good'), 'ok') }
      } else if (r.yaw < 0.33 || r.yaw > 0.67) { step = 'back'; say(t('face.good'), 'ok') }
      if (step === 'challenge' && performance.now() - t0 > 9000) { say(t('face.timeout'), 'warn'); return loop() }
    } else if (step === 'back') {
      if (frontal && r.ear > 0.25) { step = 'hold'; phase.value = 'hold' }
    }
    if (step === 'hold') {
      const d = await describe(faceapi, video.value).catch(() => null)
      if (stopped) return
      if (d && frontal) { held.push(d); pct.value = Math.round((held.length / props.samples) * 100); say('Tahan sebentar…', 'ok') }
      else { held = []; pct.value = 0; step = 'back' }
      if (held.length >= props.samples) {
        phase.value = 'ok'; say(t('face.verified'), 'ok'); stop()
        return emit('done', averageDescriptor(held))
      }
      await wait(250); continue
    }
    await wait(90)
  }
}

onMounted(run)
// Hanya build uji (VITE_FACE_MOCK=1): lewati kamera dan ML, kirim vektor yang disuntikkan test.
function simulate() { emit('done', window.__FACE_DESC__ || Array.from({ length: 128 }, (_, i) => 0.1 + (i % 7) * 0.01)) }
</script>

<template>
  <div class="fscan" :class="[phase, tone]">
    <div class="fring">
      <video v-show="!FACE_MOCK" ref="video" playsinline muted autoplay class="fvideo" :aria-label="t('face.preview')" />
      <div v-if="FACE_MOCK" class="fmock"><Icon name="user" :size="56" /></div>
      <svg class="fprog" viewBox="0 0 100 100" aria-hidden="true"><circle cx="50" cy="50" r="48" pathLength="100" :stroke-dasharray="`${pct} 100`" /></svg>
    </div>
    <p class="fmsg" role="status" aria-live="polite">{{ msg }}</p>
    <button v-if="FACE_MOCK" type="button" class="btn btn-ghost" data-test="face-sim" @click="simulate">{{ t('face.simulate') }}</button>
  </div>
</template>

<style scoped>
.fscan { display: grid; place-items: center; gap: 14px; padding: 6px 0 4px; }
.fring { position: relative; width: 230px; height: 230px; border-radius: 50%; overflow: hidden; background: var(--navy-900); box-shadow: 0 0 0 5px rgba(43, 140, 255, 0.28); }
.fscan.challenge .fring { box-shadow: 0 0 0 5px var(--blue-500), 0 0 28px rgba(43, 140, 255, 0.55); animation: fpulse 1s ease-in-out infinite; }
.fscan.ok .fring { box-shadow: 0 0 0 5px #20c07c; }
.fvideo { width: 100%; height: 100%; object-fit: cover; transform: scaleX(-1); }
.fmock { width: 100%; height: 100%; display: grid; place-items: center; color: #fff; }
.fprog { position: absolute; inset: 0; transform: rotate(-90deg); pointer-events: none; }
.fprog circle { fill: none; stroke: #20c07c; stroke-width: 3; transition: stroke-dasharray 0.25s; }
.fmsg { margin: 0; font-weight: 800; text-align: center; min-height: 1.5em; color: var(--ink-2); }
.fscan.warn .fmsg { color: var(--red); }
.fscan.ok .fmsg { color: var(--green); }
.fscan.act .fmsg { color: var(--accent); }
@keyframes fpulse { 50% { transform: scale(1.025); } }
</style>
