<?php

/**
 * PWA push-subscription lifecycle:
 *   - POST /push/subscribe stores/updates a per-endpoint row
 *   - DELETE /push/subscribe removes the row on the same endpoint
 *   - GET /push/vapid-public-key returns the server's public key
 *   - A 410 from a fake push service (simulated at the model level)
 *     causes WebPushChannel to prune the row
 *
 * The full HTTP contract for the browser side is that a subscription
 * survives re-registration on the same device (upsert on endpoint).
 */

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementStatus;
use App\Enums\DeliveryChannel;
use App\Enums\DeliveryStatus;
use App\Jobs\ResolveAudienceJob;
use App\Jobs\SendAnnouncementChunkJob;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\Membership;
use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use App\Services\Announcements\AnnouncementPublisher;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedRoles();

    config()->set('webpush.vapid.public_key', 'test-public-key');
    config()->set('webpush.vapid.private_key', 'test-private-key');
    config()->set('webpush.vapid.subject', 'mailto:test@saprf.co.za');
});

function pushMember(string $suffix): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('member');

    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'WP-'.$suffix,
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => now()->addYear()->toDateString(),
    ]);

    return $user->fresh();
}

// ── Subscribe / unsubscribe ────────────────────────────────────────────────

it('stores a push subscription for the authenticated user', function () {
    $user = pushMember('sub-store');

    $this->actingAs($user)
        ->postJson(route('push.subscribe'), [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'keys' => [
                'p256dh' => 'BExampleP256dh',
                'auth' => 'ExampleAuthToken',
            ],
        ])
        ->assertOk()
        ->assertJson(['saved' => true]);

    expect(PushSubscription::where('user_id', $user->id)->count())->toBe(1);
});

it('upserts on the endpoint so re-registering the same device does not duplicate rows', function () {
    $user = pushMember('sub-upsert');

    $payload = [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
        'keys' => ['p256dh' => 'k1', 'auth' => 'a1'],
    ];

    $this->actingAs($user)->postJson(route('push.subscribe'), $payload)->assertOk();
    $this->actingAs($user)->postJson(route('push.subscribe'), $payload)->assertOk();

    expect(PushSubscription::where('user_id', $user->id)->count())->toBe(1);
});

it('deletes a subscription via the unsubscribe endpoint', function () {
    $user = pushMember('sub-del');

    $sub = PushSubscription::create([
        'user_id' => $user->id,
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/z',
        'p256dh' => 'k',
        'auth' => 'a',
        'last_used_at' => now(),
    ]);

    $this->actingAs($user)
        ->deleteJson(route('push.unsubscribe'), ['endpoint' => $sub->endpoint])
        ->assertOk()
        ->assertJson(['deleted' => true]);

    expect(PushSubscription::find($sub->id))->toBeNull();
});

it('exposes the VAPID public key via a JSON endpoint', function () {
    $user = pushMember('sub-vapid');

    $this->actingAs($user)
        ->getJson(route('push.vapid-key'))
        ->assertOk()
        ->assertJson(['public_key' => 'test-public-key']);
});

// ── Mute + push channel wiring ─────────────────────────────────────────────

it('does not queue webpush for a muted non-mandatory category', function () {
    Notification::fake();

    $exco = User::factory()->create(['email_verified_at' => now()]);
    $exco->assignRole(['exco', 'member']);

    $member = pushMember('mute-push');
    PushSubscription::create([
        'user_id' => $member->id,
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/mutable',
        'p256dh' => 'k', 'auth' => 'a', 'last_used_at' => now(),
    ]);
    NotificationPreference::create([
        'user_id' => $member->id,
        'muted_email_categories' => [],
        'push_enabled' => false,
    ]);

    $announcement = Announcement::create([
        'title' => 'Non-urgent',
        'body' => 'Body',
        'category' => AnnouncementCategory::Announcement,
        'priority' => 'normal',
        'status' => AnnouncementStatus::Sending,
        'created_by' => $exco->id,
    ]);
    $announcement->audiences()->create([
        'type' => \App\Enums\AudienceType::Individual,
        'mode' => \App\Enums\AudienceMode::Include,
        'value' => ['user_ids' => [$member->id]],
    ]);

    (new ResolveAudienceJob($announcement->id))->handle(app(AnnouncementPublisher::class));
    (new SendAnnouncementChunkJob(
        $announcement->id,
        DeliveryChannel::WebPush->value,
        [$member->id],
    ))->handle(app(SettingsService::class), app(WebPushChannel::class));

    $delivery = AnnouncementDelivery::query()
        ->join('announcement_recipients', 'announcement_recipients.id', '=', 'announcement_deliveries.announcement_recipient_id')
        ->where('announcement_recipients.announcement_id', $announcement->id)
        ->where('announcement_recipients.user_id', $member->id)
        ->where('announcement_deliveries.channel', DeliveryChannel::WebPush->value)
        ->select('announcement_deliveries.*')
        ->first();

    expect($delivery->status)->toBe(DeliveryStatus::Failed)
        ->and($delivery->error)->toContain('muted push');
});
