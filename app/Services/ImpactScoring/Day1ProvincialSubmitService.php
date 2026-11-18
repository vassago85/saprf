<?php

namespace App\Services\ImpactScoring;

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Score;
use App\Models\ShooterLog;
use App\Services\ScoreValidationService;
use App\Services\StandingsCalculationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Copy confirmed day-1 stage totals from a live 2-day national onto its
 * provincial sibling. The national scoreboard and the live pull are left
 * running so day 2 keeps building the combined result.
 */
class Day1ProvincialSubmitService
{
    public function __construct(
        private readonly ImpactScoringClient $client,
        private readonly ImpactNameMatcher $names,
        private readonly ScoreValidationService $scoreValidation,
        private readonly StandingsCalculationService $standings,
    ) {}

    /**
     * @return array{
     *     stages: list<array{number:int,id:int,name:string,day:?int}>,
     *     counts: array{day1:int,day2:int,unset:int,shooters_with_day1:int},
     *     linked_shooters: list<array{name:string,hits:array<int,float>}>,
     *     unmatched: list<string>
     * }
     */
    public function preview(MatchEvent $match): array
    {
        $this->assertAvailable($match);
        [$live, $stagesByShooter] = $this->pull($match);

        $stageIds = $this->stageIds($live['stages']);
        $saved = $this->normalizeMap($match->impact_stage_days);
        $kept = array_intersect_key($saved, array_flip($stageIds));
        if ($kept !== $saved) {
            $match->forceFill([
                'impact_stage_days' => $kept === [] ? null : $kept,
            ])->save();
        }

        return $this->present($match->fresh() ?? $match, $live, $stagesByShooter, $kept);
    }

    /**
     * @param  array<int|string, int|string>  $stageDays
     * @return array{written:int,unmatched:list<string>,not_updated:list<string>,message:string}
     */
    public function submit(MatchEvent $match, array $stageDays): array
    {
        $this->assertAvailable($match);

        try {
            [$live, $stagesByShooter] = $this->pull($match);
        } catch (RuntimeException $e) {
            throw new RuntimeException(
                $this->nothingWritten($e->getMessage()),
                0,
                $e
            );
        }

        $stageIds = $this->stageIds($live['stages']);
        if ($stageIds === []) {
            throw new RuntimeException($this->nothingWritten('Impact did not return any stages.'));
        }

        $posted = $this->postedMap($stageDays);
        foreach ($stageIds as $stageId) {
            if (! isset($posted[$stageId])) {
                throw new RuntimeException($this->nothingWritten('Mark every stage as Day 1 or Day 2 before submitting.'));
            }
        }

        $day1StageIds = array_values(array_filter(
            $stageIds,
            fn (int $stageId): bool => $posted[$stageId] === 1
        ));
        if ($day1StageIds === []) {
            throw new RuntimeException($this->nothingWritten('Mark at least one stage as Day 1.'));
        }

        $saved = [];
        foreach ($stageIds as $stageId) {
            $saved[$stageId] = $posted[$stageId];
        }

        return $this->refreshStandings(
            $match,
            $this->writeProvincial($match, $live, $stagesByShooter, $day1StageIds, $saved),
        );
    }

    /**
     * One step from the range: stages Impact has finished are day 1, the
     * rest are day 2. Another press on day 1 picks up stages finished
     * since the last press. From day 2 onward the split stays put and
     * only the day 1 totals are refreshed.
     *
     * @return array{written:int,unmatched:list<string>,not_updated:list<string>,message:string}
     */
    public function importFinishedDay1(MatchEvent $match): array
    {
        $this->assertAvailable($match);

        try {
            [$live, $stagesByShooter] = $this->pull($match);
        } catch (RuntimeException $e) {
            throw new RuntimeException($this->nothingWritten($e->getMessage()), 0, $e);
        }

        $stageIds = $this->stageIds($live['stages']);
        if ($stageIds === []) {
            throw new RuntimeException($this->nothingWritten('Impact did not return any stages.'));
        }

        $map = $this->dayMapForImport($match, $stageIds, $stagesByShooter);
        $day1StageIds = array_values(array_filter(
            $stageIds,
            fn (int $stageId): bool => ($map[$stageId] ?? 0) === 1
        ));
        if ($day1StageIds === []) {
            throw new RuntimeException($this->nothingWritten('No day 1 stage is finished on Impact yet.'));
        }

        $result = $this->writeProvincial($match, $live, $stagesByShooter, $day1StageIds, $map);
        $result['message'] .= ' Provincial standings are updated.';

        return $this->refreshStandings($match, $result);
    }

    private function assertAvailable(MatchEvent $match): void
    {
        if ($match->scoresAreFinalized()) {
            throw new RuntimeException('National scores are already complete.');
        }

        if (! $match->isTwoDayNational() || ! $match->impact_live_sync || ! $match->impact_match_id) {
            throw new RuntimeException('Day 1 provincial submit is only available on a 2-day national or championship with live scoring.');
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, list<array<string, mixed>>>}
     */
    private function pull(MatchEvent $match): array
    {
        try {
            $live = $this->client->getLiveScores((int) $match->impact_match_id);
        } catch (Throwable $e) {
            throw new RuntimeException('Impact could not be reached. '.$e->getMessage(), 0, $e);
        }

        $shooterIds = [];
        foreach ($live['shooters'] as $shooter) {
            $shooterId = (int) ($shooter['id'] ?? 0);
            if ($shooterId > 0) {
                $shooterIds[] = $shooterId;
            }
        }

        return [$live, $this->client->getShooterStagesMany((int) $match->impact_match_id, $shooterIds)];
    }

    /**
     * @param  list<array<string, mixed>>  $stages
     * @return list<int>
     */
    private function stageIds(array $stages): array
    {
        $ids = [];
        foreach ($stages as $stage) {
            $id = (int) ($stage['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<int|string, mixed>|null  $map
     * @return array<int, int>
     */
    private function normalizeMap(?array $map): array
    {
        $out = [];
        foreach ($map ?? [] as $id => $day) {
            $stageId = (int) $id;
            $day = (int) $day;
            if ($stageId > 0 && in_array($day, [1, 2], true)) {
                $out[$stageId] = $day;
            }
        }

        return $out;
    }

    /**
     * @param  array<int|string, int|string>  $stageDays
     * @return array<int, int>
     */
    private function postedMap(array $stageDays): array
    {
        $posted = [];
        foreach ($stageDays as $id => $day) {
            $stageId = (int) $id;
            $day = (int) $day;
            if ($stageId <= 0 || ! in_array($day, [1, 2], true)) {
                throw new RuntimeException($this->nothingWritten('Mark every stage as Day 1 or Day 2 before submitting.'));
            }
            $posted[$stageId] = $day;
        }

        return $posted;
    }

    /**
     * @param  array<string, mixed>  $live
     * @param  array<int, list<array<string, mixed>>>  $stagesByShooter
     * @param  array<int, int>  $days
     * @return array{
     *     stages: list<array{number:int,id:int,name:string,day:?int}>,
     *     counts: array{day1:int,day2:int,unset:int,shooters_with_day1:int},
     *     linked_shooters: list<array{name:string,hits:array<int,float>}>,
     *     unmatched: list<string>
     * }
     */
    private function present(MatchEvent $match, array $live, array $stagesByShooter, array $days): array
    {
        $stages = [];
        $number = 0;
        foreach ($live['stages'] as $stage) {
            $id = (int) ($stage['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $number++;
            $stages[] = [
                'number' => $number,
                'id' => $id,
                'name' => (string) ($stage['name'] ?? 'Stage'),
                'day' => $days[$id] ?? null,
            ];
        }

        $day1Ids = array_keys(array_filter($days, fn (int $day): bool => $day === 1));
        $registrations = $this->registrations($match);
        $linked = [];
        $unmatched = [];
        $withDay1 = 0;

        foreach ($live['shooters'] as $shooter) {
            $impactName = trim((string) ($shooter['name'] ?? ''));
            $registration = $this->resolveRegistration($match, $impactName, $registrations);
            $shooterId = (int) ($shooter['id'] ?? 0);
            if (! $registration?->user_id) {
                if ($impactName !== '') {
                    $unmatched[] = $impactName;
                }

                continue;
            }

            $breakdown = $stagesByShooter[$shooterId] ?? [];
            $hits = [];
            foreach ($breakdown as $row) {
                $stageId = (int) ($row['id'] ?? 0);
                if ($stageId > 0) {
                    $hits[$stageId] = (float) ($row['hits'] ?? $row['points'] ?? 0);
                }
            }
            $name = $registration->user?->name ?: (string) $registration->shooter_name;
            $linked[] = ['name' => $name, 'hits' => $hits];
            if ($breakdown !== [] && $this->sumHits($hits, $day1Ids) > 0) {
                $withDay1++;
            }
        }

        return [
            'stages' => $stages,
            'counts' => [
                'day1' => count(array_filter($stages, fn (array $stage): bool => $stage['day'] === 1)),
                'day2' => count(array_filter($stages, fn (array $stage): bool => $stage['day'] === 2)),
                'unset' => count(array_filter($stages, fn (array $stage): bool => $stage['day'] === null)),
                'shooters_with_day1' => $withDay1,
            ],
            'linked_shooters' => $linked,
            'unmatched' => array_values(array_unique($unmatched)),
        ];
    }

    /**
     * @param  array<string, mixed>  $live
     * @param  array<int, list<array<string, mixed>>>  $stagesByShooter
     * @param  list<int>  $day1StageIds
     * @param  array<int, int>  $stageDays
     * @return array{written:int,unmatched:list<string>,not_updated:list<string>,message:string}
     */
    private function writeProvincial(MatchEvent $match, array $live, array $stagesByShooter, array $day1StageIds, array $stageDays): array
    {
        return DB::transaction(function () use ($match, $live, $stagesByShooter, $day1StageIds, $stageDays) {
            $match->forceFill(['impact_stage_days' => $stageDays])->save();
            $sibling = $match->findOrCreateProvincialDay1Sibling();
            $registrations = $this->registrations($match);
            $written = 0;
            $keep = [];
            $unmatched = [];
            $notUpdated = [];

            foreach ($live['shooters'] as $shooter) {
                $impactName = trim((string) ($shooter['name'] ?? ''));
                $registration = $this->resolveRegistration($match, $impactName, $registrations);
                $userId = $registration?->user_id ? (int) $registration->user_id : null;
                if (! $userId) {
                    if ($impactName !== '') {
                        $unmatched[] = $impactName;
                    }

                    continue;
                }

                $shooterId = (int) ($shooter['id'] ?? 0);
                $breakdown = $stagesByShooter[$shooterId] ?? [];
                $displayName = $registration->user?->name ?: ($registration->shooter_name ?: $impactName);

                if ($breakdown === []) {
                    $keep[] = $userId;
                    $notUpdated[] = $displayName;

                    continue;
                }

                $hits = [];
                foreach ($breakdown as $row) {
                    $stageId = (int) ($row['id'] ?? 0);
                    if ($stageId > 0) {
                        $hits[$stageId] = (float) ($row['hits'] ?? $row['points'] ?? 0);
                    }
                }
                $sum = $this->sumHits($hits, $day1StageIds);

                if ($sum <= 0) {
                    $this->deleteSiblingScore($sibling, $userId);

                    continue;
                }

                $score = Score::firstOrNew([
                    'match_id' => $sibling->id,
                    'user_id' => $userId,
                ]);
                $score->fill([
                    'match_id' => $sibling->id,
                    'user_id' => $userId,
                    'division_id' => $registration->division_id,
                    'shooter_name' => $displayName,
                    'day1_raw_score' => $sum,
                    'day2_raw_score' => null,
                    'match_date' => $sibling->match_date,
                    'counts_for_season' => true,
                    'counts_for_log' => true,
                    'status' => 'pending',
                    'is_member' => false,
                    'raw_meta' => [
                        'impact' => [
                            'day1_submit' => true,
                            'match_id' => (int) $match->impact_match_id,
                            'shooter_id' => $shooterId,
                            'impact_name' => $impactName,
                            'day1_stage_ids' => $day1StageIds,
                            'day1_impacts' => $sum,
                            'stages' => $breakdown,
                        ],
                    ],
                ]);
                $score->save();
                $this->scoreValidation->evaluateScoreStatus($score);
                $keep[] = $userId;
                $written++;
            }

            $this->deleteScoresOutside($sibling, array_values(array_unique($keep)));

            $unmatched = array_values(array_unique($unmatched));
            $notUpdated = array_values(array_unique($notUpdated));
            $message = "Submitted {$written} "
                .Str::plural('shooter', $written)
                .' to the provincial.';
            if ($unmatched !== []) {
                $message .= ' Unmatched: '.implode(', ', $unmatched).'.';
            }
            if ($notUpdated !== []) {
                $message .= ' Not updated: '.implode(', ', $notUpdated).'.';
            }

            return [
                'written' => $written,
                'unmatched' => $unmatched,
                'not_updated' => $notUpdated,
                'message' => $message,
            ];
        });
    }

    /**
     * @param  list<int>  $stageIds
     * @param  array<int, list<array<string, mixed>>>  $stagesByShooter
     * @return array<int, int>
     */
    private function dayMapForImport(MatchEvent $match, array $stageIds, array $stagesByShooter): array
    {
        $onDay1 = $match->match_date !== null
            && now('Africa/Johannesburg')->toDateString() <= $match->match_date->toDateString();
        $saved = $this->normalizeMap($match->impact_stage_days);
        $coversAll = $saved !== [] && array_diff($stageIds, array_keys($saved)) === [];
        if (! $onDay1 && $coversAll && in_array(1, $saved, true)) {
            $map = [];
            foreach ($stageIds as $stageId) {
                $map[$stageId] = $saved[$stageId];
            }

            return $map;
        }

        $shot = [];
        foreach ($stagesByShooter as $rows) {
            foreach ($rows as $row) {
                $stageId = (int) ($row['id'] ?? 0);
                if ($stageId <= 0) {
                    continue;
                }
                if (($row['completed'] ?? false) || (int) ($row['shots'] ?? 0) > 0) {
                    $shot[$stageId] = true;
                }
            }
        }

        $map = [];
        foreach ($stageIds as $stageId) {
            $map[$stageId] = isset($shot[$stageId]) ? 1 : 2;
        }

        if (! $onDay1 && ! in_array(2, $map, true)) {
            throw new RuntimeException($this->nothingWritten(
                'Every stage is already finished. Mark which stages were day 1 before importing.'
            ));
        }

        return $map;
    }

    /**
     * @param  array{written:int,unmatched:list<string>,not_updated:list<string>,message:string}  $result
     * @return array{written:int,unmatched:list<string>,not_updated:list<string>,message:string}
     */
    private function refreshStandings(MatchEvent $match, array $result): array
    {
        try {
            $sibling = $match->fresh()?->provincialDay1Match;
            if ($sibling) {
                $this->standings->recalculateForMatch($sibling);
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $result;
    }

    /**
     * @param  array<int, float>  $hits
     * @param  list<int>  $stageIds
     */
    private function sumHits(array $hits, array $stageIds): float
    {
        $sum = 0.0;
        foreach ($stageIds as $stageId) {
            $sum += (float) ($hits[$stageId] ?? 0);
        }

        return $sum;
    }

    private function deleteSiblingScore(MatchEvent $sibling, int $userId): void
    {
        $score = Score::query()
            ->where('match_id', $sibling->id)
            ->where('user_id', $userId)
            ->first();
        if (! $score) {
            return;
        }

        ShooterLog::where('score_id', $score->id)->delete();
        $score->delete();
    }

    /**
     * @param  list<int>  $keepUserIds
     */
    private function deleteScoresOutside(MatchEvent $sibling, array $keepUserIds): void
    {
        $query = Score::query()->where('match_id', $sibling->id);
        if ($keepUserIds !== []) {
            $query->where(function ($inner) use ($keepUserIds) {
                $inner->whereNull('user_id')->orWhereNotIn('user_id', $keepUserIds);
            });
        }

        $ids = $query->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }

        ShooterLog::whereIn('score_id', $ids)->delete();
        Score::whereIn('id', $ids)->delete();
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

    /**
     * @param  Collection<int, MatchRegistration>  $registrations
     */
    private function resolveRegistration(MatchEvent $match, string $impactName, Collection $registrations): ?MatchRegistration
    {
        $candidates = $registrations->map(fn (MatchRegistration $registration) => [
            'key' => 'reg:'.$registration->id,
            'name' => $registration->user?->name ?: (string) $registration->shooter_name,
        ])->all();
        $byKey = $registrations->keyBy(fn (MatchRegistration $registration) => 'reg:'.$registration->id);

        $nameMatch = $this->names->match($impactName, $candidates);
        if ($nameMatch['status'] === 'exact' && isset($nameMatch['key'])) {
            $registration = $byKey->get($nameMatch['key']);
            if ($registration) {
                return $registration;
            }
        }

        return $this->mappedRegistration($match, $impactName, $registrations);
    }

    /**
     * @param  Collection<int, MatchRegistration>  $registrations
     */
    private function mappedRegistration(MatchEvent $match, string $impactName, Collection $registrations): ?MatchRegistration
    {
        $map = $match->impact_live_name_map ?? [];
        if (! is_array($map) || $impactName === '') {
            return null;
        }

        $needle = $this->normalizeMapKey($impactName);
        foreach ($map as $storedName => $registrationId) {
            if ($this->normalizeMapKey((string) $storedName) === $needle) {
                return $registrations->first(
                    fn (MatchRegistration $registration) => (int) $registration->id === (int) $registrationId
                );
            }
        }

        return null;
    }

    private function normalizeMapKey(string $value): string
    {
        return Str::lower(trim((string) preg_replace('/\s+/', ' ', $value)));
    }

    private function nothingWritten(string $reason): string
    {
        $reason = rtrim($reason);
        if (str_contains($reason, 'Nothing was written')) {
            return $reason;
        }

        return $reason.' Nothing was written.';
    }
}
