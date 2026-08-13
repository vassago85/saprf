<?php

namespace App\Services\Selection\Rulesets;

use App\Models\Score;
use App\Models\SelectionAthlete;
use App\Models\SelectionParticipationSnapshot;
use App\Models\SelectionRuleEvaluation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SAPRF PR22 v1.1 participation ruleset — six discrete counts with hard
 * minimums (no capped-counting logic; v1.1 uses raw counts and asks that at
 * least 1 of the 2-day matches is out-of-home). Provincial 1-day matches
 * count (they're the 30% component of the scoring formula), unlike v1.4
 * which excluded them from participation altogether.
 *
 * Counting is factored into collectFacts()/computeSnapshotPayload() so
 * AutoPassParticipationRuleset can reuse the numbers for informational
 * display without also emitting FAIL outcomes.
 */
class Pr22V11ParticipationRuleset implements ParticipationRuleset
{
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
     * Bucketed scores + policy thresholds. Shared by evaluate() (which needs
     * the counts for both the snapshot and the PART-* rule details) and
     * computeSnapshotPayload() (which only needs the snapshot shape).
     *
     * @return array{
     *     scores: Collection<int, Score>,
     *     provincial1d: Collection<int, Score>,
     *     national2d: Collection<int, Score>,
     *     international2d: Collection<int, Score>,
     *     saChampsScores: Collection<int, Score>,
     *     outOfHome2d: int,
     *     homeProvinceId: int|null,
     *     thresholds: array{minProvincial:int,min2d:int,minOutOfHome:int,requireSaChamps:bool}
     * }
     */
    private function collectFacts(SelectionAthlete $athlete): array
    {
        $cycle = $athlete->cycle;
        $user = $athlete->user;
        $policy = $cycle?->activePolicy;
        $spec = $policy?->spec_json['participation']['thresholds'] ?? [];

        $homeProvinceId = $user?->province_id;
        $scores = $this->scoresInPeriod($athlete)->get();

        $provincial1d = $scores->filter(fn (Score $s) => $s->match?->series_level === 'provincial');
        $national2d = $scores->filter(fn (Score $s) => $s->match?->series_level === 'national');
        $international2d = $scores->filter(fn (Score $s) => $s->match?->series_level === 'international');
        $saChampsScores = $scores->filter(fn (Score $s) => $s->match?->series_level === 'final');

        // Internationals always count as out-of-home; nationals only when the
        // match's province differs from the athlete's home province.
        $outOfHome2d = $international2d->count() + $national2d
            ->filter(fn (Score $s) => $homeProvinceId
                && $s->match?->province_id !== null
                && $s->match->province_id !== $homeProvinceId)
            ->count();

        return [
            'scores' => $scores,
            'provincial1d' => $provincial1d,
            'national2d' => $national2d,
            'international2d' => $international2d,
            'saChampsScores' => $saChampsScores,
            'outOfHome2d' => $outOfHome2d,
            'homeProvinceId' => $homeProvinceId,
            'thresholds' => [
                'minProvincial' => (int) ($spec['min_provincial_1d'] ?? 3),
                'min2d' => (int) ($spec['min_2d_nat_or_intl'] ?? 2),
                'minOutOfHome' => (int) ($spec['min_out_of_home_2d'] ?? 1),
                'requireSaChamps' => (bool) ($spec['must_include_sa_champs'] ?? true),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    private function factsToSnapshotPayload(array $facts, SelectionAthlete $athlete): array
    {
        $countedIds = $facts['provincial1d']->pluck('id')
            ->concat($facts['national2d']->pluck('id'))
            ->concat($facts['international2d']->pluck('id'))
            ->concat($facts['saChampsScores']->pluck('id'))
            ->all();

        return [
            'provincial_1d_count' => $facts['provincial1d']->count(),
            'national_2d_count' => $facts['national2d']->count(),
            'international_2d_count' => $facts['international2d']->count(),
            'out_of_home_province_2d_count' => $facts['outOfHome2d'],
            'sa_champs_shot' => $facts['saChampsScores']->isNotEmpty(),
            'counted_score_ids' => $countedIds,
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
        $anyPr22 = $facts['scores']->isNotEmpty();
        $twoDayCount = $facts['national2d']->count() + $facts['international2d']->count();
        $saChampsShot = $facts['saChampsScores']->isNotEmpty();

        $rows = [
            [
                'rule_id' => 'PART-01',
                'outcome' => $anyPr22
                    ? SelectionRuleEvaluation::OUTCOME_PASS
                    : SelectionRuleEvaluation::OUTCOME_FAIL,
                'detail' => ['total_scores' => $facts['scores']->count()],
            ],
            [
                'rule_id' => 'PART-02',
                'outcome' => $facts['provincial1d']->count() >= $thresholds['minProvincial']
                    ? SelectionRuleEvaluation::OUTCOME_PASS
                    : SelectionRuleEvaluation::OUTCOME_FAIL,
                'detail' => [
                    'provincial_1d_shot' => $facts['provincial1d']->count(),
                    'minimum' => $thresholds['minProvincial'],
                ],
            ],
            [
                'rule_id' => 'PART-03',
                'outcome' => $twoDayCount >= $thresholds['min2d']
                    ? SelectionRuleEvaluation::OUTCOME_PASS
                    : SelectionRuleEvaluation::OUTCOME_FAIL,
                'detail' => [
                    'two_day_shot' => $twoDayCount,
                    'national_2d' => $facts['national2d']->count(),
                    'international_2d' => $facts['international2d']->count(),
                    'minimum' => $thresholds['min2d'],
                ],
            ],
            [
                'rule_id' => 'PART-04',
                'outcome' => $facts['outOfHome2d'] >= $thresholds['minOutOfHome']
                    ? SelectionRuleEvaluation::OUTCOME_PASS
                    : SelectionRuleEvaluation::OUTCOME_FAIL,
                'detail' => [
                    'out_of_home_2d_shot' => $facts['outOfHome2d'],
                    'minimum' => $thresholds['minOutOfHome'],
                    'home_province_id' => $facts['homeProvinceId'],
                ],
            ],
            [
                'rule_id' => 'PART-05',
                'outcome' => ($thresholds['requireSaChamps'] === false || $saChampsShot)
                    ? SelectionRuleEvaluation::OUTCOME_PASS
                    : SelectionRuleEvaluation::OUTCOME_FAIL,
                'detail' => [
                    'sa_champs_shot' => $saChampsShot,
                    'required' => $thresholds['requireSaChamps'],
                ],
            ],
            [
                'rule_id' => 'PART-06',
                'outcome' => SelectionRuleEvaluation::OUTCOME_PASS,
                'detail' => ['derived_from' => 'ELG-01', 'note' => 'membership check upstream'],
            ],
        ];

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
}
