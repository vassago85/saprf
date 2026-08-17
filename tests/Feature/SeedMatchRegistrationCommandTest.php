<?php

use App\Models\AuditLog;
use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\Province;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DivisionCategorySeeder;

beforeEach(function () {
    seedRoles();
    $this->seed(DivisionCategorySeeder::class);

    $province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $this->match = MatchEvent::create([
        'name' => 'Seed Test Match',
        'match_type' => 'PRS',
        'series_level' => 'national',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => Carbon::today()->addMonth(),
        'status' => 'open',
        'active_member_fee' => 1100.00,
        'non_member_fee' => 1300.00,
        'lapsed_member_fee' => 1200.00,
        'created_by' => User::factory()->create()->id,
    ]);

    $this->member = User::factory()->create();
    $this->membership = Membership::create([
        'user_id' => $this->member->id,
        'saprf_number' => 'SAPRF-SEED-001',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'expiry_date' => Carbon::today()->addYear(),
    ]);
});

it('previews the seeded registration in dry-run mode without writing', function () {
    $this->artisan('registrations:seed', [
        '--match' => $this->match->id,
        '--membership' => $this->membership->id,
        '--division' => 'ladies',
        '--force' => true,
    ])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(MatchRegistration::where('match_id', $this->match->id)->count())->toBe(0);
});

it('creates a confirmed paid registration when --apply is passed', function () {
    $this->artisan('registrations:seed', [
        '--match' => $this->match->id,
        '--membership' => $this->membership->id,
        '--division' => 'ladies',
        '--force' => true,
        '--apply' => true,
    ])->assertSuccessful();

    $ladies = Division::where('slug', 'ladies')->firstOrFail();

    $registration = MatchRegistration::where('match_id', $this->match->id)
        ->where('user_id', $this->member->id)
        ->firstOrFail();

    expect($registration->division_id)->toBe($ladies->id)
        ->and($registration->registration_status)->toBe('confirmed')
        ->and($registration->payment_status)->toBe('paid')
        ->and($registration->membership_fee_category)->toBe('active_member')
        ->and((float) $registration->fee_amount)->toBe(1100.0)
        ->and($registration->shooter_name)->toBe($this->member->name);
});

it('records a system-actor audit entry for the seeded registration', function () {
    $this->artisan('registrations:seed', [
        '--match' => $this->match->id,
        '--membership' => $this->membership->id,
        '--division' => 'ladies',
        '--force' => true,
        '--apply' => true,
    ])->assertSuccessful();

    $audit = AuditLog::where('action_type', 'registration.seeded')->firstOrFail();

    expect($audit->user_id)->toBeNull()
        ->and($audit->actor_type)->toBe(AuditLog::ACTOR_SYSTEM)
        ->and($audit->new_value)->toMatchArray([
            'match_id' => $this->match->id,
            'user_id' => $this->member->id,
            'division' => 'ladies',
            'reason' => 'manual_seed_via_artisan',
        ]);
});

it('refuses to double-book an active registration for the same user', function () {
    MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $this->member->id,
        'shooter_name' => $this->member->name,
        'email' => $this->member->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 1100.00,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    $this->artisan('registrations:seed', [
        '--match' => $this->match->id,
        '--membership' => $this->membership->id,
        '--division' => 'ladies',
        '--force' => true,
        '--apply' => true,
    ])
        ->expectsOutputToContain('already has an active registration')
        ->assertFailed();
});

it('rejects a division not offered by the match unless --force is used', function () {
    $ladies = Division::where('slug', 'ladies')->firstOrFail();
    $open = Division::where('slug', 'open')->firstOrFail();
    $this->match->divisions()->sync([$open->id]);

    $this->artisan('registrations:seed', [
        '--match' => $this->match->id,
        '--membership' => $this->membership->id,
        '--division' => 'ladies',
        '--apply' => true,
    ])
        ->expectsOutputToContain('not offered by match')
        ->assertFailed();

    expect(MatchRegistration::where('match_id', $this->match->id)->count())->toBe(0);

    $this->artisan('registrations:seed', [
        '--match' => $this->match->id,
        '--membership' => $this->membership->id,
        '--division' => 'ladies',
        '--force' => true,
        '--apply' => true,
    ])->assertSuccessful();

    expect(MatchRegistration::where('match_id', $this->match->id)->where('division_id', $ladies->id)->count())->toBe(1);
});

it('accepts --user as an alternative to --membership', function () {
    $this->artisan('registrations:seed', [
        '--match' => $this->match->id,
        '--user' => $this->member->id,
        '--division' => 'ladies',
        '--force' => true,
        '--apply' => true,
    ])->assertSuccessful();

    expect(MatchRegistration::where('user_id', $this->member->id)->count())->toBe(1);
});

it('accepts --saprf as an alternative that resolves via memberships.saprf_number', function () {
    // Operators paste SAPRF numbers off paper entry sheets; the command
    // must find the shooter by that number without needing anyone to look
    // up the internal user/membership IDs.
    $this->artisan('registrations:seed', [
        '--match' => $this->match->id,
        '--saprf' => $this->membership->saprf_number,
        '--division' => 'ladies',
        '--force' => true,
        '--apply' => true,
    ])->assertSuccessful();

    expect(MatchRegistration::where('user_id', $this->member->id)->count())->toBe(1);
});

it('strips leading zeros on --saprf so 00050 resolves to 50', function () {
    // Paper sheets often left-pad SAPRF numbers ("050", "00050"); the DB
    // stores them without padding, so the command must match either form.
    $this->membership->update(['saprf_number' => '50']);

    $this->artisan('registrations:seed', [
        '--match' => $this->match->id,
        '--saprf' => '00050',
        '--division' => 'ladies',
        '--force' => true,
        '--apply' => true,
    ])->assertSuccessful();

    expect(MatchRegistration::where('user_id', $this->member->id)->count())->toBe(1);
});

it('errors clearly when --saprf does not resolve to any membership', function () {
    $this->artisan('registrations:seed', [
        '--match' => $this->match->id,
        '--saprf' => '99999999',
        '--division' => 'ladies',
        '--force' => true,
        '--apply' => true,
    ])
        ->expectsOutputToContain("SAPRF number '99999999' has no matching membership.")
        ->assertFailed();

    expect(MatchRegistration::count())->toBe(0);
});

it('rejects the run when neither --membership, --user, nor --saprf is supplied', function () {
    $this->artisan('registrations:seed', [
        '--match' => $this->match->id,
        '--division' => 'ladies',
        '--force' => true,
        '--apply' => true,
    ])
        ->expectsOutputToContain('Required: --match, --division, and one of --membership / --user / --saprf.')
        ->assertFailed();
});

it('supports --payment=waived for comp\'d entries', function () {
    $this->artisan('registrations:seed', [
        '--match' => $this->match->id,
        '--membership' => $this->membership->id,
        '--division' => 'ladies',
        '--payment' => 'waived',
        '--force' => true,
        '--apply' => true,
    ])->assertSuccessful();

    $registration = MatchRegistration::where('user_id', $this->member->id)->firstOrFail();

    expect($registration->payment_status)->toBe('waived');
});

it('zeroes the gateway fee for seeded entries and rebalances md_net', function () {
    // Even for a normal, non-forced seed the entry never went through PayFast,
    // so the estimated gateway fee that RegistrationPricingService bakes into
    // the breakdown must not stick to the row — otherwise the MD is silently
    // short the card-rate estimate on every manually seeded entry.
    $this->artisan('registrations:seed', [
        '--match' => $this->match->id,
        '--membership' => $this->membership->id,
        '--division' => 'ladies',
        '--force' => true,
        '--apply' => true,
    ])->assertSuccessful();

    $registration = MatchRegistration::where('user_id', $this->member->id)->firstOrFail();

    $expectedMdNet = round(
        (float) $registration->fee_amount
        - (float) $registration->saprf_fee
        - (float) $registration->platform_fee
        - (float) $registration->surcharge_amount,
        2
    );

    expect((float) $registration->gateway_fee)->toBe(0.0)
        ->and((float) $registration->md_net_amount)->toBe($expectedMdNet);
});

it('waives a lapsed-member surcharge when --category=active_member is forced with a reason', function () {
    // Set up a lapsed member (expired) — natural classification would apply
    // the lapsed-member surcharge on the entry fee.
    $lapsed = User::factory()->create();
    Membership::create([
        'user_id' => $lapsed->id,
        'saprf_number' => 'SAPRF-SEED-002',
        'membership_type' => 'paid',
        'status' => 'expired',
        'payment_status' => 'paid',
        'start_date' => Carbon::today()->subYears(2),
        'expiry_date' => Carbon::today()->subMonths(6),
    ]);

    $reason = 'Grace: legacy provincial entry imported; lapsed surcharge waived one-off.';

    $this->artisan('registrations:seed', [
        '--match' => $this->match->id,
        '--user' => $lapsed->id,
        '--division' => 'ladies',
        '--category' => 'active_member',
        '--reason' => $reason,
        '--force' => true,
        '--apply' => true,
    ])->assertSuccessful();

    $registration = MatchRegistration::where('user_id', $lapsed->id)->firstOrFail();

    // Should be booked at the plain member fee with zero surcharge, and the
    // reason must be persisted on the row so future auditors know WHY the
    // shooter did not pay the lapsed rate their membership implied.
    expect($registration->membership_fee_category)->toBe('active_member')
        ->and((float) $registration->fee_amount)->toBe(1100.0)
        ->and((float) $registration->surcharge_amount)->toBe(0.0)
        ->and($registration->fee_override_reason)->toBe($reason);
});

it('rejects --category without --reason so overrides are never silent', function () {
    $this->artisan('registrations:seed', [
        '--match' => $this->match->id,
        '--membership' => $this->membership->id,
        '--division' => 'ladies',
        '--category' => 'active_member',
        '--force' => true,
        '--apply' => true,
    ])
        ->expectsOutputToContain('--category requires --reason')
        ->assertFailed();

    expect(MatchRegistration::where('match_id', $this->match->id)->count())->toBe(0);
});

it('rejects unknown --category values', function () {
    $this->artisan('registrations:seed', [
        '--match' => $this->match->id,
        '--membership' => $this->membership->id,
        '--division' => 'ladies',
        '--category' => 'life_member',
        '--reason' => 'nope',
        '--force' => true,
        '--apply' => true,
    ])
        ->expectsOutputToContain('--category must be one of')
        ->assertFailed();
});
