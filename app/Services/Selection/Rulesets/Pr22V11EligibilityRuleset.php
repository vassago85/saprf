<?php

namespace App\Services\Selection\Rulesets;

use App\Models\SelectionAthlete;
use App\Models\SelectionCycle;
use App\Models\SelectionRuleEvaluation;
use App\Models\User;
use App\Services\MembershipValidationService;
use Illuminate\Support\Facades\DB;

/**
 * SAPRF PR22 v1.1 eligibility ruleset (5 ELG rules per the 12 Dec 2025
 * document governing the 2027 IPRF WCH cycle).
 *
 * Key difference vs v1.4:
 *   - ELG-03 is an OR (reside in affiliated province OR member of SAPRF-
 *     recognised club) instead of v1.4's implicit AND. This is a real
 *     relaxation — an out-of-province member of a recognised club now
 *     passes eligibility.
 *   - ELG-04 exception routes through the 2026 SA Championship (not the
 *     Centrefire Championship named in v1.4).
 */
class Pr22V11EligibilityRuleset implements EligibilityRuleset
{
    public function __construct(private readonly MembershipValidationService $memberValidation)
    {
    }

    public function evaluate(SelectionAthlete $athlete): array
    {
        $cycle = $athlete->cycle;
        $user = $athlete->user;
        if (! $cycle || ! $user) {
            return [];
        }

        $policyVersion = $cycle->activePolicy?->version ?? 'unknown';

        $results = [
            'ELG-01' => $this->evaluateElg01($user, $cycle),
            'ELG-02' => $this->evaluateElg02($user),
            'ELG-03' => $this->evaluateElg03($user),
            'ELG-04' => $this->evaluateElg04($user, $cycle),
            'ELG-05' => $this->evaluateElg05($athlete),
        ];

        $this->persist($athlete, $results, $policyVersion);

        return $results;
    }

    private function evaluateElg01(User $user, SelectionCycle $cycle): array
    {
        $membership = $user->membership;
        $valid = $this->memberValidation->isMembershipValidOnDate($membership, $cycle->qualifying_period_start);

        return [
            'outcome' => $valid ? SelectionRuleEvaluation::OUTCOME_PASS : SelectionRuleEvaluation::OUTCOME_FAIL,
            'detail' => [
                'as_of' => $cycle->qualifying_period_start->toDateString(),
                'membership_id' => $membership?->id,
                'membership_type' => $membership?->membership_type,
                'payment_status' => $membership?->payment_status,
                'expiry_date' => optional($membership?->expiry_date)?->toDateString(),
            ],
        ];
    }

    private function evaluateElg02(User $user): array
    {
        if ($user->sa_citizen === null) {
            return [
                'outcome' => SelectionRuleEvaluation::OUTCOME_MANUAL,
                'detail' => ['reason' => 'citizenship_not_recorded'],
            ];
        }

        return [
            'outcome' => $user->sa_citizen
                ? SelectionRuleEvaluation::OUTCOME_PASS
                : SelectionRuleEvaluation::OUTCOME_FAIL,
            'detail' => ['sa_citizen' => (bool) $user->sa_citizen],
        ];
    }

    /**
     * ELG-03 (v1.1): reside in the affiliated province OR be a member of a
     * SAPRF-recognised club. Either branch passes the whole rule; both
     * branches failing at once fails it. Missing data on both branches
     * degrades to MANUAL for admin review.
     */
    private function evaluateElg03(User $user): array
    {
        $residenceProvinceId = $user->province_id;
        $club = $user->club;
        $clubProvinceId = $club?->province_id;

        $branchProvinceKnown = $residenceProvinceId !== null && $club !== null;
        $branchProvincePass = $branchProvinceKnown && $clubProvinceId === $residenceProvinceId;

        $branchClubKnown = $club !== null;
        $branchClubPass = $branchClubKnown && (bool) $club->saprf_recognised;

        if ($branchProvincePass || $branchClubPass) {
            return [
                'outcome' => SelectionRuleEvaluation::OUTCOME_PASS,
                'detail' => [
                    'branch_province_pass' => $branchProvincePass,
                    'branch_club_pass' => $branchClubPass,
                    'residence_province_id' => $residenceProvinceId,
                    'club_id' => $club?->id,
                    'club_province_id' => $clubProvinceId,
                    'club_saprf_recognised' => $club ? (bool) $club->saprf_recognised : null,
                ],
            ];
        }

        if (! $branchProvinceKnown && ! $branchClubKnown) {
            return [
                'outcome' => SelectionRuleEvaluation::OUTCOME_MANUAL,
                'detail' => ['reason' => 'missing_province_and_club_on_user'],
            ];
        }

        return [
            'outcome' => SelectionRuleEvaluation::OUTCOME_FAIL,
            'detail' => [
                'branch_province_pass' => false,
                'branch_club_pass' => false,
                'residence_province_id' => $residenceProvinceId,
                'club_id' => $club?->id,
                'club_province_id' => $clubProvinceId,
                'club_saprf_recognised' => $club ? (bool) $club->saprf_recognised : null,
            ],
        ];
    }

    /**
     * ELG-04 (v1.1): reside in SA. Exception: SA citizens abroad who shot
     * the 2026 SAPRF PR22 SA Championship. In practice this is the 'final'
     * series_level match in the qualifying period for this cycle.
     */
    private function evaluateElg04(User $user, SelectionCycle $cycle): array
    {
        if ($user->country_of_residence === null) {
            return [
                'outcome' => SelectionRuleEvaluation::OUTCOME_MANUAL,
                'detail' => ['reason' => 'residence_country_not_recorded'],
            ];
        }

        if ($user->country_of_residence === 'ZA') {
            return [
                'outcome' => SelectionRuleEvaluation::OUTCOME_PASS,
                'detail' => ['country_of_residence' => 'ZA', 'exception_applied' => false],
            ];
        }

        if ($user->sa_citizen !== true) {
            return [
                'outcome' => SelectionRuleEvaluation::OUTCOME_FAIL,
                'detail' => [
                    'country_of_residence' => $user->country_of_residence,
                    'sa_citizen' => (bool) $user->sa_citizen,
                    'reason' => 'non_resident_non_citizen',
                ],
            ];
        }

        $shotChamps = $user->scores()
            ->where('status', 'valid')
            ->whereHas('match', fn ($q) => $q
                ->where('series', $cycle->series)
                ->whereBetween('match_date', [
                    $cycle->qualifying_period_start,
                    $cycle->qualifying_period_end,
                ])
                ->where('series_level', 'final'))
            ->exists();

        return [
            'outcome' => $shotChamps
                ? SelectionRuleEvaluation::OUTCOME_PASS
                : SelectionRuleEvaluation::OUTCOME_FAIL,
            'detail' => [
                'country_of_residence' => $user->country_of_residence,
                'sa_citizen' => true,
                'exception_applied' => $shotChamps,
                'shot_sa_champs_in_period' => $shotChamps,
            ],
        ];
    }

    private function evaluateElg05(SelectionAthlete $athlete): array
    {
        $declaration = $athlete->declaration()->first();
        if (! $declaration) {
            return [
                'outcome' => SelectionRuleEvaluation::OUTCOME_MANUAL,
                'detail' => ['reason' => 'no_declaration_on_file'],
            ];
        }

        $formData = $declaration->form_data ?? [];
        $received = (bool) ($formData['eligibility_to_compete_received'] ?? false);

        return [
            'outcome' => $received
                ? SelectionRuleEvaluation::OUTCOME_PASS
                : SelectionRuleEvaluation::OUTCOME_MANUAL,
            'detail' => [
                'declaration_id' => $declaration->id,
                'declaration_status' => $declaration->status,
                'eligibility_to_compete_received' => $received,
            ],
        ];
    }

    /**
     * @param  array<string, array{outcome: string, detail: array<string, mixed>}>  $results
     */
    private function persist(SelectionAthlete $athlete, array $results, string $policyVersion): void
    {
        DB::transaction(function () use ($athlete, $results, $policyVersion) {
            $now = now();
            foreach ($results as $ruleId => $result) {
                SelectionRuleEvaluation::create([
                    'selection_athlete_id' => $athlete->id,
                    'rule_id' => $ruleId,
                    'outcome' => $result['outcome'],
                    'detail' => $result['detail'],
                    'policy_version' => $policyVersion,
                    'evaluated_at' => $now,
                ]);
            }
        });
    }
}
