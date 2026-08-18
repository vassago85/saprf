<?php

/**
 * Member-side mute preferences + global notifications kill switch.
 *
 * Truth table for the mail channel:
 *
 *   category is mandatory (Policy change / Urgent) → mail always sends
 *   notifications_enabled = false                  → mail suppressed
 *   member muted the category                      → mail suppressed
 *   otherwise                                      → mail sends
 *
 * In every case, the in-app archive row (announcement_recipients) must
 * still exist — the /communications archive must not lose entries when
 * mail is off.
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
use App\Models\Setting;
use App\Models\User;
use App\Notifications\FederationAnnouncementNotification;
use App\Notifications\Channels\WebPushChannel;
use App\Services\Announcements\AnnouncementPublisher;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    seedRoles();
});

function seedRealMember(string $suffix): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('member');

    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'NP-'.$suffix,
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => now()->addYear()->toDateString(),
    ]);

    return $user->fresh();
}

function makePipelineReadyAnnouncement(User $creator, AnnouncementCategory $category): Announcement
{
    $announcement = Announcement::create([
        'title' => 'Broadcast',
        'body' => 'Body',
        'category' => $category,
        'priority' => 'normal',
        'status' => AnnouncementStatus::Sending,
        'created_by' => $creator->id,
    ]);

    $announcement->audiences()->create([
        'type' => AudienceType::ActiveMembers,
        'mode' => AudienceMode::Include,
        'value' => [],
    ]);

    return $announcement;
}

function mailDeliveryFor(Announcement $announcement, User $user): AnnouncementDelivery
{
    return AnnouncementDelivery::query()
        ->join('announcement_recipients', 'announcement_recipients.id', '=', 'announcement_deliveries.announcement_recipient_id')
        ->where('announcement_recipients.announcement_id', $announcement->id)
        ->where('announcement_recipients.user_id', $user->id)
        ->where('announcement_deliveries.channel', DeliveryChannel::Mail->value)
        ->select('announcement_deliveries.*')
        ->first();
}

// ── mute the category ──────────────────────────────────────────────────────

it('skips mail (but keeps the in-app row) when the recipient muted the category', function () {
    Queue::fake();
    Notification::fake();

    $exco = User::factory()->create(['email_verified_at' => now()]);
    $exco->assignRole(['exco', 'member']);
    $member = seedRealMember('muted');

    NotificationPreference::create([
        'user_id' => $member->id,
        'muted_email_categories' => [AnnouncementCategory::Announcement->value],
        'push_enabled' => true,
    ]);

    $announcement = makePipelineReadyAnnouncement($exco, AnnouncementCategory::Announcement);

    (new ResolveAudienceJob($announcement->id))->handle(app(AnnouncementPublisher::class));
    (new SendAnnouncementChunkJob(
        $announcement->id,
        DeliveryChannel::Mail->value,
        [$member->id],
    ))->handle(app(SettingsService::class), app(WebPushChannel::class));

    Notification::assertNotSentTo($member, FederationAnnouncementNotification::class);

    $delivery = mailDeliveryFor($announcement, $member);
    expect($delivery->status)->toBe(DeliveryStatus::Failed)
        ->and($delivery->error)->toContain('muted');

    expect($announcement->recipients()->where('user_id', $member->id)->exists())->toBeTrue();
});

// ── kill switch ────────────────────────────────────────────────────────────

it('honours the notifications_enabled=false kill switch for non-mandatory categories', function () {
    Queue::fake();
    Notification::fake();

    Setting::updateOrCreate(['key' => 'notifications_enabled'], ['value' => '0']);

    $exco = User::factory()->create(['email_verified_at' => now()]);
    $exco->assignRole(['exco', 'member']);
    $member = seedRealMember('killed');

    $announcement = makePipelineReadyAnnouncement($exco, AnnouncementCategory::Announcement);

    (new ResolveAudienceJob($announcement->id))->handle(app(AnnouncementPublisher::class));
    (new SendAnnouncementChunkJob(
        $announcement->id,
        DeliveryChannel::Mail->value,
        [$member->id],
    ))->handle(app(SettingsService::class), app(WebPushChannel::class));

    Notification::assertNotSentTo($member, FederationAnnouncementNotification::class);

    $delivery = mailDeliveryFor($announcement, $member);
    expect($delivery->status)->toBe(DeliveryStatus::Failed);

    expect($announcement->recipients()->where('user_id', $member->id)->exists())->toBeTrue();
});

// ── mandatory categories bypass everything ─────────────────────────────────

it('sends Policy change mail even when the recipient muted every category', function () {
    Queue::fake();
    Notification::fake();

    // Belt-and-braces: kill switch is off AND every mutable category is muted.
    Setting::updateOrCreate(['key' => 'notifications_enabled'], ['value' => '0']);

    $chair = User::factory()->create(['email_verified_at' => now()]);
    $chair->assignRole(['chair', 'exco', 'member']);
    $member = seedRealMember('policy');

    NotificationPreference::create([
        'user_id' => $member->id,
        'muted_email_categories' => collect(AnnouncementCategory::cases())->pluck('value')->all(),
        'push_enabled' => false,
    ]);

    $announcement = makePipelineReadyAnnouncement($chair, AnnouncementCategory::PolicyChange);

    (new ResolveAudienceJob($announcement->id))->handle(app(AnnouncementPublisher::class));
    (new SendAnnouncementChunkJob(
        $announcement->id,
        DeliveryChannel::Mail->value,
        [$member->id],
    ))->handle(app(SettingsService::class), app(WebPushChannel::class));

    Notification::assertSentTo($member, FederationAnnouncementNotification::class);

    $delivery = mailDeliveryFor($announcement, $member);
    expect($delivery->status)->toBe(DeliveryStatus::Sent);
});

// ── preference form ────────────────────────────────────────────────────────

it('persists mute preferences via the profile form', function () {
    $member = User::factory()->create(['email_verified_at' => now()]);
    $member->assignRole('member');

    $this->actingAs($member)
        ->put(route('profile.notification-preferences.update'), [
            'muted_categories' => [AnnouncementCategory::Announcement->value, AnnouncementCategory::MatchCalendar->value],
            'push_enabled' => '1',
        ])
        ->assertRedirect(route('profile'));

    $pref = $member->fresh()->notificationPreference;

    expect($pref)->not->toBeNull()
        ->and($pref->muted_email_categories)->toEqualCanonicalizing([
            AnnouncementCategory::Announcement->value,
            AnnouncementCategory::MatchCalendar->value,
        ])
        ->and($pref->push_enabled)->toBeTrue();
});

it('refuses to store a mute against a mandatory category', function () {
    $member = User::factory()->create(['email_verified_at' => now()]);
    $member->assignRole('member');

    $this->actingAs($member)
        ->put(route('profile.notification-preferences.update'), [
            'muted_categories' => [AnnouncementCategory::PolicyChange->value],
        ])
        ->assertSessionHasErrors('muted_categories.*');
});
