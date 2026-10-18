<?php

use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);

    $this->md = User::factory()->create();
    $this->md->assignRole('match_director');
});

function makePublishedMatch(array $overrides = []): MatchEvent
{
    return MatchEvent::create(array_merge([
        'name' => 'Visibility Test Match',
        'match_type' => 'PR22',
        'series_level' => 'provincial',
        'series' => 'PR22',
        'season' => (string) now()->year,
        'province_id' => test()->province->id,
        'match_date' => Carbon::today()->addWeek(),
        'status' => 'open',
        'published' => true,
        'match_director' => test()->md->name,
        'active_member_fee' => 500,
        'created_by' => test()->md->id,
    ], $overrides));
}

it('hides cancelled past matches from the Results tab', function () {
    $cancelled = makePublishedMatch([
        'name' => 'Cancelled Past Provincial',
        'match_date' => Carbon::today()->subDay(),
        'status' => 'cancelled',
    ]);

    $completed = makePublishedMatch([
        'name' => 'Completed Past Provincial',
        'match_date' => Carbon::today()->subDays(2),
        'status' => 'completed',
    ]);

    $this->get(route('events.index', ['tab' => 'results', 'season' => (string) now()->year]))
        ->assertOk()
        ->assertDontSee('Cancelled Past Provincial')
        ->assertSee('Completed Past Provincial');

    expect(MatchEvent::query()->published()->past()->pluck('id'))
        ->not->toContain($cancelled->id)
        ->toContain($completed->id);
});

it('still lists cancelled future matches under Upcoming', function () {
    $cancelled = makePublishedMatch([
        'name' => 'Cancelled Upcoming Provincial',
        'match_date' => Carbon::today()->addWeek(),
        'status' => 'cancelled',
    ]);

    $this->get(route('events.index', ['tab' => 'upcoming']))
        ->assertOk()
        ->assertSee('Cancelled Upcoming Provincial');

    expect(MatchEvent::query()->published()->upcoming()->pluck('id'))
        ->toContain($cancelled->id);
});
