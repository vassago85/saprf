<?php

/**
 * Coverage for the walk-in shooter flow.
 *
 * Exercises both the WalkInRegistrationService directly (for edge cases and
 * user resolution) and the scores:register-walk-ins Artisan command
 * end-to-end via a small on-disk CSV.
 */

use App\Models\AuditLog;
use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Province;
use App\Models\User;
use App\Services\WalkInRegistrationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->open = Division::firstOrCreate(['slug' => 'open'], ['name' => 'Open', 'display_order' => 1]);
    $this->factory = Division::firstOrCreate(['slug' => 'factory'], ['name' => 'Factory', 'display_order' => 2]);

    $this->md = User::factory()->create(['name' => 'The MD', 'email' => 'md@example.test']);
    $this->md->assignRole('match_director');

    $this->match = MatchEvent::create([
        'name' => 'Walk-in Test Match',
        'match_type' => 'PR22',
        'series_level' => 'provincial',
        'series' => 'PR22',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::today()->subDay(),
        'status' => 'completed',
        'active_member_fee' => 500,
        'non_member_fee' => 700,
        'saprf_fee_type' => 'fixed',
        'saprf_fee_value' => 50,
        'platform_fee_type' => 'fixed',
        'platform_fee_value' => 20,
        'created_by' => $this->md->id,
    ]);
});

it('creates a walk-in MatchRegistration with fee_amount=0 and negative md_net_amount', function () {
    $service = app(WalkInRegistrationService::class);
    $existing = User::factory()->create(['name' => 'Existing Shooter', 'email' => 'existing@example.test']);

    $reg = $service->confirmWalkIn(
        $this->match,
        [
            'name' => 'Existing Shooter',
            'email' => 'existing@example.test',
            'division_slug' => 'open',
            'membership_status' => 'active_member',
            'note' => 'Paid R500 cash, receipt #123',
        ],
        $this->md,
    );

    expect($reg->registration_source)->toBe('walk_in')
        ->and($reg->user_id)->toBe($existing->id)
        ->and($reg->division_id)->toBe($this->open->id)
        ->and((float) $reg->fee_amount)->toBe(0.00)
        ->and((float) $reg->saprf_fee)->toBe(50.00)
        ->and((float) $reg->platform_fee)->toBe(20.00)
        ->and((float) $reg->gateway_fee)->toBe(0.00)
        ->and((float) $reg->md_net_amount)->toBe(-70.00)
        ->and($reg->payment_status)->toBe('paid')
        ->and($reg->walk_in_note)->toBe('Paid R500 cash, receipt #123')
        ->and($reg->walk_in_confirmed_by)->toBe($this->md->id)
        ->and($reg->walk_in_confirmed_at)->not->toBeNull();
});

it('writes an audit log entry visible to exco each time a walk-in is confirmed', function () {
    $service = app(WalkInRegistrationService::class);
    User::factory()->create(['email' => 'audit@example.test']);

    $reg = $service->confirmWalkIn(
        $this->match,
        [
            'name' => 'Audit Shooter',
            'email' => 'audit@example.test',
            'division_slug' => 'open',
            'membership_status' => 'active_member',
            'note' => 'Paid R500 cash',
        ],
        $this->md,
    );

    $log = AuditLog::where('action_type', 'registration.walk_in_confirmed')
        ->where('entity_id', $reg->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->md->id)
        ->and($log->new_value['note'])->toBe('Paid R500 cash')
        ->and((float) $log->new_value['md_deduction'])->toBe(-70.00)
        ->and($log->new_value['user_shadow'])->toBeFalse();
});

it('creates a new user from the CSV email when the email is not yet on the platform', function () {
    $service = app(WalkInRegistrationService::class);

    $reg = $service->confirmWalkIn(
        $this->match,
        [
            'name' => 'Brand-New Bertha',
            'email' => 'bertha@example.test',
            'division_slug' => 'open',
            'membership_status' => 'active_member',
            'note' => 'Walk-in — paid at desk',
        ],
        $this->md,
    );

    $user = $reg->user;
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Brand-New Bertha')
        ->and($user->email)->toBe('bertha@example.test');
});

it('creates a shadow user with a saprf.walkin email when neither email nor name matches', function () {
    $service = app(WalkInRegistrationService::class);

    $reg = $service->confirmWalkIn(
        $this->match,
        [
            'name' => 'Unknown Ursula',
            'email' => '',
            'division_slug' => 'open',
            'membership_status' => 'non_member',
            'note' => 'No email supplied; MD to invite offline',
        ],
        $this->md,
    );

    expect($reg->user->email)->toEndWith('@saprf.walkin');

    $log = AuditLog::where('action_type', 'registration.walk_in_confirmed')
        ->where('entity_id', $reg->id)
        ->first();
    expect($log->new_value['user_shadow'])->toBeTrue();
});

it('matches an existing user by name when no email is supplied', function () {
    $service = app(WalkInRegistrationService::class);
    $existing = User::factory()->create(['name' => 'Nomail Norman', 'email' => 'norman@example.test']);

    $reg = $service->confirmWalkIn(
        $this->match,
        [
            'name' => 'Nomail Norman',
            'email' => '',
            'division_slug' => 'open',
            'membership_status' => 'active_member',
            'note' => 'Recognised him at the range',
        ],
        $this->md,
    );

    expect($reg->user_id)->toBe($existing->id);
});

it('rejects a walk-in with a missing note', function () {
    $service = app(WalkInRegistrationService::class);

    expect(fn () => $service->confirmWalkIn(
        $this->match,
        [
            'name' => 'Any Shooter',
            'email' => 'any@example.test',
            'division_slug' => 'open',
            'membership_status' => 'active_member',
            'note' => '',
        ],
        $this->md,
    ))->toThrow(RuntimeException::class, 'note is required');
});

it('rejects a walk-in with an invalid division slug', function () {
    $service = app(WalkInRegistrationService::class);

    expect(fn () => $service->confirmWalkIn(
        $this->match,
        [
            'name' => 'Any Shooter',
            'email' => 'any@example.test',
            'division_slug' => 'nonexistent-division',
            'membership_status' => 'active_member',
            'note' => 'Some note',
        ],
        $this->md,
    ))->toThrow(RuntimeException::class, "'nonexistent-division' does not exist");
});

it('rejects a walk-in with an invalid membership_status', function () {
    $service = app(WalkInRegistrationService::class);

    expect(fn () => $service->confirmWalkIn(
        $this->match,
        [
            'name' => 'Any Shooter',
            'email' => 'any@example.test',
            'division_slug' => 'open',
            'membership_status' => 'made_up_status',
            'note' => 'Some note',
        ],
        $this->md,
    ))->toThrow(RuntimeException::class, 'membership_status must be one of');
});

it('processes a walk-ins CSV end-to-end via the artisan command', function () {
    User::factory()->create(['name' => 'Existing Erika', 'email' => 'erika@example.test']);

    $csv = tempnam(sys_get_temp_dir(), 'walkins_').'.csv';
    File::put($csv, implode("\n", [
        'name,email,division_slug,membership_status,note',
        '"Existing Erika",erika@example.test,open,active_member,"Paid R500 cash"',
        '"Fresh Frank",frank@example.test,factory,non_member,"New to the range"',
        '"Shadow Sam",,open,non_member,"No email on hand; MD will invite later"',
    ]));

    $this->artisan('scores:register-walk-ins', [
        'match' => $this->match->id,
        'csv' => $csv,
        '--md' => $this->md->email,
    ])->assertSuccessful();

    expect(MatchRegistration::where('match_id', $this->match->id)->where('registration_source', 'walk_in')->count())->toBe(3)
        ->and(User::where('email', 'frank@example.test')->exists())->toBeTrue()
        ->and(User::where('email', 'like', 'walkin.shadow.sam.%@saprf.walkin')->exists())->toBeTrue();

    File::delete($csv);
});

it('reports errors and does not fail the whole batch when one row is bad', function () {
    $csv = tempnam(sys_get_temp_dir(), 'walkins_').'.csv';
    File::put($csv, implode("\n", [
        'name,email,division_slug,membership_status,note',
        '"Good Shooter",good@example.test,open,active_member,"OK"',
        '"Bad Shooter",bad@example.test,nonexistent,active_member,"Bad division"',
    ]));

    $this->artisan('scores:register-walk-ins', [
        'match' => $this->match->id,
        'csv' => $csv,
    ])
        ->expectsOutputToContain('does not exist')
        ->assertFailed();

    // The good one still gets in.
    expect(MatchRegistration::where('match_id', $this->match->id)
        ->where('registration_source', 'walk_in')
        ->pluck('shooter_name')
        ->all())->toBe(['Good Shooter']);

    File::delete($csv);
});

it('writes nothing when --dry-run is passed', function () {
    $csv = tempnam(sys_get_temp_dir(), 'walkins_').'.csv';
    File::put($csv, implode("\n", [
        'name,email,division_slug,membership_status,note',
        '"Dry-run Dan",dan@example.test,open,active_member,"Test"',
    ]));

    $this->artisan('scores:register-walk-ins', [
        'match' => $this->match->id,
        'csv' => $csv,
        '--dry-run' => true,
    ])->assertSuccessful();

    expect(MatchRegistration::where('match_id', $this->match->id)->count())->toBe(0);

    File::delete($csv);
});
