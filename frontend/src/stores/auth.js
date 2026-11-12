import { defineStore } from 'pinia'
import * as syop from '../api/syop'
import { tokenStore } from '../api/client'
import { clearCaches } from '../composables/useListCache'
import { usePush } from '../composables/usePush'

const USER_KEY = 'orcax_user'
const METHOD_KEY = 'orcax_method'
const LAST_USER_KEY = 'orcax_last_username'
const readUser = () => { try { return JSON.parse(localStorage.getItem(USER_KEY) || 'null') } catch { return null } }
const writeUser = (u) => { try { u ? localStorage.setItem(USER_KEY, JSON.stringify(u)) : localStorage.removeItem(USER_KEY) } catch { /* abaikan */ } }

const readMethod = () => { try { return localStorage.getItem(METHOD_KEY) || 'password' } catch { return 'password' } }
const writeMethod = (m) => { try { m ? localStorage.setItem(METHOD_KEY, m) : localStorage.removeItem(METHOD_KEY) } catch { /* abaikan */ } }

// Kemudahan: ingat username terakhir (bukan rahasia) supaya login wajah hanya satu langkah.
export const lastUsername = () => { try { return localStorage.getItem(LAST_USER_KEY) || '' } catch { return '' } }
const HINT_KEY = 'orcax_face_hint'
export const faceHint = () => { try { return localStorage.getItem(HINT_KEY) === '1' } catch { return false } }
const writeHint = (on) => { try { on ? localStorage.setItem(HINT_KEY, '1') : localStorage.removeItem(HINT_KEY) } catch { /* abaikan */ } }
const rememberUsername = (u) => { try { localStorage.setItem(LAST_USER_KEY, u) } catch { /* abaikan */ } }

export const useAuth = defineStore('auth', {
  state: () => ({ user: readUser(), method: readMethod(), photoUrl: '' }),
  getters: { isLoggedIn: () => !!tokenStore.get() },
  actions: {
    async login(username, password) {
      const { token, user, auth_method } = await syop.login(username.trim(), password)
      this.accept(token, user, auth_method, username.trim())
    },
    async loginFace(username, descriptor) {
      const { token, user, auth_method } = await syop.faceLogin(username.trim(), descriptor)
      this.accept(token, user, auth_method, username.trim())
    },
    accept(token, user, method, username) {
      tokenStore.set(token)
      this.user = user
      this.method = method || 'password'
      writeUser(user)
      writeMethod(this.method)
      rememberUsername(user.username || username)
      writeHint(!!user.face_enrolled)
      this.loadPhoto()
    },
    /** Ambil foto profil (butuh token, jadi lewat fetch -> blob URL; bukan <img src>). */
    async loadPhoto() {
      if (!this.user?.photo_v) { this.dropPhoto(); return }
      try {
        const blob = await syop.getPhoto()
        this.dropPhoto()
        this.photoUrl = URL.createObjectURL(blob)
      } catch { /* tanpa foto: tampil inisial */ }
    },
    async changePhoto(blob) {
      const r = await syop.uploadPhoto(blob)
      this.user.photo_v = r.photo_v
      writeUser(this.user)
      this.dropPhoto()
      this.photoUrl = URL.createObjectURL(blob)
    },
    async removePhoto() {
      await syop.deletePhoto()
      this.user.photo_v = null
      writeUser(this.user)
      this.dropPhoto()
    },
    dropPhoto() {
      if (this.photoUrl) URL.revokeObjectURL(this.photoUrl)
      this.photoUrl = ''
    },
    setFaceEnrolled(on) {
      if (this.user) { this.user.face_enrolled = on; writeUser(this.user) }
      writeHint(on)
    },
    async refresh() {
      const r = await syop.me()
      this.user = r.user ?? r
      writeHint(!!this.user.face_enrolled)
      this.method = r.auth_method || this.method
      this.loadPhoto()
      writeUser(this.user)
      writeMethod(this.method)
    },
    async logout() {
      // Keluar tidak boleh macet: tiap langkah punya batas waktu, dan data lokal selalu dibersihkan.
      const wait = (ms) => new Promise((r) => setTimeout(r, ms))
      // 1) perangkat berhenti menerima notifikasi akun ini (sebelum token dicabut); paling lama 1,5 detik
      await Promise.race([usePush().releaseOnLogout().catch(() => {}), wait(1500)])
      // 2) cabut token di server; paling lama 4 detik (offline / jaringan lemot tetap keluar lokal)
      await Promise.race([syop.logout().catch(() => {}), wait(4000)])
      this.clear()
    },
    clear() {
      clearCaches() // data approval tidak boleh tertinggal di HP setelah keluar
      tokenStore.clear()
      writeUser(null)
      writeMethod(null)
      this.dropPhoto()
      this.user = null
      this.method = 'password'
    },
  },
})
