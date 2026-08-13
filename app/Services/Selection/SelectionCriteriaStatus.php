<?php

namespace App\Services\Selection;

use App\Models\SelectionAthlete;
use App\Models\SelectionRuleEvaluation;
use Illuminate\Support\Arr;

/**
 * Read-only presenter for an athlete's selection criteria.
 *
 * Produces a labelled, human-readable status for every ELG-* and PART-* rule
 * plus met/total progress tallies — computed from LIVE data every time and
 * persisting NOTHING. This is deliberately independent of the gate: cycles in
 * assume_qualified mode still auto-pass every rule for progression, while this
 * builder shows admins what the strict rules would actually say (e.g. "3 of 6
 * participation criteria met") without ever mutating the audit trail or the
 * athlete's state.
 *
 * Rule names come from the cycle's policy JSON so they track the governing
 * document verbatim; a hardcoded fallback covers policies that predate the
 * `text` field.
 */
class SelectionCriteriaStatus
{
    public const STATUS_MET = 'met';
    public const STATUS_NOT_MET = 'not_met';
    public const STATUS_REVIEW = 'review';

    public function __construct(private readonly RulesetResolver $resolver)
    {
    }

    /**
     * @return array{
     *     eligibility: list<array<string, mixed>>,
     *     participation: list<array<string, mixed>>,
     *     eligibility_met: int, eligibility_total: int,
     *     participation_met: int, participation_total: int,
     *     overall_met: int, overall_total: int, overall_pct: int
     * }
     */
    public function for(SelectionAthlete $athlete): array
    {
        $spec = $athlete->cycle?->activePolicy?->spec_json ?? [];

        $eligibility = $this->buildEligibility($athlete, $this->ruleNames(Arr::get($spec, 'eligibility.rules', []), $this->defaultElgNames()));
        $participation = $this->buildParticipation(
            $athlete,
            $this->ruleNames(Arr::get($spec, 'participation.rules', []), $this->defaultPartNames()),
            $this->ruleOrder(Arr::get($spec, 'participation.rules', []), array_keys($this->defaultPartNames())),
            $this->thresholds(Arr::get($spec, 'participation.thresholds', [])),
            $eligibility,
        );

        $elgMet = $this->countMet($eligibility);
        $partMet = $this->countMet($participation);
        $elgTotal = count($eligibility);
        $partTotal = count($participation);
        $overallMet = $elgMet + $partMet;
        $overallTotal = $elgTotal + $partTotal;

        return [
            'eligibility' => $eligibility,
            'participation' => $participation,
            'eligibility_met' => $elgMet,
            'eligibility_total' => $elgTotal,
            'participation_met' => $partMet,
            'participation_total' => $partTotal,
            'overall_met' => $overallMet,
            'overall_total' => $overallTotal,
            'overall_pct' => $overallTotal > 0 ? (int) round($overallMet / $overallTotal * 100) : 0,
        ];
    }

    /**
     * @param  array<string, string>  $names
     * @return list<array<string, mixed>>
     */
    private function buildEligibility(SelectionAthlete $athlete, array $names): array
    {
        $results = $this->resolver->strictEligibilityForCycle($athlete->cycle)->assess($athlete);

        $rows = [];
        foreach ($results as $code => $result) {
            $rows[] = [
                'code' => $code,
                'name' => $names[$code] ?? $code,
                'status' => $this->outcomeToStatus($result['outcome'] ?? SelectionRuleEvaluation::OUTCOME_MANUAL),
                'detail' => $result['detail'] ?? [],
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $names
     * @param  list<string>  $order
     * @param  array{minProvincial:int,min2d:int,minOutOfHome:int,requireSaChamps:bool}  $t
     * @param  list<array<string, mixed>>  $eligibility
     * @return list<array<string, mixed>>
     */
    private function buildParticipation(SelectionAthlete $athlete, array $names, array $order, array $t, array $eligibility): array
    {
        $snap = $athlete->participationSnapshot;

        $prov = (int) ($snap->provincial_1d_count ?? 0);
        $nat = (int) ($snap->national_2d_count ?? 0);
        $intl = (int) ($snap->international_2d_count ?? 0);
        $outOfHome = (int) ($snap->out_of_home_province_2d_count ?? 0);
        $saChamps = (bool) ($snap->sa_champs_shot ?? false);
        $twoDay = $nat + $intl;
        $anyParticipation = ($prov + $twoDay) > 0 || $saChamps;

        $elg01 = collect($eligibility)->firstWhere('code', 'ELG-01')['status'] ?? self::STATUS_REVIEW;

        $defs = [
            'PART-01' => ['met' => $anyParticipation, 'current' => $prov + $twoDay, 'required' => 1],
            'PART-02' => ['met' => $prov >= $t['minProvincial'], 'current' => $prov, 'required' => $t['minProvincial']],
            'PART-03' => ['met' => $twoDay >= $t['min2d'], 'current' => $twoDay, 'required' => $t['min2d']],
            'PART-04' => ['met' => $outOfHome >= $t['minOutOfHome'], 'current' => $outOfHome, 'required' => $t['minOutOfHome']],
            'PART-05' => ['met' => (! $t['requireSaChamps'] || $saChamps), 'boolean' => true, 'value' => $saChamps],
            'PART-06' => ['status' => $elg01],
        ];

        $rows = [];
        foreach ($order as $code) {
            $def = $defs[$code] ?? ['status' => self::STATUS_REVIEW];

            if (isset($def['status'])) {
                $status = $def['status'];
            } elseif ($snap === null) {
                $status = self::STATUS_REVIEW;
            } else {
                $status = $def['met'] ? self::STATUS_MET : self::STATUS_NOT_MET;
            }

            $rows[] = [
                'code' => $code,
                'name' => $names[$code] ?? $code,
                'status' => $status,
                'current' => $def['current'] ?? null,
                'required' => $def['required'] ?? null,
                'boolean' => $def['boolean'] ?? false,
                'value' => $def['value'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function countMet(array $rows): int
    {
        return collect($rows)->where('status', self::STATUS_MET)->count();
    }

    private function outcomeToStatus(string $outcome): string
    {
        return match ($outcome) {
            SelectionRuleEvaluation::OUTCOME_PASS => self::STATUS_MET,
            SelectionRuleEvaluation::OUTCOME_FAIL => self::STATUS_NOT_MET,
            default => self::STATUS_REVIEW,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @param  array<string, string>  $fallback
     * @return array<string, string>
     */
    private function ruleNames(array $rules, array $fallback): array
    {
        $names = $fallback;
        foreach ($rules as $rule) {
            $id = $rule['id'] ?? null;
            $text = $rule['text'] ?? null;
            if ($id !== null && $text !== null) {
                $names[$id] = $text;
            }
        }

        return $names;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @param  list<string>  $fallback
     * @return list<string>
     */
    private function ruleOrder(array $rules, array $fallback): array
    {
        $order = collect($rules)->pluck('id')->filter()->values()->all();

        return $order !== [] ? $order : $fallback;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array{minProvincial:int,min2d:int,minOutOfHome:int,requireSaChamps:bool}
     */
    private function thresholds(array $spec): array
    {
        return [
            'minProvincial' => (int) ($spec['min_provincial_1d'] ?? 3),
            'min2d' => (int) ($spec['min_2d_nat_or_intl'] ?? 2),
            'minOutOfHome' => (int) ($spec['min_out_of_home_2d'] ?? 1),
            'requireSaChamps' => (bool) ($spec['must_include_sa_champs'] ?? true),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function defaultElgNames(): array
    {
        return [
            'ELG-01' => 'Paid-up SAPRF member in good standing at the start of the selection period',
            'ELG-02' => 'South African citizen',
            'ELG-03' => 'Resides in their affiliated province or is a member of a SAPRF-recognised club',
            'ELG-04' => 'Resides in South Africa (exception: SA citizens abroad who shot the SA Champs)',
            'ELG-05' => '"Eligibility to Compete" form completed and received by ExCo',
            'ELG-06' => 'Declaration on file',
            'ELG-07' => 'Declaration on file',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function defaultPartNames(): array
    {
        return [
            'PART-01' => 'Competed in the PR22 national/international series',
            'PART-02' => 'At least 3 provincial 1-day matches',
            'PART-03' => 'At least 2 national/international 2-day matches',
            'PART-04' => 'At least 1 two-day match out of the home province',
            'PART-05' => 'Shot the mandatory SA Championship match',
            'PART-06' => 'Full SAPRF member before the selection period',
        ];
    }
}
