<?php

declare(strict_types=1);

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;
use App\Services\StandingsCalculationService;
use Carbon\Carbon;

/**
 * Phase 1.4: equal raw scores fall through to the 2027 championship
 * tiebreak (tiebreak_impacts desc, tiebreak_time_seconds asc). Rows
 * that remain genuinely tied share the rank number ("T2 / T2 / 4").
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

    $this->match = MatchEvent::create([
        'name' => 'Tie Ranking Test',
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
});

/**
 * Minimal shooter factory for this test: a paid member whose scores
 * will all evaluate to status=valid so they stay in the ranking pool.
 */
function makeTieShooter(string $name, int $index): User
{
    $user = User::factory()->create(['name' => $name]);
    Membership::create([
        'user_id' => $user->id,
        'saprf_number' => 'T-TIE-'.$index,
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => Carbon::today()->subYear(),
        'expiry_date' => Carbon::today()->addYear(),
    ]);

    return $user;
}

it('breaks a tie on tiebreak_impacts then tiebreak_time_seconds', function () {
    $better = makeTieShooter('More Impacts', 1);
    $middle = makeTieShooter('Faster Time', 2);
    $worst = makeTieShooter('No Tiebreak Data', 3);

    foreach ([
        [$better, 100, 12, 45.5],
        [$middle, 100, 12, 50.0], // same impacts, slower time
        [$worst, 100, 10, 55.0],  // fewer impacts
    ] as [$user, $raw, $tbImpacts, $tbTime]) {
        Score::create([
            'match_id' => $this->match->id,
            'user_id' => $user->id,
            'shooter_name' => $user->name,
            'raw_score' => $raw,
            'tiebreak_impacts' => $tbImpacts,
            'tiebreak_time_seconds' => $tbTime,
            'division_id' => $this->division->id,
            'status' => 'valid',
            'is_member' => true,
            'match_date' => $this->match->match_date,
        ]);
    }

    app(StandingsCalculationService::class)->calculateMatchRankings($this->match->fresh());

    $better->refresh();
    $middle->refresh();
    $worst->refresh();

    $betterScore = Score::where('user_id', $better->id)->firstOrFail();
    $middleScore = Score::where('user_id', $middle->id)->firstOrFail();
    $worstScore = Score::where('user_id', $worst->id)->firstOrFail();

    expect($betterScore->overall_rank)->toBe(1);
    expect($middleScore->overall_rank)->toBe(2);
    expect($worstScore->overall_rank)->toBe(3);
    expect($betterScore->is_tied_overall)->toBeFalse();
    expect($middleScore->is_tied_overall)->toBeFalse();
    expect($worstScore->is_tied_overall)->toBeFalse();
});

it('shares a rank and flips is_tied_overall when every ranking key is equal', function () {
    $alice = makeTieShooter('Alice T', 4);
    $bob = makeTieShooter('Bob T', 5);
    $carol = makeTieShooter('Carol Fourth', 6);

    // Alice and Bob are identical on every ranking key; Carol trails.
    foreach ([
        [$alice, 100, 12, 45.5],
        [$bob,   100, 12, 45.5],
        [$carol, 95,  10, 55.0],
    ] as [$user, $raw, $tbImpacts, $tbTime]) {
        Score::create([
            'match_id' => $this->match->id,
            'user_id' => $user->id,
            'shooter_name' => $user->name,
            'raw_score' => $raw,
            'tiebreak_impacts' => $tbImpacts,
            'tiebreak_time_seconds' => $tbTime,
            'division_id' => $this->division->id,
            'status' => 'valid',
            'is_member' => true,
            'match_date' => $this->match->match_date,
        ]);
    }

    app(StandingsCalculationService::class)->calculateMatchRankings($this->match->fresh());

    $aliceScore = Score::where('user_id', $alice->id)->firstOrFail();
    $bobScore = Score::where('user_id', $bob->id)->firstOrFail();
    $carolScore = Score::where('user_id', $carol->id)->firstOrFail();

    // Standard competition ranking: 1, 1, 3 — the "tied for first" case.
    expect($aliceScore->overall_rank)->toBe(1);
    expect($bobScore->overall_rank)->toBe(1);
    expect($carolScore->overall_rank)->toBe(3);
    expect($aliceScore->is_tied_overall)->toBeTrue();
    expect($bobScore->is_tied_overall)->toBeTrue();
    expect($carolScore->is_tied_overall)->toBeFalse();
});

it('shares a rank when neither tied row has any tiebreak data at all', function () {
    $alice = makeTieShooter('A Nullable', 7);
    $bob = makeTieShooter('B Nullable', 8);

    foreach ([
        [$alice, 100, null, null],
        [$bob,   100, null, null],
    ] as [$user, $raw, $tbImpacts, $tbTime]) {
        Score::create([
            'match_id' => $this->match->id,
            'user_id' => $user->id,
            'shooter_name' => $user->name,
            'raw_score' => $raw,
            'tiebreak_impacts' => $tbImpacts,
            'tiebreak_time_seconds' => $tbTime,
            'division_id' => $this->division->id,
            'status' => 'valid',
            'is_member' => true,
            'match_date' => $this->match->match_date,
        ]);
    }

    app(StandingsCalculationService::class)->calculateMatchRankings($this->match->fresh());

    $aliceScore = Score::where('user_id', $alice->id)->firstOrFail();
    $bobScore = Score::where('user_id', $bob->id)->firstOrFail();

    expect($aliceScore->overall_rank)->toBe(1);
    expect($bobScore->overall_rank)->toBe(1);
    expect($aliceScore->is_tied_overall)->toBeTrue();
    expect($bobScore->is_tied_overall)->toBeTrue();
});

it('renders the overall_rank_label accessor with a leading T on tied rows', function () {
    $tied = makeTieShooter('T Label', 9);
    Score::create([
        'match_id' => $this->match->id,
        'user_id' => $tied->id,
        'shooter_name' => $tied->name,
        'raw_score' => 100,
        'division_id' => $this->division->id,
        'overall_rank' => 2,
        'is_tied_overall' => true,
        'status' => 'valid',
        'is_member' => true,
        'match_date' => $this->match->match_date,
    ]);

    $score = Score::where('user_id', $tied->id)->first();

    expect($score->overall_rank_label)->toBe('T2');
});
