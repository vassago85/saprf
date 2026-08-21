<?php

use App\Models\AmmoString;
use App\Models\AmmoStringShot;
use App\Models\User;
use App\Services\AmmoString\DTO\StringFinding;
use App\Services\AmmoString\StringAnalysis;

/**
 * Build an ammo string with the given velocity sequence. Excluded flag can be
 * asserted per-shot via the `excluded` key on each element.
 *
 * @param  list<float|array{v: float, excluded?: bool}>  $shots
 */
function buildString(array $shots): AmmoString
{
    $user = User::factory()->create();
    $string = AmmoString::factory()->for($user)->create();

    foreach ($shots as $i => $entry) {
        if (is_array($entry)) {
            AmmoStringShot::factory()->create([
                'ammo_string_id' => $string->id,
                'sequence' => $i + 1,
                'velocity_fps' => $entry['v'],
                'excluded' => $entry['excluded'] ?? false,
            ]);
        } else {
            AmmoStringShot::factory()->create([
                'ammo_string_id' => $string->id,
                'sequence' => $i + 1,
                'velocity_fps' => (float) $entry,
                'excluded' => false,
            ]);
        }
    }

    return $string->fresh(['shots']);
}

it('produces the right basic stats for a well-behaved string', function () {
    // Known dataset — mean 2800, SD approximately 9.13 fps at n=10.
    $string = buildString([2791, 2809, 2795, 2812, 2803, 2789, 2805, 2810, 2798, 2788]);

    $result = StringAnalysis::analyze($string);

    expect($result->n)->toBe(10);
    expect($result->mean)->toBeGreaterThan(2799.9)->toBeLessThan(2800.1);
    expect($result->sd)->toBeGreaterThan(8.5)->toBeLessThan(9.6);
    expect($result->sdDf)->toBe(9);
    expect($result->es)->toEqual(24.0);
    expect($result->min)->toEqual(2788.0);
    expect($result->max)->toEqual(2812.0);
});

it('computes a wider SD CI as n falls', function () {
    // n=3 should yield a very wide 95% CI on SD.
    $shortString = buildString([2795, 2802, 2798]);
    $short = StringAnalysis::analyze($shortString);

    expect($short->sdCiLower)->toBeLessThan($short->sd);
    expect($short->sdCiUpper)->toBeGreaterThan($short->sd);
    // The chi-square construction at df=2 and this SD lands the upper bound
    // roughly 15x the point estimate — 30-shot bands are much tighter.
    $shortRatio = $short->sdCiUpper / $short->sdCiLower;

    $longString = buildString([2791, 2809, 2795, 2812, 2803, 2789, 2805, 2810, 2798, 2788,
        2801, 2793, 2811, 2799, 2802, 2808, 2790, 2807, 2796, 2804]);
    $long = StringAnalysis::analyze($longString);
    $longRatio = $long->sdCiUpper / $long->sdCiLower;

    expect($longRatio)->toBeLessThan($shortRatio);
});

it('detects a significant upward trend when velocities rise across the string', function () {
    // Deliberately climbing string: mean rises 30 fps across 10 shots with
    // only ~5 fps of noise. Should register as a significant positive slope.
    $string = buildString([2795, 2800, 2803, 2808, 2812, 2818, 2822, 2827, 2831, 2836]);

    $result = StringAnalysis::analyze($string);

    expect($result->trend)->not->toBeNull();
    expect($result->trend->slope)->toBeGreaterThan(4.0);
    expect($result->trend->slopeP)->toBeLessThan(0.01);
    expect($result->trend->isSignificant())->toBeTrue();
    expect($result->trend->rSquared)->toBeGreaterThan(0.95);

    $trendFinding = collect($result->findings)->first(fn ($f) => str_contains($f->title, 'trend'));
    expect($trendFinding)->not->toBeNull();
    expect($trendFinding->severity)->toBe(StringFinding::SEVERITY_WARN);
});

it('flags a cold-bore shot when shot #1 sits well above the rest', function () {
    // Shot 1 at 2825 vs rest around 2800 (SD roughly 5 fps): the z-score
    // will land well above the 2.5 threshold.
    $string = buildString([2825, 2801, 2799, 2803, 2798, 2802, 2800, 2797, 2804, 2799]);

    $result = StringAnalysis::analyze($string);

    expect($result->coldBoreOutlier)->toBeTrue();
    expect($result->coldBoreDelta)->toBeGreaterThan(15.0);

    $coldBore = collect($result->findings)->first(fn ($f) => str_contains($f->title, 'Cold-bore'));
    expect($coldBore)->not->toBeNull();
    expect($coldBore->severity)->toBe(StringFinding::SEVERITY_WARN);
});

it('does not flag cold-bore when shot 1 sits inside the rest of the string\'s scatter', function () {
    // Random-looking string with no first-shot anomaly.
    $string = buildString([2800, 2802, 2799, 2803, 2798, 2802, 2800, 2797, 2804, 2799]);

    $result = StringAnalysis::analyze($string);

    expect($result->coldBoreOutlier)->toBeFalse();
});

it('drops excluded shots from every calculation but keeps them in the shot list', function () {
    $string = buildString([
        2795,
        ['v' => 1000.0, 'excluded' => true],
        2803,
        2799,
        2801,
    ]);

    $result = StringAnalysis::analyze($string);

    expect($result->n)->toBe(4);
    expect($result->totalShots)->toBe(5);
    expect($result->mean)->toBeGreaterThan(2795)->toBeLessThan(2805);

    // The excluded shot is still present in the row list, just flagged.
    $excludedRows = collect($result->shots)->filter(fn ($s) => $s['excluded']);
    expect($excludedRows->count())->toBe(1);
    expect($excludedRows->first()['velocity'])->toEqual(1000.0);
});

it('degrades gracefully when there is only one shot', function () {
    $string = buildString([2800]);

    $result = StringAnalysis::analyze($string);

    expect($result->n)->toBe(1);
    expect($result->mean)->toEqual(2800.0);
    expect($result->sd)->toBeNull();
    expect($result->trend)->toBeNull();
    expect($result->coldBoreOutlier)->toBeNull();
});
