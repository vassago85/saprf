<?php

use App\Models\MatchEvent;
use App\Models\QualificationRule;
use App\Models\Score;
use App\Services\StandingsCalculationService;

/**
 * The PR22 national pool (40%) uses a "drop-one" rule: a shooter's worst
 * national is always dropped, so counting scores = (nationals shot − 1),
 * capped at 2, and the pool result is the AVERAGE of the counting scores.
 *   1 shot → 0,  2 shot → best 1,  3+ shot → best 2.
 * Provincial (best 3) and Champs (best 1) keep the strict divide-by-N rule.
 */

function pr22Rule(): QualificationRule
{
    $rule = new QualificationRule;
    $rule->scoring_mode = 'weighted_pools';
    $rule->provincial_pool_best_of = 3;
    $rule->provincial_pool_weight_pct = 30;
    $rule->national_pool_best_of = 2;
    $rule->national_pool_weight_pct = 40;
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

it('gives zero national pool when only one national is shot', function () {
    $b = nationalPoolBreakdown([95.0]);

    expect($b['scores_counted'])->toBe(0)
        ->and((float) $b['pool_average'])->toBe(0.0)
        ->and((float) $b['contribution'])->toBe(0.0);
});

it('counts only the single highest when two nationals are shot', function () {
    $b = nationalPoolBreakdown([90.0, 80.0]);

    expect($b['scores_counted'])->toBe(1)
        ->and((float) $b['pool_average'])->toBe(90.0)
        ->and((float) $b['contribution'])->toBe(36.0); // 90 × 40%
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
