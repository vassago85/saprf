<?php

namespace App\Notifications\Channels;

use App\Models\Announcement;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Sends a single announcement payload to every active push subscription
 * a user has. The public API is deliberately non-standard (we do NOT
 * plug this into Laravel's Notification::send pipeline) because our
 * delivery accounting is per-recipient-per-channel, tracked in the
 * announcement_deliveries rows, and the chunk job wants a synchronous
 * outcome per subscription.
 *
 * On a 404/410 response from the push service we prune the subscription
 * row — the endpoint is no longer valid (user cleared browser data,
 * uninstalled the PWA, etc.).
 *
 * The `minishlink/web-push` dependency is optional at Task 4 time — the
 * class defensively no-ops when it's not installed so the queue doesn't
 * die during the Task 4 → Task 5 gap.
 */
class WebPushChannel
{
    /**
     * @return array{sent: int, pruned: int, failed: int}
     */
    public function sendAnnouncement(User $user, Announcement $announcement): array
    {
        $payload = [
            'title' => 'SAPRF: ' . $announcement->title,
            'body' => \Illuminate\Support\Str::limit(strip_tags($announcement->body), 140),
            'url' => route('communications.show', $announcement),
            'category' => $announcement->category->value,
        ];

        return $this->sendPayload($user, $payload);
    }

    /**
     * Fire a self-test notification to every subscription this user has
     * registered on their devices. Exposed via /push/test so a member can
     * confirm end-to-end delivery is working before relying on it for
     * real announcements. Also gives support triage an instant "did you
     * see anything on your phone?" question when a member complains.
     *
     * @return array{sent: int, pruned: int, failed: int, reason?: string}
     */
    public function sendTest(User $user): array
    {
        return $this->sendPayload($user, [
            'title' => 'SAPRF: Test notification',
            'body' => 'Push notifications are working on this device. Tap to open the app.',
            'url' => url('/dashboard'),
            'category' => 'test',
        ]);
    }

    /**
     * Low-level primitive: fan a raw payload out to every push
     * subscription the user has. Handles VAPID guard, transport errors,
     * and 404/410 endpoint pruning. Both sendAnnouncement and sendTest
     * are just payload builders on top of this.
     *
     * The `reason` key on the return value is set only when the fan-out
     * short-circuited before contacting any push service — callers can
     * surface that verbatim to the caller (e.g. "vapid_missing" so the
     * profile page can show a "push isn't set up on the server" hint
     * without having to sniff logs).
     *
     * @param  array<string, mixed>  $payload
     * @return array{sent: int, pruned: int, failed: int, reason?: string}
     */
    public function sendPayload(User $user, array $payload): array
    {
        $subscriptions = $user->pushSubscriptions()->get();

        if ($subscriptions->isEmpty()) {
            return ['sent' => 0, 'pruned' => 0, 'failed' => 0, 'reason' => 'no_subscriptions'];
        }

        if (! class_exists(WebPush::class)) {
            Log::warning('WebPushChannel: minishlink/web-push is not installed; skipping push.', [
                'user_id' => $user->id,
            ]);

            return ['sent' => 0, 'pruned' => 0, 'failed' => $subscriptions->count(), 'reason' => 'library_missing'];
        }

        $vapid = config('webpush.vapid');
        if (empty($vapid['public_key']) || empty($vapid['private_key'])) {
            Log::warning('WebPushChannel: VAPID keys are not configured; skipping push.', [
                'user_id' => $user->id,
            ]);

            return ['sent' => 0, 'pruned' => 0, 'failed' => $subscriptions->count(), 'reason' => 'vapid_missing'];
        }

        // `new WebPush([...])` runs a strict validation on the VAPID
        // keypair inside the vendor library. A malformed key (wrong
        // length, non-base64url, etc.) throws — because that's a server
        // misconfiguration rather than a per-user failure, we surface
        // it as vapid_malformed so the operator sees it in logs while
        // members see a friendly "push isn't set up yet" message.
        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => $vapid['subject'],
                    'publicKey' => $vapid['public_key'],
                    'privateKey' => $vapid['private_key'],
                ],
            ]);
        } catch (Throwable $e) {
            Log::warning('WebPushChannel: VAPID keys are present but invalid; skipping push.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => 0, 'pruned' => 0, 'failed' => $subscriptions->count(), 'reason' => 'vapid_malformed'];
        }

        $encoded = json_encode($payload);

        $sent = 0;
        $pruned = 0;
        $failed = 0;

        foreach ($subscriptions as $subscription) {
            try {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $subscription->endpoint,
                        'publicKey' => $subscription->p256dh,
                        'authToken' => $subscription->auth,
                    ]),
                    $encoded,
                );
            } catch (Throwable $e) {
                Log::warning('WebPushChannel: queue error', ['error' => $e->getMessage()]);
                $failed++;
                continue;
            }
        }

        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getEndpoint();
            $row = $subscriptions->firstWhere('endpoint', $endpoint);

            if (! $row) {
                continue;
            }

            if ($report->isSuccess()) {
                $row->forceFill(['last_used_at' => now()])->save();
                $sent++;
                continue;
            }

            // 404 (Not Found) and 410 (Gone) mean the endpoint is dead —
            // prune the row so we stop attempting it. Anything else is a
            // transient failure; leave the row and count as failed.
            $status = $report->getResponse()?->getStatusCode();
            if (in_array($status, [404, 410], true)) {
                $row->delete();
                $pruned++;
            } else {
                $failed++;
            }
        }

        return ['sent' => $sent, 'pruned' => $pruned, 'failed' => $failed];
    }
}
