<script setup>
import { t } from '../i18n'
import { useScrolled } from '../composables/useScrolled'
import { computed, onMounted, reactive, ref } from 'vue'
import Icon from '../components/Icon.vue'
import UserAvatar from '../components/UserAvatar.vue'
import StateBox from '../components/StateBox.vue'
import * as syop from '../api/syop'
import { errorMessage } from '../api/client'

const scrolled = useScrolled()
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const today = iso(new Date())
const tgl = ref(today)
const data = ref(null)
const loading = ref(true)
const error = ref('')
const sheet = ref(false)
const saving = ref(false)
const formErr = ref('')
const confirmDel = ref(false)
const f = reactive({ id: null, ruangan: null, nama: '', mulai: '09:00', selesai: '10:00', personel: '', keperluan: '' })
let seq = 0

const label = computed(() => new Date(tgl.value + 'T00:00:00').toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }))
const isPast = computed(() => tgl.value < today)

async function load() {
  const my = ++seq
  loading.value = true; error.value = ''
  try { const r = await syop.listReservasi(tgl.value); if (my === seq) data.value = r } catch (e) { if (my === seq) error.value = errorMessage(e) } finally { if (my === seq) loading.value = false }
}
function geser(n) { const d = new Date(tgl.value + 'T00:00:00'); d.setDate(d.getDate() + n); tgl.value = iso(d); load() }
function pilih(e) { if (e.target.value) { tgl.value = e.target.value; load() } }

function baru(r) { Object.assign(f, { id: null, ruangan: r.id, nama: r.nama, mulai: '09:00', selesai: '10:00', personel: '', keperluan: '' }); formErr.value = ''; confirmDel.value = false; sheet.value = true }
function ubah(r, b) { Object.assign(f, { id: b.id, ruangan: r.id, nama: r.nama, mulai: b.mulai, selesai: b.selesai, personel: b.personel, keperluan: b.keperluan }); formErr.value = ''; confirmDel.value = false; sheet.value = true }
async function simpan() {
  if (!f.personel.trim() || !f.keperluan.trim()) { formErr.value = t('rsv.fill'); return }
  if (f.selesai <= f.mulai) { formErr.value = t('rsv.badTime'); return }
  saving.value = true; formErr.value = ''
  const p = { ruangan: f.ruangan, tanggal: tgl.value, mulai: f.mulai, selesai: f.selesai, personel: f.personel.trim(), keperluan: f.keperluan.trim() }
  try { f.id ? await syop.updateReservasi(f.id, p) : await syop.createReservasi(p); sheet.value = false; await load() } catch (e) { formErr.value = errorMessage(e) } finally { saving.value = false }
}
async function batal() {
  saving.value = true; formErr.value = ''
  try { await syop.deleteReservasi(f.id); sheet.value = false; await load() } catch (e) { formErr.value = errorMessage(e) } finally { saving.value = false; confirmDel.value = false }
}
onMounted(load)
</script>

<template>
  <div class="page">
    <div class="page-head" :class="{ stuck: scrolled }">
      <div>
        <h1 class="page-title">{{ t('rsv.title') }}</h1>
        <p class="page-sub">{{ data?.cabang?.nama ? t('rsv.sub', { c: data.cabang.nama }) : t('rsv.subPlain') }}</p>
      </div>
      <router-link :to="{ name: 'profil' }" class="avatar" :aria-label="t('common.profile')"><UserAvatar /><i class="dot" /></router-link>
    </div>

    <div class="dbar">
      <button class="nb" :aria-label="t('rsv.prev')" @click="geser(-1)"><Icon name="chevron" :size="18" style="transform:rotate(180deg)" /></button>
      <label class="dpick">
        <span>{{ label }}</span>
        <input type="date" :value="tgl" :aria-label="t('rsv.date')" @change="pilih" />
      </label>
      <button class="nb" :aria-label="t('rsv.next')" @click="geser(1)"><Icon name="chevron" :size="18" /></button>
    </div>
    <button v-if="tgl !== today" class="today" @click="tgl = today; load()">{{ t('rsv.today') }}</button>

    <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load" />
    <div v-else-if="loading && !data" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:120px" /></div>
    <StateBox v-else-if="!data?.ruangan?.length" icon="calendar" :title="t('rsv.empty')" :text="t('rsv.emptyText')" />
    <div v-else class="stack" :style="{ opacity: loading ? 0.6 : 1 }">
      <section v-for="(r, i) in data.ruangan" :key="r.id" class="room rise" :style="{ '--i': i % 8 }">
        <div class="rh">
          <span class="ico-box tinted tone-blue"><Icon name="calendar" :size="18" /></span>
          <b>{{ r.nama }}</b>
          <span class="chip" :class="r.reservasi.length ? 'busy' : 'free'">{{ r.reservasi.length ? t('rsv.n', { n: r.reservasi.length }) : t('rsv.free') }}</span>
        </div>
        <ul v-if="r.reservasi.length" class="bk">
          <li v-for="b in r.reservasi" :key="b.id" :class="{ mine: b.milik_saya }">
            <button v-if="b.milik_saya" class="bkb" :aria-label="t('rsv.edit')" @click="ubah(r, b)">
              <b class="tm">{{ b.mulai }}–{{ b.selesai }}</b><span class="kp">{{ b.keperluan }}</span><small>{{ b.personel }} · {{ t('rsv.mine') }}</small><Icon name="edit" :size="16" />
            </button>
            <div v-else class="bkb"><b class="tm">{{ b.mulai }}–{{ b.selesai }}</b><span class="kp">{{ b.keperluan }}</span><small>{{ b.oleh }} · {{ b.personel }}</small></div>
          </li>
        </ul>
        <button class="btn btn-ghost btn-block" :disabled="isPast" @click="baru(r)"><Icon name="plus" :size="18" /> {{ t('rsv.book') }}</button>
      </section>
    </div>

    <div v-if="sheet" class="sheet-back" @click.self="sheet = false">
      <div class="sheet" role="dialog" aria-modal="true" :aria-label="t('rsv.book')">
        <div class="grab" />
        <h2 class="sheet-t" style="margin-bottom:2px">{{ f.id ? t('rsv.edit') : t('rsv.book') }}</h2>
        <p class="page-sub" style="margin:0 0 14px">{{ f.nama }} · {{ label }}</p>
        <div class="two">
          <div class="field"><label for="rm">{{ t('rsv.start') }}</label><input id="rm" v-model="f.mulai" type="time" class="sel" /></div>
          <div class="field"><label for="rs">{{ t('rsv.end') }}</label><input id="rs" v-model="f.selesai" type="time" class="sel" /></div>
        </div>
        <div class="field"><label for="rp">{{ t('rsv.people') }}</label><input id="rp" v-model="f.personel" class="sel" maxlength="255" :placeholder="t('rsv.peopleHint')" /></div>
        <div class="field"><label for="rk">{{ t('rsv.purpose') }}</label><input id="rk" v-model="f.keperluan" class="sel" maxlength="255" /></div>
        <p v-if="formErr" class="ferr" role="alert">{{ formErr }}</p>
        <div class="sheet-act" style="margin-top:6px">
          <template v-if="confirmDel">
            <button class="btn btn-ghost" :disabled="saving" @click="confirmDel = false">{{ t('common.cancel') }}</button>
            <button class="btn btn-danger" :disabled="saving" @click="batal">{{ t('rsv.yesCancel') }}</button>
          </template>
          <template v-else>
            <button v-if="f.id" class="btn btn-ghost" :disabled="saving" @click="confirmDel = true"><Icon name="trash" :size="18" /> {{ t('rsv.cancel') }}</button>
            <button class="btn btn-primary" :disabled="saving" @click="simpan">{{ saving ? t('common.loading') : t('rsv.save') }}</button>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.dbar { display: flex; gap: 8px; align-items: center; margin-bottom: 10px; }
.nb { flex: none; width: 44px; height: 44px; border-radius: 12px; background: var(--card); border: 1px solid var(--line); display: grid; place-items: center; color: var(--ink-2); }
.dpick { position: relative; flex: 1; min-height: 44px; border-radius: 12px; background: var(--card); border: 1px solid var(--line); display: grid; place-items: center; font-weight: 700; font-size: 14px; text-align: center; padding: 0 8px; }
.dpick input { position: absolute; inset: 0; opacity: 0; width: 100%; cursor: pointer; }
.today { margin: 0 0 12px; background: none; border: 0; color: var(--accent); font-weight: 700; font-size: 13px; padding: 4px 0; }
.room { background: var(--card); border: 1px solid var(--line); border-radius: 18px; padding: 14px; box-shadow: var(--shadow); display: grid; gap: 10px; }
.rh { display: flex; align-items: center; gap: 10px; }
.rh b { flex: 1; min-width: 0; font-size: 15px; }
.chip { flex: none; font-size: 11.5px; font-weight: 700; padding: 3px 9px; border-radius: 99px; }
.chip.free { background: #e3f6ec; color: #11704a; }
.chip.busy { background: #fdeaea; color: #b4232a; }
.bk { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
.bkb { width: 100%; display: grid; grid-template-columns: auto 1fr auto; gap: 2px 10px; align-items: center; text-align: left; padding: 9px 11px; border-radius: 12px; background: var(--blue-50); border: 0; color: var(--ink); }
.mine .bkb { outline: 1.5px solid var(--focus); }
.tm { font-size: 13px; }
.kp { font-size: 13.5px; font-weight: 600; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.bkb svg { grid-column: 3; grid-row: 1; color: var(--muted); }
.bkb small { grid-column: 1 / -1; color: var(--muted); font-size: 12px; }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.sel { width: 100%; min-height: 46px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 15px; }
.ferr { color: var(--red); font-size: 13px; font-weight: 600; margin: 4px 0 8px; }
</style>
