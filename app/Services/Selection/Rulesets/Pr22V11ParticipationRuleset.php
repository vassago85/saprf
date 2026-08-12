<?php

namespace App\Services\Selection\Rulesets;

use App\Models\Score;
use App\Models\SelectionAthlete;
use App\Models\SelectionParticipationSnapshot;
use App\Models\SelectionRuleEvaluation;
use Illuminate\Support\Facades\DB;

/**
 * SAPRF PR22 v1.1 participation ruleset — six discrete counts with hard
 * minimums (no capped-counting logic; v1.1 uses raw counts and asks that at
 * least 1 of the 2-day matches is out-of-home). Provincial 1-day matches
 * count (they're the 30% component of the scoring formula), unlike v1.4
 * which excluded them from participation altogether.
 */
class Pr22V11ParticipationRuleset implements ParticipationRuleset
{
    public function evaluate(SelectionAthlete $athlete): SelectionParticipationSnapshot
    {
        $cycle = $athlete->cycle;
        $user = $athlete->user;
        $policy = $cycle?->activePolicy;
        $policyVersion = $policy?->version ?? 'unknown';
        $spec = $policy?->spec_json['participation']['thresholds'] ?? [];

        $minProvincial = (int) ($spec['min_provincial_1d'] ?? 3);
        $min2d = (int) ($spec['min_2d_nat_or_intl'] ?? 2);
        $minOutOfHome = (int) ($spec['min_out_of_home_2d'] ?? 1);
        $requireSaChamps = (bool) ($spec['must_include_sa_champs'] ?? true);

        $homeProvinceId = $user?->province_id;
        $scores = $this->scoresInPeriod($athlete)->get();

        $provincial1d = $scores->filter(fn (Score $s) => $s->match?->series_level === 'provincial');
        $national2d = $scores->filter(fn (Score $s) => $s->match?->series_level === 'national');
        $international2d = $scores->filter(fn (Score $s) => $s->match?->series_level === 'international');
        $saChampsScores = $scores->filter(fn (Score $s) => $s->match?->series_level === 'final');

        $twoDayCount = $national2d->count() + $international2d->count();

        // Out-of-home for 2-day matches: internationals always count as
        // out-of-home; nationals count only when the match's province differs
        // from the athlete's home province.
        $outOfHome2d = $international2d->count() + $national2d
            ->filter(fn (Score $s) => $homeProvinceId
                && $s->match?->province_id !== null
                && $s->match->province_id !== $homeProvinceId)
            ->count();

        $saChampsShot = $saChampsScores->isNotEmpty();
        $anyPr22 = $scores->isNotEmpty();

        $countedIds = $provincial1d->pluck('id')
            ->concat($national2d->pluck('id'))
            ->concat($international2d->pluck('id'))
            ->concat($saChampsScores->pluck('id'))
            ->all();

        $snapshot = SelectionParticipationSnapshot::updateOrCreate(
            ['selection_athlete_id' => $athlete->id],
            [
                'provincial_1d_count' => $provincial1d->count(),
                'national_2d_count' => $national2d->count(),
                'international_2d_count' => $international2d->count(),
                'out_of_home_province_2d_count' => $outOfHome2d,
                'sa_champs_shot' => $saChampsShot,
                'counted_score_ids' => $countedIds,
                'computed_at' => now(),
                'computed_against_policy_id' => $policy?->id,
            ],
        );

        $now = now();
        $rows = [
            [
                'rule_id' => 'PART-01',
                'outcome' => $anyPr22
                    ? SelectionRuleEvaluation::OUTCOME_PASS
                    : SelectionRuleEvaluation::OUTCOME_FAIL,
                'detail' => ['total_scores' => $scores->count()],
            ],
            [
                'rule_id' => 'PART-02',
                'outcome' => $provincial1d->count() >= $minProvincial
                    ? SelectionRuleEvaluation::OUTCOME_PASS
                    : SelectionRuleEvaluation::OUTCOME_FAIL,
                'detail' => ['provincial_1d_shot' => $provincial1d->count(), 'minimum' => $minProvincial],
            ],
            [
                'rule_id' => 'PART-03',
                'outcome' => $twoDayCount >= $min2d
                    ? SelectionRuleEvaluation::OUTCOME_PASS
                    : SelectionRuleEvaluation::OUTCOME_FAIL,
                'detail' => [
                    'two_day_shot' => $twoDayCount,
                    'national_2d' => $national2d->count(),
                    'international_2d' => $international2d->count(),
                    'minimum' => $min2d,
                ],
            ],
            [
                'rule_id' => 'PART-04',
                'outcome' => $outOfHome2d >= $minOutOfHome
                    ? SelectionRuleEvaluation::OUTCOME_PASS
                    : SelectionRuleEvaluation::OUTCOME_FAIL,
                'detail' => [
                    'out_of_home_2d_shot' => $outOfHome2d,
                    'minimum' => $minOutOfHome,
                    'home_province_id' => $homeProvinceId,
                ],
            ],
            [
                'rule_id' => 'PART-05',
                'outcome' => ($requireSaChamps === false || $saChampsShot)
                    ? SelectionRuleEvaluation::OUTCOME_PASS
                    : SelectionRuleEvaluation::OUTCOME_FAIL,
                'detail' => ['sa_champs_shot' => $saChampsShot, 'required' => $requireSaChamps],
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
}
