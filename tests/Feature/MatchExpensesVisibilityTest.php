<?php

/**
 * Match expenses + profit/loss are the MD's private ledger.
 *
 * These tests lock in the rule that only the match creator sees their expense
 * tracker (on the match show page) and their P&L breakdown (on the financial
 * match report). Owner, admin, developer, and exco viewers only get the
 * match payout — they don't see the MD's operating costs or profit margin.
 *
 * The controllers still allow owner/admin to hit the expense write endpoints
 * (safety valve for cleanup), but the surface area is invisible to them.
 */

use App\Models\MatchEvent;
use App\Models\MatchExpense;
use App\Models\MatchRegistration;
use App\Models\Province;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedRoles();

    // seedRoles seeds developer + exco already, but we want to be defensive.
    foreach (['developer', 'exco'] as $role) {
        Role::firstOrCreate(['name' => $role]);
    }

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $this->md = User::factory()->create(['name' => 'Match Director Dan']);
    $this->md->assignRole('match_director');

    $this->match = MatchEvent::create([
        'name' => 'Private Ledger Match',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => now()->addDays(14),
        'status' => 'open',
        'active_member_fee' => 500,
        'non_member_fee' => 750,
        'lapsed_member_fee' => 650,
        'created_by' => $this->md->id,
    ]);

    // A paid registration so the financial report has something to render.
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

    // A private expense that only the MD should see.
    MatchExpense::create([
        'match_id' => $this->match->id,
        'description' => 'Steel targets rental',
        'amount' => 200,
        'cost_type' => 'fixed',
        'category' => 'targets',
        'created_by' => $this->md->id,
    ]);
});

// ── Finances tab (replaces the old financial-match-report) ───────────
//
// The match workspace unifies the old "match show" and
// "financials.match-report" surfaces into a single Finances tab. The MD
// sees expenses + estimated profit; everyone else sees the payout
// ledger with the expense panel suppressed. The legacy route names
// (`matches.show`, `financials.match-report`) remain as 301 aliases so
// bookmarks and audit entries keep resolving — we hit the finances tab
// directly here to assert the rendered content, not the redirect.

it('shows the MD their expenses and estimated profit on the finances tab', function () {
    $this->actingAs($this->md)
        ->get(route('matches.finances', $this->match))
        ->assertOk()
        ->assertSee('Profit after costs')
        ->assertSee('Estimated')
        ->assertSee('Adult entry fee')
        ->assertSee('Full adult fees')
        ->assertSee('Registered shooters')
        ->assertSee('Net after SAPRF, platform and gateway')
        ->assertSee('Fixed costs')
        ->assertSee('Per-shooter costs')
        ->assertSee('Left after costs')
        ->assertSee('R 500.00')
        ->assertSee('R 380.00')
        ->assertSee('R 180.00')
        ->assertSee('Match expenses')
        ->assertSee('Steel targets rental')
        ->assertSee('Only you see this');
});

it('hides expenses and profit from owner viewing the finances tab', function () {
    $owner = User::factory()->create();
    $owner->assignRole('owner');

    $response = $this->actingAs($owner)
        ->get(route('matches.finances', $this->match))
        ->assertOk();

    $response->assertDontSee('Steel targets rental');
    $response->assertDontSee('Profit after costs');
    $response->assertDontSee('Fixed costs');
    $response->assertDontSee('Left after costs');
    $response->assertDontSee('Match expenses');
    // Payout number must still be visible.
    $response->assertSee('Net payout to you');
});

it('hides expenses and profit from exco viewing the finances tab', function () {
    $exco = User::factory()->create();
    $exco->assignRole('exco');

    $response = $this->actingAs($exco)
        ->get(route('matches.finances', $this->match))
        ->assertOk();

    $response->assertDontSee('Steel targets rental');
    $response->assertDontSee('Profit after costs');
    $response->assertDontSee('Fixed costs');
    $response->assertDontSee('Left after costs');
    $response->assertSee('Net payout to you');
});

it('hides expenses and profit from admin and developer viewing the finances tab', function () {
    foreach (['admin', 'developer'] as $role) {
        $user = User::factory()->create();
        $user->assignRole($role);

        $response = $this->actingAs($user)
            ->get(route('matches.finances', $this->match))
            ->assertOk();

        $response->assertDontSee('Steel targets rental', "role={$role}");
        $response->assertDontSee('Profit after costs', "role={$role}");
        $response->assertDontSee('Fixed costs', "role={$role}");
        $response->assertDontSee('Left after costs', "role={$role}");
        $response->assertSee('Net payout to you');
    }
});

// ── Overview tab visibility of expenses ──────────────────────────────
//
// Expenses no longer live on the overview tab — they only appear on the
// Finances tab, and only for the MD. The overview tab shows stat tiles
// + recent activity, so the private-expense strings must not leak.

it('does not leak the MD expense tracker onto the overview tab for the MD', function () {
    $this->actingAs($this->md)
        ->get(route('matches.overview', $this->match))
        ->assertOk()
        ->assertDontSee('Steel targets rental');
});

it('does not leak the expense tracker to owner on the overview tab', function () {
    $owner = User::factory()->create();
    $owner->assignRole('owner');

    $this->actingAs($owner)
        ->get(route('matches.overview', $this->match))
        ->assertOk()
        ->assertDontSee('Steel targets rental');
});

it('does not leak the expense tracker to admin on the overview tab', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->get(route('matches.overview', $this->match))
        ->assertOk()
        ->assertDontSee('Steel targets rental');
});

// ── Access control ───────────────────────────────────────────────────

it('refuses to show the finances tab to a match director from a different match', function () {
    $otherMd = User::factory()->create();
    $otherMd->assignRole('match_director');

    $this->actingAs($otherMd)
        ->get(route('matches.finances', $this->match))
        ->assertForbidden();
});

it('refuses to show the finances tab to an ordinary member', function () {
    $member = User::factory()->create();
    $member->assignRole('member');

    // The workspace route group is behind role middleware — members get
    // bounced before the policy runs; assert they do not land on 200.
    $response = $this->actingAs($member)
        ->get(route('matches.finances', $this->match));

    expect($response->getStatusCode())->not->toBe(200);
});

// ── Cost estimator lives on Finances, not Settings ───────────────────

it('shows an editable cost estimator on the finances tab', function () {
    $this->actingAs($this->md)
        ->get(route('matches.finances', $this->match))
        ->assertOk()
        ->assertSee('Cost Estimator')
        ->assertSee('name="estimated_shooters"', false)
        ->assertSee('Save estimate');
});

it('does not render the cost estimator on the settings tab', function () {
    $this->actingAs($this->md)
        ->get(route('matches.settings', $this->match))
        ->assertOk()
        ->assertDontSee('Cost Estimator');
});

it('lets the match director save the expected shooter count from finances', function () {
    $this->actingAs($this->md)
        ->put(route('matches.estimated-shooters.update', $this->match), [
            'estimated_shooters' => 42,
        ])
        ->assertRedirect(route('matches.finances', $this->match))
        ->assertSessionHas('success');

    expect($this->match->fresh()->estimated_shooters)->toBe(42);
});

it('refuses a foreign match director saving the expected shooter count', function () {
    $otherMd = User::factory()->create();
    $otherMd->assignRole('match_director');

    $this->actingAs($otherMd)
        ->put(route('matches.estimated-shooters.update', $this->match), [
            'estimated_shooters' => 42,
        ])
        ->assertForbidden();

    expect($this->match->fresh()->estimated_shooters)->toBeNull();
});

it('extends per-shooter match costs by the registered headcount', function () {
    $second = User::factory()->create();
    MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $second->id,
        'shooter_name' => $second->name,
        'email' => $second->email,
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

    $this->match->update(['estimated_shooters' => 40]);

    MatchExpense::create([
        'match_id' => $this->match->id,
        'description' => 'Lunch per shooter',
        'amount' => 25,
        'cost_type' => 'per_shooter',
        'category' => 'catering',
        'created_by' => $this->md->id,
    ]);

    $this->actingAs($this->md)
        ->get(route('matches.finances', $this->match))
        ->assertOk()
        ->assertSee('R 25.00 × 2')
        ->assertSee('R 50.00');
});
