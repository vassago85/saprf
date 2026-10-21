<?php

use App\Services\RankStandings;

function rankedRows(array $rows, ?int $seed = null)
{
    return app(RankStandings::class)->rank(collect($rows), $seed);
}

it('gives two shooters tied on top rank 1 and the next shooter rank 3', function () {
    $ranked = rankedRows([
        ['name' => 'Zoe Adams', 'points' => 300.0, 'id' => 1],
        ['name' => 'Amy Brown', 'points' => 300.0, 'id' => 2],
        ['name' => 'Cara Mid', 'points' => 250.0, 'id' => 3],
    ]);

    expect($ranked->pluck('rank')->all())->toBe([1, 1, 3])
        ->and($ranked->pluck('rank_label')->all())->toBe(['T1', 'T1', '3'])
        ->and($ranked->pluck('is_tied')->all())->toBe([true, true, false]);
});

it('skips the following rank after a three-way tie in the middle', function () {
    $ranked = rankedRows([
        ['name' => 'Leader Person', 'points' => 100, 'id' => 1],
        ['name' => 'Cee Middle', 'points' => 90, 'id' => 2],
        ['name' => 'Bee Middle', 'points' => 90, 'id' => 3],
        ['name' => 'Aye Middle', 'points' => 90, 'id' => 4],
        ['name' => 'Trailer Person', 'points' => 80, 'id' => 5],
    ]);

    expect($ranked->pluck('rank_label')->all())->toBe(['1', 'T2', 'T2', 'T2', '5'])
        ->and($ranked->pluck('rank')->all())->toBe([1, 2, 2, 2, 5])
        ->and($ranked->last()['name'])->toBe('Trailer Person');
});

it('keeps the same order for the same seed and only reshuffles inside a tie', function () {
    $rows = [
        ['name' => 'Leader Person', 'points' => 100, 'id' => 1],
        ['name' => 'Cee Middle', 'points' => 90, 'id' => 2],
        ['name' => 'Bee Middle', 'points' => 90, 'id' => 3],
        ['name' => 'Aye Middle', 'points' => 90, 'id' => 4],
        ['name' => 'Trailer Person', 'points' => 80, 'id' => 5],
    ];

    $first = rankedRows($rows, 7)->pluck('name')->all();
    $second = rankedRows($rows, 7)->pluck('name')->all();

    expect($second)->toBe($first);

    $orders = collect(range(1, 40))
        ->map(fn (int $seed) => rankedRows($rows, $seed)->pluck('name')->join('|'))
        ->unique();

    expect($orders->count())->toBeGreaterThan(1);

    foreach (range(1, 40) as $seed) {
        $names = rankedRows($rows, $seed)->pluck('name');

        expect($names->first())->toBe('Leader Person')
            ->and($names->last())->toBe('Trailer Person')
            ->and($names->slice(1, 3)->sort()->values()->all())->toBe([
                'Aye Middle',
                'Bee Middle',
                'Cee Middle',
            ]);
    }
});

it('exports ties in surname then first-name order no matter which seed the page used', function () {
    $rows = [
        ['name' => 'Zoe Adams', 'points' => 100, 'id' => 1],
        ['name' => 'Amy Zulu', 'points' => 100, 'id' => 2],
        ['name' => 'Bob Adams', 'points' => 100, 'id' => 3],
        ['name' => 'Cara Mid', 'points' => 50, 'id' => 4],
    ];

    $expected = ['Bob Adams', 'Zoe Adams', 'Amy Zulu', 'Cara Mid'];

    expect(app(RankStandings::class)->export(collect($rows))->pluck('name')->all())->toBe($expected)
        ->and(rankedRows($rows, null)->pluck('name')->all())->toBe($expected)
        ->and(app(RankStandings::class)->export(collect($rows))->pluck('rank_label')->all())->toBe(['T1', 'T1', 'T1', '4']);
});

it('treats points that differ only by float noise as a tie after rounding to one decimal', function () {
    $ranked = rankedRows([
        ['name' => 'Exact Points', 'points' => 300.0, 'id' => 1],
        ['name' => 'Noisy Points', 'points' => 299.99999, 'id' => 2],
        ['name' => 'Clear Third', 'points' => 250.0, 'id' => 3],
    ]);

    expect($ranked->pluck('rank_label')->all())->toBe(['T1', 'T1', '3'])
        ->and($ranked->pluck('rank')->all())->toBe([1, 1, 3]);

    $split = rankedRows([
        ['name' => 'Just Under', 'points' => 299.94, 'id' => 1],
        ['name' => 'Round Number', 'points' => 300.0, 'id' => 2],
    ]);

    expect($split->pluck('rank_label')->all())->toBe(['1', '2'])
        ->and($split->first()['name'])->toBe('Round Number');
});
