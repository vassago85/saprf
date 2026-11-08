<?php

/**
 * Match registration page must state that entering a match is subject to
 * SAPRF's terms and conditions.
 *
 * This is implied consent — no checkbox, no stored acknowledgement, just a
 * visible statement at the moment of commitment with a link to `/terms`.
 * Guards against the notice being removed in a future redesign.
 */

use App\Models\Club;
use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
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
    // Provincial matches are members-only unless the director has opened
    // them to club shooters, so the test shooter must carry an active full
    // federation membership for the registration page to render. The
    // SASCOC profile fields also need to be populated or the
    // EnsureProfileComplete middleware bounces the shooter to /profile
    // before the controller ever runs.
    $province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $club = Club::firstOrCreate(
        ['slug' => 'terms-club'],
        [
            'name' => 'Terms Club',
            'abbreviation' => 'TC',
            'province_id' => $province->id,
            'is_active' => true,
        ],
    );

    $shooter = User::factory()->create(['email_verified_at' => now()]);
    $shooter->assignRole('member');
    $shooter->forceFill([
        'province_id' => $province->id,
        'club_id' => $club->id,
        'date_of_birth' => '1990-01-01',
        'gender' => 'male',
        'ethnicity' => 'white',
        'country_of_residence' => 'ZA',
        'sa_id_number' => str_pad((string) $shooter->id, 13, '0', STR_PAD_LEFT),
        'sa_citizen' => true,
        'previously_disadvantaged' => false,
    ])->save();

    Membership::create([
        'user_id' => $shooter->id,
        'saprf_number' => 'TERMS-'.$shooter->id,
        'membership_type' => 'full',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => Carbon::today()->subMonths(2),
        'expiry_date' => Carbon::today()->addMonths(10),
    ]);

    $response = $this->actingAs($shooter->fresh())
        ->get(route('events.register', $this->match))
        ->assertOk();

    $response->assertSee('terms and conditions', false)
        ->assertSee(route('legal.terms'), false);
});
