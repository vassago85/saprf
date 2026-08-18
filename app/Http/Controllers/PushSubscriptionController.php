<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST endpoints the browser hits after `PushManager.subscribe()`.
 *
 * Subscription payload from the browser is:
 *   {
 *     endpoint: "https://fcm.googleapis.com/…",
 *     keys: { p256dh: "…", auth: "…" }
 *   }
 *
 * We upsert on `endpoint` (unique) so the same device replaces its own
 * row rather than piling up duplicates when a user re-enables push.
 */
class PushSubscriptionController extends Controller
{
    public function vapidKey(): JsonResponse
    {
        return response()->json([
            'public_key' => config('webpush.vapid.public_key'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'max:500'],
            'keys.p256dh' => ['required', 'string', 'max:200'],
            'keys.auth' => ['required', 'string', 'max:100'],
        ]);

        $user = $request->user();

        $subscription = PushSubscription::updateOrCreate(
            ['endpoint' => $validated['endpoint']],
            [
                'user_id' => $user->id,
                'p256dh' => $validated['keys']['p256dh'],
                'auth' => $validated['keys']['auth'],
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'last_used_at' => now(),
            ],
        );

        return response()->json([
            'id' => $subscription->id,
            'saved' => true,
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'max:500'],
        ]);

        $deleted = PushSubscription::query()
            ->where('user_id', $request->user()->id)
            ->where('endpoint', $validated['endpoint'])
            ->delete();

        return response()->json(['deleted' => (bool) $deleted]);
    }
}
