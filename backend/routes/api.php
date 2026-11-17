<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BadgeController;
use App\Http\Controllers\DrController;
use App\Http\Controllers\FaceController;
use App\Http\Controllers\HargaController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ShippingController;
use App\Http\Controllers\TruckingController;
use App\Http\Controllers\TruckingDrController;
use App\Http\Middleware\ModuleAccess;
use App\Http\Controllers\PenawaranController;
use App\Http\Controllers\MasterTerminalController;
use App\Http\Controllers\MasterVendorController;
use App\Http\Controllers\MobilController;
use App\Http\Controllers\PoController;
use App\Http\Controllers\ProdukController;
use App\Http\Controllers\ReservasiController;
use App\Http\Controllers\VerifikasiCustomerController;
use App\Http\Controllers\ProfilePhotoController;
use App\Http\Controllers\PushController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

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

    Route::middleware(['auth:sanctum', ModuleAccess::class.':dr'])->prefix('dr')->group(function () {
        Route::get('/', [DrController::class, 'index']);
        Route::get('{id}', [DrController::class, 'show'])->whereNumber('id');
        Route::post('{id}/verifikasi', [DrController::class, 'verify'])->whereNumber('id')->middleware('throttle:30,1');
        Route::get('{id}/po-supplier', [DrController::class, 'poSupplier'])->whereNumber('id');
        Route::get('{id}/history/{penawaran}', [DrController::class, 'historyApprove'])->whereNumber('id')->whereNumber('penawaran');
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

    Route::middleware(['auth:sanctum', ModuleAccess::class.':verifikasi_customer'])->prefix('verifikasi-customer')->group(function () {
        Route::get('/', [VerifikasiCustomerController::class, 'index']);
        Route::get('{id}', [VerifikasiCustomerController::class, 'show'])->whereNumber('id');
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
