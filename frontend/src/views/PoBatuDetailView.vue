<script setup>
import { t } from '../i18n'
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import StateBox from '../components/StateBox.vue'
import { useAuth } from '../stores/auth'
import { useInbox } from '../stores/inbox'
import * as syop from '../api/syop'
import { errorCode, errorMessage } from '../api/client'
import { showToast } from '../composables/useToast'
import { angka, tanggal, tanggalJam } from '../utils/format'

const props = defineProps({ id: String })
const router = useRouter()
const auth = useAuth()
const inbox = useInbox()
const d = ref(null)
const loading = ref(true)
const error = ref('')
const sheet = ref(null) // 'approve' | 'return'
const catatan = ref('')
const pwd = ref('')
const busy = ref(false)
const formError = ref('')
const needPassword = computed(() => false) // tidak ada konfirmasi password di modul ini (hanya di Profil untuk wajah)

async function load() {
  loading.value = true
  error.value = ''
  try { d.value = await syop.getPoBatu(props.id) } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
}
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'po-batu' }))
const open = (m) => { sheet.value = m; catatan.value = ''; pwd.value = ''; formError.value = '' }
// Catatan dari web tersimpan sebagai teks ber-<br />; tampilkan sebagai baris baru, tanpa HTML.
const plain = (s) => (s ? String(s).replace(/<br\s*\/?>/gi, '\n').replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&') : '')

// Baris rincian: [kunci, label, tipe]
const rows = computed(() => (d.value ? [['harga_tebus', 'Harga Dasar', 'rp'], ['subtotal', 'Subtotal', 'rp'], ['ppn_11', 'PPN 11%', 'rp']] : []))
const fmt = (v, tp) => (tp === 'rp' ? 'Rp ' + angka(v, Number(v) % 1 ? 2 : 0) : v)

async function submit() {
  formError.value = ''
  if (needPassword.value && !pwd.value) return (formError.value = t('det.errPwd'))
  busy.value = true
  try {
    const payload = { keputusan: sheet.value, catatan: catatan.value.trim() || undefined }
    if (needPassword.value) payload.password = pwd.value
    const r = await syop.decidePoBatu(props.id, payload)
    sheet.value = null
    showToast(r.message, 'ok')
    inbox.refresh()
    await load()
  } catch (e) {
    if (errorCode(e) === 'STEP_UP_REQUIRED') auth.method = 'face'
    formError.value = errorMessage(e)
  } finally { busy.value = false }
}
onMounted(load)
</script>

<template>
  <div>
    <header class="bar">
      <button class="back" :aria-label="t('common.back')" @click="back"><Icon name="back" :size="20" /></button>
      <div class="bar-t"><b>{{ t('pob.detail') }}</b><small v-if="d">{{ d.nomor_po }}</small></div>
      <button class="back" :aria-label="t('det.refresh')" @click="load"><Icon name="refresh" :size="18" /></button>
    </header>

    <div class="wrap">
      <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load" />
      <div v-else-if="loading" class="stack"><div class="skel" style="height:150px" /><div class="skel" style="height:240px" /></div>
      <template v-else-if="d">
        <section class="card box">
          <h1>{{ d.nama_vendor }}</h1>
          <p>{{ d.nomor_po }} · {{ d.jenis_produk }} - {{ d.merk_dagang }}</p>
          <div class="big">Rp {{ angka(d.total_order) }}</div>
          <div class="kv"><span>{{ t('po.f.tanggal') }}</span><span>{{ tanggal(d.tanggal_inven) }}</span></div>
          <div class="kv"><span>Terminal</span><span>{{ d.nama_terminal }}<template v-if="d.tanki_terminal"> - {{ d.tanki_terminal }}</template></span></div>
          <div class="kv"><span>{{ t('po.f.volume') }}</span><span>{{ angka(d.volume_po) }} m³</span></div>
          <div v-if="d.volume_bol > 0" class="kv"><span>Volume BL</span><span>{{ angka(d.volume_bol) }} m³</span></div>
          <div v-if="d.volume_ri > 0" class="kv"><span>Volume RI</span><span>{{ angka(d.volume_ri) }} m³</span></div>
          <div v-if="d.gain_loss !== null" class="kv"><span>Gain / Loss</span><span :class="d.gain_loss < 0 ? 'neg' : 'pos'">{{ angka(d.gain_loss) }} m³</span></div>
          <div v-if="d.is_close == 1" class="kv"><span>Close PO</span><span class="neg">{{ angka(d.volume_close) }} m³</span></div>
          <div v-if="d.harga_po > 0" class="kv"><span>{{ t('po.f.hargaPo') }}</span><span>Rp {{ angka(d.harga_po, 2) }}</span></div>
        </section>

        <button class="btn btn-ghost btn-block" @click="router.push({ name: 'po-batu-cetak', params: { id: props.id } })"><Icon name="doc" :size="18" /> Cetak PO</button>

        <section class="card box">
          <h2>{{ t('po.sec.cost') }}</h2>
          <div v-for="[k, label, tp] in rows" :key="k" class="kv">
            <span>{{ label }}</span>
            <span>{{ fmt(d[k], tp) }}</span>
          </div>
          <div class="kv tot"><span>Total order</span><span>Rp {{ angka(d.total_order) }}</span></div>
        </section>

        <section class="card box">
          <h2>{{ t('po.sec.terms') }}</h2>
          <div class="kv"><span>{{ t('po.f.tax') }}</span><span>{{ d.kd_tax || '-' }}</span></div>
          <div class="kv"><span>Terms</span><span>{{ d.terms || '-' }}<template v-if="d.terms === 'NET'"> ({{ d.terms_day }} {{ t('ship.days') }})</template></span></div>
          <p v-if="d.keterangan" class="note"><b>{{ t('po.f.note') }}:</b> {{ plain(d.keterangan) }}</p>
          <p v-if="d.description" class="note"><b>Deskripsi:</b> {{ plain(d.description) }}</p>
        </section>

        <section class="card box">
          <h2>{{ t('ship.sec.flow') }}</h2>
          <div class="step" :class="d.cfo_result == 1 && d.revert_cfo != 1 ? 'ok' : d.revert_cfo == 1 ? 'no' : ''"><Icon :name="d.cfo_result == 1 && d.revert_cfo != 1 ? 'check' : d.revert_cfo == 1 ? 'x' : 'clock'" :size="16" /><div><b>CFO</b><small>{{ d.cfo_pic || t('ship.waiting') }}<template v-if="d.cfo_tanggal"> · {{ tanggalJam(d.cfo_tanggal) }}</template></small><small v-if="d.cfo_summary">{{ plain(d.cfo_summary) }}</small><small v-if="d.revert_cfo == 1">{{ t('po.returned') }}: {{ plain(d.revert_cfo_summary) }}</small></div></div>
          <div class="step" :class="d.disposisi_po == 4 ? 'ok' : d.revert_ceo == 1 ? 'no' : ''"><Icon :name="d.disposisi_po == 4 ? 'check' : d.revert_ceo == 1 ? 'x' : 'clock'" :size="16" /><div><b>CEO</b><small>{{ d.ceo_result == 1 ? d.ceo_pic : t('ship.waiting') }}<template v-if="d.ceo_result == 1 && d.ceo_tanggal"> · {{ tanggalJam(d.ceo_tanggal) }}</template></small><small v-if="d.ceo_summary">{{ plain(d.ceo_summary) }}</small><small v-if="d.revert_ceo == 1">{{ t('po.returned') }}: {{ plain(d.revert_ceo_summary) }}</small></div></div>
        </section>

      </template>
    </div>

    <footer v-if="d?.can_decide" class="actions">
      <button class="btn btn-ghost" @click="open('return')"><Icon name="refresh" :size="18" /> {{ t('po.return') }}</button>
      <button class="btn btn-primary" @click="open('approve')"><Icon name="check" :size="18" /> {{ t('det.approve') }}</button>
    </footer>

    <div v-if="sheet" class="sheet-back" @click.self="!busy && (sheet = null)">
      <div class="sheet" role="dialog" aria-modal="true">
        <div class="grab" />
        <h2 class="sheet-t">{{ sheet === 'approve' ? t('po.q.approve') : t('po.q.return') }}</h2>
        <p class="muted" style="margin:4px 0 16px">{{ d.nomor_po }} · {{ d.nama_vendor }}</p>
        <div class="field">
          <label for="pc">{{ sheet === 'return' ? 'Catatan dikembalikan (opsional)' : t('det.noteOpt') }}</label>
          <textarea id="pc" v-model="catatan" class="plain" maxlength="2000" :placeholder="t('det.notePh')" />
        </div>
        <div v-if="needPassword" class="field">
          <label for="sp">{{ t('det.yourPwd') }}</label>
          <div class="input"><Icon name="lock" :size="20" /><input id="sp" v-model="pwd" type="password" autocomplete="current-password" :placeholder="t('det.confirmPwd')" /></div>
          <small class="muted">{{ t('det.faceNote') }}</small>
        </div>
        <p v-if="formError" class="err-text" role="alert" style="margin-bottom:12px">{{ formError }}</p>
        <div class="sheet-act">
          <button class="btn btn-ghost" :disabled="busy" @click="sheet = null">{{ t('common.cancel') }}</button>
          <button class="btn btn-primary" :disabled="busy" @click="submit">{{ busy ? t('det.processing') : sheet === 'approve' ? t('det.yesApprove') : t('po.yesReturn') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.bar { position: sticky; top: 0; z-index: 10; display: flex; align-items: center; gap: 8px; padding: calc(var(--safe-top) + 8px) 12px 8px; background: var(--glass-bar); backdrop-filter: blur(12px); border-bottom: 1px solid var(--line); }
.bar-t { flex: 1; min-width: 0; text-align: center; display: grid; }
.bar-t b { font-size: 14.5px; font-weight: 700; }
.bar-t small { font-size: 12px; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.back { width: 44px; height: 44px; border-radius: 13px; border: 1px solid var(--line); background: var(--card); color: var(--ink); display: grid; place-items: center; }
.wrap { padding: 12px 16px calc(var(--safe-bottom) + 100px); display: grid; gap: 12px; }
.box { padding: 14px 16px; }
.box h1 { font-size: 17px; font-weight: 700; letter-spacing: -0.02em; margin: 0; }
.box h2 { font-size: 14px; font-weight: 700; margin: 0 0 6px; }
.box p { color: var(--muted); font-size: 13px; margin: 2px 0 8px; }
.big { font-size: 26px; font-weight: 800; letter-spacing: -0.03em; color: var(--accent); margin-bottom: 6px; }
.kv span:last-child { text-align: right; font-weight: 600; overflow-wrap: anywhere; }
.kv.tot span { font-weight: 800; color: var(--ink); }
.pos { color: #14a36b; } .neg { color: var(--red); }
.was { color: var(--muted); font-weight: 500; }
.now { color: var(--red); }
.chg { display: flex; align-items: center; gap: 8px; padding: 10px 12px; border-radius: 12px; background: var(--amber-bg); color: var(--late); font-size: 13px; font-weight: 600; }
.step { display: flex; gap: 10px; padding: 8px 0; color: var(--muted); }
.step > svg { flex: none; margin-top: 2px; }
.step div { display: grid; gap: 1px; font-size: 13px; }
.step b { color: var(--ink); }
.step small { color: var(--muted); font-size: 12px; overflow-wrap: anywhere; white-space: pre-line; }
.step.ok > svg { color: #14a36b; } .step.no > svg { color: var(--red); }
.note { padding: 8px 10px; border-radius: 10px; background: var(--blue-50); white-space: pre-line; }
.actions { position: fixed; left: 50%; transform: translateX(-50%); bottom: 0; width: min(480px, 100%); display: grid; grid-template-columns: 1fr 1.4fr; gap: 10px; padding: 10px 16px calc(var(--safe-bottom) + 10px); background: var(--glass); backdrop-filter: blur(14px); border-top: 1px solid var(--line); z-index: 15; }
</style>
