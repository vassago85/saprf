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

// ── financials/match-report ─────────────────────────────────────────

it('shows the MD their expenses and profit/loss on the financial report', function () {
    $this->actingAs($this->md)
        ->get(route('financials.match-report', $this->match))
        ->assertOk()
        ->assertSee('Profit / Loss')
        ->assertSee('Match Expenses')
        ->assertSee('Steel targets rental')
        ->assertSee('Only you see this');
});

it('hides expenses and profit/loss from owner viewing the financial report', function () {
    $owner = User::factory()->create();
    $owner->assignRole('owner');

    $response = $this->actingAs($owner)
        ->get(route('financials.match-report', $this->match))
        ->assertOk();

    $response->assertDontSee('Steel targets rental');
    $response->assertDontSee('Profit / Loss');
    $response->assertDontSee('Match Expenses');
    // Payout number must still be visible.
    $response->assertSee('MD Net Payout');
});

it('hides expenses and profit/loss from exco viewing the financial report', function () {
    $exco = User::factory()->create();
    $exco->assignRole('exco');

    $response = $this->actingAs($exco)
        ->get(route('financials.match-report', $this->match))
        ->assertOk();

    $response->assertDontSee('Steel targets rental');
    $response->assertDontSee('Profit / Loss');
    $response->assertSee('MD Net Payout');
});

it('hides expenses and profit/loss from admin and developer viewing the financial report', function () {
    foreach (['admin', 'developer'] as $role) {
        $user = User::factory()->create();
        $user->assignRole($role);

        $response = $this->actingAs($user)
            ->get(route('financials.match-report', $this->match))
            ->assertOk();

        $response->assertDontSee('Steel targets rental', "role={$role}");
        $response->assertDontSee('Profit / Loss', "role={$role}");
        $response->assertSee('MD Net Payout');
    }
});

// ── matches/show ────────────────────────────────────────────────────

it('shows the MD their expense tracker on the match show page', function () {
    $this->actingAs($this->md)
        ->get(route('matches.show', $this->match))
        ->assertOk()
        ->assertSee('Match Expenses')
        ->assertSee('Steel targets rental')
        ->assertSee('Only you see this');
});

it('hides the expense tracker from owner on the match show page even though they can edit', function () {
    $owner = User::factory()->create();
    $owner->assignRole('owner');

    $response = $this->actingAs($owner)
        ->get(route('matches.show', $this->match))
        ->assertOk();

    $response->assertDontSee('Steel targets rental');
    $response->assertDontSee('Match Expenses');
});

it('hides the expense tracker from admin on the match show page even though they can edit', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $response = $this->actingAs($admin)
        ->get(route('matches.show', $this->match))
        ->assertOk();

    $response->assertDontSee('Steel targets rental');
    $response->assertDontSee('Match Expenses');
});

// ── Access control ───────────────────────────────────────────────────

it('refuses to show the financial report to a match director from a different match', function () {
    $otherMd = User::factory()->create();
    $otherMd->assignRole('match_director');

    $this->actingAs($otherMd)
        ->get(route('financials.match-report', $this->match))
        ->assertForbidden();
});

it('refuses to show the financial report to an ordinary member', function () {
    $member = User::factory()->create();
    $member->assignRole('member');

    $this->actingAs($member)
        ->get(route('financials.match-report', $this->match))
        ->assertForbidden();
});
