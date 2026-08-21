<?php

namespace App\Services\Ladder;

/**
 * Pure statistical helpers used by LadderAnalysis. Kept together so the
 * service reads at the domain level: "chi-square 95th percentile at df"
 * rather than "look up this table entry".
 *
 * Everything here is deterministic and side-effect free.
 */
final class Statistics
{
    /**
     * χ² 5th percentile at df 1..29. Values sourced from standard chi-square
     * tables at cumulative probability 0.05 (i.e. P(X ≤ value) = 0.05).
     * Used for the UPPER SD interval bound because a small critical value in
     * the denominator produces a larger SD estimate.
     *
     * @var array<int, float>
     */
    private const CHI_LOWER = [
        1 => 0.0039321,
        2 => 0.10259,
        3 => 0.35185,
        4 => 0.71072,
        5 => 1.14548,
        6 => 1.63538,
        7 => 2.16735,
        8 => 2.73264,
        9 => 3.32511,
        10 => 3.94030,
        11 => 4.57481,
        12 => 5.22603,
        13 => 5.89186,
        14 => 6.57063,
        15 => 7.26094,
        16 => 7.96165,
        17 => 8.67176,
        18 => 9.39046,
        19 => 10.1170,
        20 => 10.8508,
        21 => 11.5913,
        22 => 12.3380,
        23 => 13.0905,
        24 => 13.8484,
        25 => 14.6114,
        26 => 15.3792,
        27 => 16.1514,
        28 => 16.9279,
        29 => 17.7084,
    ];

    /**
     * χ² 95th percentile at df 1..29. Values sourced from standard chi-square
     * tables at cumulative probability 0.95. Used for the LOWER SD interval
     * bound because a large critical value in the denominator produces a
     * smaller SD estimate.
     *
     * @var array<int, float>
     */
    private const CHI_UPPER = [
        1 => 3.84146,
        2 => 5.99146,
        3 => 7.81473,
        4 => 9.48773,
        5 => 11.0705,
        6 => 12.5916,
        7 => 14.0671,
        8 => 15.5073,
        9 => 16.9190,
        10 => 18.3070,
        11 => 19.6751,
        12 => 21.0261,
        13 => 22.3620,
        14 => 23.6848,
        15 => 24.9958,
        16 => 26.2962,
        17 => 27.5871,
        18 => 28.8693,
        19 => 30.1435,
        20 => 31.4104,
        21 => 32.6706,
        22 => 33.9244,
        23 => 35.1725,
        24 => 36.4150,
        25 => 37.6525,
        26 => 38.8851,
        27 => 40.1133,
        28 => 41.3371,
        29 => 42.5570,
    ];

    /** Standard-normal quantile at 0.05 — used for Wilson–Hilferty when df ≥ 30. */
    private const Z_05 = -1.6448536269514722;

    /** Standard-normal quantile at 0.95. */
    private const Z_95 = 1.6448536269514722;

    /**
     * Value of the chi-square CDF's inverse at cumulative probability 0.05
     * for the given df.  df ≥ 30 falls back to Wilson–Hilferty per spec.
     */
    public static function chiSquareLower(int $df): float
    {
        if ($df < 1) {
            return 0.0;
        }

        if (isset(self::CHI_LOWER[$df])) {
            return self::CHI_LOWER[$df];
        }

        return self::wilsonHilferty($df, self::Z_05);
    }

    /**
     * Value of the chi-square CDF's inverse at cumulative probability 0.95
     * for the given df.  df ≥ 30 falls back to Wilson–Hilferty per spec.
     */
    public static function chiSquareUpper(int $df): float
    {
        if ($df < 1) {
            return 0.0;
        }

        if (isset(self::CHI_UPPER[$df])) {
            return self::CHI_UPPER[$df];
        }

        return self::wilsonHilferty($df, self::Z_95);
    }

    /**
     * Wilson–Hilferty approximation for the chi-square quantile:
     *   χ²_p(df) ≈ df · (1 - 2/(9df) + z_p · sqrt(2/(9df)))³
     *
     * Good to about three decimals for df ≥ 10 and nearly exact by df ≥ 30,
     * which is where the hardcoded table hands off to it.
     */
    private static function wilsonHilferty(int $df, float $z): float
    {
        $a = 2.0 / (9.0 * $df);
        $base = 1.0 - $a + $z * sqrt($a);

        return $df * ($base ** 3);
    }

    /**
     * Two-tailed p-value for Student's t with the given |t| statistic and df.
     *
     * Uses the identity:
     *   P(|T| > t) = I_x(df/2, 1/2)     where x = df / (df + t²)
     * and I is the regularised incomplete beta function.
     */
    public static function studentTwoTailP(float $t, float $df): float
    {
        if ($df <= 0.0 || ! is_finite($t)) {
            return 1.0;
        }

        $t = abs($t);
        $x = $df / ($df + $t * $t);

        return self::incompleteBeta($x, $df / 2.0, 0.5);
    }

    /**
     * Welch–Satterthwaite degrees of freedom for the difference of two means
     * with the given per-group standard errors and sample sizes.
     */
    public static function welchDf(float $seA, int $nA, float $seB, int $nB): float
    {
        if ($nA < 2 || $nB < 2) {
            return 0.0;
        }

        $varA = $seA * $seA;
        $varB = $seB * $seB;
        $num = ($varA + $varB) ** 2;
        $den = ($varA * $varA) / ($nA - 1) + ($varB * $varB) / ($nB - 1);

        if ($den <= 0.0) {
            return 0.0;
        }

        return $num / $den;
    }

    /**
     * Regularised incomplete beta function I_x(a, b), computed via the
     * continued-fraction expansion from Numerical Recipes §6.4. Accurate to
     * roughly 8–10 decimals across the parameter ranges we care about here.
     */
    public static function incompleteBeta(float $x, float $a, float $b): float
    {
        if ($x <= 0.0) {
            return 0.0;
        }
        if ($x >= 1.0) {
            return 1.0;
        }

        // Prefactor: x^a · (1-x)^b / (a · B(a,b))
        $lnBeta = self::logGamma($a) + self::logGamma($b) - self::logGamma($a + $b);
        $bt = exp(-$lnBeta + $a * log($x) + $b * log(1.0 - $x));

        // Continued fraction converges faster in one direction than the other;
        // pick the direction by comparing x to (a+1)/(a+b+2).
        if ($x < ($a + 1.0) / ($a + $b + 2.0)) {
            return $bt * self::betaContinuedFraction($x, $a, $b) / $a;
        }

        return 1.0 - $bt * self::betaContinuedFraction(1.0 - $x, $b, $a) / $b;
    }

    /**
     * Continued fraction for the regularised incomplete beta function.
     * Standard NR implementation using Lentz's method.
     */
    private static function betaContinuedFraction(float $x, float $a, float $b): float
    {
        $maxIterations = 200;
        $epsilon = 3.0e-15;
        $fpMin = 1.0e-300;

        $qab = $a + $b;
        $qap = $a + 1.0;
        $qam = $a - 1.0;
        $c = 1.0;
        $d = 1.0 - $qab * $x / $qap;
        if (abs($d) < $fpMin) {
            $d = $fpMin;
        }
        $d = 1.0 / $d;
        $h = $d;

        for ($m = 1; $m <= $maxIterations; $m++) {
            $m2 = 2 * $m;

            // Even step
            $aa = $m * ($b - $m) * $x / (($qam + $m2) * ($a + $m2));
            $d = 1.0 + $aa * $d;
            if (abs($d) < $fpMin) {
                $d = $fpMin;
            }
            $c = 1.0 + $aa / $c;
            if (abs($c) < $fpMin) {
                $c = $fpMin;
            }
            $d = 1.0 / $d;
            $h *= $d * $c;

            // Odd step
            $aa = -($a + $m) * ($qab + $m) * $x / (($a + $m2) * ($qap + $m2));
            $d = 1.0 + $aa * $d;
            if (abs($d) < $fpMin) {
                $d = $fpMin;
            }
            $c = 1.0 + $aa / $c;
            if (abs($c) < $fpMin) {
                $c = $fpMin;
            }
            $d = 1.0 / $d;
            $del = $d * $c;
            $h *= $del;

            if (abs($del - 1.0) < $epsilon) {
                return $h;
            }
        }

        return $h;
    }

    /**
     * Lanczos approximation for log Γ(x). Coefficients are the standard
     * g=7, n=9 set giving ~15-digit accuracy for x > 0.
     */
    public static function logGamma(float $x): float
    {
        static $coefficients = [
            676.5203681218851,
            -1259.1392167224028,
            771.32342877765313,
            -176.61502916214059,
            12.507343278686905,
            -0.13857109526572012,
            9.9843695780195716e-6,
            1.5056327351493116e-7,
        ];

        if ($x < 0.5) {
            // Reflection formula: Γ(x)Γ(1-x) = π / sin(πx)
            return log(M_PI / sin(M_PI * $x)) - self::logGamma(1.0 - $x);
        }

        $x -= 1.0;
        $a = 0.99999999999980993;
        $t = $x + 7.5;
        foreach ($coefficients as $i => $c) {
            $a += $c / ($x + $i + 1.0);
        }

        return 0.5 * log(2.0 * M_PI) + ($x + 0.5) * log($t) - $t + log($a);
    }
}
