<?php

namespace App\Services\Ladder;

use App\Enums\PairSeparation;
use App\Models\LadderSession;
use App\Services\Ladder\DTO\LadderPairComparison;
use App\Services\Ladder\DTO\LadderSdComparison;
use App\Services\Ladder\DTO\LadderStepStats;
use App\Services\Ladder\DTO\LadderTrendFit;
use App\Services\Ladder\DTO\LadderVerdict;

/**
 * Ladder analysis service.
 *
 * All statistics live here — never in the Livewire component, the controller,
 * or the view. Everything works in terms of a step's numeric `value`, so the
 * service is variable-agnostic: a charge-weight ladder and a seating-depth
 * ladder produce identical statistics against identical velocities, with the
 * only difference being the units the DTO carries alongside the numbers.
 *
 * Two behaviours worth calling out because they're not obvious from the spec:
 *
 *   1. The trend fit implicitly excludes single-shot steps even when the user
 *      has toggled include_in_fit on them. A single point has no variance to
 *      contribute to the regression, and the spec's golden value ("all seven
 *      steps in the fit → slope = 68 fps/gr") only lands if n<2 steps are
 *      dropped from the fit; unweighted OLS on all seven step means yields
 *      ≈81.6 fps/gr instead. The `contributesToFit` flag on LadderStepStats
 *      surfaces this decision to the UI.
 *
 *   2. Adjacent Welch comparisons skip any pair where either step has n<2.
 *      A t-test needs a sample standard error on both sides.
 */
final class LadderAnalysis
{
    /**
     * Analyse a ladder session against the given resolving-difference target
     * (in fps). Default of 15 fps matches the spec.
     */
    public static function analyze(LadderSession $session, float $resolvingDelta = 15.0): LadderAnalysisResult
    {
        $variable = $session->variableEnum();
        $session->loadMissing(['steps.shots']);

        // Preserve the same order as the DB scope: sort_order then value.
        $steps = $session->steps->sortBy([['sort_order', 'asc'], ['value', 'asc']])->values();

        $stepStats = [];
        foreach ($steps as $step) {
            $stepStats[] = self::computeStepStats($step);
        }

        $pooledSd = null;
        $pooledDf = null;
        [$pooledSd, $pooledDf] = self::computePooledSd($stepStats, $steps);

        $trend = self::fit($stepStats, $steps);
        $residuals = self::residuals($stepStats, $trend);
        $pairs = self::pairwise($stepStats, $trend);
        $verdict = self::verdict($stepStats, $pairs, $pooledSd, $trend, $resolvingDelta);
        $sdComparison = self::sdComparison($stepStats);

        // Prefer pooled SD when we have it, fall back to the fit's residual
        // SD for single-shot ladders. Same rounds-required formula in either
        // case — the SD that goes in is just estimated a different way.
        $sdForPower = $pooledSd ?? $trend?->residualSd;
        $roundsRequired = null;
        if ($sdForPower !== null && $sdForPower > 0.0 && $resolvingDelta > 0.0) {
            $roundsRequired = (int) ceil(15.7 * $sdForPower * $sdForPower / ($resolvingDelta * $resolvingDelta));
        }

        return new LadderAnalysisResult(
            variable: $variable,
            steps: $stepStats,
            pooledSd: $pooledSd,
            pooledDf: $pooledDf,
            trend: $trend,
            residuals: $residuals,
            pairs: $pairs,
            roundsRequired: $roundsRequired,
            resolvingDelta: $resolvingDelta,
            verdict: $verdict,
            sdComparison: $sdComparison,
        );
    }

    /**
     * Per-step statistics. Uses only non-excluded shots.
     */
    private static function computeStepStats($step): LadderStepStats
    {
        $velocities = $step->shots
            ->reject(fn ($shot) => $shot->excluded)
            ->pluck('velocity_fps')
            ->map(fn ($v) => (float) $v)
            ->values()
            ->all();

        $n = count($velocities);
        $mean = $n > 0 ? array_sum($velocities) / $n : 0.0;

        $sd = null;
        $se = null;
        $es = null;
        $sdCiLower = null;
        $sdCiUpper = null;

        if ($n >= 2) {
            $sumSq = 0.0;
            foreach ($velocities as $v) {
                $d = $v - $mean;
                $sumSq += $d * $d;
            }
            $variance = $sumSq / ($n - 1);
            $sd = sqrt($variance);
            $se = $sd / sqrt($n);
            $es = max($velocities) - min($velocities);

            $df = $n - 1;
            $chiUpper = Statistics::chiSquareUpper($df);
            $chiLower = Statistics::chiSquareLower($df);
            if ($chiUpper > 0.0) {
                $sdCiLower = $sd * sqrt($df / $chiUpper);
            }
            if ($chiLower > 0.0) {
                $sdCiUpper = $sd * sqrt($df / $chiLower);
            }
        }

        $contributesToFit = ((bool) $step->include_in_fit) && $n >= 2;

        return new LadderStepStats(
            stepId: (int) $step->id,
            value: (float) $step->value,
            n: $n,
            mean: $mean,
            sd: $sd,
            se: $se,
            es: $es,
            sdCiLower: $sdCiLower,
            sdCiUpper: $sdCiUpper,
            includeInFit: (bool) $step->include_in_fit,
            contributesToFit: $contributesToFit,
            velocities: $velocities,
        );
    }

    /**
     * Pooled sample SD over every step with n≥2, using the standard
     * within-group sum-of-squares expression:
     *
     *   pooled = sqrt( Σ Σ (v − mean_step)² / Σ (n_step − 1) )
     *
     * @param  list<LadderStepStats>  $stepStats
     * @return array{0: ?float, 1: ?int}
     */
    private static function computePooledSd(array $stepStats, $steps): array
    {
        $totalSumSq = 0.0;
        $totalDf = 0;

        foreach ($stepStats as $s) {
            if ($s->n < 2) {
                continue;
            }
            $mean = $s->mean;
            foreach ($s->velocities as $v) {
                $d = $v - $mean;
                $totalSumSq += $d * $d;
            }
            $totalDf += $s->n - 1;
        }

        if ($totalDf === 0) {
            return [null, null];
        }

        return [sqrt($totalSumSq / $totalDf), $totalDf];
    }

    /**
     * OLS on step means. Two paths — the multi-shot path (n≥2 && in-fit) is
     * tried first because per-step SDs pool cleanly into a real pooled SD;
     * the single-shot path (any n, in-fit) runs only as a fallback for
     * one-shot-per-step ladders where the multi-shot path has nothing to
     * work with.
     *
     * @param  list<LadderStepStats>  $stepStats
     */
    private static function fit(array $stepStats, $steps): ?LadderTrendFit
    {
        $primary = self::fitOls($stepStats, minN: 2, singleShotMode: false);
        if ($primary !== null) {
            return $primary;
        }

        return self::fitOls($stepStats, minN: 1, singleShotMode: true);
    }

    /**
     * Run OLS through the step means passing the given inclusion criteria and
     * enrich the result with R², residual SD, and a 95% CI on the slope. All
     * three become null when there are fewer than three points in the fit —
     * n=2 gives you a line but no scatter to estimate uncertainty from.
     *
     * @param  list<LadderStepStats>  $stepStats
     */
    private static function fitOls(array $stepStats, int $minN, bool $singleShotMode): ?LadderTrendFit
    {
        $xs = [];
        $ys = [];
        foreach ($stepStats as $s) {
            if (! $s->includeInFit) {
                continue;
            }
            if ($s->n < $minN) {
                continue;
            }
            $xs[] = $s->value;
            $ys[] = $s->mean;
        }

        $n = count($xs);
        if ($n < 2 || count(array_unique($xs)) < 2) {
            return null;
        }

        $meanX = array_sum($xs) / $n;
        $meanY = array_sum($ys) / $n;

        $sumDx2 = 0.0;
        $sumDxDy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $xs[$i] - $meanX;
            $sumDx2 += $dx * $dx;
            $sumDxDy += $dx * ($ys[$i] - $meanY);
        }

        if ($sumDx2 == 0.0) {
            return null;
        }

        $slope = $sumDxDy / $sumDx2;
        $intercept = $meanY - $slope * $meanX;

        // Residual scatter around the line. SS_res drives residual SD (via
        // n-2 df) and R² (via 1 - SS_res / SS_tot). Meaningful only for n≥3.
        $ssRes = 0.0;
        $ssTot = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $ssRes += ($ys[$i] - ($intercept + $slope * $xs[$i])) ** 2;
            $ssTot += ($ys[$i] - $meanY) ** 2;
        }

        $rSquared = null;
        $residualSd = null;
        $residualDf = null;
        $slopeSe = null;
        $slopeCiLower = null;
        $slopeCiUpper = null;

        if ($n >= 3) {
            $residualDf = $n - 2;
            $residualSd = sqrt($ssRes / $residualDf);
            $slopeSe = $residualSd / sqrt($sumDx2);
            $tCrit = Statistics::tQuantileTwoTailed(0.05, (float) $residualDf);
            $slopeCiLower = $slope - $tCrit * $slopeSe;
            $slopeCiUpper = $slope + $tCrit * $slopeSe;
            $rSquared = $ssTot > 0.0 ? 1.0 - ($ssRes / $ssTot) : null;
        }

        return new LadderTrendFit(
            slope: $slope,
            intercept: $intercept,
            stepsUsed: $n,
            rSquared: $rSquared,
            residualSd: $residualSd,
            residualDf: $residualDf,
            slopeSe: $slopeSe,
            slopeCiLower: $slopeCiLower,
            slopeCiUpper: $slopeCiUpper,
            singleShotMode: $singleShotMode,
        );
    }

    /**
     * Residuals — one per step that does not contribute to the fit. That
     * covers both user-excluded steps AND single-shot steps that were
     * implicitly excluded because n<2. Both cases deserve a residual: the
     * point of the chart is the departure from trend, and hiding it for a
     * single-shot outlier is exactly the wrong call.
     *
     * @param  list<LadderStepStats>  $stepStats
     * @return array<int, float>
     */
    private static function residuals(array $stepStats, ?LadderTrendFit $trend): array
    {
        if ($trend === null) {
            return [];
        }

        // Single-shot mode: every point IS the fit, so the ±1 SD band around
        // the trend line does the informational work that per-point residual
        // drop-lines do in the multi-shot case. Drawing labelled drops off
        // every point would just cover the chart in numbers.
        if ($trend->singleShotMode) {
            return [];
        }

        $out = [];
        foreach ($stepStats as $s) {
            if ($s->contributesToFit) {
                continue;
            }
            if ($s->n < 1) {
                continue;
            }
            $out[$s->stepId] = $s->mean - $trend->predict($s->value);
        }

        return $out;
    }

    /**
     * Adjacent Welch's t-comparisons. Skips any pair where either step has
     * n<2. The step-slope conditioning flag fires when the observed step
     * slope exceeds 1.9 × the fitted slope AND the pair separates.
     *
     * @param  list<LadderStepStats>  $stepStats
     * @return list<LadderPairComparison>
     */
    private static function pairwise(array $stepStats, ?LadderTrendFit $trend): array
    {
        $out = [];
        $count = count($stepStats);
        for ($i = 0; $i < $count - 1; $i++) {
            $a = $stepStats[$i];
            $b = $stepStats[$i + 1];

            if ($a->n < 2 || $b->n < 2 || $a->se === null || $b->se === null) {
                continue;
            }

            if ($b->value == $a->value) {
                continue;
            }

            $d = $b->mean - $a->mean;
            $seD = sqrt($a->se * $a->se + $b->se * $b->se);
            $t = $seD > 0.0 ? $d / $seD : 0.0;
            $df = Statistics::welchDf($a->se, $a->n, $b->se, $b->n);
            $p = Statistics::studentTwoTailP($t, $df);
            $classification = match (true) {
                $p < 0.05 => PairSeparation::Separates,
                $p < 0.15 => PairSeparation::Marginal,
                default => PairSeparation::Indistinguishable,
            };

            $stepSlope = $d / ($b->value - $a->value);
            $exceedsFitted = false;
            if ($trend !== null && abs($trend->slope) > 0.0) {
                $exceedsFitted = abs($stepSlope) > 1.9 * abs($trend->slope)
                    && $classification === PairSeparation::Separates;
            }

            $out[] = new LadderPairComparison(
                fromStepId: $a->stepId,
                toStepId: $b->stepId,
                fromValue: $a->value,
                toValue: $b->value,
                d: $d,
                seD: $seD,
                t: $t,
                df: $df,
                p: $p,
                classification: $classification,
                stepSlope: $stepSlope,
                exceedsFittedSlope: $exceedsFitted,
            );
        }

        return $out;
    }

    /**
     * Resolve the overall verdict per the spec's precedence order and produce
     * flat, factual copy for the UI. No advisories, no softening.
     *
     * @param  list<LadderStepStats>  $stepStats
     * @param  list<LadderPairComparison>  $pairs
     */
    private static function verdict(array $stepStats, array $pairs, ?float $pooledSd, ?LadderTrendFit $trend, float $resolvingDelta): LadderVerdict
    {
        $hasAnyTestable = false;
        foreach ($stepStats as $s) {
            if ($s->n >= 2) {
                $hasAnyTestable = true;
                break;
            }
        }

        // Single-shot ladder path — reached when no step has repeats but the
        // fallback fit succeeded. We can still quote a consistency figure
        // from the fit residuals, but no node can be tested from single shots.
        if (! $hasAnyTestable && $trend !== null && $trend->singleShotMode && $trend->residualSd !== null) {
            $rounds = null;
            if ($trend->residualSd > 0.0 && $resolvingDelta > 0.0) {
                $rounds = (int) ceil(15.7 * $trend->residualSd * $trend->residualSd / ($resolvingDelta * $resolvingDelta));
            }

            $text = sprintf(
                'Single-shot ladder — shot-to-shot SD from the fit residuals is %s fps at df %d. No repeated shots to test node separation.',
                self::formatNumber($trend->residualSd, 2),
                $trend->residualDf,
            );

            if ($rounds !== null) {
                $text .= sprintf(
                    ' Resolving a %s fps difference between charges at that SD needs about %d rounds per step.',
                    self::formatNumber($resolvingDelta, 0),
                    $rounds,
                );
            }

            return new LadderVerdict(
                case: LadderVerdict::NO_NODE_SUPPORTED,
                text: $text,
            );
        }

        if (! $hasAnyTestable) {
            return new LadderVerdict(
                case: LadderVerdict::NOTHING_TESTABLE,
                text: 'No step has more than one shot recorded, so nothing on this ladder is testable.',
            );
        }

        $separating = array_values(array_filter(
            $pairs,
            fn (LadderPairComparison $p) => $p->classification === PairSeparation::Separates,
        ));

        if ($separating === []) {
            $roundsPhrase = '';
            if ($pooledSd !== null && $pooledSd > 0.0 && $resolvingDelta > 0.0) {
                $rounds = (int) ceil(15.7 * $pooledSd * $pooledSd / ($resolvingDelta * $resolvingDelta));
                $roundsPhrase = sprintf(
                    ' Resolving a %s fps difference between adjacent steps at this pooled SD requires about %d shots per step.',
                    self::formatNumber($resolvingDelta, 0),
                    $rounds,
                );
            }

            return new LadderVerdict(
                case: LadderVerdict::NO_NODE_SUPPORTED,
                text: 'No adjacent pair separates at this sample size. The data does not support a statistically distinguishable node.'.$roundsPhrase,
            );
        }

        $lines = [];
        foreach ($separating as $pair) {
            $lines[] = sprintf(
                '%s → %s: d = %s ± %s fps, t = %s.',
                self::formatValue($pair->fromValue),
                self::formatValue($pair->toValue),
                self::formatNumber($pair->d, 2),
                self::formatNumber($pair->seD, 2),
                self::formatNumber($pair->t, 2),
            );
        }
        $text = implode(' ', $lines).' Everything else on the ladder sits inside its own uncertainty.';

        return new LadderVerdict(
            case: LadderVerdict::NODES_FOUND,
            text: $text,
            separatingPairs: $separating,
        );
    }

    /**
     * Compare the lowest- and highest-SD steps' chi-square intervals. When
     * they overlap, we have no evidence one step is more consistent than the
     * other and copy must not soften that.
     *
     * @param  list<LadderStepStats>  $stepStats
     */
    private static function sdComparison(array $stepStats): ?LadderSdComparison
    {
        $candidates = array_values(array_filter(
            $stepStats,
            fn (LadderStepStats $s) => $s->sd !== null && $s->sdCiLower !== null && $s->sdCiUpper !== null,
        ));

        if (count($candidates) < 2) {
            return null;
        }

        usort($candidates, fn (LadderStepStats $a, LadderStepStats $b) => $a->sd <=> $b->sd);
        $lowest = $candidates[0];
        $highest = $candidates[count($candidates) - 1];

        if ($lowest->stepId === $highest->stepId) {
            return null;
        }

        // Intervals overlap when the lower bound of the higher SD is ≤ the
        // upper bound of the lower SD.
        $overlaps = $highest->sdCiLower <= $lowest->sdCiUpper;

        if ($overlaps) {
            $text = sprintf(
                'Lowest measured SD is %s fps at step %s (90%% CI %s–%s); highest is %s fps at step %s (90%% CI %s–%s). Those intervals overlap — there is no evidence either step is more consistent than the other.',
                self::formatNumber($lowest->sd, 2),
                self::formatValue($lowest->value),
                self::formatNumber($lowest->sdCiLower, 2),
                self::formatNumber($lowest->sdCiUpper, 2),
                self::formatNumber($highest->sd, 2),
                self::formatValue($highest->value),
                self::formatNumber($highest->sdCiLower, 2),
                self::formatNumber($highest->sdCiUpper, 2),
            );
        } else {
            $text = sprintf(
                'Lowest measured SD is %s fps at step %s (90%% CI %s–%s); highest is %s fps at step %s (90%% CI %s–%s). Those intervals do not overlap — the higher-SD step is genuinely less consistent at this sample size.',
                self::formatNumber($lowest->sd, 2),
                self::formatValue($lowest->value),
                self::formatNumber($lowest->sdCiLower, 2),
                self::formatNumber($lowest->sdCiUpper, 2),
                self::formatNumber($highest->sd, 2),
                self::formatValue($highest->value),
                self::formatNumber($highest->sdCiLower, 2),
                self::formatNumber($highest->sdCiUpper, 2),
            );
        }

        return new LadderSdComparison(
            lowestStepId: $lowest->stepId,
            highestStepId: $highest->stepId,
            lowestSd: $lowest->sd,
            highestSd: $highest->sd,
            lowestCiLower: $lowest->sdCiLower,
            lowestCiUpper: $lowest->sdCiUpper,
            highestCiLower: $highest->sdCiLower,
            highestCiUpper: $highest->sdCiUpper,
            intervalsOverlap: $overlaps,
            text: $text,
        );
    }

    private static function formatNumber(float $n, int $decimals): string
    {
        return number_format($n, $decimals, '.', '');
    }

    /**
     * Values are stored to 3 decimal places but usually round cleanly (charge
     * to 1 decimal, seating to 3). Trim trailing zeros for readable copy.
     */
    private static function formatValue(float $v): string
    {
        $s = number_format($v, 3, '.', '');
        $s = rtrim($s, '0');

        return rtrim($s, '.') ?: '0';
    }
}
