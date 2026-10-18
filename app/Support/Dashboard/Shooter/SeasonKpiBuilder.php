<?php

namespace App\Support\Dashboard\Shooter;

use App\Models\MatchEvent;
use App\Models\Score;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The four KPI tiles above the matches / qualification grid.
 *
 * All four read from the shooter's own scored matches this season; nothing
 * is faked. Each tile returns a `has_data` flag so the view can hide the
 * card for a brand-new member instead of rendering "—".
 *
 * We deliberately do NOT compute an "impact rate" tile because the
 * scores.total_possible_shots column is nullable and almost never filled
 * by imports — a ring would be dishonest. Instead the fourth tile shows
 * "Peak match %" (the shooter's best single Score::normalized_score this
 * season), which is real data every scored shooter will have.
 */
final class SeasonKpiBuilder
{
    /**
     * @param  list<string>  $disciplines
     * @return array<string, mixed>
     */
    public function build(User $user, array $disciplines, ?string $season = null): array
    {
        $season = $season ?: (string) Carbon::now()->year;

        $scores = $this->seasonScores($user, $disciplines, $season)->values();
        $prevScores = $this->seasonScores($user, $disciplines, (string) ((int) $season - 1))->values();

        return [
            'matches' => $this->matchesTile($user, $disciplines, $season, $scores),
            'average' => $this->averageTile($scores, $prevScores),
            'best' => $this->bestFinishTile($user, $scores),
            'peak' => $this->peakTile($scores),
        ];
    }

    /**
     * @param  list<string>  $disciplines
     * @return \Illuminate\Database\Eloquent\Collection<int, Score>
     */
    private function seasonScores(User $user, array $disciplines, string $season): \Illuminate\Database\Eloquent\Collection
    {
        if ($disciplines === []) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        $scores = Score::query()
            ->with('match:id,name,series,series_level,match_date,season,province_id')
            ->where('user_id', $user->id)
            ->where('status', 'valid')
            ->whereHas('match', function ($q) use ($disciplines, $season) {
                $q->whereIn('series', $disciplines)
                    ->where(function ($inner) use ($season) {
                        $inner->where('season', $season)->orWhereYear('match_date', $season);
                    });
            })
            ->get()
            ->unique('match_id');

        return $scores;
    }

    /**
     * @param  list<string>  $disciplines
     * @param  Collection<int, Score>  $scores
     * @return array<string, mixed>
     */
    private function matchesTile(User $user, array $disciplines, string $season, Collection $scores): array
    {
        $shot = $scores->count();

        // Published matches in the user's disciplines dated this year form the
        // "calendar" — a season is "x of y matches" where y is everything the
        // shooter could possibly have attended, past or future.
        $calendar = MatchEvent::query()
            ->published()
            ->whereIn('series', $disciplines)
            ->where(function ($q) use ($season) {
                $q->where('season', $season)->orWhereYear('match_date', $season);
            })
            ->orderBy('match_date')
            ->get(['id', 'series', 'series_level', 'match_date']);

        $today = Carbon::today();
        $dots = $calendar->take(10)->map(function (MatchEvent $m) use ($scores, $today) {
            $hasScore = $scores->contains(fn (Score $s) => (int) $s->match_id === (int) $m->id);

            return [
                'shot' => $hasScore,
                'future' => $m->match_date && $m->match_date->greaterThan($today),
            ];
        })->values()->all();

        // Breakdown copy: "2 PRS nationals · 3 provincials · 1 PR22 entered"
        $parts = [];
        foreach ($disciplines as $d) {
            $natl = $scores->filter(fn (Score $s) => $s->match?->series === $d
                && in_array($s->match?->series_level, ['national', 'final'], true))->count();
            if ($natl > 0) {
                $parts[] = "{$natl} {$d} national".($natl === 1 ? '' : 's');
            }
        }
        $prov = $scores->filter(fn (Score $s) => $s->match?->series_level === 'provincial')->count();
        if ($prov > 0) {
            $parts[] = "{$prov} provincial".($prov === 1 ? '' : 's');
        }

        return [
            'has_data' => $calendar->isNotEmpty() || $shot > 0,
            'shot' => $shot,
            'calendar' => $calendar->count(),
            'dots' => $dots,
            'breakdown' => $parts === [] ? null : implode(' · ', $parts),
        ];
    }

    /**
     * @param  Collection<int, Score>  $scores
     * @param  Collection<int, Score>  $prevScores
     * @return array<string, mixed>
     */
    private function averageTile(Collection $scores, Collection $prevScores): array
    {
        $pcts = $scores->map(fn (Score $s) => $s->normalized_score !== null ? (float) $s->normalized_score : null)
            ->filter(fn ($p) => $p !== null)
            ->values();

        if ($pcts->isEmpty()) {
            return ['has_data' => false];
        }

        $avg = round((float) $pcts->avg(), 1);
        $best = round((float) $pcts->max(), 1);

        $prevPcts = $prevScores->map(fn (Score $s) => $s->normalized_score !== null ? (float) $s->normalized_score : null)
            ->filter(fn ($p) => $p !== null)
            ->values();

        $delta = $prevPcts->isNotEmpty()
            ? round($avg - (float) $prevPcts->avg(), 1)
            : null;

        return [
            'has_data' => true,
            'avg' => $avg,
            'best' => $best,
            'delta' => $delta,
        ];
    }

    /**
     * @param  Collection<int, Score>  $scores
     * @return array<string, mixed>
     */
    private function bestFinishTile(User $user, Collection $scores): array
    {
        $ranked = $scores->filter(fn (Score $s) => $s->displayRank() !== null);
        if ($ranked->isEmpty()) {
            return ['has_data' => false];
        }

        /** @var Score $best */
        $best = $ranked->sortBy(fn (Score $s) => $s->displayRank())->first();
        $match = $best->match;

        $totalShooters = $match
            ? Score::query()->where('match_id', $match->id)->where('status', 'valid')->count()
            : null;

        return [
            'has_data' => true,
            'rank' => $best->displayRank(),
            'total' => $totalShooters,
            'match_name' => $match?->name,
            'match_date' => $match?->match_date?->format('d M Y'),
            'href' => $match ? route('events.show', $match) : null,
        ];
    }

    /**
     * Our 4th tile: peak match % (your best single normalised score this
     * season). Rendered as a ring in the view when has_data is true.
     *
     * @param  Collection<int, Score>  $scores
     * @return array<string, mixed>
     */
    private function peakTile(Collection $scores): array
    {
        /** @var \Illuminate\Support\Collection<int, float> $pcts */
        $pcts = $scores->map(fn (Score $s) => $s->normalized_score !== null ? (float) $s->normalized_score : null)
            ->filter(fn ($p) => $p !== null)
            ->values();

        if ($pcts->isEmpty()) {
            return ['has_data' => false];
        }

        $peak = round((float) $pcts->max(), 1);

        /** @var Score|null $peakScore */
        $peakScore = $scores->sortByDesc(fn (Score $s) => (float) ($s->normalized_score ?? 0))->first();
        $peakMatch = $peakScore?->match;

        // 0..100 → arc length over SVG circumference 97.389 (r=15.5)
        $arc = round(($peak / 100) * 97.389, 1);

        return [
            'has_data' => true,
            'pct' => $peak,
            'arc' => $arc,
            'circumference' => 97.389,
            'match_name' => $peakMatch?->name,
            'match_date' => $peakMatch?->match_date?->format('d M Y'),
            'href' => $peakMatch ? route('events.show', $peakMatch) : null,
        ];
    }
}
