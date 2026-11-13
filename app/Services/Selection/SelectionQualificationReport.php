<?php

namespace App\Services\Selection;

use App\Models\Score;
use App\Models\SelectionAthlete;
use App\Models\SelectionCycle;
use Illuminate\Support\Collection;

/**
 * Selector working paper: athletes who have met every criterion, plus those
 * whose only gap is the Eligibility to Compete declaration. Ranked inside
 * each claimed division by the policy weighted score, with the matches that
 * produced that score attached as evidence.
 */
class SelectionQualificationReport
{
    public function __construct(
        private readonly SelectionCriteriaStatus $criteria,
        private readonly DeclarationReminderService $reminders,
    ) {}

    /**
     * @return array{
     *     threshold: float,
     *     summary: array{qualified: int, declaration_outstanding: int, divisions: int},
     *     divisions: list<array{name: string, shooters: list<array<string, mixed>>}>
     * }
     */
    public function build(SelectionCycle $cycle): array
    {
        $cycle->loadMissing('activePolicy');
        $declarationCode = $this->reminders->declarationRuleId($cycle);
        $threshold = (float) ($cycle->activePolicy?->spec_json['scoring']['formula']['protea_threshold_pct'] ?? 85);

        $athletes = SelectionAthlete::forCycle($cycle->id)
            ->with([
                'user.membership',
                'user.club',
                'user.province',
                'declaration',
                'participationSnapshot',
                'claimedDivision',
                'cycle.activePolicy',
            ])
            ->get();

        $included = $athletes->filter(function (SelectionAthlete $athlete) use ($declarationCode) {
            $status = $this->criteria->for($athlete);
            $athlete->setAttribute('report_criteria', $status);

            $complete = $status['overall_total'] > 0
                && $status['overall_met'] === $status['overall_total'];

            return $complete || $this->reminders->onlyDeclarationOutstanding($status, $declarationCode);
        })->values();

        $scores = $this->scoresFor($cycle, $included);
        $components = $this->components($cycle);

        $rows = $included->map(function (SelectionAthlete $athlete) use ($scores, $components, $declarationCode) {
            $status = $athlete->getAttribute('report_criteria');
            $complete = $status['overall_met'] === $status['overall_total'];
            $scored = $this->scoreAthlete($scores->get($athlete->user_id, collect()), $components);

            return [
                'division_id' => $athlete->claimed_division_id,
                'division_order' => $athlete->claimedDivision?->display_order ?? 9999,
                'division_name' => $athlete->claimedDivision?->name ?? 'Division not claimed',
                'name' => $athlete->user?->name ?? 'Unknown shooter',
                'email' => $athlete->user?->email,
                'club' => $athlete->user?->club?->name,
                'province' => $athlete->user?->province?->abbreviation,
                'saprf_number' => $athlete->user?->membership?->saprf_number,
                'status' => $complete ? 'qualified' : 'declaration_outstanding',
                'criteria_met' => $status['overall_met'],
                'criteria_total' => $status['overall_total'],
                'criteria' => $this->criterionLines($status, $athlete, $declarationCode),
                'raw_weighted_pct' => $scored['raw_weighted_pct'],
                'components' => $scored['components'],
            ];
        });

        $divisions = $this->rankByDivision($rows, $threshold);

        return [
            'threshold' => $threshold,
            'summary' => [
                'qualified' => $rows->where('status', 'qualified')->count(),
                'declaration_outstanding' => $rows->where('status', 'declaration_outstanding')->count(),
                'divisions' => count($divisions),
            ],
            'divisions' => $divisions,
        ];
    }

    /**
     * @param  Collection<int, SelectionAthlete>  $athletes
     * @return Collection<int|string, Collection<int, Score>>
     */
    private function scoresFor(SelectionCycle $cycle, Collection $athletes): Collection
    {
        $userIds = $athletes->pluck('user_id')->filter()->all();
        if ($userIds === []) {
            return collect();
        }

        return Score::query()
            ->with('match:id,name,match_date,series_level,series')
            ->whereIn('user_id', $userIds)
            ->where('status', 'valid')
            ->where('counts_for_season', true)
            ->whereHas('match', fn ($q) => $q
                ->where('series', $cycle->series)
                ->whereBetween('match_date', [
                    $cycle->qualifying_period_start,
                    $cycle->qualifying_period_end,
                ])
                ->whereIn('series_level', ['provincial', 'national', 'international', 'final']))
            ->get()
            ->groupBy('user_id');
    }

    /**
     * @return list<array{source: string, label: string, levels: list<string>, take: int, weight: float, cap: float}>
     */
    private function components(SelectionCycle $cycle): array
    {
        $configured = $cycle->activePolicy?->spec_json['scoring']['formula']['components'] ?? [];
        if ($configured === []) {
            $configured = [
                ['source' => 'provincial_1d', 'per_match_weight_pct' => 10, 'cap_pct' => 30],
                ['source' => 'national_or_international_2d', 'per_match_weight_pct' => 20, 'cap_pct' => 40],
                ['source' => 'sa_champs', 'per_match_weight_pct' => 30, 'cap_pct' => 30],
            ];
        }

        $known = [
            'provincial_1d' => ['levels' => ['provincial'], 'label' => 'Provincial 1-day', 'take' => 3],
            'national_or_international_2d' => ['levels' => ['national', 'international'], 'label' => 'National / international 2-day', 'take' => 2],
            'sa_champs' => ['levels' => ['final'], 'label' => 'SA Championship', 'take' => 1],
        ];

        $out = [];
        foreach ($configured as $component) {
            $source = $component['source'] ?? '';
            if (! isset($known[$source])) {
                continue;
            }
            $out[] = [
                'source' => $source,
                'label' => $known[$source]['label'],
                'levels' => $known[$source]['levels'],
                'take' => $known[$source]['take'],
                'weight' => (float) ($component['per_match_weight_pct'] ?? 0),
                'cap' => (float) ($component['cap_pct'] ?? 100),
            ];
        }

        return $out;
    }

    /**
     * @param  Collection<int, Score>  $scores
     * @param  list<array{source: string, label: string, levels: list<string>, take: int, weight: float, cap: float}>  $components
     * @return array{raw_weighted_pct: float, components: list<array<string, mixed>>}
     */
    private function scoreAthlete(Collection $scores, array $components): array
    {
        $built = [];
        $raw = 0.0;

        foreach ($components as $component) {
            $picked = $scores
                ->filter(fn (Score $score) => in_array($score->match?->series_level, $component['levels'], true))
                ->sortByDesc(fn (Score $score) => (float) ($score->normalized_score ?? 0))
                ->take($component['take'])
                ->values();

            $matches = [];
            $contribution = 0.0;
            foreach ($picked as $score) {
                $normalized = (float) ($score->normalized_score ?? 0);
                $share = round(($normalized / 100) * $component['weight'], 4);
                $contribution += $share;
                $matches[] = [
                    'name' => $score->match?->name ?? 'Match',
                    'date' => $score->match?->match_date?->format('j M Y'),
                    'level' => $score->match?->series_level,
                    'normalized' => round($normalized, 2),
                    'contribution' => round($share, 2),
                ];
            }
            $contribution = min(round($contribution, 4), $component['cap']);
            $raw += $contribution;

            $built[] = [
                'label' => $component['label'],
                'take' => $component['take'],
                'weight' => $component['weight'],
                'counted' => count($matches),
                'contribution' => round($contribution, 2),
                'matches' => $matches,
            ];
        }

        return [
            'raw_weighted_pct' => round($raw, 2),
            'components' => $built,
        ];
    }

    /**
     * @param  array{eligibility?: list<array<string, mixed>>, participation?: list<array<string, mixed>>}  $status
     * @return list<array{code: string, name: string, status: string, note: string}>
     */
    private function criterionLines(array $status, SelectionAthlete $athlete, string $declarationCode): array
    {
        $lines = [];
        foreach (array_merge($status['eligibility'] ?? [], $status['participation'] ?? []) as $rule) {
            $lines[] = [
                'code' => (string) ($rule['code'] ?? ''),
                'name' => (string) ($rule['name'] ?? ''),
                'status' => (string) ($rule['status'] ?? ''),
                'note' => $this->note($rule, $athlete, $declarationCode),
            ];
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function note(array $rule, SelectionAthlete $athlete, string $declarationCode): string
    {
        $code = (string) ($rule['code'] ?? '');
        if ($code === $declarationCode) {
            $submitted = $athlete->declaration?->submitted_at;

            return $submitted
                ? 'Received '.$submitted->timezone(config('app.timezone'))->format('j M Y')
                : 'Not yet received';
        }

        if (isset($rule['current'], $rule['required'])) {
            return $rule['current'].' of '.$rule['required'];
        }

        if (! empty($rule['boolean'])) {
            return ! empty($rule['value']) ? 'Shot' : 'Not shot';
        }

        $detail = $rule['detail'] ?? [];
        if (! is_array($detail)) {
            return '';
        }

        return match ($code) {
            'ELG-01' => isset($detail['expiry_date']) ? 'Expiry '.$detail['expiry_date'] : '',
            'ELG-02' => array_key_exists('sa_citizen', $detail)
                ? ($detail['sa_citizen'] ? 'SA citizen' : 'Not recorded as a citizen')
                : '',
            'ELG-03' => $athlete->user?->club?->name ?? '',
            'ELG-04' => isset($detail['country_of_residence']) ? 'Resides in '.$detail['country_of_residence'] : '',
            default => '',
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array{name: string, shooters: list<array<string, mixed>>}>
     */
    private function rankByDivision(Collection $rows, float $threshold): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = (int) ($row['division_id'] ?? 0);
            $groups[$key]['name'] = $row['division_name'];
            $groups[$key]['order'] = $row['division_order'];
            $groups[$key]['shooters'][] = $row;
        }

        uasort($groups, function (array $a, array $b) {
            return [$a['order'], $a['name']] <=> [$b['order'], $b['name']];
        });

        $divisions = [];
        foreach ($groups as $group) {
            $shooters = $group['shooters'];
            usort($shooters, function (array $a, array $b) {
                return [-$a['raw_weighted_pct'], $a['name']] <=> [-$b['raw_weighted_pct'], $b['name']];
            });

            $top = (float) ($shooters[0]['raw_weighted_pct'] ?? 0);
            $rank = 0;
            $place = 0;
            $previous = null;
            foreach ($shooters as $index => $shooter) {
                $place++;
                $raw = (float) $shooter['raw_weighted_pct'];
                if ($previous === null || abs($raw - $previous) > 0.001) {
                    $rank = $place;
                }
                $divisionPct = $top > 0 ? round($raw / $top * 100, 2) : 0.0;
                $shooters[$index]['rank'] = $rank;
                $shooters[$index]['division_pct'] = $divisionPct;
                $shooters[$index]['colour'] = $divisionPct >= $threshold ? 'protea' : 'federation';
                $previous = $raw;
            }

            $divisions[] = [
                'name' => $group['name'],
                'shooters' => $shooters,
            ];
        }

        return $divisions;
    }
}
