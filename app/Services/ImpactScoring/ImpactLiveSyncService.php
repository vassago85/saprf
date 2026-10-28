<?php

namespace App\Services\ImpactScoring;

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Score;
use App\Services\ScoreValidationService;
use App\Services\StandingsCalculationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Refresh a match's scoreboard from Impact while the match is on.
 *
 * Unlike the manual import, this never creates walk-ins, stub users,
 * score-import rows, or director emails, and it never marks the match
 * complete. New shooters land only on an exact entry-name match.
 * Scores a director has adjusted are left alone.
 */
class ImpactLiveSyncService
{
    public function __construct(
        private readonly ImpactScoringClient $client,
        private readonly ImpactNameMatcher $names,
        private readonly ScoreValidationService $scoreValidation,
        private readonly StandingsCalculationService $standings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function sync(MatchEvent $match): array
    {
        $match = $match->fresh() ?? $match;

        if (! $match->impact_live_sync || ! $match->impact_match_id) {
            return ['skipped' => true, 'reason' => 'disabled'];
        }

        if ($match->impact_live_ended_at !== null) {
            return ['skipped' => true, 'reason' => 'ended'];
        }

        if ($match->scoresAreFinalized()) {
            return ['skipped' => true, 'reason' => 'finalized'];
        }

        if (! $match->isDueForImpactLiveSync()) {
            return ['skipped' => true, 'reason' => 'not_match_day'];
        }

        try {
            $live = $this->client->getLiveScores((int) $match->impact_match_id);
        } catch (Throwable $e) {
            $match->forceFill([
                'impact_live_error' => Str::limit($e->getMessage(), 500),
            ])->save();

            return [
                'skipped' => false,
                'error' => $e->getMessage(),
                'created' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'held' => 0,
            ];
        }

        $ended = (bool) ($live['match']['matchEnded'] ?? false);
        $registrations = $this->registrations($match);
        $candidates = $registrations->map(fn (MatchRegistration $registration) => [
            'key' => 'reg:'.$registration->id,
            'name' => $registration->user?->name ?: (string) $registration->shooter_name,
        ])->all();
        $registrationsByKey = $registrations->keyBy(fn (MatchRegistration $registration) => 'reg:'.$registration->id);

        /** @var Collection<int, Score> $scores */
        $scores = $match->scores()->get();
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $held = 0;
        $skipped = 0;

        foreach ($live['shooters'] as $shooter) {
            $shooterId = (int) ($shooter['id'] ?? 0);
            if ($shooterId <= 0) {
                $skipped++;

                continue;
            }

            $impactName = trim((string) ($shooter['name'] ?? ''));
            $impacts = ((int) ($shooter['points'] ?? 0)) / 100;
            $rank = is_numeric($shooter['rank'] ?? null) ? (int) $shooter['rank'] : null;
            $nameMatch = $this->names->match($impactName, $candidates);
            $registration = ($nameMatch['status'] === 'exact' && isset($nameMatch['key']))
                ? $registrationsByKey->get($nameMatch['key'])
                : null;

            $existing = $this->findScore($scores, $shooterId, $registration?->user_id);

            if ($existing && ! empty($existing->raw_meta['impact']['adjusted'])) {
                $held++;

                continue;
            }

            if ($existing === null && ! $registration?->user_id) {
                $skipped++;

                continue;
            }

            $pointsChanged = $existing === null
                || abs((float) $existing->raw_score - $impacts) > 0.0001;
            $rankChanged = $existing !== null && (int) $existing->placement !== (int) $rank;

            if ($existing && ! $pointsChanged && ! $rankChanged) {
                $unchanged++;

                continue;
            }

            $stages = null;
            if ($pointsChanged) {
                try {
                    $stages = $this->client->getShooterStages((int) $match->impact_match_id, $shooterId);
                } catch (Throwable) {
                    $stages = null;
                }
            }

            if ($existing) {
                $this->writeScore($existing, $match, $shooterId, $impactName, $impacts, $rank, $stages, $registration);
                $updated++;

                continue;
            }

            $score = new Score([
                'match_id' => $match->id,
                'user_id' => $registration->user_id,
                'division_id' => $registration->division_id,
                'shooter_name' => $registration->user?->name ?: $registration->shooter_name ?: $impactName,
                'status' => 'pending',
                'is_member' => false,
                'match_date' => $match->match_date,
            ]);
            $this->writeScore($score, $match, $shooterId, $impactName, $impacts, $rank, $stages, $registration);
            $scores->push($score);
            $created++;
        }

        $match->forceFill([
            'impact_live_synced_at' => now(),
            'impact_live_error' => null,
            'impact_live_ended_at' => $ended ? now() : null,
        ])->save();

        if ($created > 0 || $updated > 0) {
            try {
                // Ranks on the match page only. Season standings wait until
                // the match director marks the scores complete.
                $this->standings->calculateMatchRankings($match->fresh());
            } catch (Throwable $e) {
                report($e);
            }
        }

        return [
            'skipped' => $skipped,
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'held' => $held,
            'ended' => $ended,
            'error' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>|null  $stages
     */
    private function writeScore(
        Score $score,
        MatchEvent $match,
        int $shooterId,
        string $impactName,
        float $impacts,
        ?int $rank,
        ?array $stages,
        ?MatchRegistration $registration,
    ): void {
        $meta = is_array($score->raw_meta) ? $score->raw_meta : [];
        $impact = is_array($meta['impact'] ?? null) ? $meta['impact'] : [];
        $impact = array_merge($impact, [
            'match_id' => (int) $match->impact_match_id,
            'shooter_id' => $shooterId,
            'impact_name' => $impactName,
            'live_sync' => true,
            'total_impacts' => $impacts,
            'stages' => $stages ?? ($impact['stages'] ?? []),
        ]);
        $meta['impact'] = $impact;

        $score->fill([
            'day1_raw_score' => $impacts,
            'placement' => $rank,
            'raw_meta' => $meta,
            // Incomplete until the match director marks the scores complete.
            'counts_for_season' => false,
            'counts_for_log' => false,
        ]);

        if ($registration?->division_id && ! $score->division_id) {
            $score->division_id = $registration->division_id;
        }

        $score->save();

        // The score observer copies day 1 into the provincial column whenever
        // the match is flagged dual-count. Live pulls store Impact's running
        // total, which is not the Day 1 provincial score.
        if ($match->also_counts_for_provincial && $score->provincial_raw_score !== null) {
            $score->provincial_raw_score = null;
            $score->save();
        }

        $this->scoreValidation->evaluateScoreStatus($score);
    }

    /**
     * Live scores stay off the season until the match director confirms
     * them. Unmarking puts them back to incomplete.
     */
    public function setConfirmed(MatchEvent $match, bool $confirmed): void
    {
        $live = $match->scores()->get()->filter(
            fn (Score $score) => ! empty($score->raw_meta['impact']['live_sync'])
        );

        if ($live->isEmpty()) {
            return;
        }

        foreach ($live as $score) {
            $score->forceFill([
                'counts_for_season' => $confirmed,
                'counts_for_log' => $confirmed,
            ])->save();

            $this->scoreValidation->syncOfficialLog($score);
        }

        try {
            $this->standings->recalculateForMatch($match->fresh());
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  Collection<int, Score>  $scores
     */
    private function findScore(Collection $scores, int $shooterId, ?int $userId): ?Score
    {
        $byImpact = $scores->first(function (Score $score) use ($shooterId) {
            $id = $score->raw_meta['impact']['shooter_id'] ?? null;

            return $id !== null && $id !== '' && (int) $id === $shooterId;
        });

        if ($byImpact) {
            return $byImpact;
        }

        if (! $userId) {
            return null;
        }

        return $scores->first(fn (Score $score) => (int) $score->user_id === $userId);
    }

    /**
     * @return Collection<int, MatchRegistration>
     */
    private function registrations(MatchEvent $match): Collection
    {
        return $match->registrations()
            ->with(['user:id,name', 'division:id'])
            ->where(function ($query) {
                $query->whereNull('registration_status')
                    ->orWhereNotIn('registration_status', ['cancelled']);
            })
            ->get();
    }
}
