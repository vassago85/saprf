<?php

/**
 * Match registration page must state that entering a match is subject to
 * SAPRF's terms and conditions.
 *
 * This is implied consent — no checkbox, no stored acknowledgement, just a
 * visible statement at the moment of commitment with a link to `/terms`.
 * Guards against the notice being removed in a future redesign.
 */

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();

    Division::firstOrCreate(['slug' => 'open'], [
        'name' => 'Open',
        'is_active' => true,
        'display_order' => 1,
    ]);

    $this->match = MatchEvent::create([
        'name' => 'Terms Notice Match',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2026',
        'match_date' => Carbon::today()->addMonth(),
        'status' => 'open',
        'published' => true,
        'match_director' => 'Test Director',
        'active_member_fee' => 500,
        'non_member_fee' => 750,
        'created_by' => User::factory()->create()->id,
    ]);
});

it('shows the SAPRF terms and conditions notice on the match registration page', function () {
    $shooter = User::factory()->create(['email_verified_at' => now()]);
    $shooter->assignRole('member');

    $response = $this->actingAs($shooter)
        ->get(route('events.register', $this->match))
        ->assertOk();

    $response->assertSee('terms and conditions', false)
        ->assertSee(route('legal.terms'), false);
});
