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
