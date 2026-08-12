<?php

namespace App\Services\Selection;

use App\Models\SelectionAthlete;
use App\Models\SelectionCycle;
use App\Services\Selection\Rulesets\EligibilityRuleset;
use App\Services\Selection\Rulesets\NullScoringRuleset;
use App\Services\Selection\Rulesets\ParticipationRuleset;
use App\Services\Selection\Rulesets\Pr22V11EligibilityRuleset;
use App\Services\Selection\Rulesets\Pr22V11ParticipationRuleset;
use App\Services\Selection\Rulesets\Pr22V11ScoringRuleset;
use App\Services\Selection\Rulesets\Pr22V14EligibilityRuleset;
use App\Services\Selection\Rulesets\Pr22V14ParticipationRuleset;
use App\Services\Selection\Rulesets\ScoringRuleset;
use RuntimeException;

/**
 * Maps a cycle's active policy to the concrete ruleset classes that should
 * evaluate it. Policies declare their engine via `spec.engine`
 * (e.g. "PR22_v1.4"); the resolver falls back to `series + spec_version` if
 * an older policy predates the engine field.
 *
 * When we onboard PRS (or v2.0 of PR22), each engine key just needs three
 * class registrations here — no changes elsewhere.
 */
class RulesetResolver
{
    /**
     * @var array<string, array{eligibility: class-string<EligibilityRuleset>, participation: class-string<ParticipationRuleset>, scoring: class-string<ScoringRuleset>}>
     */
    private const ENGINES = [
        'PR22_v1.4' => [
            'eligibility' => Pr22V14EligibilityRuleset::class,
            'participation' => Pr22V14ParticipationRuleset::class,
            'scoring' => NullScoringRuleset::class,
        ],
        'PR22_v1.1' => [
            'eligibility' => Pr22V11EligibilityRuleset::class,
            'participation' => Pr22V11ParticipationRuleset::class,
            'scoring' => Pr22V11ScoringRuleset::class,
        ],
    ];

    public function forAthlete(SelectionAthlete $athlete): array
    {
        return $this->forCycle($athlete->cycle);
    }

    /**
     * @return array{eligibility: EligibilityRuleset, participation: ParticipationRuleset, scoring: ScoringRuleset}
     */
    public function forCycle(?SelectionCycle $cycle): array
    {
        $engine = $this->resolveEngineKey($cycle);
        $classes = self::ENGINES[$engine] ?? null;
        if (! $classes) {
            throw new RuntimeException("No rulesets registered for engine '{$engine}'. Register it in RulesetResolver::ENGINES or set spec.engine on the policy JSON.");
        }

        return [
            'eligibility' => app($classes['eligibility']),
            'participation' => app($classes['participation']),
            'scoring' => app($classes['scoring']),
        ];
    }

    public function resolveEngineKey(?SelectionCycle $cycle): string
    {
        $spec = $cycle?->activePolicy?->spec_json['spec'] ?? [];
        $engine = $spec['engine'] ?? null;
        if (is_string($engine) && isset(self::ENGINES[$engine])) {
            return $engine;
        }

        $series = $spec['series'] ?? $cycle?->series ?? '';
        $version = $spec['spec_version'] ?? $cycle?->activePolicy?->version ?? '';
        $fallback = "{$series}_v{$version}";
        if (isset(self::ENGINES[$fallback])) {
            return $fallback;
        }

        // Default to v1.4 for cycles that predate the engine field and are
        // known to use the 2026-cycle rules (backward compat for the existing
        // seeded cycle).
        return 'PR22_v1.4';
    }
}
