<?php

use App\Models\Division;
use App\Models\Province;
use App\Models\Standing;
use App\Models\User;
use App\Services\SeasonStandingsBoard;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->gp = Province::create(['name' => 'Gauteng', 'abbreviation' => 'GP']);
    $this->wc = Province::create(['name' => 'Western Cape', 'abbreviation' => 'WC']);
    $this->open = Division::create(['slug' => 'open', 'name' => 'Open', 'is_active' => true, 'display_order' => 1]);
    $this->factory = Division::create(['slug' => 'factory', 'name' => 'Factory', 'is_active' => true, 'display_order' => 2]);
});

function rankStanding(User $user, string $level, float $points, int $storedRank, ?int $provinceId = null, ?int $divisionId = null, string $series = 'PR22'): Standing
{
    return Standing::create([
        'user_id' => $user->id,
        'series' => $series,
        'season' => '2026',
        'province_id' => $level === 'provincial' ? $provinceId : null,
        'division_id' => $divisionId,
        'points' => $points,
        'rank' => $storedRank,
    ]);
}

it('ranks all provinces as one table and shows each shooter rank inside their province', function () {
    $gpLeader = User::factory()->create(['name' => 'Gauteng Leader', 'province_id' => $this->gp->id]);
    $capeLeader = User::factory()->create(['name' => 'Cape Leader', 'province_id' => $this->wc->id]);
    $gpSecond = User::factory()->create(['name' => 'Gauteng Second', 'province_id' => $this->gp->id]);

    rankStanding($gpLeader, 'provincial', 200, 2, $this->gp->id);
    rankStanding($capeLeader, 'provincial', 300, 1, $this->wc->id);
    rankStanding($gpSecond, 'provincial', 100, 1, $this->gp->id);

    $page = app(SeasonStandingsBoard::class)->load('2026', 'PR22', 'provincial', null, null, null, null, null);

    expect($page->showProvinceRank)->toBeTrue()
        ->and($page->standings->pluck('rank_label')->all())->toBe(['1', '2', '3'])
        ->and($page->standings->pluck('user_id')->all())->toBe([
            $capeLeader->id,
            $gpLeader->id,
            $gpSecond->id,
        ])
        ->and($page->standings->map(fn ($row) => $row->province->abbreviation.' '.$row->province_rank_label)->all())->toBe([
            'WC 1',
            'GP 1',
            'GP 2',
        ]);

    $this->get('/standings?season=2026&series=PR22&level=provincial')
        ->assertOk()
        ->assertSeeInOrder(['Cape Leader', 'Gauteng Leader', 'Gauteng Second'])
        ->assertSee('data-rank-label="1"', false)
        ->assertSee('data-rank-label="2"', false)
        ->assertSee('data-rank-label="3"', false)
        ->assertSee('data-province-rank="1"', false)
        ->assertSee('data-province-rank="2"', false);
});

it('ranks a single province on its own and hides the province rank label', function () {
    $gpLeader = User::factory()->create(['name' => 'Gauteng Leader', 'province_id' => $this->gp->id]);
    $capeLeader = User::factory()->create(['name' => 'Cape Leader', 'province_id' => $this->wc->id]);
    $capeSecond = User::factory()->create(['name' => 'Cape Second', 'province_id' => $this->wc->id]);

    rankStanding($gpLeader, 'provincial', 400, 1, $this->gp->id);
    rankStanding($capeLeader, 'provincial', 300, 1, $this->wc->id);
    rankStanding($capeSecond, 'provincial', 200, 2, $this->wc->id);

    $page = app(SeasonStandingsBoard::class)->load(
        '2026', 'PR22', 'provincial', null, $this->wc->id, null, null, null,
    );

    expect($page->showProvinceRank)->toBeFalse()
        ->and($page->standings)->toHaveCount(2)
        ->and($page->standings->pluck('rank_label')->all())->toBe(['1', '2'])
        ->and($page->standings->pluck('user_id')->all())->toBe([$capeLeader->id, $capeSecond->id])
        ->and($page->standings->first()->province_rank_label)->toBeNull();

    $this->get('/standings?season=2026&series=PR22&level=provincial&province_id='.$this->wc->id)
        ->assertOk()
        ->assertSee('Cape Leader')
        ->assertSee('Cape Second')
        ->assertDontSee('Gauteng Leader')
        ->assertDontSee('data-province-rank', false);
});

it('shares national ranks when points tie and does not add a province rank', function () {
    $first = User::factory()->create(['name' => 'Nina National', 'province_id' => $this->gp->id]);
    $second = User::factory()->create(['name' => 'Owen National', 'province_id' => $this->wc->id]);
    $third = User::factory()->create(['name' => 'Piet National', 'province_id' => $this->gp->id]);

    rankStanding($first, 'national', 300, 1);
    rankStanding($second, 'national', 300, 2);
    rankStanding($third, 'national', 100, 3);

    $page = app(SeasonStandingsBoard::class)->load('2026', 'PR22', 'national', null, null, null, null, null);

    expect($page->showProvinceRank)->toBeFalse()
        ->and($page->standings->pluck('rank')->all())->toBe([1, 1, 3])
        ->and($page->standings->pluck('rank_label')->unique()->values()->all())->toContain('T1')
        ->and($page->standings->last()->rank_label)->toBe('3');

    $this->get('/standings?season=2026&series=PR22&level=national')
        ->assertOk()
        ->assertSee('data-rank-label="T1"', false)
        ->assertSee('data-rank-label="3"', false)
        ->assertDontSee('data-province-rank', false);
});

it('recomputes rank after the division filter', function () {
    $alice = User::factory()->create(['name' => 'Alice Overall', 'province_id' => $this->gp->id]);
    $bob = User::factory()->create(['name' => 'Bob Factory', 'province_id' => $this->wc->id]);

    rankStanding($alice, 'provincial', 100, 2, $this->gp->id);
    rankStanding($bob, 'provincial', 300, 1, $this->wc->id);
    rankStanding($alice, 'provincial', 300, 1, $this->gp->id, $this->factory->id);
    rankStanding($bob, 'provincial', 100, 2, $this->wc->id, $this->factory->id);

    $overall = app(SeasonStandingsBoard::class)->load('2026', 'PR22', 'provincial', null, null, null, null, null);
    $factory = app(SeasonStandingsBoard::class)->load('2026', 'PR22', 'provincial', $this->factory->id, null, null, null, null);

    expect($overall->standings->pluck('user_id')->all())->toBe([$bob->id, $alice->id])
        ->and($factory->standings->pluck('user_id')->all())->toBe([$alice->id, $bob->id])
        ->and($factory->standings->pluck('rank_label')->all())->toBe(['1', '2']);
});

it('keeps the visit seed when the national and provincial tabs change', function () {
    $shooter = User::factory()->create(['name' => 'Tab Shooter', 'province_id' => $this->gp->id]);
    rankStanding($shooter, 'provincial', 100, 1, $this->gp->id);
    rankStanding($shooter, 'national', 100, 1);

    $component = Volt::test('standings.season')
        ->set('season', '2026')
        ->set('series', 'PR22')
        ->set('level', 'provincial')
        ->set('seed', 4242);

    $component->call('setLevel', 'national');

    expect($component->get('seed'))->toBe(4242)
        ->and($component->get('level'))->toBe('national');

    $component->call('setLevel', 'provincial');

    expect($component->get('seed'))->toBe(4242);
});
