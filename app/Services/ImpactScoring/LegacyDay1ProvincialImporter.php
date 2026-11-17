<?php

namespace App\Services\ImpactScoring;

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Score;
use App\Models\ShooterLog;
use App\Models\User;
use App\Services\ScoreValidationService;
use App\Services\StandingsCalculationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Build a day-1 provincial from an old 2-day national whose Impact stages
 * are only numbered, and treat every shooter as a paid member on the day.
 * The national's combined total is left untouched.
 */
class LegacyDay1ProvincialImporter
{
    public function __construct(
        private readonly ImpactScoringClient $client,
        private readonly ImpactNameMatcher $names,
        private readonly ScoreValidationService $scoreValidation,
        private readonly StandingsCalculationService $standings,
    ) {}

    /**
     * @return array{
     *   sibling_id:int,
     *   sibling_name:string,
     *   day1_stages:list<string>,
     *   written:int,
     *   shortened:list<string>,
     *   non_members:list<string>,
     *   unmatched:list<string>
     * }
     */
    public function import(MatchEvent $national, int $impactMatchId, int $day1StageCount): array
    {
        if (! $national->isTwoDayNational()) {
            throw new InvalidArgumentException('Day 1 can only be posted from a 2-day national.');
        }
        if ($impactMatchId < 1 || $day1StageCount < 1) {
            throw new InvalidArgumentException('Impact match id and day-1 stage count are required.');
        }

        $live = $this->client->getLiveScores($impactMatchId);
        $stages = $this->orderedStages($live['stages'] ?? []);
        if (count($stages) < $day1StageCount) {
            throw new InvalidArgumentException(
                'Impact has '.count($stages).' stages, which is fewer than the '.$day1StageCount.' day-1 stages.'
            );
        }

        $day1 = array_slice($stages, 0, $day1StageCount);
        $day1Ids = array_map(fn (array $stage): int => $stage['id'], $day1);
        $stageDays = [];
        foreach ($stages as $index => $stage) {
            $stageDays[$stage['id']] = $index < $day1StageCount ? 1 : 2;
        }

        $shooterIds = [];
        foreach ($live['shooters'] as $shooter) {
            $shooterId = (int) ($shooter['id'] ?? 0);
            if ($shooterId > 0) {
                $shooterIds[] = $shooterId;
            }
        }
        $stagesByShooter = $this->client->getShooterStagesMany($impactMatchId, $shooterIds);

        $result = DB::transaction(function () use ($national, $impactMatchId, $live, $stagesByShooter, $day1, $day1Ids, $stageDays) {
            $national->forceFill([
                'impact_stage_days' => $stageDays,
                'impact_match_id' => $national->impact_match_id ?: $impactMatchId,
            ])->save();

            $sibling = $national->findOrCreateProvincialDay1Sibling();
            $sibling->forceFill([
                'everyone_counts' => true,
                'description' => 'Day-1 provincial from a legacy 2-day national. Members count even if the paid window does not cover the day. Someone who is not a member stays a non-member.',
            ])->save();

            $nationalScores = Score::query()
                ->where('match_id', $national->id)
                ->whereNotNull('user_id')
                ->get();
            $scoreCandidates = [];
            foreach ($nationalScores as $score) {
                $scoreCandidates[] = [
                    'key' => (string) $score->id,
                    'name' => (string) $score->shooter_name,
                ];
            }

            $written = 0;
            $keep = [];
            $shortened = [];
            $nonMembers = [];
            $unmatched = [];

            foreach ($live['shooters'] as $shooter) {
                $impactName = $this->cleanName((string) ($shooter['name'] ?? ''));
                if ($impactName === '') {
                    continue;
                }

                $shooterId = (int) ($shooter['id'] ?? 0);
                $breakdown = $stagesByShooter[$shooterId] ?? [];
                $sum = $this->sumHits($breakdown, $day1Ids);
                if ($sum <= 0) {
                    continue;
                }

                $linked = $this->linkToNationalScore($impactName, $scoreCandidates, $nationalScores);
                $userId = $linked['user_id'];
                $divisionId = $linked['division_id'];
                $displayName = $linked['display_name'] ?: $impactName;
                if ($linked['shortened']) {
                    $shortened[] = $impactName.' → '.$displayName;
                }

                if ($userId === null) {
                    $existing = $this->linkToExistingUser($impactName);
                    if ($existing !== null) {
                        $userId = $existing;
                        $displayName = User::query()->whereKey($userId)->value('name') ?: $impactName;
                        if ($this->names->normalize($displayName) !== $this->names->normalize($impactName)) {
                            $shortened[] = $impactName.' → '.$displayName;
                        }
                    }
                }

                if ($userId === null) {
                    $divisionId = $this->divisionId((string) ($shooter['divisionName'] ?? ''));
                    $userId = $this->createShooterName(
                        $impactName,
                        (int) $national->province_id,
                        $divisionId,
                    );
                }

                $this->releaseImportMembership($userId);

                if ($divisionId === null) {
                    $divisionId = $this->divisionId((string) ($shooter['divisionName'] ?? ''));
                }

                if (in_array($userId, $keep, true)) {
                    $unmatched[] = $impactName;

                    continue;
                }

                $score = Score::firstOrNew([
                    'match_id' => $sibling->id,
                    'user_id' => $userId,
                ]);
                $score->fill([
                    'match_id' => $sibling->id,
                    'user_id' => $userId,
                    'division_id' => $divisionId,
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
                            'legacy_day1' => true,
                            'match_id' => $impactMatchId,
                            'shooter_id' => $shooterId,
                            'impact_name' => $impactName,
                            'day1_stage_ids' => $day1Ids,
                            'day1_impacts' => $sum,
                        ],
                    ],
                ]);
                $score->save();
                $evaluated = $this->scoreValidation->evaluateScoreStatus($score->fresh(['match']));
                if ($evaluated->status === 'non_member') {
                    $nonMembers[] = $displayName;
                }
                $keep[] = $userId;
                $written++;
            }

            if ($written === 0) {
                throw new RuntimeException('Impact returned no day-1 scores. Nothing was written.');
            }

            $this->deleteScoresOutside($sibling, $keep);

            return [
                'sibling_id' => $sibling->id,
                'sibling_name' => $sibling->name,
                'written' => $written,
                'shortened' => $shortened,
                'non_members' => $nonMembers,
                'unmatched' => array_values(array_unique($unmatched)),
            ];
        });

        $sibling = MatchEvent::query()->findOrFail($result['sibling_id']);
        $this->standings->recalculateForMatch($sibling);

        $result['day1_stages'] = array_map(fn (array $stage): string => $stage['name'], $day1);

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $stages
     * @return list<array{id:int,name:string}>
     */
    private function orderedStages(array $stages): array
    {
        $ordered = [];
        foreach ($stages as $stage) {
            $id = (int) ($stage['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $name = trim((string) ($stage['name'] ?? 'Stage'));
            $number = 0;
            if (preg_match('/(\d+)/', $name, $match) === 1) {
                $number = (int) $match[1];
            }
            $ordered[] = ['id' => $id, 'name' => $name, 'number' => $number];
        }

        usort($ordered, function (array $a, array $b): int {
            return [$a['number'], $a['id']] <=> [$b['number'], $b['id']];
        });

        return array_map(fn (array $stage): array => [
            'id' => $stage['id'],
            'name' => $stage['name'],
        ], $ordered);
    }

    /**
     * @param  list<array<string, mixed>>  $breakdown
     * @param  list<int>  $stageIds
     */
    private function sumHits(array $breakdown, array $stageIds): float
    {
        $want = array_flip($stageIds);
        $sum = 0.0;
        foreach ($breakdown as $row) {
            $id = (int) ($row['id'] ?? 0);
            if (isset($want[$id])) {
                $sum += (float) ($row['hits'] ?? 0);
            }
        }

        return $sum;
    }

    /**
     * @param  list<array{key:string,name:string}>  $candidates
     * @param  \Illuminate\Support\Collection<int, Score>  $scores
     * @return array{user_id:?int,division_id:?int,display_name:?string,shortened:bool}
     */
    private function linkToNationalScore(string $impactName, array $candidates, $scores): array
    {
        $empty = ['user_id' => null, 'division_id' => null, 'display_name' => null, 'shortened' => false];
        $key = $this->choose($impactName, $candidates);
        if ($key === null) {
            return $empty;
        }

        $score = $scores->firstWhere('id', (int) $key);
        if ($score === null || $score->user_id === null) {
            return $empty;
        }

        $display = trim((string) $score->shooter_name) ?: $impactName;

        return [
            'user_id' => (int) $score->user_id,
            'division_id' => $score->division_id ? (int) $score->division_id : null,
            'display_name' => $display,
            'shortened' => $this->names->normalize($display) !== $this->names->normalize($impactName),
        ];
    }

    private function linkToExistingUser(string $impactName): ?int
    {
        $surname = preg_replace('/[^a-z]/', '', $this->surname($impactName)) ?? '';
        if (strlen($surname) < 3) {
            return null;
        }

        $users = User::query()
            ->whereRaw('LOWER(name) like ?', ['%'.$surname])
            ->get(['id', 'name']);
        $candidates = [];
        foreach ($users as $user) {
            $candidates[] = ['key' => (string) $user->id, 'name' => (string) $user->name];
        }

        $key = $this->choose($impactName, $candidates);

        return $key === null ? null : (int) $key;
    }

    /**
     * @param  list<array{key:string,name:string}>  $candidates
     */
    private function choose(string $impactName, array $candidates): ?string
    {
        if ($candidates === []) {
            return null;
        }

        $hit = $this->names->match($impactName, $candidates);
        if (($hit['status'] ?? '') === 'exact' && $hit['key'] !== null) {
            return (string) $hit['key'];
        }

        $fits = [];
        foreach ($hit['candidates'] ?? [] as $candidate) {
            if ((float) ($candidate['score'] ?? 0) < 88) {
                continue;
            }
            if (! $this->sameSurnamePrefix($impactName, (string) ($candidate['name'] ?? ''))) {
                continue;
            }
            $fits[] = $candidate;
        }

        if (count($fits) === 1) {
            return (string) $fits[0]['key'];
        }

        return null;
    }

    private function sameSurnamePrefix(string $impactName, string $candidateName): bool
    {
        $impact = preg_split('/\s+/', $this->names->normalize($impactName)) ?: [];
        $candidate = preg_split('/\s+/', $this->names->normalize($candidateName)) ?: [];
        if (count($impact) < 2 || count($candidate) < 2) {
            return false;
        }

        $impactSurname = (string) end($impact);
        $candidateSurname = (string) end($candidate);
        $impactFirst = (string) $impact[0];
        $candidateFirst = (string) $candidate[0];

        return $impactSurname === $candidateSurname
            && (str_starts_with($impactFirst, $candidateFirst) || str_starts_with($candidateFirst, $impactFirst))
            && min(strlen($impactFirst), strlen($candidateFirst)) >= 3;
    }

    private function surname(string $name): string
    {
        $parts = preg_split('/\s+/', $this->names->normalize($name)) ?: [];

        return (string) end($parts);
    }

    private function divisionId(string $impactDivision): ?int
    {
        $key = Str::lower(trim($impactDivision));
        $aliases = [
            'open' => 'open',
            'limited' => 'limited',
            'production' => 'production',
            'ladies' => 'ladies',
            'lady' => 'ladies',
            'junior' => 'junior',
            'juniors' => 'junior',
            'senior' => 'senior',
            'seniors' => 'senior',
        ];
        $slug = $aliases[$key] ?? null;
        if ($slug === null && $key !== '') {
            $slug = Division::query()
                ->whereRaw('LOWER(name) = ?', [$key])
                ->orWhereRaw('LOWER(slug) = ?', [$key])
                ->value('slug');
        }
        if ($slug === null) {
            $slug = 'open';
        }

        return Division::query()->where('slug', $slug)->value('id');
    }

    /**
     * The paid membership stamped on the first import made a non-member count.
     * Drop only that import membership. A real member number is left alone.
     */
    private function releaseImportMembership(int $userId): void
    {
        $user = User::query()->with('membership')->find($userId);
        if ($user === null || ! str_ends_with((string) $user->email, '@import.saprf.local')) {
            return;
        }

        $membership = $user->membership;
        if ($membership === null || ! str_starts_with((string) $membership->saprf_number, 'SAPRF-IMPORT-')) {
            return;
        }

        $membership->delete();
        if ($user->hasRole('member')) {
            $user->removeRole('member');
        }
    }

    private function createShooterName(string $name, int $provinceId, ?int $divisionId): int
    {
        $parts = preg_split('/\s+/', $this->cleanName($name)) ?: [];
        $first = Str::slug((string) ($parts[0] ?? 'shooter')) ?: 'shooter';
        $last = count($parts) > 1 ? Str::slug((string) end($parts)) : '';
        $base = $first.($last !== '' ? '.'.$last : '');
        $email = $base.'@import.saprf.local';
        $n = 1;
        while (User::query()->where('email', $email)->exists()) {
            $email = $base.(++$n).'@import.saprf.local';
        }

        $user = User::create([
            'name' => $this->cleanName($name),
            'email' => $email,
            'password' => Hash::make(Str::random(40)),
            'province_id' => $provinceId,
            'division_id' => $divisionId,
            'is_active' => true,
            'email_verified_at' => null,
            'must_change_password' => false,
        ]);

        return $user->id;
    }

    private function cleanName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', $name) ?? '');
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
}
