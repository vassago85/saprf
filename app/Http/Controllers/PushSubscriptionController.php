<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Notifications\Channels\WebPushChannel;
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

    /**
     * Self-service test push. Fires a canned "test notification" to every
     * subscription the current user has, and returns the WebPushChannel
     * fan-out counts so the profile page can render an accurate result:
     *   "Sent to 2 device(s)." vs
     *   "Push isn't configured on the server yet — please try again later."
     *
     * We deliberately don't leak the raw Log::warning payloads to the
     * client; the `reason` string is a stable enum-ish token
     * (no_subscriptions / library_missing / vapid_missing) that the JS
     * translates into the friendly copy.
     */
    public function test(Request $request, WebPushChannel $channel): JsonResponse
    {
        $user = $request->user();

        $result = $channel->sendTest($user);

        return response()->json([
            'sent' => $result['sent'],
            'pruned' => $result['pruned'],
            'failed' => $result['failed'],
            'reason' => $result['reason'] ?? null,
        ]);
    }
}
