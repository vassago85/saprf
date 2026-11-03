<?php

/**
 * Match close-out wizard: import → names & allocations → walk-in fees →
 * mark complete → request payout.
 *
 * Covers:
 *   - the current step is derived from the match (no persisted "step" column)
 *   - the walk-in acknowledgement endpoint stamps timestamp + user
 *   - adding a new walk-in after acknowledgement clears both columns
 *   - complete-scores and request-payout refuse to fire until names and
 *     walk-in fees are done. The payout itself can be requested before
 *     scores are marked complete, with a warning that it can open negative.
 *   - every step correctly advances once the gating condition is satisfied
 */

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;
use App\Services\MatchCloseoutWizard;
use App\Services\WalkInRegistrationService;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedRoles();
    foreach (['developer', 'exco'] as $role) {
        Role::firstOrCreate(['name' => $role]);
    }

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->open = Division::firstOrCreate(['slug' => 'open'], ['name' => 'Open', 'display_order' => 1]);

    $this->md = User::factory()->create(['name' => 'Wizard MD']);
    $this->md->assignRole('match_director');

    $this->match = MatchEvent::create([
        'name' => 'Wizard Close-out Match',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => now()->subDays(2),
        'status' => 'closed',
        'active_member_fee' => 500,
        'non_member_fee' => 700,
        'lapsed_member_fee' => 600,
        'saprf_fee_type' => 'fixed',
        'saprf_fee_value' => 50,
        'platform_fee_type' => 'fixed',
        'platform_fee_value' => 50,
        'created_by' => $this->md->id,
        'match_director' => $this->md->name,
    ]);

    // One paid card entry so the match has a real payout amount and
    // registrations are considered closed by the controller.
    $shooter = User::factory()->create();
    $paid = MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $shooter->id,
        'shooter_name' => $shooter->name,
        'email' => $shooter->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 500,
        'saprf_fee' => 50,
        'platform_fee' => 50,
        'gateway_fee' => 20,
        'surcharge_amount' => 0,
        'md_net_amount' => 380,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now()->subDay(),
    ]);
    Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $paid->id,
        'user_id' => $shooter->id,
        'amount' => 500,
        'm_payment_id' => 'REG-'.$paid->id,
        'status' => 'completed',
        'gateway' => 'payfast',
    ]);
    $this->paidShooter = $shooter;
});

function wizardFor(MatchEvent $match): array
{
    return app(MatchCloseoutWizard::class)->state($match);
}

function recordScoreForWizard(MatchEvent $match, User $shooter, array $overrides = []): Score
{
    return Score::create(array_merge([
        'match_id' => $match->id,
        'user_id' => $shooter->id,
        'shooter_name' => $shooter->name,
        'raw_score' => 100,
        'status' => 'valid',
        'match_date' => $match->match_date,
        'division_id' => \App\Models\Division::firstOrCreate(
            ['slug' => 'open'],
            ['name' => 'Open', 'display_order' => 1],
        )->id,
    ], $overrides));
}

// ─── Step derivation ──────────────────────────────────────────────────

it('starts on the import step when there are no scores', function () {
    $state = wizardFor($this->match);

    expect($state['current'])->toBe(MatchCloseoutWizard::STEP_IMPORT)
        ->and($state['has_scores'])->toBeFalse();
});

it('advances past the names step when every score is linked, divisioned and non-zero', function () {
    // A single clean score has nothing to review — the wizard rolls past
    // "names & allocations" straight to the walk-in step. The tests below
    // park on the names step by introducing a specific gating condition.
    recordScoreForWizard($this->match, $this->paidShooter);

    $state = wizardFor($this->match);

    expect($state['current'])->toBe(MatchCloseoutWizard::STEP_WALKINS);
});

it('keeps the user on the names step when a score has no division', function () {
    recordScoreForWizard($this->match, $this->paidShooter, ['division_id' => null]);

    $state = wizardFor($this->match);

    expect($state['current'])->toBe(MatchCloseoutWizard::STEP_NAMES)
        ->and($state['missing_division_count'])->toBe(1);
});

it('keeps the user on the names step while an Impact row still needs linking', function () {
    recordScoreForWizard($this->match, $this->paidShooter, [
        'raw_meta' => ['impact' => ['needs_member_link' => true]],
    ]);

    $state = wizardFor($this->match);

    expect($state['current'])->toBe(MatchCloseoutWizard::STEP_NAMES)
        ->and($state['pending_link_count'])->toBe(1);
});

it('keeps the user on the names step while a zero score is unconfirmed', function () {
    recordScoreForWizard($this->match, $this->paidShooter, ['raw_score' => 0]);

    $state = wizardFor($this->match);

    expect($state['current'])->toBe(MatchCloseoutWizard::STEP_NAMES)
        ->and($state['unconfirmed_zero_count'])->toBe(1);
});

it('moves to the walk-in step once names and allocations are clean', function () {
    recordScoreForWizard($this->match, $this->paidShooter);

    $state = wizardFor($this->match);

    expect($state['current'])->toBe(MatchCloseoutWizard::STEP_WALKINS);
});

// ─── Walk-in acknowledgement ──────────────────────────────────────────

it('stamps who and when on the match when the MD acknowledges walk-in fees', function () {
    recordScoreForWizard($this->match, $this->paidShooter);

    $this->actingAs($this->md)
        ->post(route('matches.scores.acknowledge-walkin-fees', $this->match), [
            'agreed' => '1',
        ])
        ->assertRedirect(route('matches.scores', $this->match))
        ->assertSessionHas('success');

    $match = $this->match->fresh();
    expect($match->walkInFeesAreAcknowledged())->toBeTrue()
        ->and($match->walkin_fees_acknowledged_by)->toBe($this->md->id);
});

it('refuses the acknowledgement when the agree box is not ticked', function () {
    recordScoreForWizard($this->match, $this->paidShooter);

    $this->actingAs($this->md)
        ->post(route('matches.scores.acknowledge-walkin-fees', $this->match), [])
        ->assertSessionHasErrors('agreed');

    expect($this->match->fresh()->walkInFeesAreAcknowledged())->toBeFalse();
});

it('clears the acknowledgement when a NEW walk-in is confirmed on an already-agreed match', function () {
    recordScoreForWizard($this->match, $this->paidShooter);
    $this->match->forceFill([
        'walkin_fees_acknowledged_at' => now()->subMinute(),
        'walkin_fees_acknowledged_by' => $this->md->id,
    ])->save();

    app(WalkInRegistrationService::class)->confirmWalkIn(
        $this->match,
        [
            'name' => 'Walk In Wendy',
            'email' => 'wendy@example.test',
            'division_slug' => 'open',
            'membership_status' => 'active_member',
            'note' => 'Paid cash on the day',
        ],
        $this->md,
    );

    $fresh = $this->match->fresh();
    expect($fresh->walkInFeesAreAcknowledged())->toBeFalse()
        ->and($fresh->walkin_fees_acknowledged_by)->toBeNull();
});

it('advances past the walk-in step for a match with no walk-ins once the MD ticks the box', function () {
    recordScoreForWizard($this->match, $this->paidShooter);

    $this->actingAs($this->md)
        ->post(route('matches.scores.acknowledge-walkin-fees', $this->match), [
            'agreed' => '1',
        ])
        ->assertRedirect();

    expect(wizardFor($this->match->fresh())['current'])
        ->toBe(MatchCloseoutWizard::STEP_COMPLETE);
});

// ─── Gates: complete-scores ───────────────────────────────────────────

it('refuses to mark scores complete while the walk-in step is still open', function () {
    recordScoreForWizard($this->match, $this->paidShooter);

    // Create a walk-in so the wizard parks on step 3.
    app(WalkInRegistrationService::class)->confirmWalkIn(
        $this->match,
        [
            'name' => 'Walk In Wendy',
            'email' => 'wendy@example.test',
            'division_slug' => 'open',
            'membership_status' => 'active_member',
            'note' => 'Cash on the day',
        ],
        $this->md,
    );

    $this->actingAs($this->md)
        ->post(route('matches.scores.complete', $this->match))
        ->assertRedirect(route('matches.scores', $this->match))
        ->assertSessionHas('error');

    expect($this->match->fresh()->scoresAreFinalized())->toBeFalse();
});

it('refuses to mark scores complete while an assign-later row is still pending', function () {
    recordScoreForWizard($this->match, $this->paidShooter, [
        'raw_meta' => ['impact' => ['needs_member_link' => true]],
    ]);

    $this->actingAs($this->md)
        ->post(route('matches.scores.complete', $this->match))
        ->assertRedirect(route('matches.scores', $this->match))
        ->assertSessionHas('error');

    expect($this->match->fresh()->scoresAreFinalized())->toBeFalse();
});

// ─── Gates: request payout ────────────────────────────────────────────

it('refuses the GET banking form when the walk-in step is still open', function () {
    recordScoreForWizard($this->match, $this->paidShooter);
    app(WalkInRegistrationService::class)->confirmWalkIn(
        $this->match,
        [
            'name' => 'Walk In Wendy',
            'email' => 'wendy@example.test',
            'division_slug' => 'open',
            'membership_status' => 'active_member',
            'note' => 'Cash on the day',
        ],
        $this->md,
    );

    $this->actingAs($this->md)
        ->get(route('matches.request-payout', $this->match))
        ->assertRedirect(route('matches.scores', $this->match))
        ->assertSessionHas('error');
});

it('lets the MD request a payout before scores are marked complete, and warns it can start negative', function () {
    recordScoreForWizard($this->match, $this->paidShooter);
    $this->match->forceFill([
        'walkin_fees_acknowledged_at' => now(),
        'walkin_fees_acknowledged_by' => $this->md->id,
    ])->save();

    $this->actingAs($this->md)
        ->get(route('matches.scores', $this->match))
        ->assertOk()
        ->assertSee('Request payout now')
        ->assertSee('can start off negative');

    $this->actingAs($this->md)
        ->get(route('matches.request-payout', $this->match))
        ->assertOk()
        ->assertSee('This payout can start off negative')
        ->assertSee('walk-ins, or anything else still outstanding');

    $this->actingAs($this->md)
        ->post(route('matches.complete-and-request-payout', $this->match), [
            'account_holder' => 'Wizard MD',
            'bank_name' => 'FNB',
            'account_number' => '0123456789',
            'branch_code' => '250655',
        ])
        ->assertRedirect();

    $fresh = $this->match->fresh();
    expect(Payout::where('match_id', $this->match->id)->exists())->toBeTrue()
        ->and($fresh->scoresAreFinalized())->toBeFalse()
        ->and($fresh->status)->toBe('completed');
});

it('refuses the POST payout request when a NEW walk-in landed after acknowledgement', function () {
    recordScoreForWizard($this->match, $this->paidShooter);
    $this->match->forceFill([
        'walkin_fees_acknowledged_at' => now()->subMinute(),
        'walkin_fees_acknowledged_by' => $this->md->id,
        'scores_finalized_at' => now(),
        'scores_finalized_by' => $this->md->id,
    ])->save();

    // Fresh walk-in clears the acknowledgement — payout gate re-closes.
    app(WalkInRegistrationService::class)->confirmWalkIn(
        $this->match,
        [
            'name' => 'Late Walk-in',
            'email' => 'late@example.test',
            'division_slug' => 'open',
            'membership_status' => 'active_member',
            'note' => 'Added after acknowledgement',
        ],
        $this->md,
    );

    $this->actingAs($this->md)
        ->post(route('matches.complete-and-request-payout', $this->match), [
            'account_holder' => 'Wizard MD',
            'bank_name' => 'FNB',
            'account_number' => '0123456789',
            'branch_code' => '250655',
        ])
        ->assertRedirect(route('matches.scores', $this->match))
        ->assertSessionHas('error');

    expect(Payout::where('match_id', $this->match->id)->exists())->toBeFalse();
});

it('lets the MD raise the payout when every step is done', function () {
    recordScoreForWizard($this->match, $this->paidShooter);
    $this->match->forceFill([
        'walkin_fees_acknowledged_at' => now(),
        'walkin_fees_acknowledged_by' => $this->md->id,
        'scores_finalized_at' => now(),
        'scores_finalized_by' => $this->md->id,
    ])->save();

    $this->actingAs($this->md)
        ->post(route('matches.complete-and-request-payout', $this->match), [
            'account_holder' => 'Wizard MD',
            'bank_name' => 'FNB',
            'account_number' => '0123456789',
            'branch_code' => '250655',
        ])
        ->assertRedirect();

    expect(Payout::where('match_id', $this->match->id)->exists())->toBeTrue();
});

// ─── Scores view surface ──────────────────────────────────────────────

it('renders the stepper on the scores workspace tab', function () {
    $this->actingAs($this->md)
        ->get(route('matches.scores', $this->match))
        ->assertOk()
        ->assertSee('Import')
        ->assertSee('Names & allocations')
        ->assertSee('Walk-in fees')
        ->assertSee('Complete scores')
        ->assertSee('Request payout');
});

it('shows the agreement checkbox copy on the walk-in step', function () {
    recordScoreForWizard($this->match, $this->paidShooter);
    app(WalkInRegistrationService::class)->confirmWalkIn(
        $this->match,
        [
            'name' => 'Walk In Wendy',
            'email' => 'wendy@example.test',
            'division_slug' => 'open',
            'membership_status' => 'active_member',
            'note' => 'Cash on the day',
        ],
        $this->md,
    );

    $this->actingAs($this->md)
        ->get(route('matches.scores', $this->match))
        ->assertOk()
        ->assertSee('Agree walk-in fees')
        ->assertSee('platform fee')
        ->assertSee('SAPRF levy')
        ->assertSee('Walk In Wendy');
});
