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
    { path: '/verifikasi-oa', name: 'oa', component: () => import('../views/OaView.vue'), meta: { nav: true, module: 'oa' } },
    { path: '/verifikasi-oa/:id(\\d+)', name: 'oa-detail', component: () => import('../views/OaDetailView.vue'), props: true, meta: { module: 'oa' } },
    { path: '/sistem', name: 'sistem', component: () => import('../views/SistemView.vue'), meta: { nav: true, module: 'sistem' } },
    { path: '/pengumuman', name: 'pengumuman', component: () => import('../views/PengumumanView.vue'), meta: { nav: true, module: 'pengumuman' } },
    { path: '/pengumuman/kelola', name: 'pengumuman-admin', component: () => import('../views/PengumumanAdminView.vue'), meta: { nav: true, module: 'pengumuman_admin' } },
    { path: '/forecast-bm', name: 'forecast-bm', component: () => import('../views/ForecastBmView.vue'), meta: { nav: true, module: 'forecast_bm' } },
    { path: '/forecast-om', name: 'forecast-om', component: () => import('../views/ForecastOmView.vue'), meta: { nav: true, module: 'forecast_om' } },
    { path: '/forecast', name: 'forecast', component: () => import('../views/ForecastView.vue'), meta: { nav: true, module: 'forecast' } },
    { path: '/forecast/cabang/:id(\\d+)', name: 'forecast-cabang', component: () => import('../views/ForecastCabangView.vue'), props: true, meta: { module: 'forecast' } },
    { path: '/master-terminal', name: 'master-terminal', component: () => import('../views/MasterTerminalView.vue'), meta: { nav: true, module: 'master' } },
    { path: '/master-terminal/form', name: 'master-terminal-form', component: () => import('../views/MasterTerminalFormView.vue'), meta: { module: 'master' } },
    { path: '/master-vendor', name: 'master-vendor', component: () => import('../views/MasterVendorView.vue'), meta: { nav: true, module: 'master' } },
    { path: '/master-vendor/form', name: 'master-vendor-form', component: () => import('../views/MasterVendorFormView.vue'), meta: { module: 'master' } },
    { path: '/produk', name: 'produk', component: () => import('../views/ProdukView.vue'), meta: { nav: true, module: 'produk' } },
    { path: '/produk/form', name: 'produk-form', component: () => import('../views/ProdukFormView.vue'), meta: { module: 'produk' } },
    { path: '/produk/:id(\\d+)', name: 'produk-detail', component: () => import('../views/ProdukDetailView.vue'), props: true, meta: { module: 'produk' } },
    { path: '/dr', name: 'dr', component: () => import('../views/DrView.vue'), meta: { nav: true, module: ['dr', 'dr_lihat'] } },
    { path: '/report', name: 'report', component: () => import('../views/ReportView.vue'), meta: { nav: true, module: ['report', 'rssp', 'report_procurement'] } }, // Menu Report: Report Marketing (OM, BM, CEO) dan/atau Report RSSP (Purchasing, OM, CEO)
    { path: '/report/procurement', name: 'report-procurement', component: () => import('../views/ReportProcurementView.vue'), meta: { module: 'report_procurement' } },
    { path: '/report/rssp', name: 'report-rssp', component: () => import('../views/ReportRsspView.vue'), meta: { module: 'rssp' } },
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
    { path: '/trucking-ti', name: 'trucking-ti', component: () => import('../views/TruckingTiView.vue'), meta: { nav: true, module: 'trucking_ti' } },
    { path: '/trucking-ti/baru', name: 'trucking-ti-baru', component: () => import('../views/TruckingTiFormView.vue'), meta: { module: 'trucking_ti' } },
    { path: '/trucking-ti/:id(\\d+)', name: 'trucking-ti-form', component: () => import('../views/TruckingTiFormView.vue'), props: true, meta: { module: 'trucking_ti' } },
    { path: '/trucking-ti/:id(\\d+)/terima', name: 'trucking-ti-terima', component: () => import('../views/TruckingTiTerimaView.vue'), props: true, meta: { module: 'trucking_ti' } },
    { path: '/trucking-ti/:id(\\d+)/cetak-received', name: 'trucking-ti-cetak-received', component: () => import('../views/TruckingTiCetakReceivedView.vue'), props: true, meta: { module: 'trucking_ti' } },
    { path: '/trucking-ti/:id(\\d+)/cetak', name: 'trucking-ti-cetak', component: () => import('../views/TruckingTiCetakView.vue'), props: true, meta: { module: 'trucking_ti' } },
    { path: '/trucking-dr', name: 'trucking-dr', component: () => import('../views/TruckingDrView.vue'), meta: { nav: true, module: 'trucking_dr' } },
    { path: '/trucking-dr/:id(\\d+)', name: 'trucking-dr-detail', component: () => import('../views/TruckingDrDetailView.vue'), props: true, meta: { module: 'trucking_dr' } },
    { path: '/adjustment-stock', name: 'adjustment-stock', component: () => import('../views/AdjustmentStockView.vue'), meta: { nav: true, module: 'adjustment_stock' } },
    { path: '/adjustment-stock/baru', name: 'adjustment-stock-baru', component: () => import('../views/AdjustmentStockFormView.vue'), meta: { module: 'adjustment_stock' } },
    { path: '/adjustment-stock/:id(\\d+)', name: 'adjustment-stock-detail', component: () => import('../views/AdjustmentStockDetailView.vue'), props: true, meta: { module: 'adjustment_stock' } },
    { path: '/po-supplier', name: 'po-supplier', component: () => import('../views/PoSupplierView.vue'), meta: { nav: true, module: 'po_supplier' } },
    { path: '/po-supplier/baru', name: 'po-supplier-baru', component: () => import('../views/PoSupplierFormView.vue'), meta: { module: 'po_supplier' } },
    { path: '/po-supplier/:id(\\d+)/ubah', name: 'po-supplier-ubah', component: () => import('../views/PoSupplierFormView.vue'), props: true, meta: { module: 'po_supplier' } },
    { path: '/po-supplier/:id(\\d+)/ubah-harga', name: 'po-supplier-ubah-harga', component: () => import('../views/PoSupplierFormView.vue'), props: (r) => ({ id: r.params.id, mode: 'harga' }), meta: { module: 'po_supplier' } },
    { path: '/po-supplier/:id(\\d+)/close', name: 'po-supplier-close', component: () => import('../views/PoSupplierCloseView.vue'), props: true, meta: { module: 'po_supplier' } },
    { path: '/po-supplier/:id(\\d+)/shipping', name: 'po-supplier-shipping', component: () => import('../views/PoSupplierShipView.vue'), props: true, meta: { module: 'po_supplier' } },
    { path: '/po-supplier/:id(\\d+)/shipping/:sr(\\d+)/cetak', name: 'po-supplier-shipping-cetak', component: () => import('../views/PoSupplierSiCetakView.vue'), props: true, meta: { module: 'po_supplier' } },
    { path: '/po-supplier/:id(\\d+)', name: 'po-supplier-detail', component: () => import('../views/PoSupplierDetailView.vue'), props: true, meta: { module: 'po_supplier' } },
    { path: '/po-supplier/:id(\\d+)/terima', name: 'po-supplier-terima', component: () => import('../views/PoSupplierTerimaView.vue'), props: true, meta: { module: 'po_supplier' } },
    { path: '/po-supplier/:id(\\d+)/terima/form/:ri(\\d+)?', name: 'po-supplier-terima-form', component: () => import('../views/PoSupplierTerimaFormView.vue'), props: true, meta: { module: 'po_supplier' } },
    { path: '/po-supplier/:id(\\d+)/loss-gain', name: 'po-supplier-loss-gain', component: () => import('../views/PoSupplierLossGainView.vue'), props: true, meta: { module: 'po_supplier' } },
    { path: '/po-supplier/:id(\\d+)/cetak', name: 'po-supplier-cetak', component: () => import('../views/PoSupplierCetakView.vue'), props: true, meta: { module: 'po_supplier' } },
    { path: '/dr/:id(\\d+)', name: 'dr-detail', component: () => import('../views/DrDetailView.vue'), props: true, meta: { module: ['dr', 'dr_lihat'] } },
    { path: '/harga', name: 'harga', component: () => import('../views/HargaView.vue'), meta: { nav: true, module: 'harga' } },
    { path: '/harga/detail', name: 'harga-detail', component: () => import('../views/HargaDetailView.vue'), meta: { module: 'harga' } },
    { path: '/harga/form', name: 'harga-form', component: () => import('../views/HargaFormView.vue'), meta: { module: 'harga' } },
    { path: '/po', name: 'po', component: () => import('../views/PoView.vue'), meta: { nav: true, module: 'po' } },
    { path: '/po/:id(\\d+)', name: 'po-detail', component: () => import('../views/PoDetailView.vue'), props: true, meta: { module: 'po' } },
    { path: '/po-batu-supplier', name: 'po-batu-supplier', component: () => import('../views/PoBatuSupplierView.vue'), meta: { nav: true, module: 'po_supplier_batu' } },
    { path: '/po-batu-supplier/baru', name: 'po-batu-supplier-baru', component: () => import('../views/PoBatuSupplierFormView.vue'), meta: { module: 'po_supplier_batu' } },
    { path: '/po-batu-supplier/:id(\\d+)/ubah', name: 'po-batu-supplier-ubah', component: () => import('../views/PoBatuSupplierFormView.vue'), props: true, meta: { module: 'po_supplier_batu' } },
    { path: '/po-batu-supplier/:id(\\d+)/cetak', name: 'po-batu-supplier-cetak', component: () => import('../views/PoBatuCetakView.vue'), props: true, meta: { module: 'po_supplier_batu' } },
    { path: '/po-batu-supplier/:id(\\d+)', name: 'po-batu-supplier-detail', component: () => import('../views/PoBatuSupplierDetailView.vue'), props: true, meta: { module: 'po_supplier_batu' } },
    { path: '/po-batu', name: 'po-batu', component: () => import('../views/PoBatuView.vue'), meta: { nav: true, module: 'po_batu' } },
    { path: '/po-batu/:id(\\d+)/cetak', name: 'po-batu-cetak', component: () => import('../views/PoBatuCetakView.vue'), props: true, meta: { module: 'po_batu' } },
    { path: '/po-batu/:id(\\d+)', name: 'po-batu-detail', component: () => import('../views/PoBatuDetailView.vue'), props: true, meta: { module: 'po_batu' } },
    { path: '/shipping', name: 'shipping', component: () => import('../views/ShippingView.vue'), meta: { nav: true, module: 'shipping' } },
    { path: '/shipping/:id(\\d+)', name: 'shipping-detail', component: () => import('../views/ShippingDetailView.vue'), props: true, meta: { module: 'shipping' } },
    { path: '/riwayat', name: 'riwayat', component: () => import('../views/HistoryView.vue'), meta: { nav: true } },
    { path: '/profil', name: 'profil', component: () => import('../views/ProfileView.vue'), meta: { nav: true } },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach((to, from) => {
  // Detail masuk dari kanan, kembali ke kiri; antar tab memudar naik.
  navDir.value = ['approval-detail', 'shipping-detail', 'trucking-detail', 'oa-detail', 'forecast-cabang', 'dr-detail', 'trucking-dr-detail', 'po-detail', 'po-batu-detail', 'po-batu-cetak', 'po-batu-supplier-detail', 'po-batu-supplier-cetak', 'po-batu-supplier-baru', 'po-batu-supplier-ubah', 'po-supplier-detail', 'po-supplier-cetak', 'trucking-ti-baru', 'trucking-ti-form', 'trucking-ti-cetak', 'trucking-ti-cetak-received', 'trucking-ti-terima', 'po-supplier-baru', 'po-supplier-ubah', 'po-supplier-ubah-harga', 'po-supplier-close', 'po-supplier-shipping', 'po-supplier-shipping-cetak', 'po-supplier-terima', 'po-supplier-terima-form', 'po-supplier-loss-gain', 'harga-detail', 'harga-form', 'produk-detail', 'produk-form', 'master-terminal-form', 'master-vendor-form'].includes(to.name) ? 'slide-left' : ['approval-detail', 'shipping-detail', 'trucking-detail', 'oa-detail', 'forecast-cabang', 'dr-detail', 'trucking-dr-detail', 'po-detail', 'po-supplier-detail', 'po-supplier-cetak', 'po-supplier-baru', 'po-supplier-ubah', 'po-supplier-ubah-harga', 'po-supplier-close', 'po-supplier-terima', 'po-supplier-terima-form', 'po-supplier-loss-gain', 'harga-detail', 'harga-form', 'produk-detail', 'produk-form', 'master-terminal-form', 'master-vendor-form'].includes(from.name) ? 'slide-right' : 'fade-up'
  if (!to.meta.public && !tokenStore.get()) return { name: 'login' }
  if (to.name === 'login' && tokenStore.get()) return { name: 'home' }
  // Modul yang tidak tersedia untuk peran ini kembali ke Beranda.
  const mods = JSON.parse(localStorage.getItem('orcax_user') || 'null')?.modules
  const need = to.meta.module || (['approval', 'approval-detail', 'riwayat'].includes(to.name) ? 'penawaran' : '')
  if (need && Array.isArray(mods) && !(Array.isArray(need) ? need.some((m) => mods.includes(m)) : mods.includes(need))) return { name: 'home' }
})

export default router
