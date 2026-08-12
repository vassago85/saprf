<?php

namespace Database\Seeders;

use App\Models\SelectionCycle;
use App\Services\Selection\PolicyImportService;
use Illuminate\Database\Seeder;

/**
 * Seeds the two live PR22 selection cycles as published by SAPRF:
 *   - PR22 / 2026 (v1.4) : historical — IPRF WCH 2026, Aug 17-26 2026.
 *   - PR22 / 2027 (v1.1) : active     — IPRF WCH 2027, Aug 2027 (venue TBA).
 *
 * Each cycle imports its structured policy from docs/selection/pr22/{season}/policy.json.
 * Idempotent: safe to re-run.
 */
class SelectionCycleSeeder extends Seeder
{
    /**
     * @var array<int, array{season: string, championship: string, dates: array<string, string>, status: string, policy_path: string}>
     */
    private const CYCLES = [
        [
            'season' => '2026',
            'championship' => 'IPRF PR22 World Championships 2026',
            'dates' => [
                'qualifying_period_start' => '2024-11-15',
                'qualifying_period_end' => '2025-11-30',
                'declaration_deadline' => '2025-09-30 23:59:00',
                'panel_lock_date' => '2025-09-30',
                'deliberation_start' => '2026-01-01',
                'deliberation_end' => '2026-02-28',
                'results_freeze' => '2026-03-01',
                'publication_date' => '2026-03-15',
            ],
            'status' => 'announced',
            'policy_path' => 'docs/selection/pr22/2026/policy.json',
        ],
        [
            'season' => '2027',
            'championship' => 'IPRF PR22 Team World Championships 2027',
            'dates' => [
                'qualifying_period_start' => '2026-01-01',
                'qualifying_period_end' => '2026-12-31',
                'declaration_deadline' => '2026-11-30 23:59:00',
                'panel_lock_date' => '2027-01-12',
                'deliberation_start' => '2027-01-16',
                'deliberation_end' => '2027-01-28',
                'results_freeze' => '2026-12-31',
                'publication_date' => '2027-01-30',
            ],
            'status' => 'open',
            'policy_path' => 'docs/selection/pr22/2027/policy.json',
        ],
    ];

    public function run(): void
    {
        $importer = app(PolicyImportService::class);

        foreach (self::CYCLES as $config) {
            $cycle = SelectionCycle::updateOrCreate(
                ['series' => 'PR22', 'season' => $config['season']],
                array_merge(
                    $config['dates'],
                    [
                        'championship_name' => $config['championship'],
                        'status' => $config['status'],
                    ],
                ),
            );

            $jsonPath = base_path($config['policy_path']);
            if (! is_file($jsonPath)) {
                $this->command?->warn("Policy JSON missing at {$jsonPath}; skipping import for PR22 {$config['season']}.");
                continue;
            }

            $policy = $importer->import($jsonPath, $cycle);
            $this->command?->info("Cycle PR22 {$config['season']} seeded with policy v{$policy->version}.");
        }
    }
}
