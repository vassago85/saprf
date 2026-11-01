<?php

declare(strict_types=1);

use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\User;
use Carbon\Carbon;

/**
 * Phase 2.5 guarantees: Day-1 provincial siblings (rows with
 * source_national_match_id set) must NOT appear as their own cards on the
 * public /events list. The paired national should carry a nested row that
 * points at the sibling instead, so a shooter sees one event, not two.
 *
 * `?include_children=1` is an audit escape hatch — the full flat list is
 * still reachable for match directors who need to spot-check the mapping.
 */
beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $this->director = User::factory()->create();
    $this->director->assignRole('match_director');

    $this->national = MatchEvent::create([
        'name' => 'Darling Steel Valley National',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'national',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::parse('2026-10-24')->toDateString(),
        'match_end_date' => Carbon::parse('2026-10-25')->toDateString(),
        'status' => 'published',
        'published' => true,
        'created_by' => $this->director->id,
        'active_member_fee' => 100,
        'non_member_fee' => 100,
        'lapsed_member_fee' => 100,
    ]);

    $this->day1Sibling = MatchEvent::create([
        'name' => 'Darling Steel Valley Day 1 (Gauteng Provincial)',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'provincial',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::parse('2026-10-24')->toDateString(),
        'status' => 'published',
        'published' => true,
        'created_by' => $this->director->id,
        'source_national_match_id' => $this->national->id,
        'active_member_fee' => 100,
        'non_member_fee' => 100,
        'lapsed_member_fee' => 100,
    ]);
});

it('hides the Day-1 provincial sibling from the public /events list by default', function () {
    $response = $this->get('/events?tab=results');

    $response->assertOk();
    // The parent national headline is on the page.
    $response->assertSee('Darling Steel Valley National');

    // The sibling's name still appears exactly ONCE as a nested "Day 1 also
    // counts as …" link on the parent card. When the sibling ALSO had its
    // own standalone card (what we're preventing) the name would appear
    // twice — once as the card heading, once as the nested link.
    $body = $response->getContent();
    $occurrences = substr_count($body, 'Darling Steel Valley Day 1 (Gauteng Provincial)');
    expect($occurrences)->toBe(1, "Sibling name appeared {$occurrences}× — expected 1 (nested link only).");
});

it('shows the Day-1 provincial sibling as its own card when ?include_children=1 is used', function () {
    $response = $this->get('/events?tab=results&include_children=1');

    $response->assertOk();
    $response->assertSee('Darling Steel Valley National');

    // Audit escape hatch: both cards are present, so the sibling name
    // appears at least twice (its own card heading plus the nested link
    // on the parent card).
    $body = $response->getContent();
    $occurrences = substr_count($body, 'Darling Steel Valley Day 1 (Gauteng Provincial)');
    expect($occurrences)->toBeGreaterThanOrEqual(2);
});
