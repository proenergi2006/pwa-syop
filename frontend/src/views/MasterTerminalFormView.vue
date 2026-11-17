<script setup>
import { t } from '../i18n'
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '../components/Icon.vue'
import StateBox from '../components/StateBox.vue'
import { useAuth } from '../stores/auth'
import * as syop from '../api/syop'
import { errorCode, errorMessage } from '../api/client'
import { showToast } from '../composables/useToast'

const route = useRoute()
const router = useRouter()
const auth = useAuth()
const id = route.query.id
const editing = !!id
const f = reactive({ nama: '', tanki: '', initial: '', id_cabang: '', kategori: '', batas_atas: 0, batas_bawah: 0, lokasi: '', telp: '', alamat: '', fax: '', pic: '', latitude: '', longitude: '', catatan: '', aktif: true })
const attention = ref([])
const docs = ref([])      // dokumen tersimpan
const removed = ref([])   // id dokumen yang akan dihapus
const newDocs = ref([])
const opt = ref({ cabang: [], kategori: [] })
const meta = ref(null)
const pwd = ref('')
const loading = ref(true)
const error = ref('')
const busy = ref(false)
const formError = ref('')
const submitted = ref(false)
const needPassword = computed(() => auth.method === 'face')
const REQ = ['nama', 'tanki', 'initial', 'id_cabang', 'kategori', 'lokasi', 'telp', 'alamat', 'fax', 'pic', 'latitude', 'longitude']
const bad = (k) => submitted.value && !String(f[k] ?? '').trim()

onMounted(async () => {
  try {
    opt.value = await syop.terminalOptions()
    if (editing) {
      const d = await syop.getTerminal(id)
      for (const k of Object.keys(f)) f[k] = d[k] ?? f[k]
      f.id_cabang = d.id_cabang ? String(d.id_cabang) : ''
      f.kategori = d.kategori ? String(d.kategori) : ''
      attention.value = d.attention
      docs.value = d.dokumen
      meta.value = d
    }
  } catch (e) { error.value = errorMessage(e) } finally { loading.value = false }
})
const back = () => (window.history.length > 1 ? router.back() : router.replace({ name: 'master-terminal' }))
const addAtt = () => attention.value.push({ nama: '', posisi: '', hp: '', email: '' })
const addDoc = () => newDocs.value.push({ dokumen: '', masa_berlaku: '' })
const removeDoc = (d) => { removed.value.push(d.id); docs.value = docs.value.filter((x) => x.id !== d.id) }

async function save() {
  submitted.value = true
  formError.value = ''
  if (REQ.some((k) => !String(f[k] ?? '').trim()) || newDocs.value.some((d) => d.dokumen.trim() === '' && (d.masa_berlaku || false))) return (formError.value = t('mst.errReq'))
  if (needPassword.value && !pwd.value) return (formError.value = t('det.errPwd'))
  busy.value = true
  try {
    const p = {
      ...f, id_cabang: Number(f.id_cabang), kategori: Number(f.kategori), batas_atas: Number(f.batas_atas) || 0, batas_bawah: Number(f.batas_bawah) || 0,
      attention: attention.value, dokumen_baru: newDocs.value.filter((d) => d.dokumen.trim()).map((d) => ({ dokumen: d.dokumen.trim(), masa_berlaku: d.masa_berlaku || null })), hapus_dokumen: removed.value,
    }
    if (needPassword.value) p.password = pwd.value
    if (editing) await syop.updateTerminal(id, p)
    else await syop.createTerminal(p)
    showToast(t('mst.saved'), 'ok')
    router.replace({ name: 'master-terminal' })
  } catch (e) {
    if (errorCode(e) === 'STEP_UP_REQUIRED') auth.method = 'face'
    formError.value = errorMessage(e)
  } finally { busy.value = false }
}
</script>

<template>
  <div>
    <header class="bar">
      <button class="back" :aria-label="t('common.back')" @click="back"><Icon name="back" :size="20" /></button>
      <div class="bar-t"><b>{{ editing ? t('mst.t.edit') : t('mst.t.add') }}</b></div>
      <span class="back" style="visibility:hidden" />
    </header>
    <div class="wrap">
      <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" />
      <div v-else-if="loading" class="skel" style="height:320px" />
      <form v-else class="card box" novalidate @submit.prevent="save">
        <div class="field"><label for="tn">{{ t('mst.f.nama') }} *</label><input id="tn" v-model="f.nama" class="sel" :class="{ bad: bad('nama') }" maxlength="80" autocomplete="off" /></div>
        <div class="two">
          <div class="field"><label for="tk">{{ t('mst.f.tanki') }} *</label><input id="tk" v-model="f.tanki" class="sel" :class="{ bad: bad('tanki') }" maxlength="50" autocomplete="off" /></div>
          <div class="field"><label for="ti">{{ t('mst.f.initial') }} *</label><input id="ti" v-model="f.initial" class="sel" :class="{ bad: bad('initial') }" maxlength="20" autocomplete="off" /></div>
        </div>
        <div class="field"><label for="tc">{{ t('mst.f.cabang') }} *</label>
          <select id="tc" v-model="f.id_cabang" class="sel" :class="{ bad: bad('id_cabang') }"><option value="">{{ t('mst.pick') }}</option><option v-for="c in opt.cabang" :key="c.id" :value="String(c.id)">{{ c.nama }}</option></select></div>
        <div class="field"><label for="tg">{{ t('mst.f.kategori') }} *</label>
          <select id="tg" v-model="f.kategori" class="sel" :class="{ bad: bad('kategori') }"><option value="">{{ t('mst.pick') }}</option><option v-for="k in opt.kategori" :key="k.id" :value="String(k.id)">{{ k.nama }}</option></select></div>
        <div class="two">
          <div class="field"><label for="ta">{{ t('mst.f.atas') }}</label><input id="ta" v-model.number="f.batas_atas" class="sel" type="number" inputmode="numeric" min="0" /></div>
          <div class="field"><label for="tb">{{ t('mst.f.bawah') }}</label><input id="tb" v-model.number="f.batas_bawah" class="sel" type="number" inputmode="numeric" min="0" /></div>
        </div>
        <div class="field"><label for="tl">{{ t('mst.f.lokasi') }} *</label><input id="tl" v-model="f.lokasi" class="sel" :class="{ bad: bad('lokasi') }" autocomplete="off" /></div>
        <div class="field"><label for="tal">{{ t('mst.f.alamat') }} *</label><input id="tal" v-model="f.alamat" class="sel" :class="{ bad: bad('alamat') }" maxlength="255" autocomplete="off" /></div>
        <div class="two">
          <div class="field"><label for="tt">{{ t('mst.f.telp') }} *</label><input id="tt" v-model="f.telp" class="sel" :class="{ bad: bad('telp') }" maxlength="20" inputmode="tel" /></div>
          <div class="field"><label for="tf">{{ t('mst.f.fax') }} *</label><input id="tf" v-model="f.fax" class="sel" :class="{ bad: bad('fax') }" maxlength="20" inputmode="tel" /></div>
        </div>
        <div class="field"><label for="tp">{{ t('mst.f.pic') }} *</label><input id="tp" v-model="f.pic" class="sel" :class="{ bad: bad('pic') }" maxlength="20" autocomplete="off" /></div>
        <div class="two">
          <div class="field"><label for="tla">{{ t('mst.f.lat') }} *</label><input id="tla" v-model="f.latitude" class="sel" :class="{ bad: bad('latitude') }" inputmode="decimal" /></div>
          <div class="field"><label for="tlo">{{ t('mst.f.lng') }} *</label><input id="tlo" v-model="f.longitude" class="sel" :class="{ bad: bad('longitude') }" inputmode="decimal" /></div>
        </div>

        <h2 class="sec">{{ t('mst.att') }}</h2>
        <div v-for="(a, i) in attention" :key="i" class="sub">
          <input v-model="a.nama" class="sel" :placeholder="t('mst.att.nama')" :aria-label="t('mst.att.nama')" />
          <input v-model="a.posisi" class="sel" :placeholder="t('mst.att.posisi')" :aria-label="t('mst.att.posisi')" />
          <input v-model="a.hp" class="sel" :placeholder="t('mst.att.hp')" :aria-label="t('mst.att.hp')" inputmode="tel" />
          <input v-model="a.email" class="sel" :placeholder="t('mst.att.email')" :aria-label="t('mst.att.email')" inputmode="email" />
          <button type="button" class="lnk danger" @click="attention.splice(i, 1)">{{ t('mst.remove') }}</button>
        </div>
        <button type="button" class="lnk" @click="addAtt"><Icon name="plus" :size="14" /> {{ t('mst.att.add') }}</button>

        <h2 class="sec">{{ t('mst.doc') }}</h2>
        <div v-for="d in docs" :key="d.id" class="sub doc">
          <div><b>{{ d.dokumen }}</b><small>{{ d.masa_berlaku || '-' }}<template v-if="d.lampiran"> · {{ d.lampiran }}</template></small></div>
          <button type="button" class="lnk danger" @click="removeDoc(d)">{{ t('mst.remove') }}</button>
        </div>
        <div v-for="(d, i) in newDocs" :key="'n' + i" class="sub">
          <input v-model="d.dokumen" class="sel" :placeholder="t('mst.doc.nama')" :aria-label="t('mst.doc.nama')" maxlength="250" />
          <input v-model="d.masa_berlaku" class="sel" type="date" :aria-label="t('mst.doc.until')" />
          <button type="button" class="lnk danger" @click="newDocs.splice(i, 1)">{{ t('mst.remove') }}</button>
        </div>
        <button type="button" class="lnk" @click="addDoc"><Icon name="plus" :size="14" /> {{ t('mst.doc.add') }}</button>
        <small class="muted">{{ t('mst.doc.note') }}</small>

        <div class="field" style="margin-top:12px"><label for="tnote">{{ t('mst.f.note') }}</label><textarea id="tnote" v-model="f.catatan" class="plain" maxlength="1000" /></div>
        <label class="sw"><span>{{ t('mst.active') }}</span><input v-model="f.aktif" type="checkbox" role="switch" /><i /></label>
        <div v-if="needPassword" class="field">
          <label for="tpw">{{ t('det.yourPwd') }}</label>
          <div class="input"><Icon name="lock" :size="20" /><input id="tpw" v-model="pwd" type="password" autocomplete="current-password" :placeholder="t('det.confirmPwd')" /></div>
          <small class="muted">{{ t('det.faceNote') }}</small>
        </div>
        <p v-if="formError" class="err-text" role="alert">{{ formError }}</p>
        <button class="btn btn-primary btn-block" :disabled="busy">{{ busy ? t('det.processing') : t('rsv.save') }}</button>
      </form>
    </div>
  </div>
</template>

<style scoped>
.bar { position: sticky; top: 0; z-index: 10; display: flex; align-items: center; gap: 8px; padding: calc(var(--safe-top) + 8px) 12px 8px; background: var(--glass-bar); backdrop-filter: blur(12px); border-bottom: 1px solid var(--line); }
.bar-t { flex: 1; text-align: center; font-size: 14.5px; }
.back { width: 44px; height: 44px; border-radius: 13px; border: 1px solid var(--line); background: var(--card); color: var(--ink); display: grid; place-items: center; }
.wrap { padding: 12px 16px calc(var(--safe-bottom) + 32px); }
.box { padding: 14px 16px; display: grid; gap: 4px; }
.sel { width: 100%; min-height: 46px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; }
.sel.bad { border-color: var(--red); }
.sw { display: flex; align-items: center; justify-content: space-between; margin: 8px 0 12px; font-weight: 600; position: relative; }
.sw input { position: absolute; right: 0; width: 44px; height: 26px; opacity: 0; margin: 0; cursor: pointer; }
.sw i { width: 44px; height: 26px; border-radius: 13px; background: #c5cedd; position: relative; transition: background 0.2s; pointer-events: none; }
.sw i::after { content: ''; position: absolute; top: 3px; left: 3px; width: 20px; height: 20px; border-radius: 50%; background: #fff; transition: transform 0.2s; }
.sw input:checked + i { background: var(--accent); }
.sw input:checked + i::after { transform: translateX(18px); }
.two { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 10px; }
.sec { font-size: 14px; font-weight: 700; margin: 14px 0 6px; }
.sub { display: grid; gap: 8px; padding: 10px; margin-bottom: 8px; border: 1px dashed var(--line); border-radius: 14px; }
.sub.doc { grid-template-columns: minmax(0, 1fr) auto; align-items: center; }
.sub.doc small { display: block; color: var(--muted); font-size: 12px; overflow-wrap: anywhere; }
.lnk { background: none; border: 0; color: var(--accent); font-weight: 700; font-size: 13px; padding: 6px 0; display: inline-flex; align-items: center; gap: 6px; justify-self: start; }
.lnk.danger { color: var(--red); }
</style>
