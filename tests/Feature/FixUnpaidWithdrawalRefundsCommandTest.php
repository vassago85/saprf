<?php

use App\Models\AuditLog;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Province;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();

    $province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $this->match = MatchEvent::create([
        'name' => 'Historical Refund Fix Match',
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
});

function makeUnpaidWithdrawnRegistration(MatchEvent $match, User $user, array $overrides = []): MatchRegistration
{
    return MatchRegistration::create(array_merge([
        'match_id' => $match->id,
        'user_id' => $user->id,
        'shooter_name' => $user->name,
        'email' => $user->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 1100.00,
        'payment_status' => 'pending',
        'registration_status' => 'cancelled',
        'cancelled_at' => now(),
        'refund_amount' => 1000,
        'admin_fee_charged' => 100,
        'registered_at' => now()->subDay(),
    ], $overrides));
}

it('reports affected rows in dry-run mode without touching them', function () {
    $registration = makeUnpaidWithdrawnRegistration($this->match, $this->member);

    $this->artisan('registrations:fix-unpaid-refunds')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    $registration->refresh();

    expect((float) $registration->refund_amount)->toBe(1000.0)
        ->and((float) $registration->admin_fee_charged)->toBe(100.0);

    expect(AuditLog::where('action_type', 'registration.refund.corrected')->count())->toBe(0);
});

it('zeros refund_amount and admin_fee_charged when --apply is passed', function () {
    $registration = makeUnpaidWithdrawnRegistration($this->match, $this->member);

    $this->artisan('registrations:fix-unpaid-refunds', ['--apply' => true])
        ->assertSuccessful();

    $registration->refresh();

    expect((float) $registration->refund_amount)->toBe(0.0)
        ->and((float) $registration->admin_fee_charged)->toBe(0.0);
});

it('writes a system-actor audit entry recording the retroactive correction', function () {
    $registration = makeUnpaidWithdrawnRegistration($this->match, $this->member);

    $this->artisan('registrations:fix-unpaid-refunds', ['--apply' => true])
        ->assertSuccessful();

    $audit = AuditLog::where('action_type', 'registration.refund.corrected')
        ->where('entity_id', $registration->id)
        ->firstOrFail();

    expect($audit->user_id)->toBeNull()
        ->and($audit->actor_type)->toBe(AuditLog::ACTOR_SYSTEM)
        ->and($audit->old_value)->toMatchArray([
            'refund_amount' => 1000,
            'admin_fee_charged' => 100,
        ])
        ->and($audit->new_value)->toMatchArray([
            'refund_amount' => 0,
            'admin_fee_charged' => 0,
            'reason' => 'unpaid_withdrawal_retroactive_correction',
        ]);
});

it('leaves paid withdrawals alone', function () {
    $paid = makeUnpaidWithdrawnRegistration($this->match, $this->member, [
        'payment_status' => 'paid',
    ]);

    $this->artisan('registrations:fix-unpaid-refunds', ['--apply' => true])
        ->assertSuccessful();

    $paid->refresh();

    expect((float) $paid->refund_amount)->toBe(1000.0)
        ->and((float) $paid->admin_fee_charged)->toBe(100.0);
});

it('leaves active (non-cancelled) registrations alone', function () {
    $active = makeUnpaidWithdrawnRegistration($this->match, $this->member, [
        'registration_status' => 'confirmed',
        'cancelled_at' => null,
    ]);

    $this->artisan('registrations:fix-unpaid-refunds', ['--apply' => true])
        ->assertSuccessful();

    $active->refresh();

    expect((float) $active->refund_amount)->toBe(1000.0);
});

it('scopes to specific IDs when --id is provided', function () {
    $keep = makeUnpaidWithdrawnRegistration($this->match, $this->member);
    $other = makeUnpaidWithdrawnRegistration(
        $this->match,
        User::factory()->create(),
    );

    $this->artisan('registrations:fix-unpaid-refunds', [
        '--apply' => true,
        '--id' => [$other->id],
    ])->assertSuccessful();

    $keep->refresh();
    $other->refresh();

    expect((float) $keep->refund_amount)->toBe(1000.0)
        ->and((float) $other->refund_amount)->toBe(0.0);
});
