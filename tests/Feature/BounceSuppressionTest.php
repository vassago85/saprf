<?php

/**
 * Verifies SendAnnouncementChunkJob honours the email_bounced_at and
 * email_complained_at flags:
 *
 *   - Non-mandatory broadcast + user has bounced → skipped, delivery
 *     marked Bounced with a clear "skipped previously" reason.
 *   - Mandatory broadcast (Policy change / Urgent) + user has bounced
 *     → STILL SENT. Exco needs the delivery attempt on record even if
 *     Mailgun refuses it — the compliance trail matters more than the
 *     sender-reputation savings.
 */

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementStatus;
use App\Enums\AudienceMode;
use App\Enums\AudienceType;
use App\Enums\DeliveryChannel;
use App\Enums\DeliveryStatus;
use App\Jobs\ResolveAudienceJob;
use App\Jobs\SendAnnouncementChunkJob;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\Membership;
use App\Models\User;
use App\Notifications\FederationAnnouncementNotification;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedRoles();
});

function seedBouncedMember(string $suffix, ?string $flag = 'bounced'): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('member');

    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'SP-'.$suffix,
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => now()->addYear()->toDateString(),
    ]);

    if ($flag === 'bounced') {
        $user->forceFill(['email_bounced_at' => now(), 'email_bounce_count' => 1])->save();
    } elseif ($flag === 'complained') {
        $user->forceFill(['email_complained_at' => now()])->save();
    }

    return $user->fresh();
}

function runAnnouncementFor(AnnouncementCategory $category, User $exco, User $target): AnnouncementDelivery
{
    $announcement = Announcement::create([
        'title' => 'Suppression test',
        'body' => 'Body',
        'category' => $category,
        'priority' => 'normal',
        'status' => AnnouncementStatus::Sending,
        'created_by' => $exco->id,
    ]);
    $announcement->audiences()->create([
        'type' => AudienceType::Individual, 'mode' => AudienceMode::Include, 'value' => ['user_ids' => [$target->id]],
    ]);

    (new ResolveAudienceJob($announcement->id))->handle(app(\App\Services\Announcements\AnnouncementPublisher::class));

    (new SendAnnouncementChunkJob(
        $announcement->id,
        DeliveryChannel::Mail->value,
        [$target->id],
    ))->handle(app(SettingsService::class), app(\App\Notifications\Channels\WebPushChannel::class));

    return AnnouncementDelivery::query()
        ->join('announcement_recipients', 'announcement_recipients.id', '=', 'announcement_deliveries.announcement_recipient_id')
        ->where('announcement_recipients.announcement_id', $announcement->id)
        ->where('announcement_recipients.user_id', $target->id)
        ->where('announcement_deliveries.channel', DeliveryChannel::Mail->value)
        ->select('announcement_deliveries.*')
        ->firstOrFail();
}

it('skips a hard-bounced user for a non-mandatory broadcast', function () {
    Notification::fake();

    $exco = User::factory()->create(['email_verified_at' => now()]);
    $exco->assignRole(['exco', 'member']);
    $member = seedBouncedMember('b1', 'bounced');

    $delivery = runAnnouncementFor(AnnouncementCategory::Announcement, $exco, $member);

    Notification::assertNotSentTo($member, FederationAnnouncementNotification::class);
    expect($delivery->status)->toBe(DeliveryStatus::Bounced);
    expect($delivery->error)->toContain('bounced');
});

it('skips a user who complained for a non-mandatory broadcast', function () {
    Notification::fake();

    $exco = User::factory()->create(['email_verified_at' => now()]);
    $exco->assignRole(['exco', 'member']);
    $member = seedBouncedMember('c1', 'complained');

    $delivery = runAnnouncementFor(AnnouncementCategory::Announcement, $exco, $member);

    Notification::assertNotSentTo($member, FederationAnnouncementNotification::class);
    expect($delivery->status)->toBe(DeliveryStatus::Bounced);
    expect($delivery->error)->toContain('spam');
});

it('STILL sends a mandatory broadcast to a hard-bounced user', function () {
    Notification::fake();

    $exco = User::factory()->create(['email_verified_at' => now()]);
    $exco->assignRole(['exco', 'member']);
    $member = seedBouncedMember('m1', 'bounced');

    $delivery = runAnnouncementFor(AnnouncementCategory::PolicyChange, $exco, $member);

    Notification::assertSentTo($member, FederationAnnouncementNotification::class);
    expect($delivery->status)->toBe(DeliveryStatus::Sent);
});
