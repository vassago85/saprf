<?php

use App\Console\Commands\SendNs2027DivisionMoveAmendmentCommand;
use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementRetention;
use App\Enums\AudienceType;
use App\Enums\DeliveryChannel;
use App\Models\Announcement;
use App\Models\User;

/**
 * Covers the one-off policy notice fired on next deploy to tell active
 * members that the signed 2027 national series rules now have a
 * simpler division-move clause.
 *
 * Three guarantees matter here:
 *   1. First run creates a Policy-change, permanent, acknowledgement-
 *      required announcement with Active members as the audience, all
 *      three delivery channels, and the amended signed PDF attached.
 *   2. Running it again is a no-op — the Docker entrypoint fires it on
 *      every `app` container restart, so a second broadcast would be a
 *      user-visible bug.
 *   3. A fresh install with no Chair role assigned fails loudly rather
 *      than silently emailing without the second-approver guarantee.
 */

it('fires the 2027 division-move amendment once and is idempotent on re-run', function () {
    seedRoles();
    $chair = User::factory()->create();
    $chair->assignRole('chair');
    $member = User::factory()->create();
    giveActiveMembership($member);

    $this->artisan('saprf:send-ns-2027-division-move-amendment', ['--force' => true])
        ->assertSuccessful();

    $announcement = Announcement::where('title', SendNs2027DivisionMoveAmendmentCommand::NOTICE_TITLE)->firstOrFail();

    expect($announcement->category)->toBe(AnnouncementCategory::PolicyChange)
        ->and($announcement->retention)->toBe(AnnouncementRetention::Permanent)
        ->and($announcement->requires_acknowledgement)->toBeTrue()
        ->and($announcement->created_by)->toBe($chair->id)
        ->and($announcement->deliver_via)->toEqualCanonicalizing([
            DeliveryChannel::Database->value,
            DeliveryChannel::Mail->value,
            DeliveryChannel::WebPush->value,
        ])
        ->and($announcement->audiences()->first()->type)->toBe(AudienceType::ActiveMembers)
        ->and($announcement->attachments)->toHaveCount(1)
        ->and($announcement->attachments->first()->filename)->toBe('saprf-national-series-2027.pdf');

    // Body quotes both the old and the new sentence so a member reading
    // only the email (never opening the attachment) sees the exact
    // wording change rather than a vague "the rules changed" summary.
    expect($announcement->body)
        ->toContain('except if the rifle used to shoot in Open was a factory qualified rifle')
        ->toContain('A shooter moving from Open to Factory starts again.')
        ->toContain(route('rules.national-series-2027'));

    // Second run must be a no-op or every container restart would re-send.
    $this->artisan('saprf:send-ns-2027-division-move-amendment', ['--force' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Nothing sent');

    expect(Announcement::where('title', SendNs2027DivisionMoveAmendmentCommand::NOTICE_TITLE)->count())->toBe(1);
});

it('refuses to send and names the Chair-by-position when the Chair role is unassigned', function () {
    seedRoles();
    $andries = User::factory()->create(['name' => 'Andries Lategan', 'exco_position' => 'Chair']);
    $andries->assignRole('exco');

    $this->artisan('saprf:send-ns-2027-division-move-amendment', ['--force' => true])
        ->expectsOutputToContain('Andries Lategan')
        ->assertFailed();

    expect(Announcement::where('title', SendNs2027DivisionMoveAmendmentCommand::NOTICE_TITLE)->exists())->toBeFalse();
});
