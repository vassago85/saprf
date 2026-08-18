<?php

/**
 * End-to-end wiring for the async send pipeline:
 *
 *   POST /announcements?action=send
 *     → status becomes 'sending'
 *     → ResolveAudienceJob queued (nothing sent inline!)
 *   ResolveAudienceJob->handle()
 *     → recipient rows created
 *     → per-channel delivery rows created (database=sent, mail/push=queued)
 *     → DispatchAnnouncementJob queued
 *   DispatchAnnouncementJob->handle()
 *     → SendAnnouncementChunkJob queued per (channel, chunk)
 *   SendAnnouncementChunkJob->handle()
 *     → mail Notification queued, delivery row moves queued → sent
 */

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementStatus;
use App\Enums\AudienceMode;
use App\Enums\AudienceType;
use App\Enums\DeliveryChannel;
use App\Enums\DeliveryStatus;
use App\Jobs\DispatchAnnouncementJob;
use App\Jobs\ResolveAudienceJob;
use App\Jobs\SendAnnouncementChunkJob;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\AnnouncementRecipient;
use App\Models\Membership;
use App\Models\User;
use App\Notifications\FederationAnnouncementNotification;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    seedRoles();
});

function seedActiveMember(string $suffix): User
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

    return $user->fresh();
}

// ── HTTP layer ─────────────────────────────────────────────────────────────

it('never sends mail synchronously from the HTTP request', function () {
    Queue::fake();
    Notification::fake();

    $exco = User::factory()->create(['email_verified_at' => now()]);
    $exco->assignRole(['exco', 'member']);
    seedActiveMember('one');
    seedActiveMember('two');

    $this->actingAs($exco)
        ->post(route('announcements.store'), [
            'title' => 'Test title',
            'body' => 'Test body',
            'category' => AnnouncementCategory::Announcement->value,
            'priority' => 'normal',
            'audiences' => [
                ['type' => AudienceType::ActiveMembers->value, 'mode' => AudienceMode::Include->value, 'value' => []],
            ],
            'action' => 'send',
        ])
        ->assertRedirect();

    // Not a single email should have been queued by the HTTP round-trip:
    // the pipeline is entirely async from `store` onwards.
    Notification::assertNothingSent();
    Queue::assertPushed(ResolveAudienceJob::class);

    $announcement = Announcement::firstOrFail();
    expect($announcement->status)->toBe(AnnouncementStatus::Sending);
});

// ── ResolveAudienceJob ─────────────────────────────────────────────────────

it('resolves and freezes recipients + delivery rows when run', function () {
    Queue::fake();

    $exco = User::factory()->create(['email_verified_at' => now()]);
    $exco->assignRole(['exco', 'member']);
    $memberOne = seedActiveMember('r1');
    $memberTwo = seedActiveMember('r2');

    $announcement = Announcement::create([
        'title' => 'Test title',
        'body' => 'Body',
        'category' => AnnouncementCategory::Announcement,
        'priority' => 'normal',
        'status' => AnnouncementStatus::Sending,
        'created_by' => $exco->id,
    ]);
    $announcement->audiences()->create([
        'type' => AudienceType::ActiveMembers, 'mode' => AudienceMode::Include, 'value' => [],
    ]);

    (new ResolveAudienceJob($announcement->id))->handle(app(\App\Services\Announcements\AnnouncementPublisher::class));

    $announcement->refresh();
    expect($announcement->status)->toBe(AnnouncementStatus::Sent)
        ->and($announcement->recipient_count)->toBe(2);

    $recipientIds = AnnouncementRecipient::where('announcement_id', $announcement->id)->pluck('user_id')->all();
    sort($recipientIds);
    $expected = [$memberOne->id, $memberTwo->id];
    sort($expected);
    expect($recipientIds)->toBe($expected);

    // Each recipient should have exactly three delivery rows.
    $deliveryCounts = AnnouncementDelivery::query()
        ->join('announcement_recipients', 'announcement_recipients.id', '=', 'announcement_deliveries.announcement_recipient_id')
        ->where('announcement_recipients.announcement_id', $announcement->id)
        ->selectRaw('channel, count(*) as c, sum(case when status = ? then 1 else 0 end) as sent',
            [DeliveryStatus::Sent->value])
        ->groupBy('channel')
        ->get()
        ->keyBy('channel');

    expect((int) $deliveryCounts[DeliveryChannel::Database->value]->c)->toBe(2)
        ->and((int) $deliveryCounts[DeliveryChannel::Database->value]->sent)->toBe(2)
        ->and((int) $deliveryCounts[DeliveryChannel::Mail->value]->c)->toBe(2)
        ->and((int) $deliveryCounts[DeliveryChannel::Mail->value]->sent)->toBe(0)
        ->and((int) $deliveryCounts[DeliveryChannel::WebPush->value]->c)->toBe(2)
        ->and((int) $deliveryCounts[DeliveryChannel::WebPush->value]->sent)->toBe(0);

    Queue::assertPushed(DispatchAnnouncementJob::class);
});

// ── SendAnnouncementChunkJob ───────────────────────────────────────────────

it('marks a mail delivery row sent once the chunk job runs', function () {
    Notification::fake();

    $exco = User::factory()->create(['email_verified_at' => now()]);
    $exco->assignRole(['exco', 'member']);
    $member = seedActiveMember('mail1');

    $announcement = Announcement::create([
        'title' => 'Test title',
        'body' => 'Body',
        'category' => AnnouncementCategory::Announcement,
        'priority' => 'normal',
        'status' => AnnouncementStatus::Sending,
        'created_by' => $exco->id,
    ]);
    $announcement->audiences()->create([
        'type' => AudienceType::ActiveMembers, 'mode' => AudienceMode::Include, 'value' => [],
    ]);

    (new ResolveAudienceJob($announcement->id))->handle(app(\App\Services\Announcements\AnnouncementPublisher::class));

    (new SendAnnouncementChunkJob(
        $announcement->id,
        DeliveryChannel::Mail->value,
        [$member->id],
    ))->handle(app(SettingsService::class), app(\App\Notifications\Channels\WebPushChannel::class));

    Notification::assertSentTo($member, FederationAnnouncementNotification::class);

    $delivery = AnnouncementDelivery::query()
        ->join('announcement_recipients', 'announcement_recipients.id', '=', 'announcement_deliveries.announcement_recipient_id')
        ->where('announcement_recipients.announcement_id', $announcement->id)
        ->where('announcement_recipients.user_id', $member->id)
        ->where('announcement_deliveries.channel', DeliveryChannel::Mail->value)
        ->select('announcement_deliveries.*')
        ->first();

    expect($delivery->status)->toBe(DeliveryStatus::Sent)
        ->and($delivery->sent_at)->not->toBeNull();
});
