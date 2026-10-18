<?php

namespace App\Support\Dashboard\Shooter;

use App\Models\MatchEvent;
use App\Models\Score;
use App\Models\Standing;
use App\Models\User;
use App\Services\ShooterStandingsSummaryService;
use Illuminate\Support\Carbon;

/**
 * Per-discipline hero data for the shooter dashboard.
 *
 * Produces, for each series the shooter is active in:
 *   • current overall rank + total ranked, points, "top X%"
 *   • distance to the national-squad cut (points to catch the shooter in
 *     the configured cut rank, 12 by default — the headline Protea squad
 *     size for both PRS and PR22)
 *   • a sparkline / area-chart series of match % per scored match, where
 *     "match %" is Score::normalized_score (raw / top raw × 100) — this is
 *     the standard PRS performance metric computed by the standings
 *     calculator for every valid score
 *
 * Nothing in this builder invents numbers. If the shooter isn't ranked
 * yet AND has no scored matches for the series, the hero returns `null`
 * so the view can skip that tab instead of rendering "—".
 */
final class HeroStandingBuilder
{
    /** Default top-N cut for both disciplines (Protea squad size). */
    private const DEFAULT_CUT_RANK = 12;

    public function __construct(
        private readonly ShooterStandingsSummaryService $summary,
    ) {}

    /**
     * @param  list<string>  $disciplines
     * @return array<string, array<string, mixed>|null>
     */
    public function build(User $user, array $disciplines, ?string $season = null): array
    {
        $season = $season ?: (string) Carbon::now()->year;
        $summary = $this->summary->build($user, $season, $disciplines)->keyBy('series');
        $heroes = [];

        foreach ($disciplines as $series) {
            $row = $summary->get($series);
            $heroes[$series] = $this->buildOne($user, $series, $season, $row);
        }

        return $heroes;
    }

    /**
     * @param  array<string, mixed>|null  $row
     * @return array<string, mixed>|null
     */
    private function buildOne(User $user, string $series, string $season, ?array $row): ?array
    {
        $chart = $this->chartSeries($user, $series, $season);

        if (! $row && $chart === []) {
            return null;
        }

        $rank = $row['overall_rank'] ?? null;
        $points = isset($row['overall_points']) && $row['overall_points'] !== null
            ? (float) $row['overall_points']
            : null;
        $totalRanked = $this->countRanked($series, $season);
        $topPct = ($rank && $totalRanked > 0)
            ? max(1, (int) ceil(($rank / $totalRanked) * 100))
            : null;

        [$cutPoints, $cutShooterRank] = $this->cutPoints($series, $season, self::DEFAULT_CUT_RANK);
        $cut = $this->cutProgress($points, $rank, $cutPoints, $cutShooterRank);

        $movementLabel = $this->movementLabel($user, $series, $season);

        return [
            'series' => $series,
            'label' => $series === 'PR22' ? 'PR22 season standing' : 'PRS season standing',
            'rank' => $rank,
            'total_ranked' => $totalRanked ?: null,
            'points' => $points,
            'top_pct' => $topPct,
            'movement_label' => $movementLabel,
            'cut' => $cut,
            'chart' => $chart === [] ? null : $this->chartLayout($chart),
            'avg_match_pct' => $this->averageMatchPct($user, $series, $season),
        ];
    }

    /**
     * Shooters who hold at least one points row in the overall table for the
     * series/season. Rank values alone would skip ties, so we count distinct
     * users directly.
     */
    private function countRanked(string $series, string $season): int
    {
        return (int) Standing::query()
            ->where('season', $season)
            ->where('series', $series)
            ->whereNull('province_id')
            ->whereNull('division_id')
            ->distinct('user_id')
            ->count('user_id');
    }

    /**
     * Points held by the shooter currently sitting at the cut rank. Returns
     * ($cutPoints, $cutShooterRank) — if ties push the Nth-ranked shooter
     * past $targetRank, we take the highest rank <= target that exists.
     *
     * @return array{0: float|null, 1: int|null}
     */
    private function cutPoints(string $series, string $season, int $targetRank): array
    {
        $row = Standing::query()
            ->where('season', $season)
            ->where('series', $series)
            ->whereNull('province_id')
            ->whereNull('division_id')
            ->where('rank', '<=', $targetRank)
            ->orderByDesc('rank')
            ->first(['rank', 'points']);

        if (! $row) {
            return [null, null];
        }

        return [(float) $row->points, (int) $row->rank];
    }

    /**
     * @return array{target_rank:int, target_points:?float, points_to_cut:?float, pct_filled:int, mark_pct:int, in_cut:bool, has_data:bool}
     */
    private function cutProgress(?float $points, ?int $rank, ?float $cutPoints, ?int $cutShooterRank): array
    {
        $targetRank = self::DEFAULT_CUT_RANK;
        $inCut = $rank !== null && $rank <= $targetRank;

        if ($points === null || $cutPoints === null) {
            return [
                'target_rank' => $targetRank,
                'target_points' => null,
                'points_to_cut' => null,
                'pct_filled' => 0,
                'mark_pct' => 92,
                'in_cut' => $inCut,
                'has_data' => false,
            ];
        }

        $pointsToCut = $inCut ? 0.0 : max(0.0, round($cutPoints - $points, 1));
        $pct = $cutPoints > 0 ? min(100, max(5, (int) round(($points / $cutPoints) * 92))) : 0;

        return [
            'target_rank' => $targetRank,
            'target_points' => round($cutPoints, 1),
            'points_to_cut' => $pointsToCut,
            'pct_filled' => $inCut ? 100 : $pct,
            'mark_pct' => 92,
            'in_cut' => $inCut,
            'has_data' => true,
        ];
    }

    /**
     * We don't store historical rank snapshots, so the hero movement line is
     * conservative: just the name of the most recent scored match. The view
     * uses this to render "last match: Highveld Classic" rather than making
     * up a rank delta.
     */
    private function movementLabel(User $user, string $series, string $season): ?string
    {
        $lastMatch = Score::query()
            ->with('match:id,name,match_date')
            ->where('user_id', $user->id)
            ->where('status', 'valid')
            ->whereHas('match', function ($q) use ($series, $season) {
                $q->where('series', $series)
                    ->where(function ($inner) use ($season) {
                        $inner->where('season', $season)->orWhereYear('match_date', $season);
                    });
            })
            ->orderByDesc(
                MatchEvent::select('match_date')
                    ->whereColumn('matches.id', 'scores.match_id')
                    ->limit(1)
            )
            ->first();

        $name = $lastMatch?->match?->name;

        return $name ? "last match: {$name}" : null;
    }

    /**
     * @return list<array{date:string, date_full:string, pct:float, name:string, href:?string}>
     */
    private function chartSeries(User $user, string $series, string $season): array
    {
        $scores = Score::query()
            ->with('match:id,name,match_date')
            ->where('user_id', $user->id)
            ->where('status', 'valid')
            ->whereNotNull('normalized_score')
            ->whereHas('match', function ($q) use ($series, $season) {
                $q->where('series', $series)
                    ->where(function ($inner) use ($season) {
                        $inner->where('season', $season)->orWhereYear('match_date', $season);
                    });
            })
            ->get()
            ->unique('match_id')
            ->sortBy(fn (Score $s) => $s->match?->match_date?->timestamp ?? 0)
            ->values();

        return $scores->map(function (Score $s) {
            $match = $s->match;

            return [
                'date' => $match?->match_date?->format('M') ?? '',
                'date_full' => $match?->match_date?->format('d M') ?? '',
                'pct' => round((float) $s->normalized_score, 1),
                'name' => $match?->name ?? '',
                'href' => $match ? route('events.show', $match) : null,
            ];
        })->all();
    }

    /**
     * @param  list<array{date:string, date_full:string, pct:float, name:string, href:?string}>  $points
     * @return array<string, mixed>
     */
    private function chartLayout(array $points): array
    {
        $pcts = array_map(fn ($p) => $p['pct'], $points);
        $max = max($pcts);
        $min = min($pcts);
        $pad = max(5.0, ($max - $min) * 0.15);
        $yMax = min(100.0, ceil(($max + $pad) / 5) * 5);
        $yMin = max(0.0, floor(($min - $pad) / 5) * 5);

        if ($yMax - $yMin < 20) {
            $yMin = max(0, $yMax - 20);
        }

        $latest = end($points);
        $peak = max($pcts);

        return [
            'points' => $points,
            'y_max' => $yMax,
            'y_min' => $yMin,
            'latest_pct' => round($latest['pct'], 1),
            'latest_name' => $latest['name'] ?? '',
            'peak_pct' => round($peak, 1),
            'match_count' => count($points),
        ];
    }

    private function averageMatchPct(User $user, string $series, string $season): ?float
    {
        $pcts = Score::query()
            ->with('match:id,match_date')
            ->where('user_id', $user->id)
            ->where('status', 'valid')
            ->whereNotNull('normalized_score')
            ->whereHas('match', function ($q) use ($series, $season) {
                $q->where('series', $series)
                    ->where(function ($inner) use ($season) {
                        $inner->where('season', $season)->orWhereYear('match_date', $season);
                    });
            })
            ->get()
            ->unique('match_id')
            ->map(fn (Score $s) => (float) $s->normalized_score)
            ->values();

        return $pcts->isEmpty() ? null : round((float) $pcts->avg(), 1);
    }
}
