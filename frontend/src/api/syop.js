import { api } from './client'

export const login = (username, password) => api.post('/auth/login', { username, password }).then((r) => r.data)
export const me = () => api.get('/auth/me').then((r) => r.data)
export const logout = () => api.post('/auth/logout', null, { timeout: 5000 }).then((r) => r.data)

export const listPending = (params = {}) => api.get('/penawaran', { params }).then((r) => r.data)
export const listHistory = (params = {}) => api.get('/penawaran/riwayat', { params }).then((r) => r.data)
export const ringkasan = () => api.get('/penawaran/ringkasan').then((r) => r.data)
export const getPenawaran = (id) => api.get(`/penawaran/${id}`).then((r) => r.data)
export const decide = (id, payload) => api.post(`/penawaran/${id}/keputusan`, payload).then((r) => r.data)

// Face Recognition 1:1 (embedding 128 angka; tidak ada foto yang dikirim)
export const faceLogin = (username, descriptor) => api.post('/auth/face-login', { username, descriptor }).then((r) => r.data)
export const faceStatus = () => api.get('/face').then((r) => r.data)
export const faceEnroll = (password, descriptor) => api.post('/face', { password, consent: true, descriptor }).then((r) => r.data)
export const faceRemove = (password) => api.delete('/face', { data: { password } }).then((r) => r.data)

// Foto profil (hanya milik sendiri)
export const getPhoto = () => api.get('/profile/photo', { responseType: 'blob' }).then((r) => r.data)
export const uploadPhoto = (blob) => {
  const fd = new FormData()
  fd.append('photo', blob, 'foto.jpg')
  return api.post('/profile/photo', fd, { headers: { 'Content-Type': 'multipart/form-data' } }).then((r) => r.data)
}
export const deletePhoto = () => api.delete('/profile/photo').then((r) => r.data)

// Inventory: sisa stok per PO supplier
export const listStock = (params = {}) => api.get('/inventory', { params }).then((r) => r.data)
export const stockFilters = () => api.get('/inventory/filter').then((r) => r.data)
export const stockTerminal = () => api.get('/inventory/terminal').then((r) => r.data)

// Shipping Instruction (persetujuan akhir CEO)
export const listShipping = (params = {}) => api.get('/shipping', { params }).then((r) => r.data)
export const getShipping = (id) => api.get(`/shipping/${id}`).then((r) => r.data)
export const decideShipping = (id, payload) => api.post(`/shipping/${id}/keputusan`, payload).then((r) => r.data)

// Verifikasi PO Supplier (tahap CEO)
export const listPo = (params = {}) => api.get('/po', { params }).then((r) => r.data)
export const getPo = (id) => api.get(`/po/${id}`).then((r) => r.data)
export const decidePo = (id, payload) => api.post(`/po/${id}/keputusan`, payload).then((r) => r.data)

export const badges = () => api.get('/badges').then((r) => r.data)

// Master harga jual (CEO)
export const listHarga = (params = {}) => api.get('/harga', { params }).then((r) => r.data)
export const hargaOptions = () => api.get('/harga/options').then((r) => r.data)
export const getHarga = (key) => api.get('/harga/item', { params: key }).then((r) => r.data)
export const createHarga = (payload) => api.post('/harga', payload).then((r) => r.data)
export const updateHarga = (payload) => api.put('/harga/item', payload).then((r) => r.data)
export const deleteHarga = (payload) => api.delete('/harga/item', { data: payload }).then((r) => r.data)

// Reservasi ruangan (semua peran, ruangan sesuai cabang)
export const listReservasi = (tanggal) => api.get('/reservasi', { params: { tanggal } }).then((r) => r.data)
export const createReservasi = (p) => api.post('/reservasi', p).then((r) => r.data)
export const updateReservasi = (id, p) => api.put(`/reservasi/${id}`, p).then((r) => r.data)
export const deleteReservasi = (id) => api.delete(`/reservasi/${id}`).then((r) => r.data)

// Peminjaman mobil (semua peran, mobil sesuai cabang)
export const listMobil = (tanggal, cabang) => api.get('/mobil', { params: { tanggal, cabang: cabang || undefined } }).then((r) => r.data)
export const createMobil = (p) => api.post('/mobil', p).then((r) => r.data)
export const updateMobil = (id, p) => api.put(`/mobil/${id}`, p).then((r) => r.data)
export const deleteMobil = (id) => api.delete(`/mobil/${id}`).then((r) => r.data)

// Trucking Instruction (CEO)
export const listTrucking = (params = {}) => api.get('/trucking', { params }).then((r) => r.data)
export const getTrucking = (id) => api.get(`/trucking/${id}`).then((r) => r.data)
export const decideTrucking = (id, payload) => api.post(`/trucking/${id}/keputusan`, payload).then((r) => r.data)

// Master produk (CEO)
export const listProduk = (params = {}) => api.get('/produk', { params }).then((r) => r.data)
export const getProduk = (id) => api.get(`/produk/${id}`).then((r) => r.data)
export const createProduk = (p) => api.post('/produk', p).then((r) => r.data)
export const updateProduk = (id, p) => api.put(`/produk/${id}`, p).then((r) => r.data)

// Delivery Request (Purchasing)
export const listDr = (params = {}) => api.get('/dr', { params }).then((r) => r.data)
export const getDr = (id) => api.get(`/dr/${id}`).then((r) => r.data)
export const drPoSupplier = (id, q) => api.get(`/dr/${id}/po-supplier`, { params: { q: q || undefined } }).then((r) => r.data)
export const drHistory = (id, penawaran) => api.get(`/dr/${id}/history/${penawaran}`).then((r) => r.data)
export const verifyDr = (id, p) => api.post(`/dr/${id}/verifikasi`, p).then((r) => r.data)
export const reviseDr = (id, p = {}) => api.post(`/dr/${id}/revisi`, p).then((r) => r.data)
export const returnDr = (id, p = {}) => api.post(`/dr/${id}/kembali-dp`, p).then((r) => r.data)
export const resetSplitDr = (id, p) => api.post(`/dr/${id}/reset-split`, p).then((r) => r.data)

export const listTruckingDr = (params = {}) => api.get('/trucking-dr', { params }).then((r) => r.data)
export const getTruckingDr = (id) => api.get(`/trucking-dr/${id}`).then((r) => r.data)
export const truckingDrPoSupplier = (id, q) => api.get(`/trucking-dr/${id}/po-supplier`, { params: { q: q || undefined } }).then((r) => r.data)
export const verifyTruckingDr = (id, p) => api.post(`/trucking-dr/${id}/verifikasi`, p).then((r) => r.data)
export const reviseTruckingDr = (id, p = {}) => api.post(`/trucking-dr/${id}/revisi`, p).then((r) => r.data)
export const returnTruckingDr = (id, p = {}) => api.post(`/trucking-dr/${id}/kembali-dp`, p).then((r) => r.data)

// Master Terminal & Vendor (Purchasing/Procurement)
export const terminalOptions = () => api.get('/master/terminal/options').then((r) => r.data)
export const listTerminal = (params = {}) => api.get('/master/terminal', { params }).then((r) => r.data)
export const getTerminal = (id) => api.get(`/master/terminal/${id}`).then((r) => r.data)
export const createTerminal = (p) => api.post('/master/terminal', p).then((r) => r.data)
export const updateTerminal = (id, p) => api.put(`/master/terminal/${id}`, p).then((r) => r.data)
export const listVendor = (params = {}) => api.get('/master/vendor', { params }).then((r) => r.data)
export const getVendor = (id) => api.get(`/master/vendor/${id}`).then((r) => r.data)
export const vendorLastCode = () => api.get('/master/vendor/kode-terakhir').then((r) => r.data)
export const createVendor = (p) => api.post('/master/vendor', p).then((r) => r.data)
export const updateVendor = (id, p) => api.put(`/master/vendor/${id}`, p).then((r) => r.data)

export const listVerifikasiCustomer = (params = {}) => api.get('/verifikasi-customer', { params }).then((r) => r.data)
export const getVerifikasiCustomer = (id) => api.get(`/verifikasi-customer/${id}`).then((r) => r.data)
export const decideVerifikasiCustomer = (id, p) => api.post(`/verifikasi-customer/${id}/keputusan`, p).then((r) => r.data)
