<?php

use App\Enums\LadderVariable;
use App\Enums\PairSeparation;
use App\Models\LadderSession;
use App\Models\LadderShot;
use App\Models\LadderStep;
use App\Models\User;
use App\Services\Ladder\DTO\LadderVerdict;
use App\Services\Ladder\LadderAnalysis;

beforeEach(function () {
    seedRoles();
});

/**
 * Build the seven-step charge-weight fixture from the spec. The service is
 * variable-agnostic, so exactly the same helper is reused for the seating-
 * depth test with a different variable.
 *
 * @param  array<int, array{value: float, velocities: list<float>, in_fit?: bool}>  $rows
 */
function buildLadderFixture(array $rows, LadderVariable $variable = LadderVariable::ChargeWeight): LadderSession
{
    $user = User::factory()->create();
    $user->assignRole('member');

    $session = LadderSession::factory()->for($user)->create([
        'variable' => $variable,
    ]);

    foreach ($rows as $i => $row) {
        $step = LadderStep::factory()->for($session)->create([
            'value' => $row['value'],
            'include_in_fit' => $row['in_fit'] ?? true,
            'sort_order' => $i,
        ]);

        foreach ($row['velocities'] as $j => $v) {
            LadderShot::factory()->for($step)->create([
                'velocity_fps' => $v,
                'sequence' => $j,
                'excluded' => false,
            ]);
        }
    }

    return $session->fresh(['steps.shots']);
}

/**
 * Fixture from the spec, base configuration: 40.4 / 40.6 / 40.8 / 41.0 in fit,
 * 40.0 / 40.2 / 41.6 excluded (include_in_fit=false).
 */
function goldenFixture(): LadderSession
{
    return buildLadderFixture([
        ['value' => 40.0, 'velocities' => [2576.0], 'in_fit' => false],
        ['value' => 40.2, 'velocities' => [2586.3, 2575.9, 2584.6], 'in_fit' => false],
        ['value' => 40.4, 'velocities' => [2618.7, 2608.8, 2607.1], 'in_fit' => true],
        ['value' => 40.6, 'velocities' => [2611.6, 2606.0, 2634.7], 'in_fit' => true],
        ['value' => 40.8, 'velocities' => [2633.8, 2620.6, 2626.7], 'in_fit' => true],
        ['value' => 41.0, 'velocities' => [2652.8, 2632.2], 'in_fit' => true],
        ['value' => 41.6, 'velocities' => [2709.6], 'in_fit' => false],
    ]);
}

it('reproduces the spec fitted slope and intercept with the middle four steps', function () {
    $result = LadderAnalysis::analyze(goldenFixture());

    expect($result->trend)->not->toBeNull();
    expect($result->trend->slope)->toBeGreaterThan(51.24)->toBeLessThan(51.26);
    expect($result->trend->intercept)->toBeGreaterThan(538.74)->toBeLessThan(538.76);
    expect($result->trend->stepsUsed)->toBe(4);
});

it('reproduces the spec pooled SD and df', function () {
    $result = LadderAnalysis::analyze(goldenFixture());

    expect($result->pooledSd)->toBeGreaterThan(10.007)->toBeLessThan(10.027);
    expect($result->pooledDf)->toBe(9);
});

it('reproduces the residuals at 41.6 and 40.2', function () {
    $session = goldenFixture();
    $result = LadderAnalysis::analyze($session);

    // Find residual entries by step value rather than id (ids are dynamic).
    $residualsByValue = [];
    foreach ($result->steps as $step) {
        if (isset($result->residuals[$step->stepId])) {
            $residualsByValue[(string) $step->value] = $result->residuals[$step->stepId];
        }
    }

    expect($residualsByValue['41.6'])->toBeGreaterThan(38.84)->toBeLessThan(38.86);
    expect($residualsByValue['40.2'])->toBeLessThan(-16.72)->toBeGreaterThan(-16.74);
});

it('reproduces the Welch t-statistic between 40.2 and 40.4', function () {
    $result = LadderAnalysis::analyze(goldenFixture());

    $pair = collect($result->pairs)->first(fn ($p) => abs($p->fromValue - 40.2) < 1e-6 && abs($p->toValue - 40.4) < 1e-6);
    expect($pair)->not->toBeNull();
    expect($pair->d)->toBeGreaterThan(29.266)->toBeLessThan(29.268);
    expect($pair->seD)->toBeGreaterThan(4.833)->toBeLessThan(4.853);
    expect($pair->t)->toBeGreaterThan(6.033)->toBeLessThan(6.053);
    expect($pair->classification)->toBe(PairSeparation::Separates);
});

it('reproduces the chi-square SD interval at step 40.6', function () {
    $result = LadderAnalysis::analyze(goldenFixture());

    $step = collect($result->steps)->first(fn ($s) => abs($s->value - 40.6) < 1e-6);
    expect($step)->not->toBeNull();
    expect($step->sd)->toBeGreaterThan(15.208)->toBeLessThan(15.218);
    expect($step->sdCiLower)->toBeGreaterThan(8.78)->toBeLessThan(8.80);
    expect($step->sdCiUpper)->toBeGreaterThan(67.16)->toBeLessThan(67.18);
});

it('reports 8 shots per step needed to resolve 15 fps', function () {
    $result = LadderAnalysis::analyze(goldenFixture(), 15.0);

    expect($result->roundsRequired)->toBe(8);
});

it('produces the correct verdict with the base configuration', function () {
    $result = LadderAnalysis::analyze(goldenFixture());

    // Exactly one pair separates (40.2 → 40.4) — 40.2 was toggled off in the
    // fit but pairwise comparisons run on every eligible pair, not just the
    // fit set.
    $separating = collect($result->pairs)->filter(fn ($p) => $p->classification === PairSeparation::Separates);
    expect($separating->count())->toBe(1);
    $pair = $separating->first();
    expect($pair->fromValue)->toBe(40.2);
    expect($pair->toValue)->toBe(40.4);
});

it('yields slope 68 fps/gr when every step is toggled into the fit', function () {
    $session = buildLadderFixture([
        ['value' => 40.0, 'velocities' => [2576.0]],
        ['value' => 40.2, 'velocities' => [2586.3, 2575.9, 2584.6]],
        ['value' => 40.4, 'velocities' => [2618.7, 2608.8, 2607.1]],
        ['value' => 40.6, 'velocities' => [2611.6, 2606.0, 2634.7]],
        ['value' => 40.8, 'velocities' => [2633.8, 2620.6, 2626.7]],
        ['value' => 41.0, 'velocities' => [2652.8, 2632.2]],
        ['value' => 41.6, 'velocities' => [2709.6]],
    ]);

    $result = LadderAnalysis::analyze($session);

    // Single-shot steps do not contribute to the fit even when include_in_fit
    // is true — you cannot estimate a mean with uncertainty from one point.
    // That is what makes the assertion land at 68 fps/gr instead of ~81.6.
    // The exact value is 67.9833 fps/gr; the spec's "68" is rounded to the
    // nearest whole grain unit, so the tolerance here is looser than the
    // exact assertions above.
    expect($result->trend)->not->toBeNull();
    expect($result->trend->slope)->toBeGreaterThan(67.97)->toBeLessThan(68.02);

    $separating = collect($result->pairs)->filter(fn ($p) => $p->classification === PairSeparation::Separates);
    expect($separating->count())->toBe(1);
    expect($separating->first()->fromValue)->toBe(40.2);
    expect($separating->first()->toValue)->toBe(40.4);
});

it('returns NothingTestable when no step has n>=2', function () {
    $session = buildLadderFixture([
        ['value' => 40.0, 'velocities' => [2576.0]],
        ['value' => 40.2, 'velocities' => [2582.0]],
        ['value' => 40.4, 'velocities' => [2611.0]],
    ]);

    $result = LadderAnalysis::analyze($session);

    expect($result->verdict->case)->toBe(LadderVerdict::NOTHING_TESTABLE);
    expect($result->pooledSd)->toBeNull();
    expect($result->trend)->toBeNull();
    expect($result->roundsRequired)->toBeNull();
});

it('returns NoNodeSupported and cites rounds required when nothing separates', function () {
    // A flat, tight ladder where consecutive means are within noise.
    $session = buildLadderFixture([
        ['value' => 40.0, 'velocities' => [2600.0, 2601.0, 2599.0]],
        ['value' => 40.2, 'velocities' => [2600.5, 2600.5, 2600.0]],
        ['value' => 40.4, 'velocities' => [2601.0, 2600.0, 2600.5]],
    ]);

    $result = LadderAnalysis::analyze($session, 15.0);

    expect($result->verdict->case)->toBe(LadderVerdict::NO_NODE_SUPPORTED);
    expect($result->verdict->text)->toContain('shots per step');
});

it('discards excluded shots from every calculation', function () {
    $session = buildLadderFixture([
        ['value' => 40.4, 'velocities' => [2618.7, 2608.8, 2607.1]],
        ['value' => 40.6, 'velocities' => [2611.6, 2606.0, 2634.7]],
    ]);

    $before = LadderAnalysis::analyze($session);

    // Drop the high shot at 40.6 — 2634.7 — as a chrono misread.
    $step = $session->steps->firstWhere('value', 40.6);
    $shot = $step->shots->firstWhere('velocity_fps', 2634.7);
    $shot->update(['excluded' => true]);

    $after = LadderAnalysis::analyze($session->fresh(['steps.shots']));

    $step406Before = collect($before->steps)->firstWhere('value', 40.6);
    $step406After = collect($after->steps)->firstWhere('value', 40.6);
    expect($step406After->n)->toBe(2);
    expect($step406After->sd)->toBeLessThan($step406Before->sd);
});

it('flags step-slope conditioning when a pair jumps more than 1.9x the fitted slope', function () {
    // Small, tight steps in the middle with a huge jump at the top — the last
    // pair should trip the 1.9x flag because the observed step slope is much
    // larger than the fitted trend can account for.
    $session = buildLadderFixture([
        ['value' => 40.0, 'velocities' => [2600.0, 2601.0, 2599.5]],
        ['value' => 40.2, 'velocities' => [2610.0, 2611.0, 2609.5]],
        ['value' => 40.4, 'velocities' => [2620.0, 2621.0, 2619.5]],
        ['value' => 40.6, 'velocities' => [2720.0, 2721.0, 2719.5]],
    ]);

    $result = LadderAnalysis::analyze($session);
    $lastPair = collect($result->pairs)->last();
    expect($lastPair->exceedsFittedSlope)->toBeTrue();
});
