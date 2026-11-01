<?php

declare(strict_types=1);

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * Phase 1.5: /scores (admin) must sort by match_date desc, match_id asc,
 * placement asc, with a tie-break on score id so pagination is strictly
 * deterministic. Without this, placements 54-73 showed before 1 for the
 * same match on page 1.
 */
beforeEach(function () {
    seedRoles();

    $this->province = Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    $this->division = Division::firstOrCreate(
        ['slug' => 'open'],
        ['name' => 'Open', 'display_order' => 1, 'is_active' => true]
    );

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

/**
 * Create a bare match with a given date.
 */
function sortTestMatch(string $name, Carbon $date, User $creator, Province $province): MatchEvent
{
    return MatchEvent::create([
        'name' => $name,
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'provincial',
        'season' => '2026',
        'province_id' => $province->id,
        'match_date' => $date->toDateString(),
        'status' => 'completed',
        'published' => true,
        'created_by' => $creator->id,
        'active_member_fee' => 100,
        'non_member_fee' => 100,
        'lapsed_member_fee' => 100,
    ]);
}

it('orders by match_date desc, then match_id, then placement asc', function () {
    // Two matches, newer first by date; the older one is created first so
    // its match_id is smaller — in the broken state its rows won.
    $olderMatch = sortTestMatch('Older Match', Carbon::today()->subDays(14), $this->admin, $this->province);
    $newerMatch = sortTestMatch('Newer Match', Carbon::today()->subDays(7), $this->admin, $this->province);

    // Insert placements out of order; the controller's sort should put
    // placement 1 before placement 73 within the same match regardless.
    $planted = [
        [$olderMatch->id, 1, Carbon::today()->subDays(14)],
        [$olderMatch->id, 73, Carbon::today()->subDays(14)],
        [$newerMatch->id, 73, Carbon::today()->subDays(7)],
        [$newerMatch->id, 1, Carbon::today()->subDays(7)],
    ];

    foreach ($planted as $i => [$matchId, $placement, $matchDate]) {
        $user = User::factory()->create(['name' => "User {$i}"]);
        Score::create([
            'match_id' => $matchId,
            'user_id' => $user->id,
            'shooter_name' => $user->name,
            'raw_score' => 100 - $placement, // just a number
            'placement' => $placement,
            'division_id' => $this->division->id,
            'status' => 'pending',
            'is_member' => true,
            'match_date' => $matchDate->toDateString(),
        ]);
    }

    // Phase 5: the admin /scores view is now a Livewire island. The sort
    // contract lives in the component's `scores` computed property, so
    // test it there directly instead of digging through viewData.
    $this->actingAs($this->admin);
    $component = Livewire::test('admin.scores-table');

    $paginator = $component->instance()->scores;

    $expected = [
        ['match_id' => $newerMatch->id, 'placement' => 1],
        ['match_id' => $newerMatch->id, 'placement' => 73],
        ['match_id' => $olderMatch->id, 'placement' => 1],
        ['match_id' => $olderMatch->id, 'placement' => 73],
    ];

    $actual = collect($paginator->items())
        ->map(fn (Score $s) => ['match_id' => $s->match_id, 'placement' => $s->placement])
        ->values()
        ->all();

    expect($actual)->toBe($expected);
});
