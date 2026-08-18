<?php

/**
 * Contract: when the queued mail send exhausts retries or otherwise
 * dies, the paired `announcement_deliveries` row must be flipped to
 * `failed` with a useful error message. This closes the gap that
 * previously left rows stranded on `sent` when the mail rate-limiter
 * (or any other queue-level failure) killed the job in flight.
 *
 * We deliberately test `failed()` in isolation rather than routing
 * through the full queue/rate-limiter stack — Laravel's queue worker
 * is what calls `failed()`, and every other test that exercises the
 * queue is already covered by AnnouncementSendPipelineTest.
 */

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementRetention;
use App\Enums\AnnouncementStatus;
use App\Enums\DeliveryChannel;
use App\Enums\DeliveryStatus;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\AnnouncementRecipient;
use App\Models\User;
use App\Notifications\FederationAnnouncementNotification;

beforeEach(function () {
    seedRoles();

    $creator = User::factory()->create(['email_verified_at' => now()]);
    $creator->assignRole('exco');

    $this->announcement = Announcement::create([
        'title' => 'Test broadcast',
        'body' => 'Body text',
        'category' => AnnouncementCategory::Announcement,
        'retention' => AnnouncementRetention::ExpiresOnDate,
        'priority' => 'normal',
        'status' => AnnouncementStatus::Sent,
        'requires_acknowledgement' => false,
        'created_by' => $creator->id,
        'sent_at' => now(),
        'recipient_count' => 0,
    ]);

    $this->recipient = User::factory()->create(['email_verified_at' => now()]);

    $announcementRecipient = AnnouncementRecipient::create([
        'announcement_id' => $this->announcement->id,
        'user_id' => $this->recipient->id,
    ]);

    $this->delivery = AnnouncementDelivery::create([
        'announcement_recipient_id' => $announcementRecipient->id,
        'channel' => DeliveryChannel::Mail,
        'status' => DeliveryStatus::Sent,
        'sent_at' => now(),
    ]);
});

it('flips the delivery row from sent to failed when the queued send dies', function () {
    $notification = new FederationAnnouncementNotification(
        $this->announcement,
        $this->delivery,
    );

    $notification->failed(new RuntimeException('Rate limit exhausted'));

    $refreshed = $this->delivery->fresh();

    expect($refreshed->status)->toBe(DeliveryStatus::Failed)
        ->and($refreshed->error)->toContain('Rate limit exhausted');
});

it('leaves an already-bounced delivery alone so a webhook update is not downgraded', function () {
    // Simulate the Mailgun webhook having already recorded a permanent
    // bounce (hard fail — mailbox does not exist). If the queued send
    // then also fails, we must NOT overwrite the bounce with a generic
    // "failed" — the Mailgun signal is more specific.
    $this->delivery->forceFill([
        'status' => DeliveryStatus::Bounced,
        'error' => 'Recipient mailbox does not exist',
    ])->save();

    $notification = new FederationAnnouncementNotification(
        $this->announcement,
        $this->delivery,
    );

    $notification->failed(new RuntimeException('Any other reason'));

    $refreshed = $this->delivery->fresh();
    expect($refreshed->status)->toBe(DeliveryStatus::Bounced)
        ->and($refreshed->error)->toBe('Recipient mailbox does not exist');
});

it('does not blow up when the notification has no delivery reference', function () {
    // Legacy call sites may still construct the notification without a
    // delivery row (e.g. one-off admin resends). failed() must not
    // throw in that case — it only logs and returns.
    $notification = new FederationAnnouncementNotification($this->announcement);

    $notification->failed(new RuntimeException('some failure'));

    // Nothing to assert on the delivery row (there wasn't one) — the
    // real assertion is that failed() didn't throw.
    expect(true)->toBeTrue();
});
