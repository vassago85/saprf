<?php

namespace App\Services\AmmoString;

use App\Models\AmmoString;
use App\Services\AmmoString\DTO\StringFinding;
use App\Services\AmmoString\DTO\StringTrendFit;
use App\Services\Ladder\Statistics;

/**
 * String analyser — analyse a single confirmation string (N shots of one
 * load, fired in order over a chronograph). Everything is shot-order-aware:
 * the ladder analyser lives on the charge axis, this lives on time.
 *
 * Design notes:
 *
 *   - We reuse App\Services\Ladder\Statistics rather than duplicating the
 *     chi-square / t-CDF machinery. It's pure math, not ladder-specific.
 *
 *   - Excluded shots are dropped for every calculation but kept in the
 *     ordered shot list so the UI can render them struck-through and let
 *     the shooter toggle them back on. Sequence numbering never gets
 *     re-packed; a run of 1,2,3,exc,5 is a legitimate string with shot 4
 *     dropped, not a string with sequence errors.
 *
 *   - Cold-bore is treated as a first-shot Grubbs test: is shot #1 a bigger
 *     outlier vs the rest of the string than pure chance would explain?
 *     Only fires when n_rest ≥ 5, otherwise there's no meaningful reference
 *     distribution.
 */
final class StringAnalysis
{
    /**
     * Confidence level for the SD interval — 0.10 total (α = 0.05 each tail),
     * matching the ladder analyser so both tools speak the same language.
     * Reuses App\Services\Ladder\Statistics chi-square tables directly.
     */
    private const SD_ALPHA = 0.10;

    /**
     * Two-sided critical-value threshold for the cold-bore Grubbs check.
     * z > ≈2.5 lands roughly at α = 0.01 on the max of an n=10-ish sample.
     */
    private const COLD_BORE_Z_THRESHOLD = 2.5;

    public static function analyze(AmmoString $string): StringAnalysisResult
    {
        $string->loadMissing('shots');

        // Ordered by sequence per the model's default scope.
        $ordered = $string->shots->sortBy('sequence')->values();
        $totalShots = $ordered->count();

        // Compact view of every shot, with residuals populated later once
        // we have the mean and trend.
        $shotRows = [];
        $vs = [];
        $seqs = [];
        foreach ($ordered as $shot) {
            $shotRows[] = [
                'sequence' => (int) $shot->sequence,
                'velocity' => (float) $shot->velocity_fps,
                'excluded' => (bool) $shot->excluded,
                'residualFromMean' => null,
                'residualFromTrend' => null,
            ];
            if (! $shot->excluded) {
                $vs[] = (float) $shot->velocity_fps;
                $seqs[] = (int) $shot->sequence;
            }
        }

        $n = count($vs);
        if ($n === 0) {
            return new StringAnalysisResult(
                n: 0,
                totalShots: $totalShots,
                mean: null,
                sd: null,
                sdDf: null,
                sdCiLower: null,
                sdCiUpper: null,
                es: null,
                min: null,
                max: null,
                hiShot: null,
                loShot: null,
                shots: $shotRows,
                trend: null,
                coldBoreOutlier: null,
                coldBoreDelta: null,
                findings: [new StringFinding(
                    severity: StringFinding::SEVERITY_WARN,
                    title: 'No shots recorded yet',
                    body: 'Paste the chronograph velocities in fire order to see the analysis.',
                )],
            );
        }

        // Basic stats.
        $mean = array_sum($vs) / $n;
        $sd = null;
        $sdDf = null;
        $sdCiLower = null;
        $sdCiUpper = null;
        if ($n >= 2) {
            $sumSq = 0.0;
            foreach ($vs as $v) {
                $sumSq += ($v - $mean) ** 2;
            }
            $sdDf = $n - 1;
            $sd = sqrt($sumSq / $sdDf);

            // Chi-square 95% CI on the SD. Lower bound uses the upper chi
            // critical value, and vice versa — flipped from your instinct.
            $chiLower = Statistics::chiSquareLower($sdDf);
            $chiUpper = Statistics::chiSquareUpper($sdDf);
            if ($chiLower > 0.0) {
                $sdCiUpper = $sd * sqrt($sdDf / $chiLower);
            }
            if ($chiUpper > 0.0) {
                $sdCiLower = $sd * sqrt($sdDf / $chiUpper);
            }
        }

        $min = min($vs);
        $max = max($vs);
        $es = $max - $min;

        // Find the 1-based shot number that produced the min/max.
        $hiShot = null;
        $loShot = null;
        foreach ($vs as $i => $v) {
            if ($hiShot === null && $v === $max) {
                $hiShot = $seqs[$i];
            }
            if ($loShot === null && $v === $min) {
                $loShot = $seqs[$i];
            }
        }

        // Trend fit — OLS of velocity against shot number.
        $trend = self::fitTrend($vs, $seqs);

        // Residuals against mean and against trend.
        foreach ($shotRows as $i => $row) {
            if ($row['excluded']) {
                continue;
            }
            $shotRows[$i]['residualFromMean'] = $row['velocity'] - $mean;
            if ($trend !== null) {
                $shotRows[$i]['residualFromTrend'] = $row['velocity'] - $trend->predict($row['sequence']);
            }
        }

        // Cold-bore check.
        [$coldBoreOutlier, $coldBoreDelta] = self::coldBoreCheck($vs, $seqs);

        // Findings — order matters, most important on top.
        $findings = self::buildFindings($n, $mean, $sd, $sdCiLower, $sdCiUpper, $es, $trend, $coldBoreOutlier, $coldBoreDelta);

        return new StringAnalysisResult(
            n: $n,
            totalShots: $totalShots,
            mean: $mean,
            sd: $sd,
            sdDf: $sdDf,
            sdCiLower: $sdCiLower,
            sdCiUpper: $sdCiUpper,
            es: $es,
            min: $min,
            max: $max,
            hiShot: $hiShot,
            loShot: $loShot,
            shots: $shotRows,
            trend: $trend,
            coldBoreOutlier: $coldBoreOutlier,
            coldBoreDelta: $coldBoreDelta,
            findings: $findings,
        );
    }

    /**
     * OLS of velocity on shot number with slope significance test.
     *
     * @param  list<float>  $velocities
     * @param  list<int>  $sequences
     */
    private static function fitTrend(array $velocities, array $sequences): ?StringTrendFit
    {
        $n = count($velocities);
        if ($n < 3) {
            return null;
        }

        $xs = array_map(fn ($s) => (float) $s, $sequences);
        $meanX = array_sum($xs) / $n;
        $meanY = array_sum($velocities) / $n;

        $sumDx2 = 0.0;
        $sumDxDy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $xs[$i] - $meanX;
            $sumDx2 += $dx * $dx;
            $sumDxDy += $dx * ($velocities[$i] - $meanY);
        }
        if ($sumDx2 == 0.0) {
            return null;
        }

        $slope = $sumDxDy / $sumDx2;
        $intercept = $meanY - $slope * $meanX;

        $ssRes = 0.0;
        $ssTot = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $ssRes += ($velocities[$i] - ($intercept + $slope * $xs[$i])) ** 2;
            $ssTot += ($velocities[$i] - $meanY) ** 2;
        }
        $rSquared = $ssTot > 0.0 ? 1.0 - ($ssRes / $ssTot) : 0.0;

        $df = $n - 2;
        $residualVar = $ssRes / $df;
        $slopeSe = sqrt($residualVar / $sumDx2);
        $slopeT = $slopeSe > 0.0 ? $slope / $slopeSe : 0.0;
        $slopeP = Statistics::studentTwoTailP($slopeT, (float) $df);
        $tCrit = Statistics::tQuantileTwoTailed(0.05, (float) $df);

        return new StringTrendFit(
            slope: $slope,
            intercept: $intercept,
            rSquared: $rSquared,
            slopeSe: $slopeSe,
            slopeT: $slopeT,
            slopeP: $slopeP,
            slopeCiLower: $slope - $tCrit * $slopeSe,
            slopeCiUpper: $slope + $tCrit * $slopeSe,
            df: $df,
        );
    }

    /**
     * Grubbs-style outlier check for shot #1 against the rest of the string.
     * Returns [isOutlier, delta] where delta is shot1 - mean(rest). Null when
     * there aren't enough remaining shots to build a reference distribution.
     *
     * @param  list<float>  $velocities
     * @param  list<int>  $sequences
     * @return array{0: ?bool, 1: ?float}
     */
    private static function coldBoreCheck(array $velocities, array $sequences): array
    {
        $firstIdx = null;
        foreach ($sequences as $i => $seq) {
            if ($seq === 1) {
                $firstIdx = $i;
                break;
            }
        }
        if ($firstIdx === null) {
            return [null, null];
        }

        $rest = $velocities;
        array_splice($rest, $firstIdx, 1);
        if (count($rest) < 5) {
            return [null, null];
        }

        $restMean = array_sum($rest) / count($rest);
        $restVar = 0.0;
        foreach ($rest as $v) {
            $restVar += ($v - $restMean) ** 2;
        }
        $restSd = sqrt($restVar / (count($rest) - 1));

        $delta = $velocities[$firstIdx] - $restMean;
        if ($restSd <= 0.0) {
            return [false, $delta];
        }

        $z = abs($delta) / $restSd;

        return [$z > self::COLD_BORE_Z_THRESHOLD, $delta];
    }

    /**
     * @return list<StringFinding>
     */
    private static function buildFindings(
        int $n,
        float $mean,
        ?float $sd,
        ?float $sdCiLower,
        ?float $sdCiUpper,
        float $es,
        ?StringTrendFit $trend,
        ?bool $coldBoreOutlier,
        ?float $coldBoreDelta,
    ): array {
        $findings = [];

        if ($n < 2) {
            $findings[] = new StringFinding(
                severity: StringFinding::SEVERITY_WARN,
                title: 'Only one shot',
                body: 'A single velocity is a data point, not a string. Add at least four or five more shots to get a real spread estimate.',
            );

            return $findings;
        }

        // SD headline.
        if ($sd !== null && $sdCiLower !== null && $sdCiUpper !== null) {
            $body = sprintf(
                'Sample SD is <b>%s fps</b> at n = %d. The 90%% confidence interval on the true SD is <b>%s to %s fps</b> — the honest range this string constrains the load to.',
                self::fmt($sd, 2),
                $n,
                self::fmt($sdCiLower, 2),
                self::fmt($sdCiUpper, 2),
            );

            if ($n < 10) {
                $body .= ' The interval will tighten a lot with the next few shots — at n = 20 it typically halves.';
            }

            $findings[] = new StringFinding(
                severity: $n >= 10 ? StringFinding::SEVERITY_OK : StringFinding::SEVERITY_WARN,
                title: sprintf('SD is %s fps across the string', self::fmt($sd, 1)),
                body: $body,
            );
        }

        // Mean.
        $findings[] = new StringFinding(
            severity: StringFinding::SEVERITY_OK,
            title: sprintf('Mean velocity %s fps', self::fmt($mean, 1)),
            body: sprintf(
                'Extreme spread across the string is <b>%s fps</b> from the fastest shot to the slowest. ES scales with n even when the underlying spread has not changed, so weigh the SD figure ahead of it.',
                self::fmt($es, 1),
            ),
        );

        // Trend.
        if ($trend !== null) {
            $direction = $trend->slope >= 0 ? 'up' : 'down';
            $absSlope = abs($trend->slope);
            $totalDrift = $absSlope * ($n - 1);

            if ($trend->isSignificant()) {
                $findings[] = new StringFinding(
                    severity: StringFinding::SEVERITY_WARN,
                    title: sprintf('Velocity trends %s across the string', $direction),
                    body: sprintf(
                        'The fit against shot number is <b>%s fps/shot</b> (p = %s), which means the first and last shots differ by about <b>%s fps</b> on average. Barrel heating is the usual cause of an upward trend; a slow leak of powder consistency or shooter fatigue tends to drift the other way. Space shots further apart on the next string and see if it flattens.',
                        self::fmt($trend->slope, 2),
                        self::fmt($trend->slopeP, 3),
                        self::fmt($totalDrift, 1),
                    ),
                );
            } else {
                $findings[] = new StringFinding(
                    severity: StringFinding::SEVERITY_OK,
                    title: 'No trend across the string',
                    body: sprintf(
                        'The velocity-versus-shot regression has a slope of %s fps/shot with p = %s. That is inside the natural scatter — the string is stable, no barrel-heating or cold-bore drift is visible in these numbers.',
                        self::fmt($trend->slope, 2),
                        self::fmt($trend->slopeP, 3),
                    ),
                );
            }
        }

        // Cold-bore.
        if ($coldBoreOutlier === true && $coldBoreDelta !== null) {
            $side = $coldBoreDelta >= 0 ? 'higher' : 'lower';
            $findings[] = new StringFinding(
                severity: StringFinding::SEVERITY_WARN,
                title: 'Cold-bore shot ran '.$side,
                body: sprintf(
                    'Shot #1 landed <b>%s fps %s</b> than the mean of the rest of the string. That is more than the shots that followed can explain by chance, so treat it as a genuine cold-bore effect. On this rifle you probably want a fouling shot before you trust the group.',
                    self::fmt(abs($coldBoreDelta), 1),
                    $side,
                ),
            );
        } elseif ($coldBoreOutlier === false && $coldBoreDelta !== null) {
            $findings[] = new StringFinding(
                severity: StringFinding::SEVERITY_OK,
                title: 'Cold bore looks clean',
                body: sprintf(
                    'Shot #1 sat within the string\'s own scatter (%s fps from the rest\'s mean). No cold-bore shift worth compensating for — the first round from cold is behaving like every other round.',
                    self::fmt(abs($coldBoreDelta), 1),
                ),
            );
        }

        return $findings;
    }

    private static function fmt(float $value, int $decimals): string
    {
        return number_format($value, $decimals);
    }
}
