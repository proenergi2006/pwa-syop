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
const cab = ref(null)
const f = reactive({ id: null, mobil: null, nama: '', mulai: '09:00', selesai: '10:00', keperluan: '', km: '', bensin: '' })
let seq = 0

const label = computed(() => new Date(tgl.value + 'T00:00:00').toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }))
const isPast = computed(() => tgl.value < today)
const pct = (b) => Math.max(0, Math.min(100, b))

async function load() {
  const my = ++seq
  loading.value = true; error.value = ''
  try { const r = await syop.listMobil(tgl.value, cab.value); if (my === seq) { data.value = r; cab.value = r.cabang.id } } catch (e) { if (my === seq) error.value = errorMessage(e) } finally { if (my === seq) loading.value = false }
}
function geser(n) { const d = new Date(tgl.value + 'T00:00:00'); d.setDate(d.getDate() + n); tgl.value = iso(d); load() }
function ganti(id) { cab.value = id; load() }
function pilih(e) { if (e.target.value) { tgl.value = e.target.value; load() } }

function baru(r) { Object.assign(f, { id: null, mobil: r.id, nama: r.nama + ' · ' + r.plat, mulai: '09:00', selesai: '10:00', keperluan: '', km: '', bensin: '' }); formErr.value = ''; confirmDel.value = false; sheet.value = true }
function ubah(r, b) { Object.assign(f, { id: b.id, mobil: r.id, nama: r.nama + ' · ' + r.plat, mulai: b.mulai, selesai: b.selesai, keperluan: b.keperluan, km: b.km || '', bensin: b.bensin || '' }); formErr.value = ''; confirmDel.value = false; sheet.value = true }
async function simpan() {
  if (!f.keperluan.trim()) { formErr.value = t('mbl.fill'); return }
  const bn = f.bensin === '' ? null : Number(f.bensin)
  if (bn !== null && (!Number.isInteger(bn) || bn < 0 || bn > 100)) { formErr.value = t('mbl.badFuel'); return }
  if (f.selesai <= f.mulai) { formErr.value = t('rsv.badTime'); return }
  saving.value = true; formErr.value = ''
  const p = { mobil: f.mobil, tanggal: tgl.value, mulai: f.mulai, selesai: f.selesai, keperluan: f.keperluan.trim() }
  if (f.id) { p.km = f.km === '' ? 0 : Number(f.km); p.bensin = bn ?? 0 }
  try { f.id ? await syop.updateMobil(f.id, p) : await syop.createMobil(p); sheet.value = false; await load() } catch (e) { formErr.value = errorMessage(e) } finally { saving.value = false }
}
async function batal() {
  saving.value = true; formErr.value = ''
  try { await syop.deleteMobil(f.id); sheet.value = false; await load() } catch (e) { formErr.value = errorMessage(e) } finally { saving.value = false; confirmDel.value = false }
}
onMounted(load)
</script>

<template>
  <div class="page">
    <div class="page-head" :class="{ stuck: scrolled }">
      <div>
        <h1 class="page-title">{{ t('mbl.title') }}</h1>
        <p class="page-sub">{{ data?.cabang?.nama ? t('mbl.sub', { c: data.cabang.nama }) : t('mbl.subPlain') }}</p>
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
    <div v-if="data?.pilih_cabang?.length" class="chips cabs" role="tablist">
      <button v-for="c in data.pilih_cabang" :key="c.id" class="chip" :class="{ on: cab === c.id }" role="tab" :aria-selected="cab === c.id" @click="ganti(c.id)">{{ c.nama }}</button>
    </div>
    <button v-if="tgl !== today" class="today" @click="tgl = today; load()">{{ t('rsv.today') }}</button>

    <StateBox v-if="error" icon="alert" :title="t('common.failedLoad')" :text="error" :action="t('common.retry')" @action="load" />
    <div v-else-if="loading && !data" class="stack"><div v-for="i in 3" :key="i" class="skel" style="height:120px" /></div>
    <StateBox v-else-if="!data?.mobil?.length" icon="truck" :title="t('mbl.empty')" :text="t('mbl.emptyText')" />
    <div v-else class="stack" :style="{ opacity: loading ? 0.6 : 1 }">
      <section v-for="(r, i) in data.mobil" :key="r.id" class="room rise" :style="{ '--i': i % 8 }">
        <div class="rh">
          <span class="ico-box tinted tone-blue"><Icon name="car" :size="18" /></span>
          <b>{{ r.nama }}<small class="plat">{{ r.plat }}</small></b>
          <span class="chip" :class="r.reservasi.length ? 'busy' : 'free'">{{ r.reservasi.length ? t('mbl.n', { n: r.reservasi.length }) : t('mbl.free') }}</span>
        </div>
        <ul v-if="r.reservasi.length" class="bk">
          <li v-for="b in r.reservasi" :key="b.id" :class="{ mine: b.milik_saya }">
            <button v-if="b.bisa_ubah" class="bkb" :aria-label="t('mbl.edit')" @click="ubah(r, b)">
              <b class="tm">{{ b.mulai }}–{{ b.selesai }}</b><span class="kp">{{ b.keperluan }}</span><small>{{ b.milik_saya ? t('rsv.mine') : b.oleh }}<template v-if="b.km"> · {{ b.km.toLocaleString('id-ID') }} km</template><template v-if="b.bensin"> · {{ b.bensin }}%</template></small><Icon name="edit" :size="16" />
            </button>
            <div v-else class="bkb"><b class="tm">{{ b.mulai }}–{{ b.selesai }}</b><span class="kp">{{ b.keperluan }}</span><small>{{ b.oleh }}<template v-if="b.km"> · {{ b.km.toLocaleString('id-ID') }} km</template><template v-if="b.bensin"> · {{ b.bensin }}%</template></small></div>
          </li>
        </ul>
        <button class="btn btn-ghost btn-block" :disabled="isPast" @click="baru(r)"><Icon name="plus" :size="18" /> {{ t('mbl.book') }}</button>
      </section>
    </div>

    <div v-if="sheet" class="sheet-back" @click.self="sheet = false">
      <div class="sheet" role="dialog" aria-modal="true" :aria-label="t('mbl.book')">
        <div class="grab" />
        <h2 class="sheet-t" style="margin-bottom:2px">{{ f.id ? t('mbl.edit') : t('mbl.book') }}</h2>
        <p class="page-sub" style="margin:0 0 14px">{{ f.nama }} · {{ label }}</p>
        <div class="two">
          <div class="field"><label for="rm">{{ t('rsv.start') }}</label><input id="rm" v-model="f.mulai" type="time" class="sel" /></div>
          <div class="field"><label for="rs">{{ t('rsv.end') }}</label><input id="rs" v-model="f.selesai" type="time" class="sel" /></div>
        </div>
        <div class="field"><label for="rk">{{ t('rsv.purpose') }}</label><input id="rk" v-model="f.keperluan" class="sel" maxlength="255" /></div>
        <div v-if="f.id" class="two">
          <div class="field"><label for="rkm">{{ t('mbl.km') }}</label><input id="rkm" v-model="f.km" class="sel" type="number" inputmode="decimal" min="0" /></div>
          <div class="field"><label for="rbn">{{ t('mbl.fuel') }}</label><input id="rbn" v-model="f.bensin" class="sel" type="number" inputmode="numeric" min="0" max="100" /></div>
        </div>
        <div v-if="f.id && f.bensin !== ''" class="meter" aria-hidden="true"><i :style="{ width: pct(Number(f.bensin)) + '%' }" /></div>
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
.plat { display: block; font-size: 12px; font-weight: 600; color: var(--muted); }
.cabs { margin-bottom: 12px; }
.meter { height: 10px; border-radius: 5px; background: #e2e6ee; overflow: hidden; margin: -2px 0 10px; }
.meter i { display: block; height: 100%; background: linear-gradient(90deg, #f0645a, #f08007, #20c07c); }
</style>
