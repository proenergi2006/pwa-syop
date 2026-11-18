import { createRouter, createWebHistory } from 'vue-router'
import { tokenStore } from '../api/client'
import { navDir } from '../composables/useNav'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'login', component: () => import('../views/LoginView.vue'), meta: { public: true } },
    { path: '/', name: 'home', component: () => import('../views/HomeView.vue'), meta: { nav: true } },
    { path: '/menu', name: 'menu', component: () => import('../views/MenuView.vue'), meta: { nav: true } },
    { path: '/approval', name: 'approval', component: () => import('../views/ApprovalView.vue'), meta: { nav: true } },
    { path: '/approval/:id(\\d+)', name: 'approval-detail', component: () => import('../views/ApprovalDetailView.vue'), props: true },
    { path: '/inventory', name: 'inventory', component: () => import('../views/InventoryView.vue'), meta: { nav: true, module: 'inventory' } },
    { path: '/reservasi', name: 'reservasi', component: () => import('../views/ReservasiView.vue'), meta: { nav: true, module: 'reservasi' } },
    { path: '/mobil', name: 'mobil', component: () => import('../views/MobilView.vue'), meta: { nav: true, module: 'mobil' } },
    { path: '/trucking', name: 'trucking', component: () => import('../views/TruckingView.vue'), meta: { nav: true, module: 'trucking' } },
    { path: '/trucking/:id(\\d+)', name: 'trucking-detail', component: () => import('../views/TruckingDetailView.vue'), props: true, meta: { module: 'trucking' } },
    { path: '/master-terminal', name: 'master-terminal', component: () => import('../views/MasterTerminalView.vue'), meta: { nav: true, module: 'master' } },
    { path: '/master-terminal/form', name: 'master-terminal-form', component: () => import('../views/MasterTerminalFormView.vue'), meta: { module: 'master' } },
    { path: '/master-vendor', name: 'master-vendor', component: () => import('../views/MasterVendorView.vue'), meta: { nav: true, module: 'master' } },
    { path: '/master-vendor/form', name: 'master-vendor-form', component: () => import('../views/MasterVendorFormView.vue'), meta: { module: 'master' } },
    { path: '/produk', name: 'produk', component: () => import('../views/ProdukView.vue'), meta: { nav: true, module: 'produk' } },
    { path: '/produk/form', name: 'produk-form', component: () => import('../views/ProdukFormView.vue'), meta: { module: 'produk' } },
    { path: '/produk/:id(\\d+)', name: 'produk-detail', component: () => import('../views/ProdukDetailView.vue'), props: true, meta: { module: 'produk' } },
    { path: '/dr', name: 'dr', component: () => import('../views/DrView.vue'), meta: { nav: true, module: 'dr' } },
    { path: '/report', name: 'report', component: () => import('../views/ReportView.vue'), meta: { nav: true, module: 'report' } },
    { path: '/report/marketing', name: 'report-marketing', component: () => import('../views/ReportMarketingView.vue'), meta: { module: 'report' } },
    { path: '/sales-confirmation', name: 'sc', component: () => import('../views/SalesConfirmationView.vue'), meta: { nav: true, module: 'sc' } },
    { path: '/sales-confirmation/:id(\\d+)', name: 'sc-detail', component: () => import('../views/SalesConfirmationDetailView.vue'), meta: { module: 'sc' } },
    { path: '/permohonan', name: 'permohonan', component: () => import('../views/PermohonanView.vue'), meta: { nav: true, module: 'permohonan' } },
    { path: '/permohonan/:id(\\d+)', name: 'permohonan-detail', component: () => import('../views/PermohonanDetailView.vue'), meta: { module: 'permohonan' } },
    { path: '/lcr', name: 'lcr', component: () => import('../views/LcrView.vue'), meta: { nav: true, module: 'lcr' } },
    { path: '/lcr/:id(\\d+)', name: 'lcr-detail', component: () => import('../views/LcrDetailView.vue'), meta: { module: 'lcr' } },
    { path: '/refund-penerima', name: 'refund-penerima', component: () => import('../views/RefundView.vue'), meta: { nav: true, module: 'refund' } },
    { path: '/refund-penerima/:id(\\d+)', name: 'refund-penerima-detail', component: () => import('../views/RefundDetailView.vue'), meta: { module: 'refund' } },
    { path: '/verifikasi-customer', name: 'verifikasi-customer', component: () => import('../views/VerifikasiCustomerView.vue'), meta: { nav: true, module: 'verifikasi_customer' } },
    { path: '/verifikasi-customer/:id(\\d+)', name: 'verifikasi-customer-detail', component: () => import('../views/VerifikasiCustomerDetailView.vue'), meta: { module: 'verifikasi_customer' } },
    { path: '/trucking-dr', name: 'trucking-dr', component: () => import('../views/TruckingDrView.vue'), meta: { nav: true, module: 'trucking_dr' } },
    { path: '/trucking-dr/:id(\\d+)', name: 'trucking-dr-detail', component: () => import('../views/TruckingDrDetailView.vue'), props: true, meta: { module: 'trucking_dr' } },
    { path: '/dr/:id(\\d+)', name: 'dr-detail', component: () => import('../views/DrDetailView.vue'), props: true, meta: { module: 'dr' } },
    { path: '/harga', name: 'harga', component: () => import('../views/HargaView.vue'), meta: { nav: true, module: 'harga' } },
    { path: '/harga/detail', name: 'harga-detail', component: () => import('../views/HargaDetailView.vue'), meta: { module: 'harga' } },
    { path: '/harga/form', name: 'harga-form', component: () => import('../views/HargaFormView.vue'), meta: { module: 'harga' } },
    { path: '/po', name: 'po', component: () => import('../views/PoView.vue'), meta: { nav: true, module: 'po' } },
    { path: '/po/:id(\\d+)', name: 'po-detail', component: () => import('../views/PoDetailView.vue'), props: true, meta: { module: 'po' } },
    { path: '/shipping', name: 'shipping', component: () => import('../views/ShippingView.vue'), meta: { nav: true, module: 'shipping' } },
    { path: '/shipping/:id(\\d+)', name: 'shipping-detail', component: () => import('../views/ShippingDetailView.vue'), props: true, meta: { module: 'shipping' } },
    { path: '/riwayat', name: 'riwayat', component: () => import('../views/HistoryView.vue'), meta: { nav: true } },
    { path: '/profil', name: 'profil', component: () => import('../views/ProfileView.vue'), meta: { nav: true } },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach((to, from) => {
  // Detail masuk dari kanan, kembali ke kiri; antar tab memudar naik.
  navDir.value = ['approval-detail', 'shipping-detail', 'trucking-detail', 'dr-detail', 'trucking-dr-detail', 'po-detail', 'harga-detail', 'harga-form', 'produk-detail', 'produk-form', 'master-terminal-form', 'master-vendor-form'].includes(to.name) ? 'slide-left' : ['approval-detail', 'shipping-detail', 'trucking-detail', 'dr-detail', 'trucking-dr-detail', 'po-detail', 'harga-detail', 'harga-form', 'produk-detail', 'produk-form', 'master-terminal-form', 'master-vendor-form'].includes(from.name) ? 'slide-right' : 'fade-up'
  if (!to.meta.public && !tokenStore.get()) return { name: 'login' }
  if (to.name === 'login' && tokenStore.get()) return { name: 'home' }
  // Modul yang tidak tersedia untuk peran ini kembali ke Beranda.
  const mods = JSON.parse(localStorage.getItem('orcax_user') || 'null')?.modules
  const need = to.meta.module || (['approval', 'approval-detail', 'riwayat'].includes(to.name) ? 'penawaran' : '')
  if (need && Array.isArray(mods) && !mods.includes(need)) return { name: 'home' }
})

export default router
