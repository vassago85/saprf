<?php

namespace App\Support\Dashboard\Shooter;

use App\Models\Score;
use App\Models\User;
use App\Support\ImpactStages;

/**
 * The shooter's most recent match that actually has a per-stage
 * breakdown, drawn as one bar per stage in course order.
 */
class LastMatchStagesBuilder
{
    /**
     * @return array{
     *     match_name: string,
     *     match_date: ?string,
     *     href: ?string,
     *     total_label: string,
     *     stages: list<array{label: string, short: string, points: float, points_label: string, pct: float}>
     * }|null
     */
    public function for(User $user): ?array
    {
        $scores = Score::query()
            ->with('match:id,name,match_date,status,published')
            ->where('user_id', $user->id)
            ->whereNotNull('raw_meta')
            ->orderByDesc('match_date')
            ->orderByDesc('id')
            ->limit(40)
            ->get();

        foreach ($scores as $score) {
            $stages = ImpactStages::forDisplay($score->raw_meta['impact']['stages'] ?? null);
            if ($stages === []) {
                continue;
            }

            $match = $score->match;
            $points = array_map(fn (array $stage): float => (float) ($stage['points'] ?? 0), $stages);
            $peak = max($points) > 0 ? max($points) : 1.0;
            $multiDay = collect($stages)->contains(fn (array $stage): bool => $stage['day'] > 1);

            $bars = [];
            foreach ($stages as $index => $stage) {
                $value = (float) ($stage['points'] ?? 0);
                $number = $stage['number'] ?? ($index + 1);
                $short = $multiDay ? $stage['day'].'.'.$number : (string) $number;

                $bars[] = [
                    'label' => $stage['label'],
                    'short' => $short,
                    'points' => $value,
                    'points_label' => self::pointsLabel($value),
                    'pct' => round(($value / $peak) * 100, 1),
                ];
            }

            $total = array_sum($points);
            $href = ($match && $match->published && $match->status !== 'draft')
                ? route('events.show', $match)
                : null;

            return [
                'match_name' => $match?->name ?? 'Last match',
                'match_date' => $match?->match_date?->format('j M Y'),
                'href' => $href,
                'total_label' => self::pointsLabel($total),
                'stages' => $bars,
            ];
        }

        return null;
    }

    private static function pointsLabel(float $points): string
    {
        return abs($points - round($points)) < 0.001
            ? (string) (int) round($points)
            : number_format($points, 1);
    }
}
