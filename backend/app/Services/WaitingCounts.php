<?php

namespace App\Services;

use App\Http\Controllers\DrController;
use App\Http\Controllers\PoController;
use App\Http\Controllers\ShippingController;
use App\Http\Controllers\TruckingController;
use App\Http\Middleware\ModuleAccess;
use App\Models\AclUser;

/** Jumlah item yang menunggu keputusan satu user, per modul (hanya modul yang boleh dipakai perannya). Dipakai /badges dan notifikasi push. */
class WaitingCounts
{
    public const MODULES = ['penawaran', 'po', 'shipping', 'trucking', 'dr'];

    /** @return array{penawaran:int,po:int,shipping:int,trucking:int,dr:int} */
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
        ];
    }
}
