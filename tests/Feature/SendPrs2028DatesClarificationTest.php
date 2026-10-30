<?php

use App\Console\Commands\SendPrs2028DatesClarificationCommand;
use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementRetention;
use App\Enums\AudienceType;
use App\Enums\DeliveryChannel;
use App\Models\Announcement;
use App\Models\User;

/**
 * Covers the one-off policy notice fired on next deploy to tell active
 * members that the PRS 2028 selection policy now spells out the
 * calendar dates for the Qualifying Period and the team announcement.
 *
 * Three guarantees matter here:
 *   1. First run creates a Policy-change, permanent, acknowledgement-
 *      required announcement with Active members as the audience and
 *      all three delivery channels.
 *   2. Running it again is a no-op — the Docker entrypoint fires it on
 *      every `app` container restart, so a second broadcast would be a
 *      user-visible bug.
 *   3. A fresh install with no Chair role assigned fails loudly rather
 *      than silently emailing without the second-approver guarantee.
 */

it('fires the PRS 2028 dates clarification once and is idempotent on re-run', function () {
    seedRoles();
    $chair = User::factory()->create();
    $chair->assignRole('chair');
    $member = User::factory()->create();
    giveActiveMembership($member);

    $this->artisan('saprf:send-prs-2028-dates-clarification', ['--force' => true])
        ->assertSuccessful();

    $announcement = Announcement::where('title', SendPrs2028DatesClarificationCommand::NOTICE_TITLE)->firstOrFail();

    expect($announcement->category)->toBe(AnnouncementCategory::PolicyChange)
        ->and($announcement->retention)->toBe(AnnouncementRetention::Permanent)
        ->and($announcement->requires_acknowledgement)->toBeTrue()
        ->and($announcement->created_by)->toBe($chair->id)
        ->and($announcement->deliver_via)->toEqualCanonicalizing([
            DeliveryChannel::Database->value,
            DeliveryChannel::Mail->value,
            DeliveryChannel::WebPush->value,
        ])
        ->and($announcement->audiences()->first()->type)->toBe(AudienceType::ActiveMembers);

    // Body is framed as "Annexure A" per Chair's explicit instruction
    // ("it must be an annexure and very clear") and names the two
    // things Russell asked for in writing: the calendar-year qualifying
    // window and the team-announcement date.
    expect($announcement->body)
        ->toContain('Annexure A')
        ->toContain('1 January 2027')
        ->toContain('31 December 2027')
        ->toContain('31 January 2028')
        ->toContain(route('selection.policy.public', ['series' => 'prs', 'season' => '2028']));
    expect($announcement->title)->toStartWith('Annexure A to the PRS 2028 selection policy');

    // Second run must be a no-op or every container restart would re-send.
    $this->artisan('saprf:send-prs-2028-dates-clarification', ['--force' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Nothing sent');

    expect(Announcement::where('title', SendPrs2028DatesClarificationCommand::NOTICE_TITLE)->count())->toBe(1);
});

it('refuses to send and names the Chair-by-position when the Chair role is unassigned', function () {
    seedRoles();
    $andries = User::factory()->create(['name' => 'Andries Lategan', 'exco_position' => 'Chair']);
    $andries->assignRole('exco');

    $this->artisan('saprf:send-prs-2028-dates-clarification', ['--force' => true])
        ->expectsOutputToContain('Andries Lategan')
        ->assertFailed();

    expect(Announcement::where('title', SendPrs2028DatesClarificationCommand::NOTICE_TITLE)->exists())->toBeFalse();
});
