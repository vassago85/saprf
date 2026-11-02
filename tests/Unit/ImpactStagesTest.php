<?php

declare(strict_types=1);

use App\Support\ImpactStages;

it('orders stages by day and then the stage number in the name', function () {
    $ordered = ImpactStages::forDisplay([
        ['name' => 'Stage 2 Stumps', 'points' => 12],
        ['name' => 'Stage 5 Drums', 'points' => 9],
        ['name' => 'Stage 1 Bench', 'points' => 12],
        ['name' => 'Day 2 Stage 1', 'points' => 8],
        ['name' => 'Stage 3 Boomsloop', 'points' => 11],
        ['name' => 'Day 2 Stage 2', 'points' => 7],
    ]);

    expect(array_column($ordered, 'label'))->toBe([
        'Stage 1 Bench',
        'Stage 2 Stumps',
        'Stage 3 Boomsloop',
        'Stage 5 Drums',
        'Day 2 Stage 1',
        'Day 2 Stage 2',
    ]);
});

it('keeps a stage that has no number after the numbered ones', function () {
    $ordered = ImpactStages::sort([
        ['name' => 'Tie breaker'],
        ['name' => 'Stage 2'],
        ['name' => 'Stage 1'],
    ]);

    expect(array_column($ordered, 'name'))->toBe(['Stage 1', 'Stage 2', 'Tie breaker']);
});
