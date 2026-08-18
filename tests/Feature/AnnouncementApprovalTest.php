<?php

/**
 * Second-approver enforcement for Policy change announcements.
 *
 * The rules (see AnnouncementPublisher::approve + assertReadyToSend):
 *   - Chair sends immediately, no second approver needed
 *   - Exco (not Chair) must have a different Exco/Chair approve first
 *   - Author cannot approve their own draft
 *   - Non-Policy-change categories do not require an approver
 */

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementStatus;
use App\Enums\AudienceMode;
use App\Enums\AudienceType;
use App\Jobs\ResolveAudienceJob;
use App\Models\Announcement;
use App\Models\User;
use App\Services\Announcements\AnnouncementPublisher;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    seedRoles();
    Queue::fake();
});

function makeDraft(User $creator, AnnouncementCategory $category): Announcement
{
    $announcement = Announcement::create([
        'title' => 'Draft',
        'body' => 'Body',
        'category' => $category,
        'priority' => 'normal',
        'status' => AnnouncementStatus::Draft,
        'requires_acknowledgement' => false,
        'created_by' => $creator->id,
    ]);

    $announcement->audiences()->create([
        'type' => AudienceType::ActiveMembers,
        'mode' => AudienceMode::Include,
        'value' => [],
    ]);

    return $announcement;
}

it('lets a Chair send a Policy change immediately, no second approver required', function () {
    $chair = User::factory()->create(['email_verified_at' => now()]);
    $chair->assignRole(['chair', 'exco', 'member']);

    $announcement = makeDraft($chair, AnnouncementCategory::PolicyChange);

    app(AnnouncementPublisher::class)->sendNow($announcement);

    expect($announcement->fresh()->status)->toBe(AnnouncementStatus::Sending);
    Queue::assertPushed(ResolveAudienceJob::class);
});

it('blocks a non-Chair Exco author from sending a Policy change without approval', function () {
    $exco = User::factory()->create(['email_verified_at' => now()]);
    $exco->assignRole(['exco', 'member']);

    $announcement = makeDraft($exco, AnnouncementCategory::PolicyChange);

    app(AnnouncementPublisher::class)->sendNow($announcement);
})->throws(RuntimeException::class, 'needs a second Exco or Chair approver');

it('does not let an author approve their own Policy change', function () {
    $exco = User::factory()->create(['email_verified_at' => now()]);
    $exco->assignRole(['exco', 'member']);

    $announcement = makeDraft($exco, AnnouncementCategory::PolicyChange);

    app(AnnouncementPublisher::class)->approve($announcement, $exco);
})->throws(RuntimeException::class, 'cannot approve their own');

it('accepts a different Exco as approver and unlocks the send', function () {
    $author = User::factory()->create(['email_verified_at' => now()]);
    $author->assignRole(['exco', 'member']);

    $approver = User::factory()->create(['email_verified_at' => now()]);
    $approver->assignRole(['exco', 'member']);

    $announcement = makeDraft($author, AnnouncementCategory::PolicyChange);

    app(AnnouncementPublisher::class)->approve($announcement, $approver);
    app(AnnouncementPublisher::class)->sendNow($announcement->fresh());

    expect($announcement->fresh()->approved_by)->toBe($approver->id)
        ->and($announcement->fresh()->status)->toBe(AnnouncementStatus::Sending);
});

it('does not require approval for non-Policy-change categories', function () {
    $exco = User::factory()->create(['email_verified_at' => now()]);
    $exco->assignRole(['exco', 'member']);

    $announcement = makeDraft($exco, AnnouncementCategory::Announcement);

    app(AnnouncementPublisher::class)->sendNow($announcement);

    expect($announcement->fresh()->status)->toBe(AnnouncementStatus::Sending);
});
