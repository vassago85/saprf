<?php

namespace App\Support\Dashboard\Shooter;

use App\Models\QualificationRule;
use App\Models\Score;
use App\Models\User;
use App\Services\QualificationService;
use App\Services\ShooterStandingsSummaryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class SeasonCardBuilder
{
    public function __construct(
        private readonly QualificationService $qualification,
        private readonly ShooterStandingsSummaryService $standings,
    ) {}

    /**
     * Cards only for disciplines the member has scores in *this* season.
     *
     * @param  list<string>  $disciplines
     * @return list<array<string, mixed>>
     */
    public function build(User $user, array $disciplines, ?string $season = null): array
    {
        $season = $season ?: (string) Carbon::now()->year;
        $summary = $this->standings->build($user, $season, $disciplines)->keyBy('series');
        $cards = [];

        foreach ($disciplines as $series) {
            $levelStats = $this->levelStats($user, $series, $season);
            $matchesShot = $levelStats['national']['shot'] + $levelStats['provincial']['shot'] + $levelStats['final']['shot'];

            // Season cards only when they have scores this season.
            if ($matchesShot === 0 && ! $summary->has($series)) {
                continue;
            }

            $row = $summary->get($series);
            $progress = $this->qualification->getDashboardProgress($user, $season)[$series] ?? null;
            $rule = QualificationRule::query()->where('series', $series)->where('season', $season)->first();
            $mode = $rule?->scoring_mode ?? ($row['scoring_mode'] ?? 'best_of_n');

            $cards[] = [
                'series' => $series,
                'label' => $series === 'PR22' ? 'Rimfire' : 'Centrefire',
                'matches' => $matchesShot,
                'national' => [
                    'rank' => $row['overall_rank'] ?? null,
                    'points' => $row['overall_points'] ?? null,
                    'open_rank' => $this->openDivisionRank($row['divisions'] ?? []),
                    'includes_provincial_points' => $mode === 'weighted_pools' || ($rule?->isPooledScoring() ?? false),
                ],
                'provincial' => [
                    'name' => $row['province_name'] ?? ($user->province?->name ?? 'Province'),
                    'rank' => $row['provincial_rank'] ?? null,
                    'points' => $row['provincial_points'] ?? null,
                    'open_rank' => $this->openDivisionRank($row['provincial_divisions'] ?? []),
                    'has' => (bool) ($row['has_provincial'] ?? false),
                ],
                'levels' => [
                    [
                        'label' => 'National',
                        'shot' => $levelStats['national']['shot'],
                        'best' => $levelStats['national']['best'],
                        'avg' => $levelStats['national']['avg'],
                    ],
                    [
                        'label' => 'Provincial',
                        'shot' => $levelStats['provincial']['shot'],
                        'best' => $levelStats['provincial']['best'],
                        'avg' => $levelStats['provincial']['avg'],
                    ],
                ],
                'qualification' => $this->qualificationBlock($progress, $rule, $levelStats),
            ];
        }

        return $cards;
    }

    /**
     * @return array{national: array{shot:int, best:?int, avg:?float}, provincial: array{shot:int, best:?int, avg:?float}, final: array{shot:int, best:?int, avg:?float}}
     */
    private function levelStats(User $user, string $series, string $season): array
    {
        $scores = Score::query()
            ->with('match')
            ->where('user_id', $user->id)
            ->where('status', 'valid')
            ->whereHas('match', function ($q) use ($series, $season) {
                $q->where('series', $series)
                    ->where(function ($inner) use ($season) {
                        $inner->where('season', $season)->orWhereYear('match_date', $season);
                    });
            })
            ->get()
            ->unique('match_id');

        $bucket = fn (string $level) => $scores->filter(fn (Score $s) => $s->match?->series_level === $level);

        $summarize = function (Collection $set): array {
            $placed = $set->map(fn (Score $s) => $s->displayRank())->filter();

            return [
                'shot' => $set->count(),
                'best' => $placed->isEmpty() ? null : (int) $placed->min(),
                'avg' => $placed->isEmpty() ? null : round((float) $placed->avg(), 1),
            ];
        };

        return [
            'national' => $summarize($bucket('national')),
            'provincial' => $summarize($bucket('provincial')),
            'final' => $summarize($bucket('final')),
        ];
    }

    /**
     * @param  list<array{name: ?string, rank: int}>  $divisions
     */
    private function openDivisionRank(array $divisions): ?int
    {
        foreach ($divisions as $div) {
            if (strcasecmp((string) ($div['name'] ?? ''), 'Open') === 0) {
                return $div['rank'] ?? null;
            }
        }

        return $divisions[0]['rank'] ?? null;
    }

    /**
     * @param  array<string, mixed>|null  $progress
     * @param  array{national: array{shot:int}, provincial: array{shot:int}, final: array{shot:int}}  $levelStats
     * @return array<string, mixed>
     */
    private function qualificationBlock(?array $progress, ?QualificationRule $rule, array $levelStats): array
    {
        $oop = $progress['oop'] ?? ['required' => 0, 'completed' => 0, 'qualified' => true];
        $steps = $progress['steps'] ?? [];

        if ($rule?->isPooledScoring()) {
            $pools = collect($steps)->map(fn (array $step) => [
                'label' => $step['label'],
                'detail' => $step['detail'],
                'completed' => (int) $step['completed'],
                'required' => (int) $step['required'],
                'pct' => $step['required'] > 0
                    ? min(100, ((int) $step['completed'] / (int) $step['required']) * 100)
                    : 0,
            ])->all();

            $met = collect($pools)->every(fn ($p) => $p['required'] === 0 || $p['completed'] >= $p['required'])
                && (($oop['required'] ?? 0) === 0 || ($oop['qualified'] ?? false));

            return [
                'mode' => 'weighted_pools',
                'met' => $met,
                'pools' => $pools,
                'oop' => $oop,
            ];
        }

        // Best-of-N / nationals-focused display.
        $need = (int) ($rule?->best_of_count ?: $rule?->total_qualifying_matches ?: ($steps[0]['required'] ?? 0));
        $shot = $levelStats['national']['shot'] + $levelStats['final']['shot'];
        $segments = [];
        for ($i = 0; $i < max($shot, 0); $i++) {
            $segments[] = $i < $need ? 'counting' : 'dropped';
        }

        $met = ($need === 0 || $shot >= $need)
            && (($oop['required'] ?? 0) === 0 || ($oop['qualified'] ?? false));

        return [
            'mode' => 'best_of_n',
            'met' => $met,
            'shot' => $shot,
            'need' => $need,
            'segments' => $segments,
            'hint' => $need > 0
                ? "Your best {$need} normalised national scores count toward the season total. Dark = counting, light = dropped."
                : null,
            'oop' => $oop,
        ];
    }
}
