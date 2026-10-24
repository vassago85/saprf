<?php

use App\Support\MatchName;

/**
 * The MatchName rewriter runs on every write path (create / update form
 * requests and the scraped-PRS importer). These tests pin the canonical
 * shape — "Centrefire", "N-Day", "Day N" — so a regex tweak later cannot
 * silently drift back to the old spellings.
 */
it('rewrites every Centre/Center spelling to Centrefire', function (string $input) {
    expect(MatchName::normalize($input))->toContain('Centrefire')
        ->and(MatchName::normalize($input))->not->toContain('CenterFire')
        ->and(MatchName::normalize($input))->not->toContain('Centerfire')
        ->and(MatchName::normalize($input))->not->toContain('CentreFire')
        ->and(MatchName::normalize($input))->not->toContain('Centre Fire');
})->with([
    'two words British' => 'Centre Fire WC Provincial',
    'camel British' => 'CentreFire WC Provincial',
    'two words American' => 'Center Fire WC Provincial',
    'camel American' => 'CenterFire 1day',
    'one word American' => 'Centerfire 1Day',
    'hyphen American' => 'Center-Fire WC',
]);

it('rewrites 1day / 1Day / 1-day to 1-Day', function (string $input) {
    expect(MatchName::normalize($input))->toContain('1-Day')
        ->and(MatchName::normalize($input))->not->toMatch('/\b1day\b/i');
})->with([
    'lower no space' => 'Hippo Brawl 1day',
    'camel' => 'Hippo Brawl 1Day',
    'hyphen' => 'Hippo Brawl 1-day',
    'spaced' => 'Hippo Brawl 1 Day',
]);

it('rewrites day1 / day 1 to Day 1', function (string $input) {
    expect(MatchName::normalize($input))->toContain('Day 1')
        ->and(MatchName::normalize($input))->not->toMatch('/\bday1\b/i');
})->with([
    'no space' => 'Hippo Brawl 2026 day1 Provincial',
    'spaced lower' => 'Hippo Brawl 2026 day 1 Provincial',
]);

it('collapses whitespace that scraped names leave behind', function () {
    expect(MatchName::normalize('Hippo Brawl 2026  day1   Provincial'))
        ->toBe('Hippo Brawl 2026 Day 1 Provincial');
});

it('leaves well-formed names alone', function () {
    expect(MatchName::normalize('Centrefire WC 2-Day National'))
        ->toBe('Centrefire WC 2-Day National');
});

it('handles the exact scraped audit samples', function () {
    expect(MatchName::normalize('CenterFire 1day'))->toBe('Centrefire 1-Day');
    expect(MatchName::normalize('CentreFire 1Day'))->toBe('Centrefire 1-Day');
    expect(MatchName::normalize('Centre Fire LP 2-Day National'))->toBe('Centrefire LP 2-Day National');
    expect(MatchName::normalize('Hippo Brawl 2026 day1 Provincial'))
        ->toBe('Hippo Brawl 2026 Day 1 Provincial');
});

it('returns empty string for null or empty input', function () {
    expect(MatchName::normalize(null))->toBe('');
    expect(MatchName::normalize(''))->toBe('');
    expect(MatchName::normalize('   '))->toBe('');
});
