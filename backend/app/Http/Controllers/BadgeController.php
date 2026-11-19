<?php

namespace App\Http\Controllers;

use App\Services\ClientVersion;
use App\Services\WaitingCounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Jumlah item yang menunggu keputusan user, per modul (lencana di ikon menu). Hanya modul yang boleh dipakai perannya. */
class BadgeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        ClientVersion::catat($request);

        // pengumuman belum dibaca dihitung terpisah: bukan "menunggu keputusan", jadi tidak ikut jumlah total approval
        return response()->json(WaitingCounts::for($request->user()) + ['pengumuman' => PengumumanController::belumDibaca($request->user())]);
    }
}
