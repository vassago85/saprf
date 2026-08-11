<?php

use App\Models\MatchEvent;
use App\Models\QualificationRule;
use App\Models\Score;
use App\Services\StandingsCalculationService;

/**
 * The PR22 national pool (40%) uses a minimum-matches gate + strict-divisor rule
 * with NO drop-one:
 *   - gate:      a shooter must complete at least `national_pool_min_matches` (2)
 *                national matches before ANY national score is earned.
 *                1 shot → 0.
 *   - count:     at/above the gate, the best `best_of` (2) scores count and are
 *                summed. 2 shot → both count, 3+ shot → best 2.
 *   - divisor:   ALWAYS best_of (2). So two solid nationals now earn full pool
 *                credit (sum ÷ 2), matching a three-match shooter whose best two
 *                are equal — you must simply have shot at least the minimum.
 */

function pr22Rule(): QualificationRule
{
    $rule = new QualificationRule;
    $rule->scoring_mode = 'weighted_pools';
    $rule->provincial_pool_best_of = 3;
    $rule->provincial_pool_weight_pct = 30;
    $rule->national_pool_best_of = 2;
    $rule->national_pool_weight_pct = 40;
    $rule->national_pool_min_matches = 2;
    $rule->champs_pool_best_of = 1;
    $rule->champs_pool_weight_pct = 30;

    return $rule;
}

function poolScore(string $seriesLevel, float $normalized): Score
{
    $match = new MatchEvent;
    $match->series_level = $seriesLevel;

    $score = new Score;
    $score->user_id = 1;
    $score->normalized_score = $normalized;
    $score->setRelation('match', $match);

    return $score;
}

/**
 * @param  array<int, float>  $normalizedScores
 * @return array{scores_counted:int, pool_average:float, contribution:float}
 */
function nationalPoolBreakdown(array $normalizedScores): array
{
    $service = app(StandingsCalculationService::class);
    $method = new ReflectionMethod($service, 'aggregateWeightedPools');
    $method->setAccessible(true);

    $scores = collect($normalizedScores)->map(fn (float $n) => poolScore('national', $n));
    $result = $method->invoke($service, $scores, pr22Rule(), 'overall');

    return $result->first()['pool_breakdown']['national'] ?? [
        'scores_counted' => 0, 'pool_average' => 0.0, 'contribution' => 0.0,
    ];
}

it('gives zero national pool when only one national is shot (below the minimum)', function () {
    $b = nationalPoolBreakdown([95.0]);

    expect($b['scores_counted'])->toBe(0)
        ->and((float) $b['pool_average'])->toBe(0.0)
        ->and((float) $b['contribution'])->toBe(0.0);
});

it('counts both nationals (summed) once the two-match minimum is met', function () {
    // 2 shot → both count (no drop-one) now that the minimum is 2. Divisor is
    // best_of = 2, so (90 + 80) ÷ 2 = 85. A shooter who puts in two solid
    // nationals earns full pool credit — they simply had to shoot the minimum.
    $b = nationalPoolBreakdown([90.0, 80.0]);

    expect($b['scores_counted'])->toBe(2)
        ->and((float) $b['pool_average'])->toBe(85.0) // (90 + 80) ÷ 2
        ->and((float) $b['contribution'])->toBe(34.0); // 85 × 40%
});

it('counts the best two (averaged) once three nationals are shot', function () {
    $b = nationalPoolBreakdown([90.0, 80.0, 70.0]);

    expect($b['scores_counted'])->toBe(2)
        ->and((float) $b['pool_average'])->toBe(85.0) // (90+80)/2
        ->and((float) $b['contribution'])->toBe(34.0); // 85 × 40%
});

it('still counts only the best two when five nationals are shot', function () {
    $b = nationalPoolBreakdown([90.0, 80.0, 70.0, 60.0, 50.0]);

    expect($b['scores_counted'])->toBe(2)
        ->and((float) $b['pool_average'])->toBe(85.0)
        ->and((float) $b['contribution'])->toBe(34.0);
});

it('leaves the provincial pool on the strict divide-by-best-of rule', function () {
    $service = app(StandingsCalculationService::class);
    $method = new ReflectionMethod($service, 'aggregateWeightedPools');
    $method->setAccessible(true);

    // One provincial score of 90, best_of = 3 → strict average 90/3 = 30.
    $scores = collect([poolScore('provincial', 90.0)]);
    $result = $method->invoke($service, $scores, pr22Rule(), 'overall');
    $prov = $result->first()['pool_breakdown']['provincial'];

    expect($prov['scores_counted'])->toBe(1)
        ->and((float) $prov['pool_average'])->toBe(30.0)
        ->and((float) $prov['contribution'])->toBe(9.0); // 30 × 30%
});

it('records per-match national contribution and marks the surplus match as dropped', function () {
    // Three nationals, only the best two count (best_of = 2), so the worst is
    // excluded. Each counted contributes pct * weight / 100 / best_of
    // = pct * 40 / 100 / 2 = pct * 0.2 pts.
    $b = nationalPoolBreakdown([90.0, 80.0, 70.0]);
    $matches = $b['matches'] ?? [];

    expect($matches)->toHaveCount(3);

    // Sorted by pct desc: 90, 80 counted; 70 dropped.
    expect((bool) $matches[0]['counted'])->toBeTrue()
        ->and((bool) $matches[1]['counted'])->toBeTrue()
        ->and((bool) $matches[2]['counted'])->toBeFalse();

    // Contribution = pct × 40% ÷ 2  →  90→18, 80→16, dropped→0.
    expect((float) $matches[0]['contribution'])->toBe(18.0)
        ->and((float) $matches[1]['contribution'])->toBe(16.0)
        ->and((float) $matches[2]['contribution'])->toBe(0.0);

    // Sum of per-match contributions equals the pool contribution.
    $sum = collect($matches)->sum('contribution');
    expect(round($sum, 2))->toBe((float) $b['contribution']);
});

it('excludes the sole national when below the two-match minimum (national pool contribution is 0)', function () {
    $b = nationalPoolBreakdown([95.0]);
    $matches = $b['matches'] ?? [];

    expect($matches)->toHaveCount(1);
    expect((bool) $matches[0]['counted'])->toBeFalse()
        ->and((float) $matches[0]['contribution'])->toBe(0.0);
});

it('excludes national scores from the national standing\'s provincial-matches pool', function () {
    // A national score must never contribute to the "provincial" pool inside
    // the PR22 NATIONAL standing — even historically-set provincial_normalized
    // values on the score should be ignored. Only genuine provincial-level
    // matches feed that pool now.
    $service = app(StandingsCalculationService::class);
    $method = new ReflectionMethod($service, 'aggregateWeightedPools');
    $method->setAccessible(true);

    $nationalScore = poolScore('national', 90.0);
    // Simulate the legacy fields that used to sneak this score into the
    // provincial pool — the code must ignore them.
    $nationalScore->provincial_normalized_score = 90.0;
    $nationalScore->match->also_counts_for_provincial = true;

    $result = $method->invoke($service, collect([$nationalScore]), pr22Rule(), 'overall');
    $breakdown = $result->first()['pool_breakdown'];

    // The provincial pool sees zero matches; the national pool sees one
    // (which is dropped by the drop-one rule when only one is shot).
    expect($breakdown['provincial']['scores_counted'])->toBe(0)
        ->and((float) $breakdown['provincial']['pool_average'])->toBe(0.0)
        ->and((float) $breakdown['provincial']['contribution'])->toBe(0.0)
        ->and($breakdown['provincial']['matches'])->toBe([]);
});

it('records per-match contribution for the strict provincial pool', function () {
    $service = app(StandingsCalculationService::class);
    $method = new ReflectionMethod($service, 'aggregateWeightedPools');
    $method->setAccessible(true);

    // Two provincial scores, best_of = 3. Both counted, but divisor = 3.
    // Per-match contribution = pct × 30% ÷ 3  →  90→9, 60→6.
    $scores = collect([poolScore('provincial', 90.0), poolScore('provincial', 60.0)]);
    $result = $method->invoke($service, $scores, pr22Rule(), 'overall');
    $prov = $result->first()['pool_breakdown']['provincial'];
    $matches = $prov['matches'] ?? [];

    expect($matches)->toHaveCount(2)
        ->and((bool) $matches[0]['counted'])->toBeTrue()
        ->and((float) $matches[0]['contribution'])->toBe(9.0)
        ->and((bool) $matches[1]['counted'])->toBeTrue()
        ->and((float) $matches[1]['contribution'])->toBe(6.0);
});
