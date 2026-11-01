<?php

declare(strict_types=1);

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;
use App\Services\SettingsService;
use Carbon\Carbon;

/**
 * Phase 4.7 guarantee: when `show_non_members_on_podium` is false
 * (default), non-member scores must NOT appear on the podium block of
 * the /events listing card. Turning the setting on restores them.
 *
 * The target is `event-card.blade.php`, which is rendered on
 * `/events?tab=results` for completed matches with scores.
 */
beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->division = Division::firstOrCreate(['slug' => 'open'], ['name' => 'Open', 'display_order' => 1, 'is_active' => true]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->match = MatchEvent::create([
        'name' => 'Non-Member Podium Test',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'provincial',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::yesterday()->toDateString(),
        'status' => 'completed',
        'published' => true,
        'created_by' => $this->admin->id,
        'active_member_fee' => 100,
        'non_member_fee' => 100,
        'lapsed_member_fee' => 100,
    ]);

    // Winning score is a non-member; second & third are valid members.
    $this->nonMember = User::factory()->create(['name' => 'Johan Nonmember']);
    $this->memberA = User::factory()->create(['name' => 'Sarah Member']);
    $this->memberB = User::factory()->create(['name' => 'Pieter Member']);

    foreach ([$this->memberA, $this->memberB] as $i => $u) {
        Membership::create([
            'user_id' => $u->id,
            'saprf_number' => 'MEMBER-'.$i,
            'membership_type' => 'paid',
            'status' => 'active',
            'payment_status' => 'paid',
            'start_date' => Carbon::today()->subYear(),
            'expiry_date' => Carbon::today()->addYear(),
        ]);
    }

    $matchDate = $this->match->match_date->toDateString();
    Score::create([
        'match_id' => $this->match->id,
        'user_id' => $this->nonMember->id,
        'shooter_name' => 'Johan Nonmember',
        'raw_score' => 150, 'placement' => 1, 'overall_rank' => 1, 'division_rank' => 1,
        'status' => 'non_member', 'division_id' => $this->division->id, 'counts_for_season' => false,
        'match_date' => $matchDate,
    ]);
    Score::create([
        'match_id' => $this->match->id,
        'user_id' => $this->memberA->id,
        'shooter_name' => 'Sarah Member',
        'raw_score' => 140, 'placement' => 2, 'overall_rank' => 2, 'division_rank' => 2,
        'status' => 'valid', 'division_id' => $this->division->id, 'counts_for_season' => true,
        'match_date' => $matchDate,
    ]);
    Score::create([
        'match_id' => $this->match->id,
        'user_id' => $this->memberB->id,
        'shooter_name' => 'Pieter Member',
        'raw_score' => 130, 'placement' => 3, 'overall_rank' => 3, 'division_rank' => 3,
        'status' => 'valid', 'division_id' => $this->division->id, 'counts_for_season' => true,
        'match_date' => $matchDate,
    ]);
});

afterEach(function () {
    app(SettingsService::class)->clearCache();
});

it('hides non-members from the events-card podium by default', function () {
    $response = $this->get('/events?tab=results');

    $response->assertOk();
    // Members ARE on the podium.
    $response->assertSee('Sarah Member');
    $response->assertSee('Pieter Member');
    // Non-member is NOT.
    $response->assertDontSee('Johan Nonmember');
});

it('shows non-members on the events-card podium when the setting is on', function () {
    app(SettingsService::class)->set(SettingsService::SHOW_NON_MEMBERS_ON_PODIUM, true);

    $response = $this->get('/events?tab=results');

    $response->assertOk();
    $response->assertSee('Johan Nonmember');
    $response->assertSee('Sarah Member');
});
