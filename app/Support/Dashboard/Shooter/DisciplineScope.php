<?php

namespace App\Support\Dashboard\Shooter;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * A member's disciplines = series with at least one valid score this season
 * or last season. No scores at all → both PRS and PR22 (show all upcoming).
 */
final class DisciplineScope
{
    public const ALL = ['PRS', 'PR22'];

    /**
     * @return list<string>
     */
    public function for(User $user, ?string $season = null): array
    {
        $season = $season ?: (string) Carbon::now()->year;
        $lastSeason = (string) ((int) $season - 1);

        $series = $user->scores()
            ->where('status', 'valid')
            ->whereHas('match', function ($q) use ($season, $lastSeason) {
                $q->where(function ($inner) use ($season, $lastSeason) {
                    $inner->whereIn('season', [$season, $lastSeason])
                        ->orWhereYear('match_date', $season)
                        ->orWhereYear('match_date', $lastSeason);
                });
            })
            ->with('match:id,series')
            ->get()
            ->pluck('match.series')
            ->filter(fn ($s) => in_array($s, self::ALL, true))
            ->unique()
            ->values()
            ->all();

        if ($series === []) {
            return self::ALL;
        }

        // Stable order: PRS first, then PR22.
        return array_values(array_filter(self::ALL, fn (string $s) => in_array($s, $series, true)));
    }

    /**
     * @param  list<string>  $disciplines
     */
    public function isDual(array $disciplines): bool
    {
        return count($disciplines) > 1;
    }

    /**
     * @param  list<string>  $disciplines
     */
    public function scopeNote(array $disciplines): string
    {
        if (count($disciplines) > 1) {
            return 'PRS and PR22, from the disciplines you shoot';
        }

        $only = $disciplines[0] ?? 'PRS';

        return "{$only} only, from the disciplines you shoot";
    }
}
