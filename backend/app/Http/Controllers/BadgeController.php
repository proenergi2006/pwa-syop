<?php

namespace App\Http\Controllers;

use App\Services\WaitingCounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Jumlah item yang menunggu keputusan user, per modul (lencana di ikon menu). Hanya modul yang boleh dipakai perannya. */
class BadgeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(WaitingCounts::for($request->user()));
    }
}
