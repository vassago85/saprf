<?php

/**
 * MD payout request now collects banking details.
 *
 * Previously the MD could request a payout with a single POST and SAPRF had
 * no way to know where to transfer the money. These tests lock in the
 * two-step flow: a GET renders the form (pre-filled from a prior payout if
 * the MD has one), and a POST validates + persists the banking fields onto
 * the payout record so finance can process payment without chasing anyone.
 */

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Payout;
use App\Models\Province;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedRoles();
    foreach (['developer', 'exco'] as $role) {
        Role::firstOrCreate(['name' => $role]);
    }

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $this->md = User::factory()->create(['name' => 'Match Director Marco']);
    $this->md->assignRole('match_director');

    $this->match = MatchEvent::create([
        'name' => 'Banking Flow Test Match',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => now()->subDays(2),
        'status' => 'closed',
        'active_member_fee' => 500,
        'non_member_fee' => 750,
        'lapsed_member_fee' => 650,
        'created_by' => $this->md->id,
        'match_director' => $this->md->name,
    ]);

    // One paid registration so the payout has a real amount to raise.
    $shooter = User::factory()->create();
    MatchRegistration::create([
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
});

// ─── GET /matches/{match}/request-payout ──────────────────────────────

it('shows the request-payout form to the owning MD with every banking field present', function () {
    $this->actingAs($this->md)
        ->get(route('matches.request-payout', $this->match))
        ->assertOk()
        ->assertSee('Request your match-director payout')
        ->assertSee('account_holder', false)
        ->assertSee('bank_name', false)
        ->assertSee('account_number', false)
        ->assertSee('branch_code', false)
        // Projected net amount visible.
        ->assertSee('R 380.00');
});

it('refuses the request-payout form to another MD', function () {
    $other = User::factory()->create();
    $other->assignRole('match_director');

    $this->actingAs($other)
        ->get(route('matches.request-payout', $this->match))
        ->assertForbidden();
});

it('pre-fills the request-payout form from the MDs most recent payout banking details', function () {
    // Simulate a prior payout from a *different* match so the "already
    // raised" short-circuit doesn't kick in on this match.
    $priorMatch = MatchEvent::create([
        'name' => 'Earlier match',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2025',
        'province_id' => $this->province->id,
        'match_date' => now()->subMonth(),
        'status' => 'completed',
        'active_member_fee' => 400,
        'non_member_fee' => 600,
        'lapsed_member_fee' => 500,
        'created_by' => $this->md->id,
    ]);

    Payout::create([
        'reference' => Payout::generateReference(),
        'payee_type' => 'match_director',
        'payee_user_id' => $this->md->id,
        'match_id' => $priorMatch->id,
        'gross_amount' => 400,
        'fees_deducted' => 100,
        'net_amount' => 300,
        'status' => 'paid',
        'account_holder' => 'Marco Prior Match',
        'bank_name' => 'FNB',
        'account_number' => '62001234567',
        'branch_code' => '250655',
        'created_by' => $this->md->id,
    ]);

    $this->actingAs($this->md)
        ->get(route('matches.request-payout', $this->match))
        ->assertOk()
        ->assertSee('Marco Prior Match')
        ->assertSee('FNB')
        ->assertSee('62001234567')
        ->assertSee('250655')
        ->assertSee('Pre-filled from your last payout');
});

it('bounces the MD to the existing statement when a payout is already raised', function () {
    $existing = Payout::create([
        'reference' => Payout::generateReference(),
        'payee_type' => 'match_director',
        'payee_user_id' => $this->md->id,
        'match_id' => $this->match->id,
        'gross_amount' => 500,
        'fees_deducted' => 120,
        'net_amount' => 380,
        'status' => 'pending',
        'created_by' => $this->md->id,
    ]);

    $this->actingAs($this->md)
        ->get(route('matches.request-payout', $this->match))
        ->assertRedirect(route('my-payouts.show', $existing));
});

// ─── POST /matches/{match}/complete-and-request-payout ────────────────

it('rejects the payout POST when banking fields are missing', function () {
    $this->actingAs($this->md)
        ->from(route('matches.request-payout', $this->match))
        ->post(route('matches.complete-and-request-payout', $this->match), [])
        ->assertSessionHasErrors(['account_holder', 'bank_name', 'account_number', 'branch_code']);

    // No payout should have been raised when validation failed.
    expect(Payout::where('match_id', $this->match->id)->count())->toBe(0);
    // Match status should NOT have been flipped either.
    expect($this->match->fresh()->status)->toBe('closed');
});

it('rejects a payout POST when the branch code is not exactly 6 digits', function () {
    $this->actingAs($this->md)
        ->from(route('matches.request-payout', $this->match))
        ->post(route('matches.complete-and-request-payout', $this->match), [
            'account_holder' => 'Marco Director',
            'bank_name' => 'ABSA',
            'account_number' => '4055123456',
            'branch_code' => '63500', // 5 digits, invalid
        ])
        ->assertSessionHasErrors('branch_code');

    expect(Payout::where('match_id', $this->match->id)->count())->toBe(0);
});

it('saves banking details onto the payout, marks the match completed, and redirects to the statement', function () {
    $this->actingAs($this->md)
        ->post(route('matches.complete-and-request-payout', $this->match), [
            'account_holder' => 'Marco Director',
            'bank_name' => 'Standard Bank',
            'account_number' => '0123456789',
            'branch_code' => '051001',
        ])
        ->assertRedirect();

    $payout = Payout::where('match_id', $this->match->id)->firstOrFail();

    expect($payout->account_holder)->toBe('Marco Director')
        ->and($payout->bank_name)->toBe('Standard Bank')
        ->and($payout->account_number)->toBe('0123456789')
        ->and($payout->branch_code)->toBe('051001')
        ->and($payout->status)->toBe('pending')
        ->and($payout->payee_user_id)->toBe($this->md->id);

    expect($this->match->fresh()->status)->toBe('completed');
});

it('surfaces banking details on the my-payouts statement after submission', function () {
    $this->actingAs($this->md)
        ->post(route('matches.complete-and-request-payout', $this->match), [
            'account_holder' => 'Marco Director',
            'bank_name' => 'Nedbank',
            'account_number' => '1098765432',
            'branch_code' => '198765',
        ]);

    $payout = Payout::where('match_id', $this->match->id)->firstOrFail();

    $this->actingAs($this->md)
        ->get(route('my-payouts.show', $payout))
        ->assertOk()
        ->assertSee('Marco Director')
        ->assertSee('Nedbank')
        ->assertSee('1098765432')
        ->assertSee('198765');
});

// ─── Pending-entry projection ─────────────────────────────────────────
//
// Pending entries used to be hidden in a "not included" footer at the
// bottom of the per-entry breakdown. The MD will almost always chase
// the money from those shooters, so the Finances tab now treats them as
// projected rows with the worst-case gateway fee applied, and the
// request-payout page shows both the settled total (what SAPRF will pay
// today) and the projected total (what the MD will end up with).

it('projects a pending entry with worst-case gateway fee onto the finances breakdown', function () {
    // Second entry: unpaid, stored fees at zero (common for walk-ins /
    // import stubs). This is the worst case the back-fill must handle.
    $pendingShooter = \App\Models\User::factory()->create(['name' => 'Tiaan Scheepers']);
    MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $pendingShooter->id,
        'shooter_name' => $pendingShooter->name,
        'email' => $pendingShooter->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 500,
        'saprf_fee' => 0,
        'platform_fee' => 0,
        'gateway_fee' => 0,
        'surcharge_amount' => 0,
        'md_net_amount' => 0,
        'payment_status' => 'pending',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    $money = app(\App\Services\MatchFinanceService::class)->matchMoney($this->match->fresh());
    $projected = $money['projected_entries'];

    expect($projected)->toHaveCount(1);

    $tiaan = $projected->first();
    expect((float) $tiaan->saprf_fee)->toBeGreaterThan(0, 'SAPRF fee back-filled from settings')
        ->and((float) $tiaan->gateway_fee)->toBeGreaterThan(0, 'Gateway fee back-filled from worst-case rate')
        ->and((float) $tiaan->md_net_amount)->toBeGreaterThan(0)
        ->and((float) $tiaan->md_net_amount)->toBeLessThan((float) $tiaan->fee_amount, 'Net is less than gross after deductions');

    // Projected totals = settled totals + the back-filled pending row.
    expect((float) $money['md_payout_projection'])->toBeGreaterThan((float) $money['md_net'])
        ->and($money['projected_totals']['entry_count'])->toBe(2);
});

it('shows pending rows inline on the finances tab flagged Projected', function () {
    $tiaan = \App\Models\User::factory()->create(['name' => 'Tiaan Scheepers']);
    MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $tiaan->id,
        'shooter_name' => $tiaan->name,
        'email' => $tiaan->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 500,
        'saprf_fee' => 50,
        'platform_fee' => 50,
        'gateway_fee' => 19.50,
        'surcharge_amount' => 0,
        'md_net_amount' => 380.50,
        'payment_status' => 'pending',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    $this->actingAs($this->md)
        ->get(route('matches.finances', $this->match))
        ->assertOk()
        ->assertSee('Tiaan Scheepers')
        ->assertSee('Projected')
        ->assertSee('Projected total')
        // Totals row still says "settled" so the MD can see what's actually
        // in the bank right now vs. what they'll end up with.
        ->assertSee('Total settled');
});

it('shows both settled and projected totals on the request-payout form when a pending entry exists', function () {
    $tiaan = \App\Models\User::factory()->create(['name' => 'Tiaan Scheepers']);
    MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $tiaan->id,
        'shooter_name' => $tiaan->name,
        'email' => $tiaan->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 500,
        'saprf_fee' => 50,
        'platform_fee' => 50,
        'gateway_fee' => 19.50,
        'surcharge_amount' => 0,
        'md_net_amount' => 380.50,
        'payment_status' => 'pending',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    $this->actingAs($this->md)
        ->get(route('matches.request-payout', $this->match))
        ->assertOk()
        ->assertSee('Payout now (settled entries)')
        ->assertSee('Projected total');
});
