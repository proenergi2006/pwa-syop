<?php

namespace App\Services;

use App\Http\Controllers\DrController;
use App\Http\Controllers\ForecastController;
use App\Http\Controllers\ForecastOmController;
use App\Http\Controllers\LcrController;
use App\Http\Controllers\PermohonanController;
use App\Http\Controllers\PoController;
use App\Http\Controllers\RequestOaController;
use App\Http\Controllers\SalesConfirmationController;
use App\Http\Controllers\ShippingController;
use App\Http\Controllers\TruckingController;
use App\Http\Controllers\RefundPenerimaController;
use App\Http\Controllers\TruckingDrController;
use App\Http\Controllers\VerifikasiCustomerController;
use App\Http\Middleware\ModuleAccess;
use App\Models\AclUser;

/** Jumlah item yang menunggu keputusan satu user, per modul (hanya modul yang boleh dipakai perannya). Dipakai /badges dan notifikasi push. */
class WaitingCounts
{
    public const MODULES = ['penawaran', 'po', 'shipping', 'trucking', 'dr', 'trucking_dr', 'verifikasi_customer', 'refund', 'permohonan', 'lcr', 'sc', 'oa', 'forecast', 'forecast_request', 'forecast_om', 'forecast_revisi'];

    /** Item yang menunggu per modul: id => kode dokumen (hanya modul yang boleh dipakai perannya). */
    public static function items(AclUser $user): array
    {
        $role = (int) $user->id_role;
        $can  = fn (string $m) => ModuleAccess::allows($m, $role);

        return [
            'penawaran' => $can('penawaran') ? app(PenawaranApprovalService::class)->waitingItems($user) : [],
            'po'        => $can('po') ? app(PoController::class)->waitingItems() : [],
            'shipping'  => $can('shipping') ? app(ShippingController::class)->waitingItems() : [],
            'trucking'  => $can('trucking') ? app(TruckingController::class)->waitingItems() : [],
            'dr'        => $can('dr') ? app(DrController::class)->waitingItems() : [],
            'trucking_dr' => $can('trucking_dr') ? app(TruckingDrController::class)->waitingItems() : [],
            'verifikasi_customer' => $can('verifikasi_customer') ? app(VerifikasiCustomerController::class)->waitingItems($user) : [],
            'refund' => $can('refund') ? app(RefundPenerimaController::class)->waitingItems($user) : [],
            'permohonan' => $can('permohonan') ? app(PermohonanController::class)->waitingItems($user) : [],
            'lcr' => $can('lcr') ? app(LcrController::class)->waitingItems($user) : [],
            'sc' => $can('sc') ? app(SalesConfirmationController::class)->waitingItems($user) : [],
            'oa' => $can('oa') ? app(RequestOaController::class)->waitingItems() : [],
            'forecast' => $can('forecast') ? app(ForecastController::class)->waitingItems() : [],
            'forecast_request' => $can('forecast') ? app(ForecastController::class)->waitingRequestItems() : [],
            'forecast_om' => $can('forecast_om') ? app(ForecastOmController::class)->waitingItems() : [],
            'forecast_revisi' => $can('forecast_om') ? app(ForecastOmController::class)->revisiItems() : [],
        ];
    }

    /** @return array{penawaran:int,po:int,shipping:int,trucking:int,dr:int,trucking_dr:int,verifikasi_customer:int,refund:int,permohonan:int,lcr:int,sc:int,oa:int,forecast:int,forecast_request:int,forecast_om:int,forecast_revisi:int} */
    public static function for(AclUser $user): array
    {
        $role = (int) $user->id_role;
        $can = fn (string $m) => ModuleAccess::allows($m, $role);

        return [
            'penawaran' => $can('penawaran') ? (int) app(PenawaranApprovalService::class)->ringkasan($user)['menunggu'] : 0,
            'po'        => $can('po') ? app(PoController::class)->waitingCount() : 0,
            'shipping'  => $can('shipping') ? app(ShippingController::class)->waitingCount() : 0,
            'trucking'  => $can('trucking') ? app(TruckingController::class)->waitingCount() : 0,
            'dr'        => $can('dr') ? app(DrController::class)->waitingCount() : 0,
            'trucking_dr' => $can('trucking_dr') ? app(TruckingDrController::class)->waitingCount() : 0,
            'verifikasi_customer' => $can('verifikasi_customer') ? app(VerifikasiCustomerController::class)->waitingCount($user) : 0,
            'refund' => $can('refund') ? app(RefundPenerimaController::class)->waitingCount($user) : 0,
            'permohonan' => $can('permohonan') ? app(PermohonanController::class)->waitingCount($user) : 0,
            'lcr' => $can('lcr') ? app(LcrController::class)->waitingCount($user) : 0,
            'sc' => $can('sc') ? app(SalesConfirmationController::class)->waitingCount($user) : 0,
            'oa' => $can('oa') ? app(RequestOaController::class)->waitingCount() : 0,
            'forecast' => $can('forecast') ? app(ForecastController::class)->waitingCount() : 0,
            'forecast_request' => $can('forecast') ? app(ForecastController::class)->waitingRequestCount() : 0,
            'forecast_om' => $can('forecast_om') ? app(ForecastOmController::class)->waitingCount() : 0,
            'forecast_revisi' => $can('forecast_om') ? app(ForecastOmController::class)->revisiCount() : 0,
        ];
    }
}
