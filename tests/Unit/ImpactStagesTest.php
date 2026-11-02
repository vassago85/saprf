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

it('orders stages whose names lead with a number instead of the word "stage"', function () {
    // Impact returns stages in bay order (1, 3, 2, 4…). Many PR22 courses
    // name them "1 - When Pigs Fly", "2 - Unstable", "3 - Odin's Barrels"
    // without the "stage" prefix, so the sort has to pick up the leading
    // digit — otherwise the display mirrors the bay order and reads wrong.
    $ordered = ImpactStages::forDisplay([
        ['name' => '1 - When Pigs Fly', 'points' => 12],
        ['name' => "3 - Odin's Barrels", 'points' => 11],
        ['name' => '2 - Unstable Relationships', 'points' => 9],
        ['name' => '4 - Crossfit', 'points' => 7],
        ['name' => '5 - Overwatch', 'points' => 11],
        ['name' => '6 - Rooftop Varmints', 'points' => 12],
        ['name' => '7 - MISS Management', 'points' => 10],
        ['name' => '8 - Impact Trio', 'points' => 10],
    ]);

    expect(array_column($ordered, 'label'))->toBe([
        '1 - When Pigs Fly',
        '2 - Unstable Relationships',
        "3 - Odin's Barrels",
        '4 - Crossfit',
        '5 - Overwatch',
        '6 - Rooftop Varmints',
        '7 - MISS Management',
        '8 - Impact Trio',
    ]);
});
