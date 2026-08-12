<?php

namespace Database\Seeders;

use App\Models\SelectionCycle;
use App\Services\Selection\PolicyImportService;
use Illuminate\Database\Seeder;

/**
 * Seeds the two live SAPRF selection cycles as published:
 *   - PRS  / 2026 (v1.4) : historical Centrefire cycle. Team already
 *                          selected — imported for governance record only,
 *                          runs in 'assume_qualified' mode.
 *   - PR22 / 2027 (v1.1) : active rimfire cycle. Runs in 'assume_qualified'
 *                          mode until the data feeding the strict rules
 *                          (citizenship, club recognition, sanctioning body,
 *                          etc.) is complete enough to switch to 'strict'.
 *
 * Idempotent: safe to re-run.
 */
class SelectionCycleSeeder extends Seeder
{
    /**
     * @var array<int, array{series: string, season: string, championship: string, dates: array<string, string>, status: string, evaluation_mode: string, policy_path: string}>
     */
    private const CYCLES = [
        [
            'series' => 'PRS',
            'season' => '2026',
            'championship' => 'IPRF World Championships 2026 (Centrefire)',
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
            'status' => 'closed',
            'evaluation_mode' => SelectionCycle::MODE_ASSUME_QUALIFIED,
            'policy_path' => 'docs/selection/prs/2026/policy.json',
        ],
        [
            'series' => 'PR22',
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
            'evaluation_mode' => SelectionCycle::MODE_ASSUME_QUALIFIED,
            'policy_path' => 'docs/selection/pr22/2027/policy.json',
        ],
    ];

    public function run(): void
    {
        $importer = app(PolicyImportService::class);

        // Clean up the pre-correction row that used to sit under
        // (series=PR22, season=2026) — it has moved to (series=PRS, season=2026).
        SelectionCycle::query()
            ->where('series', 'PR22')
            ->where('season', '2026')
            ->delete();

        foreach (self::CYCLES as $config) {
            $cycle = SelectionCycle::updateOrCreate(
                ['series' => $config['series'], 'season' => $config['season']],
                array_merge(
                    $config['dates'],
                    [
                        'championship_name' => $config['championship'],
                        'status' => $config['status'],
                        'evaluation_mode' => $config['evaluation_mode'],
                    ],
                ),
            );

            $jsonPath = base_path($config['policy_path']);
            if (! is_file($jsonPath)) {
                $this->command?->warn("Policy JSON missing at {$jsonPath}; skipping import for {$config['series']} {$config['season']}.");
                continue;
            }

            $policy = $importer->import($jsonPath, $cycle);
            $this->command?->info("Cycle {$config['series']} {$config['season']} seeded with policy v{$policy->version} ({$config['evaluation_mode']}).");
        }
    }
}
