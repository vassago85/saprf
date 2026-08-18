<?php

use App\Models\Division;
use App\Models\Province;
use App\Models\Standing;
use App\Models\User;

beforeEach(function () {
    $this->gp = Province::create(['name' => 'Gauteng', 'abbreviation' => 'GP']);
    $this->wc = Province::create(['name' => 'Western Cape', 'abbreviation' => 'WC']);
    $this->lp = Province::create(['name' => 'Limpopo', 'abbreviation' => 'LP']);

    $this->open = Division::create(['slug' => 'open', 'name' => 'Open']);
    $this->senior = Division::create(['slug' => 'senior', 'name' => 'Senior']);

    // Three shooters, each in a different province — makes the multi-scope
    // "rank 1 in each province" situation from the bug report reproducible.
    $this->alice = User::factory()->create(['name' => 'Alice Zulu', 'province_id' => $this->gp->id]);
    $this->bob   = User::factory()->create(['name' => 'Bob Alpha',  'province_id' => $this->wc->id]);
    $this->cara  = User::factory()->create(['name' => 'Cara Mid',   'province_id' => $this->lp->id]);

    // Provincial, division=null (overall) rows — one per shooter's home
    // province. Each is rank 1 in its own (province, no-division) scope.
    // This matches how the standings service writes overall/provincial rows.
    Standing::create([
        'user_id' => $this->alice->id, 'series' => 'PR22', 'season' => '2026',
        'province_id' => $this->gp->id, 'division_id' => null,
        'points' => 150.0, 'rank' => 1,
    ]);
    Standing::create([
        'user_id' => $this->bob->id, 'series' => 'PR22', 'season' => '2026',
        'province_id' => $this->wc->id, 'division_id' => null,
        'points' => 300.0, 'rank' => 1,
    ]);
    Standing::create([
        'user_id' => $this->cara->id, 'series' => 'PR22', 'season' => '2026',
        'province_id' => $this->lp->id, 'division_id' => null,
        'points' => 225.0, 'rank' => 1,
    ]);
});

function fetchProvincialStandings(array $extra = []): \Illuminate\Support\Collection
{
    $params = array_merge([
        'season' => '2026',
        'series' => 'PR22',
        'level' => 'provincial',
    ], $extra);
    $response = test()->get('/standings?'.http_build_query($params));
    $response->assertOk();

    return collect($response->viewData('standings'))->values();
}

it('defaults to points desc when no division/province filter is applied', function () {
    $standings = fetchProvincialStandings();

    expect($standings->pluck('user_id')->all())->toBe([
        $this->bob->id,   // 300.0
        $this->cara->id,  // 225.0
        $this->alice->id, // 150.0
    ]);
});

it('flips to points asc when the user clicks Points a second time', function () {
    $standings = fetchProvincialStandings(['sort' => 'points', 'direction' => 'asc']);

    expect($standings->pluck('user_id')->all())->toBe([
        $this->alice->id, // 150.0
        $this->cara->id,  // 225.0
        $this->bob->id,   // 300.0
    ]);
});

it('sorts by shooter name ascending', function () {
    $standings = fetchProvincialStandings(['sort' => 'shooter', 'direction' => 'asc']);

    expect($standings->pluck('user_id')->all())->toBe([
        $this->alice->id, // Alice Zulu
        $this->bob->id,   // Bob Alpha
        $this->cara->id,  // Cara Mid
    ]);
});

it('sorts by shooter name descending', function () {
    $standings = fetchProvincialStandings(['sort' => 'shooter', 'direction' => 'desc']);

    expect($standings->pluck('user_id')->all())->toBe([
        $this->cara->id,
        $this->bob->id,
        $this->alice->id,
    ]);
});

it('sorts by province ascending on provincial view', function () {
    // Provinces: Gauteng, Limpopo, Western Cape (alphabetical)
    $standings = fetchProvincialStandings(['sort' => 'province', 'direction' => 'asc']);

    expect($standings->pluck('user_id')->all())->toBe([
        $this->alice->id, // Gauteng
        $this->cara->id,  // Limpopo
        $this->bob->id,   // Western Cape
    ]);
});

it('sorts by rank asc — mostly cosmetic here but confirms the option works', function () {
    // All three rows are rank 1, so the stable id tie-breaker orders them
    // by creation order (alice, bob, cara).
    $standings = fetchProvincialStandings(['sort' => 'rank', 'direction' => 'asc']);

    expect($standings->pluck('user_id')->all())->toBe([
        $this->alice->id,
        $this->bob->id,
        $this->cara->id,
    ]);
});

it('exposes the resolved sort/direction to the view', function () {
    $response = $this->get('/standings?'.http_build_query([
        'season' => '2026',
        'series' => 'PR22',
        'level' => 'provincial',
    ]));
    $response->assertOk();

    expect($response->viewData('sort'))->toBe('points')
        ->and($response->viewData('direction'))->toBe('desc');
});

it('defaults to rank asc when a full single-scope filter is applied', function () {
    // Provincial + specific province + specific division = one ranking scope,
    // so "rank" is meaningful and remains the natural default.
    Standing::create([
        'user_id' => $this->alice->id, 'series' => 'PR22', 'season' => '2026',
        'province_id' => $this->gp->id, 'division_id' => $this->open->id,
        'points' => 200.0, 'rank' => 2,
    ]);
    Standing::create([
        'user_id' => $this->bob->id, 'series' => 'PR22', 'season' => '2026',
        'province_id' => $this->gp->id, 'division_id' => $this->open->id,
        'points' => 100.0, 'rank' => 1, // deliberately inverted vs points, to prove the sort key used
    ]);

    $response = $this->get('/standings?'.http_build_query([
        'season' => '2026',
        'series' => 'PR22',
        'level' => 'provincial',
        'division_id' => $this->open->id,
        'province_id' => $this->gp->id,
    ]));
    $response->assertOk();

    expect($response->viewData('sort'))->toBe('rank')
        ->and($response->viewData('direction'))->toBe('asc')
        ->and(collect($response->viewData('standings'))->pluck('user_id')->all())->toBe([
            $this->bob->id,   // rank 1
            $this->alice->id, // rank 2
        ]);
});

it('ignores an unknown sort value and falls back to the default', function () {
    $response = $this->get('/standings?'.http_build_query([
        'season' => '2026',
        'series' => 'PR22',
        'level' => 'provincial',
        'sort' => 'not-a-column',
        'direction' => 'sideways',
    ]));
    $response->assertOk();

    expect($response->viewData('sort'))->toBe('points')
        ->and($response->viewData('direction'))->toBe('desc');
});

it('renders clickable sort links in the header with active-column indicator', function () {
    $response = $this->get('/standings?season=2026&series=PR22&level=provincial');
    $response->assertOk();

    $html = $response->getContent();

    // The Points header is the active default sort — should have the ↓ arrow.
    expect($html)->toContain('sort=points')
        ->toContain('sort=shooter')
        ->toContain('sort=province')
        ->toContain('sort=rank')
        ->toContain('↓'); // active desc indicator on Points
});
