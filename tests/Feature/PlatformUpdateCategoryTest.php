<?php

/**
 * Pins the semantics of the "Website / platform update" announcement
 * category so a future refactor of AnnouncementCategory can't quietly
 * turn it into a mandatory one (which would bypass mute preferences
 * and mail every member every time we ship a UI tweak).
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
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\FederationAnnouncementNotification;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedRoles();
});

it('is registered in the enum with a stable string value', function () {
    $case = AnnouncementCategory::from('platform_update');

    expect($case)->toBe(AnnouncementCategory::PlatformUpdate);
    expect($case->label())->toBe('Website / platform update');
});

it('is NOT mandatory — recipients can mute it', function () {
    expect(AnnouncementCategory::PlatformUpdate->isMandatory())->toBeFalse();
});

it('does not default to requiring acknowledgement or a second approver', function () {
    expect(AnnouncementCategory::PlatformUpdate->defaultRequiresAcknowledgement())->toBeFalse();
    expect(AnnouncementCategory::PlatformUpdate->requiresSecondApproval())->toBeFalse();
});

it('appears in the select-options helper used by the composer', function () {
    $options = AnnouncementCategory::options();

    expect($options)->toHaveKey('platform_update');
    expect($options['platform_update'])->toBe('Website / platform update');
});

it('honours a member mute — no mail is sent for a muted platform update', function () {
    Notification::fake();

    $exco = User::factory()->create(['email_verified_at' => now()]);
    $exco->assignRole(['exco', 'member']);

    $member = User::factory()->create(['email_verified_at' => now()]);
    $member->assignRole('member');
    Membership::create([
        'user_id' => $member->id,
        'saprf_number' => 'SP-PU1',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => now()->addYear()->toDateString(),
    ]);

    NotificationPreference::create([
        'user_id' => $member->id,
        'muted_email_categories' => [AnnouncementCategory::PlatformUpdate->value],
        'push_enabled' => true,
    ]);

    $announcement = Announcement::create([
        'title' => 'New feature: dark mode',
        'body' => 'Try it out in your profile.',
        'category' => AnnouncementCategory::PlatformUpdate,
        'priority' => 'normal',
        'status' => AnnouncementStatus::Sending,
        'created_by' => $exco->id,
    ]);
    $announcement->audiences()->create([
        'type' => AudienceType::Individual,
        'mode' => AudienceMode::Include,
        'value' => ['user_ids' => [$member->id]],
    ]);

    (new ResolveAudienceJob($announcement->id))->handle(app(\App\Services\Announcements\AnnouncementPublisher::class));

    (new SendAnnouncementChunkJob(
        $announcement->id,
        DeliveryChannel::Mail->value,
        [$member->id],
    ))->handle(app(SettingsService::class), app(\App\Notifications\Channels\WebPushChannel::class));

    // Muted → no email, but the in-app archive row still exists (that
    // logic lives in the freeze step which we already exercised above).
    Notification::assertNotSentTo($member, FederationAnnouncementNotification::class);

    $delivery = AnnouncementDelivery::query()
        ->join('announcement_recipients', 'announcement_recipients.id', '=', 'announcement_deliveries.announcement_recipient_id')
        ->where('announcement_recipients.announcement_id', $announcement->id)
        ->where('announcement_recipients.user_id', $member->id)
        ->where('announcement_deliveries.channel', DeliveryChannel::Mail->value)
        ->select('announcement_deliveries.*')
        ->first();

    expect($delivery->status)->toBe(DeliveryStatus::Failed);
    expect($delivery->error)->toContain('muted');
});
