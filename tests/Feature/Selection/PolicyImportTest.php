<?php

use App\Models\SelectionCycle;
use App\Services\Selection\PolicyImportService;

beforeEach(fn () => seedRoles());

function makeImportCycle(): SelectionCycle
{
    return SelectionCycle::create([
        'series' => 'PRS',
        'season' => '2026',
        'championship_name' => 'IPRF World Championships 2026 (Centrefire)',
        'qualifying_period_start' => '2024-11-15',
        'qualifying_period_end' => '2025-11-30',
        'declaration_deadline' => '2025-09-30 23:59:00',
        'results_freeze' => '2026-03-01',
        'status' => 'draft',
        'evaluation_mode' => SelectionCycle::MODE_STRICT,
    ]);
}

it('imports the PRS v1.4 policy JSON and marks it active on the cycle', function () {
    $cycle = makeImportCycle();

    $policy = app(PolicyImportService::class)->import(
        base_path('docs/selection/prs/2026/policy.json'),
        $cycle,
    );

    $cycle->refresh();

    expect($policy->version)->toBe('1.4');
    expect(strlen($policy->source_hash))->toBe(64);
    expect($cycle->active_policy_version_id)->toBe($policy->id);
    expect($policy->spec_json['spec']['spec_version'] ?? null)->toBe('1.4');
    expect($policy->spec_json['spec']['series'] ?? null)->toBe('PRS');
    expect($policy->spec_json['spec']['engine'] ?? null)->toBe('PRS_v1.4');
    expect($policy->spec_json['participation']['thresholds']['min_counted_2d_matches'] ?? null)->toBe(4);
});

it('is idempotent: re-import of the same file updates in place', function () {
    $cycle = makeImportCycle();
    $svc = app(PolicyImportService::class);

    $first = $svc->import(base_path('docs/selection/prs/2026/policy.json'), $cycle);
    $second = $svc->import(base_path('docs/selection/prs/2026/policy.json'), $cycle);

    expect($second->id)->toBe($first->id);
    expect(SelectionCycle::find($cycle->id)->active_policy_version_id)->toBe($first->id);
});

it('throws when the file is not JSON', function () {
    $cycle = makeImportCycle();
    $bad = tempnam(sys_get_temp_dir(), 'bad_policy_');
    file_put_contents($bad, 'not json {[');

    app(PolicyImportService::class)->import($bad, $cycle);
})->throws(RuntimeException::class);
