<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Services\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushController extends Controller
{
    /** Kunci publik VAPID untuk pushManager.subscribe() di perangkat. */
    public function key(PushService $push): JsonResponse
    {
        return response()->json(['enabled' => $push->enabled(), 'public_key' => config('orca.push.public_key')]);
    }

    /** Daftar langganan perangkat ini (mengganti yang lama dengan endpoint sama). */
    public function subscribe(Request $request): JsonResponse
    {
        $d = $request->validate([
            'endpoint'    => ['required', 'string', 'url:https', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth'   => ['required', 'string', 'max:255'],
            'lang'        => ['nullable', 'in:id,en'],
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $d['endpoint'])],
            [
                'id_user'    => $request->user()->id_user,   // endpoint pindah akun = ikut akun yang login sekarang
                'endpoint'   => $d['endpoint'],
                'p256dh'     => $d['keys']['p256dh'],
                'auth'       => $d['keys']['auth'],
                'lang'       => $d['lang'] ?? 'id',
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ],
        );

        return response()->json(['subscribed' => true]);
    }

    /** Matikan notifikasi untuk perangkat ini. */
    public function unsubscribe(Request $request): JsonResponse
    {
        $d = $request->validate(['endpoint' => ['required', 'string']]);

        PushSubscription::where('endpoint_hash', hash('sha256', $d['endpoint']))
            ->where('id_user', $request->user()->id_user)
            ->delete();

        return response()->json(['subscribed' => false]);
    }

    /** Apakah endpoint ini terdaftar atas akun yang login (untuk status tombol). */
    public function status(Request $request): JsonResponse
    {
        $d = $request->validate(['endpoint' => ['required', 'string']]);

        return response()->json(['subscribed' => PushSubscription::where('endpoint_hash', hash('sha256', $d['endpoint']))
            ->where('id_user', $request->user()->id_user)->exists()]);
    }

    /** Kirim notifikasi uji ke semua perangkat milik user ini. */
    public function test(Request $request, PushService $push): JsonResponse
    {
        $n = $push->send([$request->user()->id_user], [
            'title' => 'ORCA X Mobile',
            'body'  => 'Notifikasi berfungsi. Anda akan diberi tahu saat ada penawaran menunggu.',
            'url'   => '/',
            'tag'   => 'test',
            'en'    => ['title' => 'ORCA X Mobile', 'body' => 'Notifications work. You will be alerted when an offer is waiting.'],
        ]);

        return response()->json(['sent' => $n]);
    }
}
