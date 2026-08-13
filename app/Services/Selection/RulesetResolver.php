<?php

namespace App\Services\Selection;

use App\Models\SelectionAthlete;
use App\Models\SelectionCycle;
use App\Services\Selection\Rulesets\AutoPassEligibilityRuleset;
use App\Services\Selection\Rulesets\AutoPassParticipationRuleset;
use App\Services\Selection\Rulesets\EligibilityRuleset;
use App\Services\Selection\Rulesets\NullScoringRuleset;
use App\Services\Selection\Rulesets\ParticipationRuleset;
use App\Services\Selection\Rulesets\Pr22V11EligibilityRuleset;
use App\Services\Selection\Rulesets\Pr22V11ParticipationRuleset;
use App\Services\Selection\Rulesets\Pr22V11ScoringRuleset;
use App\Services\Selection\Rulesets\PrsV14EligibilityRuleset;
use App\Services\Selection\Rulesets\PrsV14ParticipationRuleset;
use App\Services\Selection\Rulesets\ScoringRuleset;
use RuntimeException;

/**
 * Maps a cycle's active policy to the concrete ruleset classes that should
 * evaluate it. Policies declare their engine via `spec.engine`
 * (e.g. "PRS_v1.4"); the resolver falls back to `series + spec_version` if
 * an older policy predates the engine field.
 *
 * The cycle can also opt out of the strict rules entirely by setting
 * evaluation_mode = 'assume_qualified' — the resolver then returns
 * auto-pass rulesets regardless of the policy engine.
 *
 * Adding a new engine (e.g. PR22_v2.0) is a three-line change here — no
 * changes elsewhere.
 */
class RulesetResolver
{
    /**
     * @var array<string, array{eligibility: class-string<EligibilityRuleset>, participation: class-string<ParticipationRuleset>, scoring: class-string<ScoringRuleset>}>
     */
    private const ENGINES = [
        'PRS_v1.4' => [
            'eligibility' => PrsV14EligibilityRuleset::class,
            'participation' => PrsV14ParticipationRuleset::class,
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
        if ($cycle?->isAssumeQualified()) {
            return [
                'eligibility' => app(AutoPassEligibilityRuleset::class),
                'participation' => app(AutoPassParticipationRuleset::class),
                'scoring' => app(NullScoringRuleset::class),
            ];
        }

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

    /**
     * Resolve the engine-specific ("strict") participation ruleset for the
     * cycle, bypassing the assume_qualified short-circuit. This is how
     * AutoPassParticipationRuleset borrows the real counter to populate the
     * snapshot with informational numbers while still auto-passing every
     * PART-* rule.
     */
    public function strictParticipationForCycle(?SelectionCycle $cycle): ParticipationRuleset
    {
        $engine = $this->resolveEngineKey($cycle);
        $classes = self::ENGINES[$engine] ?? null;
        if (! $classes) {
            throw new RuntimeException("No rulesets registered for engine '{$engine}'. Register it in RulesetResolver::ENGINES or set spec.engine on the policy JSON.");
        }

        return app($classes['participation']);
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

        // Default to the PRS v1.4 centrefire ruleset for cycles that predate
        // the engine field. This is the oldest ruleset in the system and the
        // v1.4 rules are also the most protective (strictest ELG/PART), so
        // an unknown legacy cycle is best represented conservatively.
        return 'PRS_v1.4';
    }
}
