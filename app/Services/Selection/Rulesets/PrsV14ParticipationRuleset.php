<?php

namespace App\Services\Selection\Rulesets;

use App\Models\MatchEvent;
use App\Models\Score;
use App\Models\SelectionAthlete;
use App\Models\SelectionParticipationSnapshot;
use App\Models\SelectionRuleEvaluation;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SAPRF PRS (Centrefire) v1.4 participation ruleset — capped 2-day match
 * counting with geographical caps, SA-Champs geographical exemption, "within
 * 3 months of close" recency requirement, and a fail-open sanctioning check
 * that will light up once matches carry a sanctioning_body field.
 *
 * Counting is factored into collectFacts()/computeSnapshotPayload() so
 * AutoPassParticipationRuleset can reuse the numbers for informational
 * display without also emitting FAIL outcomes.
 */
class PrsV14ParticipationRuleset implements ParticipationRuleset
{
    private const TWO_DAY_LEVELS = ['national', 'international', 'final'];

    public function evaluate(SelectionAthlete $athlete): SelectionParticipationSnapshot
    {
        $facts = $this->collectFacts($athlete);

        $snapshot = SelectionParticipationSnapshot::updateOrCreate(
            ['selection_athlete_id' => $athlete->id],
            $this->factsToSnapshotPayload($facts, $athlete),
        );

        $this->persistRuleEvaluations($athlete, $facts);

        return $snapshot;
    }

    public function computeSnapshotPayload(SelectionAthlete $athlete): array
    {
        return $this->factsToSnapshotPayload($this->collectFacts($athlete), $athlete);
    }

    /**
     * Bucketed scores + capped selections + policy thresholds. Shared by
     * evaluate() and computeSnapshotPayload() so both paths agree on which
     * scores are "counted" (post-cap) vs raw.
     *
     * @return array<string, mixed>
     */
    private function collectFacts(SelectionAthlete $athlete): array
    {
        $cycle = $athlete->cycle;
        $user = $athlete->user;
        $policy = $cycle?->activePolicy;
        $spec = $policy?->spec_json['participation']['thresholds'] ?? [];

        $inProvinceCap = (int) ($spec['in_province_2d_national_cap'] ?? 1);
        $outOfProvinceCap = (int) ($spec['out_of_province_2d_national_cap'] ?? 3);
        $minCounted = (int) ($spec['min_counted_2d_matches'] ?? 4);
        $requireSaChamps = (bool) ($spec['must_include_sa_champs'] ?? true);
        $saChampsGeoExempt = (bool) ($spec['sa_champs_geographic_exempt'] ?? true);
        $minWithinClose = (int) ($spec['min_within_3_months_of_close'] ?? 1);

        $windowStart = $cycle?->qualifying_period_end?->copy()->subMonthsNoOverflow(3);
        $windowEnd = $cycle?->qualifying_period_end;

        $scores = $this->scoresInPeriod($athlete)->get();
        $homeProvinceId = $user?->province_id;

        [$saChampsScores, $twoDayScores] = $scores
            ->filter(fn (Score $s) => in_array($s->match?->series_level, self::TWO_DAY_LEVELS, true))
            ->partition(fn (Score $s) => $s->match?->series_level === 'final');

        $nationalScores = $twoDayScores->filter(fn (Score $s) => $s->match?->series_level === 'national');
        $internationalScores = $twoDayScores->filter(fn (Score $s) => $s->match?->series_level === 'international');

        [$inProvinceNationals, $outOfProvinceNationals] = $nationalScores
            ->partition(fn (Score $s) => $homeProvinceId
                && $s->match?->province_id === $homeProvinceId);

        $inProvinceNationals = $inProvinceNationals->sortBy(fn (Score $s) => $s->match?->match_date)->values();
        $outOfProvinceNationals = $outOfProvinceNationals->sortBy(fn (Score $s) => $s->match?->match_date)->values();

        $countedInProvince = $inProvinceNationals->take($inProvinceCap);
        $countedOutOfProvince = $outOfProvinceNationals->take($outOfProvinceCap);

        $countedSet = collect();
        $saChampsShot = $saChampsScores->isNotEmpty();
        if ($saChampsShot) {
            $countedSet = $countedSet->concat($saChampsScores);
        }
        $countedSet = $countedSet
            ->concat($countedInProvince)
            ->concat($countedOutOfProvince)
            ->concat($internationalScores);

        $withinCloseWindowCount = 0;
        if ($windowStart && $windowEnd) {
            $withinCloseWindowCount = $countedSet
                ->filter(fn (Score $s) => $this->matchDateInWindow($s->match, $windowStart, $windowEnd))
                ->count();
        }

        return [
            'scores' => $scores,
            'provincial1d' => $scores->filter(fn (Score $s) => $s->match?->series_level === 'provincial'),
            'saChampsScores' => $saChampsScores,
            'saChampsShot' => $saChampsShot,
            'nationalScores' => $nationalScores,
            'internationalScores' => $internationalScores,
            'inProvinceNationals' => $inProvinceNationals,
            'outOfProvinceNationals' => $outOfProvinceNationals,
            'countedInProvince' => $countedInProvince,
            'countedOutOfProvince' => $countedOutOfProvince,
            'countedSet' => $countedSet,
            'withinCloseWindowCount' => $withinCloseWindowCount,
            'windowStart' => $windowStart,
            'windowEnd' => $windowEnd,
            'homeProvinceId' => $homeProvinceId,
            'thresholds' => [
                'minCounted' => $minCounted,
                'inProvinceCap' => $inProvinceCap,
                'outOfProvinceCap' => $outOfProvinceCap,
                'requireSaChamps' => $requireSaChamps,
                'saChampsGeoExempt' => $saChampsGeoExempt,
                'minWithinClose' => $minWithinClose,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private function factsToSnapshotPayload(array $facts, SelectionAthlete $athlete): array
    {
        return [
            'provincial_1d_count' => $facts['provincial1d']->count(),
            'national_2d_count' => $facts['countedInProvince']->count() + $facts['countedOutOfProvince']->count(),
            'international_2d_count' => $facts['internationalScores']->count(),
            'out_of_home_province_2d_count' => $facts['countedOutOfProvince']->count(),
            'sa_champs_shot' => $facts['saChampsShot'],
            'counted_score_ids' => $facts['countedSet']->pluck('id')->all(),
            'computed_at' => now(),
            'computed_against_policy_id' => $athlete->cycle?->activePolicy?->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function persistRuleEvaluations(SelectionAthlete $athlete, array $facts): void
    {
        $policyVersion = $athlete->cycle?->activePolicy?->version ?? 'unknown';
        $now = now();

        $thresholds = $facts['thresholds'];
        $countedTotal = $facts['countedSet']->count();

        $part01 = [
            'rule_id' => 'PART-01',
            'outcome' => $thresholds['requireSaChamps'] === false || $facts['saChampsShot']
                ? SelectionRuleEvaluation::OUTCOME_PASS
                : SelectionRuleEvaluation::OUTCOME_FAIL,
            'detail' => [
                'sa_champs_shot' => $facts['saChampsShot'],
                'sa_champs_required' => $thresholds['requireSaChamps'],
            ],
        ];

        $part02 = [
            'rule_id' => 'PART-02',
            'outcome' => $countedTotal >= $thresholds['minCounted']
                ? SelectionRuleEvaluation::OUTCOME_PASS
                : SelectionRuleEvaluation::OUTCOME_FAIL,
            'detail' => ['counted' => $countedTotal, 'minimum' => $thresholds['minCounted']],
        ];

        $part03 = [
            'rule_id' => 'PART-03',
            'outcome' => $facts['withinCloseWindowCount'] >= $thresholds['minWithinClose']
                ? SelectionRuleEvaluation::OUTCOME_PASS
                : SelectionRuleEvaluation::OUTCOME_FAIL,
            'detail' => [
                'within_close_window' => $facts['withinCloseWindowCount'],
                'minimum' => $thresholds['minWithinClose'],
                'window' => [
                    'from' => $facts['windowStart']?->toDateString(),
                    'to' => $facts['windowEnd']?->toDateString(),
                ],
            ],
        ];

        $part04Over = $facts['inProvinceNationals']->count() - $facts['countedInProvince']->count();
        $part04 = [
            'rule_id' => 'PART-04',
            'outcome' => SelectionRuleEvaluation::OUTCOME_PASS,
            'detail' => [
                'in_province_shot' => $facts['inProvinceNationals']->count(),
                'counted' => $facts['countedInProvince']->count(),
                'cap' => $thresholds['inProvinceCap'],
                'sa_champs_geographic_exempt' => $thresholds['saChampsGeoExempt'],
                'discarded_by_cap' => $part04Over,
            ],
        ];

        $part05Over = $facts['outOfProvinceNationals']->count() - $facts['countedOutOfProvince']->count();
        $part05 = [
            'rule_id' => 'PART-05',
            'outcome' => SelectionRuleEvaluation::OUTCOME_PASS,
            'detail' => [
                'out_of_province_shot' => $facts['outOfProvinceNationals']->count(),
                'counted' => $facts['countedOutOfProvince']->count(),
                'cap' => $thresholds['outOfProvinceCap'],
                'discarded_by_cap' => $part05Over,
            ],
        ];

        $part06 = [
            'rule_id' => 'PART-06',
            'outcome' => SelectionRuleEvaluation::OUTCOME_BLOCKED,
            'detail' => [
                'reason' => 'matches_missing_sanctioning_body_field',
                'note' => 'Add a sanctioning_body column to matches (IPRF|PRS|other) and re-run.',
            ],
        ];

        $part07 = [
            'rule_id' => 'PART-07',
            'outcome' => SelectionRuleEvaluation::OUTCOME_PASS,
            'detail' => ['derived_from' => 'ELG-01'],
        ];

        $rows = [$part01, $part02, $part03, $part04, $part05, $part06, $part07];

        DB::transaction(function () use ($athlete, $rows, $policyVersion, $now) {
            foreach ($rows as $row) {
                SelectionRuleEvaluation::create([
                    'selection_athlete_id' => $athlete->id,
                    'rule_id' => $row['rule_id'],
                    'outcome' => $row['outcome'],
                    'detail' => $row['detail'],
                    'policy_version' => $policyVersion,
                    'evaluated_at' => $now,
                ]);
            }
        });
    }

    private function scoresInPeriod(SelectionAthlete $athlete)
    {
        $cycle = $athlete->cycle;

        return Score::query()
            ->with('match')
            ->where('user_id', $athlete->user_id)
            ->where('counts_for_season', true)
            ->where('status', 'valid')
            ->whereHas('match', fn ($q) => $q
                ->where('series', $cycle->series)
                ->whereBetween('match_date', [
                    $cycle->qualifying_period_start,
                    $cycle->qualifying_period_end,
                ])
                ->whereIn('series_level', ['provincial', 'national', 'international', 'final']));
    }

    private function matchDateInWindow(?MatchEvent $match, CarbonInterface $start, CarbonInterface $end): bool
    {
        if (! $match || ! $match->match_date) {
            return false;
        }
        $date = $match->match_date->copy()->startOfDay();

        return $date->betweenIncluded($start->copy()->startOfDay(), $end->copy()->endOfDay());
    }
}
