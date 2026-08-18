<?php

/**
 * Mailgun webhook consumer at POST /webhooks/mailgun.
 *
 * Covers the four event types we act on (delivered, failed-permanent,
 * failed-temporary, complained), plus HMAC verification and the effect
 * on the user's email_bounced_at / email_complained_at flags that
 * drive suppression in SendAnnouncementChunkJob.
 */

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementStatus;
use App\Enums\DeliveryChannel;
use App\Enums\DeliveryStatus;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\AnnouncementRecipient;
use App\Models\User;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    seedRoles();
    Config::set('services.mailgun.webhook_signing_key', 'test-signing-key');
});

function makeDeliveryForWebhook(): array
{
    $exco = User::factory()->create(['email_verified_at' => now()]);
    $exco->assignRole(['exco', 'member']);

    $member = User::factory()->create(['email_verified_at' => now(), 'email' => 'bob@example.test']);
    $member->assignRole('member');

    $announcement = Announcement::create([
        'title' => 'Webhook test',
        'body' => 'Body',
        'category' => AnnouncementCategory::Announcement,
        'priority' => 'normal',
        'status' => AnnouncementStatus::Sending,
        'created_by' => $exco->id,
    ]);

    $recipient = AnnouncementRecipient::create([
        'announcement_id' => $announcement->id,
        'user_id' => $member->id,
    ]);

    $delivery = AnnouncementDelivery::create([
        'announcement_recipient_id' => $recipient->id,
        'channel' => DeliveryChannel::Mail,
        'status' => DeliveryStatus::Sent,
        'sent_at' => now(),
    ]);

    return [$member, $announcement, $delivery];
}

function mailgunPayload(string $event, AnnouncementDelivery $delivery, User $user, string $severity = '', string $reason = ''): array
{
    $timestamp = (string) time();
    $token = bin2hex(random_bytes(16));
    $signature = hash_hmac('sha256', $timestamp . $token, 'test-signing-key');

    $eventData = [
        'event' => $event,
        'recipient' => $user->email,
        'user-variables' => [
            'delivery_id' => $delivery->id,
            'user_id' => $user->id,
        ],
    ];
    if ($severity !== '') {
        $eventData['severity'] = $severity;
    }
    if ($reason !== '') {
        $eventData['reason'] = $reason;
    }

    return [
        'signature' => [
            'timestamp' => $timestamp,
            'token' => $token,
            'signature' => $signature,
        ],
        'event-data' => $eventData,
    ];
}

it('rejects a webhook with a missing signature block', function () {
    $this->postJson('/webhooks/mailgun', ['event-data' => ['event' => 'delivered']])
        ->assertStatus(401);
});

it('rejects a webhook whose HMAC does not match', function () {
    [$member, $announcement, $delivery] = makeDeliveryForWebhook();

    $payload = mailgunPayload('delivered', $delivery, $member);
    $payload['signature']['signature'] = 'deadbeef';

    $this->postJson('/webhooks/mailgun', $payload)->assertStatus(401);
});

it('rejects a webhook whose timestamp is too old (replay protection)', function () {
    [$member, $announcement, $delivery] = makeDeliveryForWebhook();

    $timestamp = (string) (time() - 3600);
    $token = bin2hex(random_bytes(16));
    $signature = hash_hmac('sha256', $timestamp . $token, 'test-signing-key');

    $this->postJson('/webhooks/mailgun', [
        'signature' => ['timestamp' => $timestamp, 'token' => $token, 'signature' => $signature],
        'event-data' => ['event' => 'delivered', 'user-variables' => ['delivery_id' => $delivery->id]],
    ])->assertStatus(401);
});

it('marks the delivery Delivered on a delivered event', function () {
    [$member, $announcement, $delivery] = makeDeliveryForWebhook();

    $this->postJson('/webhooks/mailgun', mailgunPayload('delivered', $delivery, $member))
        ->assertOk();

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Delivered);
});

it('marks the delivery Bounced and flags the user on a permanent failure', function () {
    [$member, $announcement, $delivery] = makeDeliveryForWebhook();

    $this->postJson('/webhooks/mailgun', mailgunPayload('failed', $delivery, $member, 'permanent', 'No such user'))
        ->assertOk();

    $delivery->refresh();
    expect($delivery->status)->toBe(DeliveryStatus::Bounced);
    expect($delivery->error)->toContain('No such user');

    $member->refresh();
    expect($member->email_bounced_at)->not->toBeNull();
    expect($member->email_bounce_count)->toBe(1);
});

it('marks the delivery Failed on a temporary failure but does NOT flag the user', function () {
    [$member, $announcement, $delivery] = makeDeliveryForWebhook();

    $this->postJson('/webhooks/mailgun', mailgunPayload('failed', $delivery, $member, 'temporary', 'Greylisted'))
        ->assertOk();

    $delivery->refresh();
    expect($delivery->status)->toBe(DeliveryStatus::Failed);

    $member->refresh();
    expect($member->email_bounced_at)->toBeNull();
    expect($member->email_bounce_count)->toBe(0);
});

it('marks the delivery Bounced and flags a spam complaint', function () {
    [$member, $announcement, $delivery] = makeDeliveryForWebhook();

    $this->postJson('/webhooks/mailgun', mailgunPayload('complained', $delivery, $member))
        ->assertOk();

    $delivery->refresh();
    expect($delivery->status)->toBe(DeliveryStatus::Bounced);

    $member->refresh();
    expect($member->email_complained_at)->not->toBeNull();
});

it('does not roll a Bounced delivery back to Delivered', function () {
    [$member, $announcement, $delivery] = makeDeliveryForWebhook();

    // First: hard bounce.
    $this->postJson('/webhooks/mailgun', mailgunPayload('failed', $delivery, $member, 'permanent'))
        ->assertOk();
    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Bounced);

    // Then a late-arriving Delivered event (this happens; Mailgun sometimes
    // buffers events out of order). Must NOT overwrite Bounced.
    $this->postJson('/webhooks/mailgun', mailgunPayload('delivered', $delivery, $member))
        ->assertOk();
    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Bounced);
});

it('increments email_bounce_count on repeated permanent failures', function () {
    [$member, $announcement, $delivery] = makeDeliveryForWebhook();

    $this->postJson('/webhooks/mailgun', mailgunPayload('failed', $delivery, $member, 'permanent'))->assertOk();
    $this->postJson('/webhooks/mailgun', mailgunPayload('failed', $delivery, $member, 'permanent'))->assertOk();

    expect($member->fresh()->email_bounce_count)->toBe(2);
});
