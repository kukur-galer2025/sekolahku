<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Models\UserNotification;
use App\Services\Notify\WebPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Lonceng (daftar & tandai dibaca) dan pendaftaran push per HP. */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = UserNotification::query()->where('user_id', $request->user()->id);

        return response()->json([
            'unread' => (clone $q)->whereNull('read_at')->count(),
            'items' => $q->latest('id')->limit(40)->get(['id', 'type', 'title', 'body', 'url', 'read_at', 'created_at']),
            'push' => ['available' => WebPush::configured(), 'public_key' => config('services.webpush.public_key')],
        ]);
    }

    /** Dipanggil service worker saat HP dibangunkan push: pemberitahuan belum dibaca terbaru. */
    public function latest(Request $request): JsonResponse
    {
        $n = UserNotification::query()->where('user_id', $request->user()->id)->whereNull('read_at')->latest('id')->first(['id', 'title', 'body', 'url']);
        $unread = UserNotification::query()->where('user_id', $request->user()->id)->whereNull('read_at')->count();

        return response()->json(['item' => $n, 'unread' => $unread])->header('Cache-Control', 'no-store');
    }

    public function read(Request $request): JsonResponse
    {
        $d = $request->validate(['id' => ['nullable', 'integer']]);
        UserNotification::query()->where('user_id', $request->user()->id)->whereNull('read_at')
            ->when($d['id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Ditandai sudah dibaca.']);
    }

    public function subscribe(Request $request): JsonResponse
    {
        abort_unless(WebPush::configured(), 503, 'Notifikasi push belum diaktifkan oleh sekolah.');
        $d = $request->validate(['endpoint' => ['required', 'url', 'starts_with:https://', 'max:1000']]);
        $hash = hash('sha256', $d['endpoint']);

        // Satu HP = satu akun penerima: HP yang dipakai bergantian berpindah ke akun yang terakhir mengaktifkan.
        PushSubscription::query()->withoutGlobalScopes()->where('endpoint_hash', $hash)->delete();
        PushSubscription::create(['user_id' => $request->user()->id, 'endpoint_hash' => $hash, 'endpoint' => $d['endpoint'],
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 160)]);

        return response()->json(['message' => 'Notifikasi aktif di perangkat ini.'], 201);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $d = $request->validate(['endpoint' => ['required', 'string', 'max:1000']]);
        PushSubscription::query()->where('user_id', $request->user()->id)->where('endpoint_hash', hash('sha256', $d['endpoint']))->delete();

        return response()->json(['message' => 'Notifikasi dimatikan di perangkat ini.']);
    }
}
