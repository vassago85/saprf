<?php

declare(strict_types=1);

use App\Services\ImpactScoring\ImpactNameMatcher;

it('treats an accented surname as the same name', function () {
    $matcher = new ImpactNameMatcher;

    $hit = $matcher->match('Gerhard Rörich', [
        ['key' => '1', 'name' => 'Gerhard Rorich'],
    ]);

    expect($hit['status'])->toBe('exact')->and($hit['key'])->toBe('1');
});

it('treats an apostrophe and a joined surname particle as the same name', function () {
    $matcher = new ImpactNameMatcher;

    $oneill = $matcher->match("Oneill Kammies", [
        ['key' => '1', 'name' => "O'Neill Kammies"],
    ]);
    $leroux = $matcher->match('Petrus Leroux', [
        ['key' => '2', 'name' => 'Petrus Le Roux'],
    ]);

    expect($oneill['status'])->toBe('exact')->and($oneill['key'])->toBe('1')
        ->and($leroux['status'])->toBe('exact')->and($leroux['key'])->toBe('2');
});

it('links an extra given name only when one entry is contained in the scoreboard name', function () {
    $matcher = new ImpactNameMatcher;
    $candidates = [
        ['key' => 'petrus', 'name' => 'Petrus Le Roux'],
        ['key' => 'monica', 'name' => 'Monica Le Roux'],
    ];

    $hit = $matcher->match('Petrus Bosman Le Roux', $candidates);

    expect($hit['status'])->toBe('exact')->and($hit['key'])->toBe('petrus');
});

it('does not auto-link when two entries both fit inside the scoreboard name', function () {
    $matcher = new ImpactNameMatcher;

    $hit = $matcher->match('Petrus Bosman Le Roux', [
        ['key' => 'petrus', 'name' => 'Petrus Le Roux'],
        ['key' => 'bosman', 'name' => 'Bosman Le Roux'],
    ]);

    expect($hit['status'])->not->toBe('exact')->and($hit['key'])->toBeNull();
});
