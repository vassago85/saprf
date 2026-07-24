<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\Score;
use App\Models\Standing;
use App\Models\User;
use App\Services\StandingsCalculationService;

beforeEach(function () {
    $this->gp = Province::create(['name' => 'Gauteng', 'abbreviation' => 'GP']);
    $this->wc = Province::create(['name' => 'Western Cape', 'abbreviation' => 'WC']);
    $this->division = Division::create(['slug' => 'open', 'name' => 'Open']);
    $this->service = app(StandingsCalculationService::class);
});

function provincialMatch(Province $host): MatchEvent
{
    return MatchEvent::create([
        'name' => 'Provincial '.$host->abbreviation,
        'match_type' => 'PR22',
        'series_level' => 'provincial',
        'series' => 'PR22',
        'season' => '2026',
        'province_id' => $host->id,
        'match_date' => '2026-03-15',
        'status' => 'completed',
        'created_by' => User::factory()->create()->id,
        'active_member_fee' => 500,
        'published' => true,
    ]);
}

function provincialScore(MatchEvent $match, User $user, float $raw, int $divisionId): Score
{
    return Score::create([
        'match_id' => $match->id,
        'shooter_name' => $user->name,
        'user_id' => $user->id,
        'raw_score' => $raw,
        'division_id' => $divisionId,
        'status' => 'valid',
        'counts_for_season' => true,
        'match_date' => $match->match_date->toDateString(),
    ]);
}

it('scores a shooter in their home province, not the host province', function () {
    $match = provincialMatch($this->wc); // hosted in Western Cape

    $gpShooter = User::factory()->create(['province_id' => $this->gp->id]);
    $wcShooter = User::factory()->create(['province_id' => $this->wc->id]);

    provincialScore($match, $gpShooter, 48, $this->division->id);
    provincialScore($match, $wcShooter, 40, $this->division->id);

    $this->service->recalculateForMatch($match);

    // Gauteng shooter is credited to Gauteng even though the match was in WC.
    expect(Standing::where('user_id', $gpShooter->id)->where('province_id', $this->gp->id)->whereNull('division_id')->exists())->toBeTrue()
        ->and(Standing::where('user_id', $gpShooter->id)->where('province_id', $this->wc->id)->exists())->toBeFalse();

    // Western Cape shooter stays in WC.
    expect(Standing::where('user_id', $wcShooter->id)->where('province_id', $this->wc->id)->whereNull('division_id')->exists())->toBeTrue()
        ->and(Standing::where('user_id', $wcShooter->id)->where('province_id', $this->gp->id)->exists())->toBeFalse();
});

it('gives each shooter first place in their own province — no duplicate podiums', function () {
    $match = provincialMatch($this->wc);

    $gpShooter = User::factory()->create(['province_id' => $this->gp->id]);
    $wcShooter = User::factory()->create(['province_id' => $this->wc->id]);

    provincialScore($match, $gpShooter, 48, $this->division->id);
    provincialScore($match, $wcShooter, 40, $this->division->id);

    $this->service->recalculateForMatch($match);

    expect(Standing::where('user_id', $gpShooter->id)->where('province_id', $this->gp->id)->whereNull('division_id')->value('rank'))->toBe(1)
        ->and(Standing::where('user_id', $wcShooter->id)->where('province_id', $this->wc->id)->whereNull('division_id')->value('rank'))->toBe(1);

    // Exactly one shooter in each provincial overall table.
    expect(Standing::where('province_id', $this->gp->id)->whereNull('division_id')->count())->toBe(1)
        ->and(Standing::where('province_id', $this->wc->id)->whereNull('division_id')->count())->toBe(1);
});

it('aggregates a shooter\'s out-of-province results into their home province only', function () {
    $gpShooter = User::factory()->create(['province_id' => $this->gp->id]);

    $homeMatch = provincialMatch($this->gp);
    provincialScore($homeMatch, $gpShooter, 50, $this->division->id);
    $this->service->recalculateForMatch($homeMatch);

    $awayMatch = provincialMatch($this->wc);
    provincialScore($awayMatch, $gpShooter, 30, $this->division->id);
    $this->service->recalculateForMatch($awayMatch);

    // One aggregated row in Gauteng, nothing in Western Cape.
    expect(Standing::where('user_id', $gpShooter->id)->where('province_id', $this->gp->id)->whereNull('division_id')->count())->toBe(1)
        ->and(Standing::where('user_id', $gpShooter->id)->where('province_id', $this->wc->id)->exists())->toBeFalse();
});
