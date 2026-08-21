<?php

use App\Enums\LadderVariable;
use App\Models\LadderSession;
use App\Models\LadderShot;
use App\Models\LadderStep;
use App\Models\User;
use App\Services\Ladder\LadderAnalysis;

beforeEach(function () {
    seedRoles();
});

/**
 * The whole point of the service being variable-agnostic: same velocities
 * against seating-depth values produce the same t statistics and the same
 * verdict text, with the slope expressed per mm instead of per grain.
 */
it('produces identical stats for the same velocities on a seating-depth ladder', function () {
    $chargeFixture = function (array $rows, LadderVariable $variable) {
        $user = User::factory()->create();
        $user->assignRole('member');
        $session = LadderSession::factory()->for($user)->create(['variable' => $variable]);
        foreach ($rows as $i => $row) {
            $step = LadderStep::factory()->for($session)->create([
                'value' => $row['value'],
                'include_in_fit' => $row['in_fit'],
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
    };

    // Same numeric x-axis values, once as grains, once as millimetres. The
    // service must not distinguish between them.
    $rows = [
        ['value' => 40.0, 'velocities' => [2576.0], 'in_fit' => false],
        ['value' => 40.2, 'velocities' => [2586.3, 2575.9, 2584.6], 'in_fit' => false],
        ['value' => 40.4, 'velocities' => [2618.7, 2608.8, 2607.1], 'in_fit' => true],
        ['value' => 40.6, 'velocities' => [2611.6, 2606.0, 2634.7], 'in_fit' => true],
        ['value' => 40.8, 'velocities' => [2633.8, 2620.6, 2626.7], 'in_fit' => true],
        ['value' => 41.0, 'velocities' => [2652.8, 2632.2], 'in_fit' => true],
        ['value' => 41.6, 'velocities' => [2709.6], 'in_fit' => false],
    ];

    $chargeResult = LadderAnalysis::analyze($chargeFixture($rows, LadderVariable::ChargeWeight));
    $seatingResult = LadderAnalysis::analyze($chargeFixture($rows, LadderVariable::SeatingDepth));

    // Slope value is identical numerically — its unit changes only.
    expect($seatingResult->trend->slope)->toBe($chargeResult->trend->slope);
    expect($seatingResult->trend->intercept)->toBe($chargeResult->trend->intercept);
    expect($seatingResult->pooledSd)->toBe($chargeResult->pooledSd);
    expect($seatingResult->roundsRequired)->toBe($chargeResult->roundsRequired);

    // t statistics for every adjacent pair line up exactly.
    $chargeTs = collect($chargeResult->pairs)->map(fn ($p) => $p->t)->all();
    $seatingTs = collect($seatingResult->pairs)->map(fn ($p) => $p->t)->all();
    expect($seatingTs)->toBe($chargeTs);

    // Verdict text is identical — copy generation reads no variable-specific
    // strings, only the numbers.
    expect($seatingResult->verdict->case)->toBe($chargeResult->verdict->case);
    expect($seatingResult->verdict->text)->toBe($chargeResult->verdict->text);

    // Enum surface differs.
    expect($chargeResult->variable)->toBe(LadderVariable::ChargeWeight);
    expect($seatingResult->variable)->toBe(LadderVariable::SeatingDepth);
    expect($chargeResult->variable->slopeUnit())->toBe('fps/gr');
    expect($seatingResult->variable->slopeUnit())->toBe('fps/mm');
});
