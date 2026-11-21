<?php

use App\Http\Controllers\AdjustmentStockController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BadgeController;
use App\Http\Controllers\DrController;
use App\Http\Controllers\FaceController;
use App\Http\Controllers\HargaController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ShippingController;
use App\Http\Controllers\TruckingController;
use App\Http\Controllers\TruckingTiController;
use App\Http\Controllers\TruckingTiTerimaController;
use App\Http\Controllers\TruckingDrController;
use App\Http\Middleware\ModuleAccess;
use App\Http\Controllers\PenawaranController;
use App\Http\Controllers\MasterTerminalController;
use App\Http\Controllers\MasterVendorController;
use App\Http\Controllers\MobilController;
use App\Http\Controllers\PoController;
use App\Http\Controllers\LcrController;
use App\Http\Controllers\AksesController;
use App\Http\Controllers\PengumumanController;
use App\Http\Controllers\SistemController;
use App\Http\Controllers\PermohonanController;
use App\Http\Controllers\ProdukController;
use App\Http\Controllers\ForecastController;
use App\Http\Controllers\ForecastBmController;
use App\Http\Controllers\ForecastOmController;
use App\Http\Controllers\RequestOaController;
use App\Http\Controllers\ReportMarketingController;
use App\Http\Controllers\ReportProcurementController;
use App\Http\Controllers\ReportRsspController;
use App\Http\Controllers\RsspInputController;
use App\Http\Controllers\RefundPenerimaController;
use App\Http\Controllers\ReservasiController;
use App\Http\Controllers\SalesConfirmationController;
use App\Http\Controllers\VerifikasiCustomerController;
use App\Http\Controllers\PenggunaController;
use App\Http\Controllers\PoSupplierCloseController;
use App\Http\Controllers\PoSupplierController;
use App\Http\Controllers\PoSupplierFormController;
use App\Http\Controllers\PoSupplierLossGainController;
use App\Http\Controllers\PoSupplierShipController;
use App\Http\Controllers\PoSupplierTerimaController;
use App\Http\Controllers\ProfilePhotoController;
use App\Http\Controllers\PushController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::get('status', [SistemController::class, 'status'])->middleware('throttle:60,1');

    Route::middleware(['auth:sanctum', ModuleAccess::class.':sistem'])->prefix('sistem')->group(function () {
        Route::get('ringkasan', [SistemController::class, 'ringkasan']);
        Route::get('kesehatan', [SistemController::class, 'kesehatan']);
        Route::get('wajah', [SistemController::class, 'wajah']);
        Route::delete('wajah/{id}', [SistemController::class, 'resetWajah'])->whereNumber('id')->middleware('throttle:30,1');
        Route::get('log', [SistemController::class, 'log']);
        Route::get('pengaturan', [SistemController::class, 'pengaturan']);
        Route::put('pengaturan', [SistemController::class, 'simpanPengaturan'])->middleware('throttle:20,1');
        Route::get('pengguna', [PenggunaController::class, 'index']);
        Route::get('pengguna/opsi', [PenggunaController::class, 'opsi']);
        Route::post('pengguna', [PenggunaController::class, 'store'])->middleware('throttle:30,1');
        Route::get('pengguna/{id}', [PenggunaController::class, 'show'])->whereNumber('id');
        Route::put('pengguna/{id}', [PenggunaController::class, 'update'])->whereNumber('id')->middleware('throttle:60,1');
        Route::put('pengguna/{id}/peran', [PenggunaController::class, 'ubahPeran'])->whereNumber('id')->middleware('throttle:30,1');
        Route::delete('pengguna/{id}', [PenggunaController::class, 'destroy'])->whereNumber('id')->middleware('throttle:20,1');
        Route::put('pengguna/{id}/password', [PenggunaController::class, 'aturPassword'])->whereNumber('id')->middleware('throttle:20,1');
        Route::post('pengguna/{id}/reset-password', [PenggunaController::class, 'resetPassword'])->whereNumber('id')->middleware('throttle:20,1');
        Route::get('akses', [AksesController::class, 'index']);
        Route::put('akses/{modul}', [AksesController::class, 'update'])->where('modul', '[a-z_]+')->middleware('throttle:30,1');
        Route::delete('akses/{modul}', [AksesController::class, 'reset'])->where('modul', '[a-z_]+')->middleware('throttle:30,1');
    });

    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
        Route::post('face-login', [FaceController::class, 'login'])->middleware('throttle:20,1');

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
        });
    });

    Route::middleware('auth:sanctum')->prefix('face')->group(function () {
        Route::get('/', [FaceController::class, 'status']);
        Route::post('/', [FaceController::class, 'enroll'])->middleware('throttle:10,1');
        Route::delete('/', [FaceController::class, 'destroy'])->middleware('throttle:10,1');
    });

    Route::middleware('auth:sanctum')->prefix('profile/photo')->group(function () {
        Route::get('/', [ProfilePhotoController::class, 'show']);
        Route::post('/', [ProfilePhotoController::class, 'store'])->middleware('throttle:10,1');
        Route::delete('/', [ProfilePhotoController::class, 'destroy'])->middleware('throttle:10,1');
    });

    Route::middleware('auth:sanctum')->prefix('push')->group(function () {
        Route::get('key', [PushController::class, 'key']);
        Route::get('status', [PushController::class, 'status']);
        Route::post('subscribe', [PushController::class, 'subscribe'])->middleware('throttle:20,1');
        Route::post('unsubscribe', [PushController::class, 'unsubscribe'])->middleware('throttle:20,1');
        Route::post('test', [PushController::class, 'test'])->middleware('throttle:5,1');
    });

    Route::middleware('auth:sanctum')->get('badges', BadgeController::class);

    Route::middleware(['auth:sanctum', ModuleAccess::class.':po_supplier'])->prefix('po-supplier')->group(function () {
        Route::get('opsi', [PoSupplierController::class, 'opsi']);
        Route::get('akun-accurate', [PoSupplierLossGainController::class, 'akun']);
        Route::get('form-opsi', [PoSupplierFormController::class, 'opsi']);
        Route::get('barang-accurate', [PoSupplierFormController::class, 'barang']);
        Route::post('hitung', [PoSupplierFormController::class, 'hitung']);
        Route::post('/', [PoSupplierFormController::class, 'store'])->middleware('throttle:20,1');
        Route::get('kapal', [PoSupplierShipController::class, 'kapal']);
        Route::get('{id}/shipping', [PoSupplierShipController::class, 'show'])->whereNumber('id');
        Route::post('{id}/shipping', [PoSupplierShipController::class, 'store'])->whereNumber('id')->middleware('throttle:20,1');
        Route::put('{id}/shipping/{sr}', [PoSupplierShipController::class, 'update'])->whereNumber(['id', 'sr'])->middleware('throttle:20,1');
        Route::get('{id}/shipping/{sr}/cetak', [PoSupplierShipController::class, 'cetak'])->whereNumber(['id', 'sr']);
        Route::post('{id}/shipping/{sr}/cancel', [PoSupplierShipController::class, 'cancel'])->whereNumber(['id', 'sr'])->middleware('throttle:20,1');
        Route::get('{id}/close', [PoSupplierCloseController::class, 'form'])->whereNumber('id');
        Route::post('{id}/close', [PoSupplierCloseController::class, 'store'])->whereNumber('id')->middleware('throttle:20,1');
        Route::get('{id}/ubah', [PoSupplierFormController::class, 'ubah'])->whereNumber('id');
        Route::post('{id}/harga-sementara', [PoSupplierFormController::class, 'hargaSementara'])->whereNumber('id')->middleware('throttle:20,1');
        Route::get('{id}/ubah-harga', [PoSupplierFormController::class, 'ubahHarga'])->whereNumber('id');
        Route::put('{id}/ubah-harga', [PoSupplierFormController::class, 'simpanHarga'])->whereNumber('id')->middleware('throttle:20,1');
        Route::put('{id}', [PoSupplierFormController::class, 'update'])->whereNumber('id')->middleware('throttle:20,1');
        Route::get('/', [PoSupplierController::class, 'index']);
        Route::get('{id}', [PoSupplierController::class, 'show'])->whereNumber('id');
        Route::get('{id}/cetak', [PoSupplierController::class, 'cetak'])->whereNumber('id');
        Route::get('{id}/terima', [PoSupplierTerimaController::class, 'index'])->whereNumber('id');
        Route::get('{id}/terima/form', [PoSupplierTerimaController::class, 'form'])->whereNumber('id');
        Route::post('{id}/terima', [PoSupplierTerimaController::class, 'store'])->whereNumber('id')->middleware('throttle:30,1');
        Route::get('{id}/terima/{ri}', [PoSupplierTerimaController::class, 'form'])->whereNumber(['id', 'ri']);
        Route::put('{id}/terima/{ri}', [PoSupplierTerimaController::class, 'update'])->whereNumber(['id', 'ri'])->middleware('throttle:30,1');
        Route::post('{id}/terima/{ri}', [PoSupplierTerimaController::class, 'update'])->whereNumber(['id', 'ri'])->middleware('throttle:30,1'); // multipart dengan _method=PUT
        Route::delete('{id}/terima/{ri}', [PoSupplierTerimaController::class, 'destroy'])->whereNumber(['id', 'ri'])->middleware('throttle:30,1');
        Route::get('{id}/terima/{ri}/riwayat', [PoSupplierTerimaController::class, 'riwayat'])->whereNumber(['id', 'ri']);
        Route::get('{id}/loss-gain', [PoSupplierLossGainController::class, 'form'])->whereNumber('id');
        Route::post('{id}/loss-gain', [PoSupplierLossGainController::class, 'store'])->whereNumber('id')->middleware('throttle:20,1');
        Route::get('{id}/loss-gain/{gl}/lampiran', [PoSupplierLossGainController::class, 'lampiran'])->whereNumber(['id', 'gl']);
        Route::get('{id}/terima/{ri}/lampiran', [PoSupplierTerimaController::class, 'lampiran'])->whereNumber(['id', 'ri']);
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':trucking_ti'])->prefix('trucking-ti')->group(function () {
        Route::get('opsi', [TruckingTiController::class, 'opsi']);
        Route::get('transportir', [TruckingTiController::class, 'transportir']);
        Route::get('ongkos', [TruckingTiController::class, 'ongkos']);
        Route::get('baru', [TruckingTiController::class, 'baru']);
        Route::get('/', [TruckingTiController::class, 'index']);
        Route::post('/', [TruckingTiController::class, 'store'])->middleware('throttle:20,1');
        Route::get('{id}', [TruckingTiController::class, 'show'])->whereNumber('id');
        Route::put('{id}', [TruckingTiController::class, 'update'])->whereNumber('id')->middleware('throttle:20,1');
        Route::post('{id}/cancel', [TruckingTiController::class, 'cancel'])->whereNumber('id')->middleware('throttle:20,1');
        Route::get('{id}/cetak', [TruckingTiController::class, 'cetak'])->whereNumber('id');
        Route::get('{id}/terima', [TruckingTiTerimaController::class, 'show'])->whereNumber('id');
        Route::post('{id}/terima', [TruckingTiTerimaController::class, 'received'])->whereNumber('id')->middleware('throttle:20,1');
        Route::post('{id}/ds/{dsd}/approve-cancel', [TruckingTiTerimaController::class, 'approveCancel'])->whereNumber(['id', 'dsd'])->middleware('throttle:20,1');
        Route::get('{id}/prd/{prd}/sj', [TruckingTiTerimaController::class, 'lampiran'])->whereNumber(['id', 'prd']);
        Route::get('{id}/cetak-received', [TruckingTiTerimaController::class, 'cetak'])->whereNumber('id');
        Route::get('{id}/prd/{prd}/accurate', [TruckingTiTerimaController::class, 'detailAccurate'])->whereNumber(['id', 'prd']);
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':adjustment_stock'])->prefix('adjustment-stock')->group(function () {
        Route::get('opsi', [AdjustmentStockController::class, 'opsi']);
        Route::get('form-opsi', [AdjustmentStockController::class, 'formOpsi']);
        Route::get('terminal', [AdjustmentStockController::class, 'terminal']);
        Route::get('po-transfer', [AdjustmentStockController::class, 'poTransfer']);
        Route::post('/', [AdjustmentStockController::class, 'store'])->middleware('throttle:20,1');
        Route::get('/', [AdjustmentStockController::class, 'index']);
        Route::get('{id}', [AdjustmentStockController::class, 'show'])->whereNumber('id');
        Route::delete('{id}', [AdjustmentStockController::class, 'destroy'])->whereNumber('id')->middleware('throttle:20,1');
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':inventory'])->prefix('inventory')->group(function () {
        Route::get('/', [InventoryController::class, 'index']);
        Route::get('filter', [InventoryController::class, 'filters']);
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':stok_terminal'])->get('inventory/terminal', [InventoryController::class, 'terminals']);

    Route::middleware(['auth:sanctum', ModuleAccess::class.':reservasi'])->prefix('reservasi')->group(function () {
        Route::get('/', [ReservasiController::class, 'index']);
        Route::post('/', [ReservasiController::class, 'store'])->middleware('throttle:30,1');
        Route::put('{id}', [ReservasiController::class, 'update'])->whereNumber('id')->middleware('throttle:30,1');
        Route::delete('{id}', [ReservasiController::class, 'destroy'])->whereNumber('id')->middleware('throttle:30,1');
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':mobil'])->prefix('mobil')->group(function () {
        Route::get('/', [MobilController::class, 'index']);
        Route::post('/', [MobilController::class, 'store'])->middleware('throttle:30,1');
        Route::put('{id}', [MobilController::class, 'update'])->whereNumber('id')->middleware('throttle:30,1');
        Route::delete('{id}', [MobilController::class, 'destroy'])->whereNumber('id')->middleware('throttle:30,1');
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':master'])->prefix('master')->group(function () {
        Route::get('terminal/options', [MasterTerminalController::class, 'options']);
        Route::get('terminal', [MasterTerminalController::class, 'index']);
        Route::get('terminal/{id}', [MasterTerminalController::class, 'show'])->whereNumber('id');
        Route::post('terminal', [MasterTerminalController::class, 'store'])->middleware('throttle:30,1');
        Route::put('terminal/{id}', [MasterTerminalController::class, 'update'])->whereNumber('id')->middleware('throttle:30,1');
        Route::get('vendor/kode-terakhir', [MasterVendorController::class, 'lastCode'])->middleware('throttle:20,1');
        Route::get('vendor', [MasterVendorController::class, 'index']);
        Route::get('vendor/{id}', [MasterVendorController::class, 'show'])->whereNumber('id');
        Route::post('vendor', [MasterVendorController::class, 'store'])->middleware('throttle:30,1');
        Route::put('vendor/{id}', [MasterVendorController::class, 'update'])->whereNumber('id')->middleware('throttle:30,1');
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':produk'])->prefix('produk')->group(function () {
        Route::get('/', [ProdukController::class, 'index']);
        Route::get('{id}', [ProdukController::class, 'show'])->whereNumber('id');
        Route::post('/', [ProdukController::class, 'store'])->middleware('throttle:30,1');
        Route::put('{id}', [ProdukController::class, 'update'])->whereNumber('id')->middleware('throttle:30,1');
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':harga'])->prefix('harga')->group(function () {
        Route::get('/', [HargaController::class, 'index']);
        Route::get('options', [HargaController::class, 'options']);
        Route::get('item', [HargaController::class, 'show']);
        Route::post('/', [HargaController::class, 'store'])->middleware('throttle:30,1');
        Route::put('item', [HargaController::class, 'update'])->middleware('throttle:30,1');
        Route::delete('item', [HargaController::class, 'destroy'])->middleware('throttle:30,1');
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':po'])->prefix('po')->group(function () {
        Route::get('/', [PoController::class, 'index']);
        Route::get('{id}', [PoController::class, 'show'])->whereNumber('id');
        Route::post('{id}/keputusan', [PoController::class, 'decide'])->whereNumber('id')->middleware('throttle:30,1');
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':shipping'])->prefix('shipping')->group(function () {
        Route::get('/', [ShippingController::class, 'index']);
        Route::get('{id}', [ShippingController::class, 'show'])->whereNumber('id');
        Route::post('{id}/keputusan', [ShippingController::class, 'decide'])->whereNumber('id')->middleware('throttle:30,1');
    });

    // Lihat saja (CEO: dr_lihat) dan peran verifikator (dr) memakai GET yang sama; semua yang mengubah data hanya modul dr.
    Route::middleware(['auth:sanctum', ModuleAccess::class.':dr|dr_lihat'])->prefix('dr')->group(function () {
        Route::get('/', [DrController::class, 'index']);
        Route::get('{id}', [DrController::class, 'show'])->whereNumber('id');
        Route::get('{id}/history/{penawaran}', [DrController::class, 'historyApprove'])->whereNumber('id')->whereNumber('penawaran');
    });
    Route::middleware(['auth:sanctum', ModuleAccess::class.':dr'])->prefix('dr')->group(function () {
        Route::post('{id}/verifikasi', [DrController::class, 'verify'])->whereNumber('id')->middleware('throttle:30,1');
        Route::get('{id}/po-supplier', [DrController::class, 'poSupplier'])->whereNumber('id');
        Route::post('{id}/revisi', [DrController::class, 'revise'])->whereNumber('id')->middleware('throttle:30,1');
        Route::post('{id}/kembali-dp', [DrController::class, 'returnToDp'])->whereNumber('id')->middleware('throttle:10,1');
        Route::post('{id}/reset-split', [DrController::class, 'resetSplit'])->whereNumber('id')->middleware('throttle:10,1');
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':trucking_dr'])->prefix('trucking-dr')->group(function () {
        Route::get('/', [TruckingDrController::class, 'index']);
        Route::get('{id}', [TruckingDrController::class, 'show'])->whereNumber('id');
        Route::get('{id}/po-supplier', [TruckingDrController::class, 'poSupplier'])->whereNumber('id');
        Route::post('{id}/verifikasi', [TruckingDrController::class, 'verify'])->whereNumber('id')->middleware('throttle:30,1');
        Route::post('{id}/revisi', [TruckingDrController::class, 'revise'])->whereNumber('id')->middleware('throttle:30,1');
        Route::post('{id}/kembali-dp', [TruckingDrController::class, 'returnToDp'])->whereNumber('id')->middleware('throttle:10,1');
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':report'])->prefix('report/marketing')->group(function () {
        Route::get('cabang', [ReportMarketingController::class, 'cabangList']);
        Route::get('volume', [ReportMarketingController::class, 'volume']);
        Route::get('volume/detail', [ReportMarketingController::class, 'volumeDetail']);
        Route::get('aktif', [ReportMarketingController::class, 'aktif']);
        Route::get('aktif/detail', [ReportMarketingController::class, 'aktifDetail']);
        Route::get('baru', [ReportMarketingController::class, 'baru']);
        Route::get('baru/detail', [ReportMarketingController::class, 'baruDetail']);
        Route::get('kegiatan', [ReportMarketingController::class, 'kegiatan']);
        Route::get('kegiatan/list', [ReportMarketingController::class, 'kegiatanList']);
        Route::get('kegiatan/{id}', [ReportMarketingController::class, 'kegiatanShow'])->whereNumber('id');
        Route::get('kegiatan/{id}/berkas/{fid}', [ReportMarketingController::class, 'kegiatanBerkas'])->whereNumber('id')->whereNumber('fid');
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':report_procurement'])->prefix('report/procurement')->group(function () {
        Route::get('opsi', [ReportProcurementController::class, 'opsi']);
        Route::get('loaded-truck', [ReportProcurementController::class, 'loadedTruckList']);
        Route::get('loaded-truck/export', [ReportProcurementController::class, 'loadedTruckExport'])->middleware('throttle:20,1');
        Route::get('loaded-kapal', [ReportProcurementController::class, 'loadedKapalList']);
        Route::get('loaded-kapal/export', [ReportProcurementController::class, 'loadedKapalExport'])->middleware('throttle:20,1');
        Route::get('stok-truck', [ReportProcurementController::class, 'stokTruckList']);
        Route::put('stok-truck/{id}', [ReportProcurementController::class, 'stokTruckUbahTanggal'])->whereNumber('id')->middleware('throttle:30,1');
        Route::get('stok-operasional', [ReportProcurementController::class, 'stokOperasionalList']);
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':rssp'])->prefix('report/rssp')->group(function () {
        Route::get('cabang', [ReportRsspController::class, 'cabangList']);
        Route::get('/', [ReportRsspController::class, 'index']);
        Route::get('detail', [ReportRsspController::class, 'detail']);
        Route::get('export', [ReportRsspController::class, 'export'])->middleware('throttle:20,1');
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':rssp_input'])->prefix('report/rssp/input')->group(function () {
        Route::get('/', [RsspInputController::class, 'show']);
        Route::post('/', [RsspInputController::class, 'save'])->middleware('throttle:60,1');
        Route::post('hapus', [RsspInputController::class, 'destroy'])->middleware('throttle:60,1');
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':oa'])->prefix('request-oa')->group(function () {
        Route::get('/', [RequestOaController::class, 'index']);
        Route::get('{id}', [RequestOaController::class, 'show'])->whereNumber('id');
        Route::post('{id}/keputusan', [RequestOaController::class, 'decide'])->whereNumber('id')->middleware('throttle:20,1');
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':pengumuman'])->prefix('pengumuman')->group(function () {
        Route::get('/', [PengumumanController::class, 'index']);
        Route::post('baca-semua', [PengumumanController::class, 'bacaSemua'])->middleware('throttle:30,1');
        Route::get('{id}', [PengumumanController::class, 'show'])->whereNumber('id');
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':pengumuman_admin'])->prefix('pengumuman-admin')->group(function () {
        Route::get('/', [PengumumanController::class, 'adminIndex']);
        Route::get('peran', [PengumumanController::class, 'peran']);
        Route::get('perkiraan', [PengumumanController::class, 'perkiraan']);
        Route::post('/', [PengumumanController::class, 'store'])->middleware('throttle:20,1');
        Route::put('{id}', [PengumumanController::class, 'update'])->whereNumber('id')->middleware('throttle:20,1');
        Route::get('perangkat', [PengumumanController::class, 'perangkat']);
        Route::get('{id}/pembaca', [PengumumanController::class, 'pembaca'])->whereNumber('id');
        Route::post('{id}/gandakan', [PengumumanController::class, 'gandakan'])->whereNumber('id')->middleware('throttle:20,1');
        Route::post('{id}/kirim', [PengumumanController::class, 'kirim'])->whereNumber('id')->middleware('throttle:10,1');
        Route::delete('{id}', [PengumumanController::class, 'destroy'])->whereNumber('id')->middleware('throttle:20,1');
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':forecast_bm'])->prefix('forecast-bm')->group(function () {
        Route::get('data', [ForecastBmController::class, 'data']);
        Route::get('customer', [ForecastBmController::class, 'customer']);
        Route::post('isi', [ForecastBmController::class, 'isi'])->middleware('throttle:120,1');
        Route::get('request', [ForecastBmController::class, 'requests']);
        Route::post('request', [ForecastBmController::class, 'requestStore'])->middleware('throttle:20,1');
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':forecast_om'])->prefix('forecast-om')->group(function () {
        Route::get('cabang', [ForecastOmController::class, 'cabang']);
        Route::get('menunggu', [ForecastOmController::class, 'menunggu']);
        Route::get('data', [ForecastOmController::class, 'data']);
        Route::get('customer', [ForecastOmController::class, 'customer']);
        Route::post('isi', [ForecastOmController::class, 'isi'])->middleware('throttle:120,1');
        Route::post('ajukan', [ForecastOmController::class, 'ajukan'])->middleware('throttle:20,1');
        Route::get('request', [ForecastOmController::class, 'requests']);
        Route::post('request/{id}/keputusan', [ForecastOmController::class, 'decideRequest'])->whereNumber('id')->middleware('throttle:30,1');
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':forecast'])->prefix('forecast')->group(function () {
        Route::get('menunggu', [ForecastController::class, 'menunggu']);
        Route::get('rekap', [ForecastController::class, 'rekap']);
        Route::get('cabang', [ForecastController::class, 'cabang']);
        Route::get('request', [ForecastController::class, 'requests']);
        Route::post('periode/setujui', [ForecastController::class, 'approvePeriode'])->middleware('throttle:20,1');
        Route::post('periode/tolak', [ForecastController::class, 'rejectPeriode'])->middleware('throttle:20,1');
        Route::post('request/{id}/setujui', [ForecastController::class, 'approveRequest'])->whereNumber('id')->middleware('throttle:20,1');
        Route::post('request/{id}/tolak', [ForecastController::class, 'rejectRequest'])->whereNumber('id')->middleware('throttle:20,1');
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':sc'])->prefix('sales-confirmation')->group(function () {
        Route::get('/', [SalesConfirmationController::class, 'index']);
        Route::get('{id}', [SalesConfirmationController::class, 'show'])->whereNumber('id');
        Route::get('{id}/berkas/{jenis}', [SalesConfirmationController::class, 'berkas'])->whereNumber('id')->where('jenis', 'po|unblock');
        Route::post('{id}/keputusan', [SalesConfirmationController::class, 'decide'])->whereNumber('id')->middleware('throttle:20,1');
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':permohonan'])->prefix('permohonan')->group(function () {
        Route::get('/', [PermohonanController::class, 'index']);
        Route::get('{id}', [PermohonanController::class, 'show'])->whereNumber('id');
        Route::get('{id}/berkas', [PermohonanController::class, 'berkas'])->whereNumber('id');
        Route::post('{id}/keputusan', [PermohonanController::class, 'decide'])->whereNumber('id')->middleware('throttle:20,1');
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':lcr'])->prefix('lcr')->group(function () {
        Route::get('/', [LcrController::class, 'index']);
        Route::get('{id}', [LcrController::class, 'show'])->whereNumber('id');
        Route::get('{id}/gambar/{jenis}/{idx?}', [LcrController::class, 'gambar'])->whereNumber('id')->whereNumber('idx');
        Route::post('{id}/keputusan', [LcrController::class, 'decide'])->whereNumber('id')->middleware('throttle:20,1');
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':refund'])->prefix('refund-penerima')->group(function () {
        Route::get('/', [RefundPenerimaController::class, 'index']);
        Route::get('{id}', [RefundPenerimaController::class, 'show'])->whereNumber('id');
        Route::get('{id}/berkas/{jenis}', [RefundPenerimaController::class, 'berkas'])->whereNumber('id')->where('jenis', 'ktp|npwp');
        Route::post('{id}/keputusan', [RefundPenerimaController::class, 'decide'])->whereNumber('id')->middleware('throttle:20,1');
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':verifikasi_customer'])->prefix('verifikasi-customer')->group(function () {
        Route::get('/', [VerifikasiCustomerController::class, 'index']);
        Route::get('{id}', [VerifikasiCustomerController::class, 'show'])->whereNumber('id');
        Route::get('{id}/berkas/{jenis}/{ref}', [VerifikasiCustomerController::class, 'berkas'])->whereNumber('id')->where('jenis', 'kyc|review|legal');
        Route::post('{id}/keputusan', [VerifikasiCustomerController::class, 'decide'])->whereNumber('id')->middleware('throttle:20,1');
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':trucking'])->prefix('trucking')->group(function () {
        Route::get('/', [TruckingController::class, 'index']);
        Route::get('{id}', [TruckingController::class, 'show'])->whereNumber('id');
        Route::post('{id}/keputusan', [TruckingController::class, 'decide'])->whereNumber('id')->middleware('throttle:30,1');
    });

    Route::middleware(['auth:sanctum', ModuleAccess::class.':penawaran'])->prefix('penawaran')->group(function () {
        Route::get('/', [PenawaranController::class, 'pending']);
        Route::get('ringkasan', [PenawaranController::class, 'ringkasan']);
        Route::get('riwayat', [PenawaranController::class, 'history']);
        Route::get('{id}', [PenawaranController::class, 'show'])->whereNumber('id');
        Route::post('{id}/keputusan', [PenawaranController::class, 'decide'])
            ->whereNumber('id')
            ->middleware('throttle:30,1');
    });
});
