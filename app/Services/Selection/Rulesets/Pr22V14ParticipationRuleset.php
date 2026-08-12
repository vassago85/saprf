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
 * SAPRF PR22 v1.4 participation ruleset — capped 2-day match counting with
 * geographical caps, SA-Champs geographical exemption, "within 3 months of
 * close" recency requirement, and a fail-open sanctioning check that will
 * light up once matches carry a sanctioning_body field.
 */
class Pr22V14ParticipationRuleset implements ParticipationRuleset
{
    private const TWO_DAY_LEVELS = ['national', 'international', 'final'];

    public function evaluate(SelectionAthlete $athlete): SelectionParticipationSnapshot
    {
        $cycle = $athlete->cycle;
        $user = $athlete->user;
        $policy = $cycle?->activePolicy;
        $policyVersion = $policy?->version ?? 'unknown';
        $spec = $policy?->spec_json['participation']['thresholds'] ?? [];

        $minCounted = (int) ($spec['min_counted_2d_matches'] ?? 4);
        $inProvinceCap = (int) ($spec['in_province_2d_national_cap'] ?? 1);
        $outOfProvinceCap = (int) ($spec['out_of_province_2d_national_cap'] ?? 3);
        $requireSaChamps = (bool) ($spec['must_include_sa_champs'] ?? true);
        $saChampsGeoExempt = (bool) ($spec['sa_champs_geographic_exempt'] ?? true);
        $minWithinCloseWindow = (int) ($spec['min_within_3_months_of_close'] ?? 1);

        $windowStart = $cycle?->qualifying_period_end?->copy()->subMonthsNoOverflow(3);
        $windowEnd = $cycle?->qualifying_period_end;

        $scores = $this->scoresInPeriod($athlete)->get();
        $homeProvinceId = $user?->province_id;

        [$saChampsScores, $twoDayScores] = $scores
            ->filter(fn (Score $s) => in_array($s->match?->series_level, self::TWO_DAY_LEVELS, true))
            ->partition(fn (Score $s) => $s->match?->series_level === 'final');

        $saChampsShot = $saChampsScores->isNotEmpty();

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
        if ($saChampsShot) {
            $countedSet = $countedSet->concat($saChampsScores);
        }
        $countedSet = $countedSet->concat($countedInProvince)->concat($countedOutOfProvince)->concat($internationalScores);

        $countedIds = $countedSet->pluck('id')->all();
        $countedTotal = $countedSet->count();

        $withinCloseWindowCount = 0;
        if ($windowStart && $windowEnd) {
            $withinCloseWindowCount = $countedSet
                ->filter(fn (Score $s) => $this->matchDateInWindow($s->match, $windowStart, $windowEnd))
                ->count();
        }

        $snapshot = SelectionParticipationSnapshot::updateOrCreate(
            ['selection_athlete_id' => $athlete->id],
            [
                'provincial_1d_count' => $scores->filter(fn (Score $s) => $s->match?->series_level === 'provincial')->count(),
                'national_2d_count' => $countedInProvince->count() + $countedOutOfProvince->count(),
                'international_2d_count' => $internationalScores->count(),
                'out_of_home_province_2d_count' => $countedOutOfProvince->count(),
                'sa_champs_shot' => $saChampsShot,
                'counted_score_ids' => $countedIds,
                'computed_at' => now(),
                'computed_against_policy_id' => $policy?->id,
            ],
        );

        $this->persistRuleEvaluations(
            $athlete,
            policyVersion: $policyVersion,
            saChampsShot: $saChampsShot,
            requireSaChamps: $requireSaChamps,
            saChampsGeoExempt: $saChampsGeoExempt,
            countedTotal: $countedTotal,
            minCounted: $minCounted,
            withinCloseWindowCount: $withinCloseWindowCount,
            minWithinClose: $minWithinCloseWindow,
            inProvinceNationals: $inProvinceNationals,
            countedInProvince: $countedInProvince,
            inProvinceCap: $inProvinceCap,
            outOfProvinceNationals: $outOfProvinceNationals,
            countedOutOfProvince: $countedOutOfProvince,
            outOfProvinceCap: $outOfProvinceCap,
            windowStart: $windowStart,
            windowEnd: $windowEnd,
        );

        return $snapshot;
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

    /**
     * @param  Collection<int, Score>  $inProvinceNationals
     * @param  Collection<int, Score>  $countedInProvince
     * @param  Collection<int, Score>  $outOfProvinceNationals
     * @param  Collection<int, Score>  $countedOutOfProvince
     */
    private function persistRuleEvaluations(
        SelectionAthlete $athlete,
        string $policyVersion,
        bool $saChampsShot,
        bool $requireSaChamps,
        bool $saChampsGeoExempt,
        int $countedTotal,
        int $minCounted,
        int $withinCloseWindowCount,
        int $minWithinClose,
        Collection $inProvinceNationals,
        Collection $countedInProvince,
        int $inProvinceCap,
        Collection $outOfProvinceNationals,
        Collection $countedOutOfProvince,
        int $outOfProvinceCap,
        ?CarbonInterface $windowStart,
        ?CarbonInterface $windowEnd,
    ): void {
        $now = now();

        $part01 = [
            'rule_id' => 'PART-01',
            'outcome' => $requireSaChamps === false || $saChampsShot
                ? SelectionRuleEvaluation::OUTCOME_PASS
                : SelectionRuleEvaluation::OUTCOME_FAIL,
            'detail' => ['sa_champs_shot' => $saChampsShot, 'sa_champs_required' => $requireSaChamps],
        ];

        $part02 = [
            'rule_id' => 'PART-02',
            'outcome' => $countedTotal >= $minCounted
                ? SelectionRuleEvaluation::OUTCOME_PASS
                : SelectionRuleEvaluation::OUTCOME_FAIL,
            'detail' => ['counted' => $countedTotal, 'minimum' => $minCounted],
        ];

        $part03 = [
            'rule_id' => 'PART-03',
            'outcome' => $withinCloseWindowCount >= $minWithinClose
                ? SelectionRuleEvaluation::OUTCOME_PASS
                : SelectionRuleEvaluation::OUTCOME_FAIL,
            'detail' => [
                'within_close_window' => $withinCloseWindowCount,
                'minimum' => $minWithinClose,
                'window' => [
                    'from' => $windowStart?->toDateString(),
                    'to' => $windowEnd?->toDateString(),
                ],
            ],
        ];

        $part04Over = $inProvinceNationals->count() - $countedInProvince->count();
        $part04 = [
            'rule_id' => 'PART-04',
            'outcome' => SelectionRuleEvaluation::OUTCOME_PASS,
            'detail' => [
                'in_province_shot' => $inProvinceNationals->count(),
                'counted' => $countedInProvince->count(),
                'cap' => $inProvinceCap,
                'sa_champs_geographic_exempt' => $saChampsGeoExempt,
                'discarded_by_cap' => $part04Over,
            ],
        ];

        $part05Over = $outOfProvinceNationals->count() - $countedOutOfProvince->count();
        $part05 = [
            'rule_id' => 'PART-05',
            'outcome' => SelectionRuleEvaluation::OUTCOME_PASS,
            'detail' => [
                'out_of_province_shot' => $outOfProvinceNationals->count(),
                'counted' => $countedOutOfProvince->count(),
                'cap' => $outOfProvinceCap,
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
}
