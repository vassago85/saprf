<?php

use App\Console\Commands\SendDecisionRegisterIntroCommand;
use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementRetention;
use App\Enums\AudienceType;
use App\Enums\DeliveryChannel;
use App\Models\Announcement;
use App\Models\User;

it('emails active members the Decision Register intro once and is idempotent on re-run', function () {
    seedRoles();
    $exco = User::factory()->create();
    $exco->assignRole('exco');
    $member = User::factory()->create();
    giveActiveMembership($member);

    $this->artisan('saprf:send-decision-register-intro', ['--force' => true])
        ->assertSuccessful();

    $announcement = Announcement::where('title', SendDecisionRegisterIntroCommand::NOTICE_TITLE)->firstOrFail();

    expect($announcement->category)->toBe(AnnouncementCategory::PlatformUpdate)
        ->and($announcement->retention)->toBe(AnnouncementRetention::Permanent)
        ->and($announcement->requires_acknowledgement)->toBeFalse()
        ->and($announcement->created_by)->toBe($exco->id)
        ->and($announcement->deliver_via)->toEqualCanonicalizing([
            DeliveryChannel::Database->value,
            DeliveryChannel::Mail->value,
            DeliveryChannel::WebPush->value,
        ])
        ->and($announcement->audiences()->first()->type)->toBe(AudienceType::ActiveMembers);

    expect($announcement->body)
        ->toContain('Decision Register')
        ->toContain('👍 For')
        ->toContain('👎 Against')
        ->toContain('🤷 Neutral')
        ->toContain('only records that you voted')
        ->toContain('never linked')
        ->toContain('vote only once')
        ->toContain('does not immediately change')
        ->toContain('Please use your vote')
        ->toContain('guide ExCo')
        ->toContain('Without a broad response')
        ->toContain('quiet, anonymous way to be counted')
        ->toContain('relook')
        ->toContain(route('decisions.index'));

    $this->artisan('saprf:send-decision-register-intro', ['--force' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Nothing sent');

    expect(Announcement::where('title', SendDecisionRegisterIntroCommand::NOTICE_TITLE)->count())->toBe(1);
});

it('refuses to send when no ExCo or Chair account exists', function () {
    seedRoles();

    $this->artisan('saprf:send-decision-register-intro', ['--force' => true])
        ->expectsOutputToContain('No ExCo or Chair account')
        ->assertFailed();

    expect(Announcement::where('title', SendDecisionRegisterIntroCommand::NOTICE_TITLE)->exists())->toBeFalse();
});
