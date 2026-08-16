<?php

use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $this->md = User::factory()->create();
    $this->md->assignRole('match_director');

    $this->match = MatchEvent::create([
        'name' => 'Hidden Draft Visibility Match',
        'match_type' => 'PRS',
        'series_level' => 'provincial',
        'series' => 'PRS',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::today()->addMonth(),
        'status' => 'open',
        'published' => true,
        'match_director' => $this->md->name,
        'active_member_fee' => 500,
        'created_by' => $this->md->id,
    ]);
});

it('unpublishes a match and hides it from the public when status is changed back to draft', function () {
    $this->actingAs($this->md)
        ->put(route('matches.update', $this->match), [
            'name' => $this->match->name,
            'match_type' => 'PRS',
            'series_level' => 'provincial',
            'match_date' => $this->match->match_date->format('Y-m-d'),
            'active_member_fee' => 500,
            'status' => 'draft',
        ])
        ->assertRedirect(route('matches.show', $this->match));

    $this->match->refresh();

    expect($this->match->status)->toBe('draft')
        ->and($this->match->published)->toBeFalse();

    $this->get(route('events.index'))
        ->assertOk()
        ->assertDontSee($this->match->name);

    $this->get(route('events.show', $this->match))
        ->assertNotFound();
});

it('hides a draft match from the public even when the published flag was left on', function () {
    // Simulate the pre-fix production row: status flipped to draft without
    // clearing the published flag. Bypass Eloquent so the saving hook cannot
    // tidy the row first.
    DB::table('matches')->where('id', $this->match->id)->update([
        'status' => 'draft',
        'published' => 1,
    ]);
    $this->match->refresh();

    $this->get(route('events.index'))
        ->assertOk()
        ->assertDontSee($this->match->name);

    $this->get(route('events.show', $this->match))
        ->assertNotFound();

    $this->getJson('/api/v1/events/calendar?month=' . $this->match->match_date->month . '&year=' . $this->match->match_date->year)
        ->assertOk()
        ->assertJsonMissing(['name' => $this->match->name]);
});
