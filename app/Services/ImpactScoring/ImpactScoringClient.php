<?php

namespace App\Services\ImpactScoring;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for Impact Scoring's public website REST API
 * (https://app-api.impactscoring.net). No auth required for live results.
 */
class ImpactScoringClient
{
    public const API_BASE = 'https://app-api.impactscoring.net';

    public function __construct(
        private readonly ?PendingRequest $http = null,
    ) {}

    /**
     * Extract a numeric Impact match id from a bare id or the match's own page.
     * Accepted links are only the match itself:
     * https://www.impactscoring.net/match/view?id=10558
     * https://www.impactscoring.net/match/results?id=11086
     */
    public function parseMatchId(string $input): int
    {
        $input = trim($input);
        if ($input === '') {
            throw new RuntimeException('Impact match id or URL is required.');
        }

        if (ctype_digit($input)) {
            return (int) $input;
        }

        $parts = parse_url($input);
        $host = strtolower((string) (is_array($parts) ? ($parts['host'] ?? '') : ''));
        $host = (string) preg_replace('/^www\./', '', $host);
        $path = strtolower(rtrim((string) (is_array($parts) ? ($parts['path'] ?? '') : ''), '/'));
        parse_str((string) (is_array($parts) ? ($parts['query'] ?? '') : ''), $query);
        $id = $query['id'] ?? null;
        $isImpact = $host === '' || $host === 'impactscoring.net';
        $isMatchPage = in_array($path, ['/match/view', '/match/results'], true);

        if ($isImpact && $isMatchPage && is_scalar($id) && ctype_digit((string) $id)) {
            return (int) $id;
        }

        if ($isImpact && preg_match('#^/match/(?:view|results)/(\d+)$#', $path, $matches)) {
            return (int) $matches[1];
        }

        throw new RuntimeException('Paste the Impact match link (/match/view?id=…) or the match number.');
    }

    /**
     * @return array{match: array, shooters: list<array>, stages: list<array>, divisions: list<array>}
     */
    public function getLiveScores(int $impactMatchId): array
    {
        $response = $this->client()->get('/rest/website/getLiveScores', [
            'matchId' => $impactMatchId,
            'dividePoints' => 'false',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Impact API getLiveScores failed (HTTP {$response->status()}) for match {$impactMatchId}."
            );
        }

        $json = $response->json();
        if (! is_array($json) || (int) ($json['errorCode'] ?? -1) !== 0 || empty($json['payload'])) {
            $label = is_array($json) ? ($json['errorLabel'] ?? 'unknown') : 'invalid';
            throw new RuntimeException("Impact API returned no payload for match {$impactMatchId} ({$label}).");
        }

        $payload = $json['payload'];

        return [
            'match' => $payload['match'] ?? [],
            'shooters' => array_values($payload['shooters'] ?? []),
            'stages' => array_values($payload['stages'] ?? []),
            'divisions' => array_values($payload['divisions'] ?? []),
            'categories' => array_values($payload['categories'] ?? []),
        ];
    }

    /**
     * Per-shooter stage breakdown (hits/misses/complete).
     *
     * @return list<array{
     *   id:int,name:string,hits:float,misses:int,shots:int,max_shots:int,
     *   completed:bool,points:float,points_raw:int
     * }>
     */
    public function getShooterStages(int $impactMatchId, int $impactShooterId): array
    {
        $response = $this->client()->get('/rest/website/getLiveScoresForShooter', [
            'matchId' => $impactMatchId,
            'shooterId' => $impactShooterId,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Impact API getLiveScoresForShooter failed (HTTP {$response->status()})."
            );
        }

        return $this->normalizeShooterStages($response->json());
    }

    /**
     * Fetch stage breakdowns for many shooters.
     * Sequential on purpose: Impact's API is fine at this volume, and it
     * keeps Http::fake in tests honest (pool + wildcard fakes are flaky).
     *
     * @param  list<int>  $impactShooterIds
     * @return array<int, list<array>>
     */
    public function getShooterStagesMany(int $impactMatchId, array $impactShooterIds): array
    {
        $impactShooterIds = array_values(array_unique(array_filter(array_map('intval', $impactShooterIds))));
        $out = [];
        foreach ($impactShooterIds as $shooterId) {
            try {
                $out[$shooterId] = $this->getShooterStages($impactMatchId, $shooterId);
            } catch (\Throwable) {
                $out[$shooterId] = [];
            }
        }

        return $out;
    }

    /**
     * @return list<array{
     *   id:int,name:string,hits:float,misses:int,shots:int,max_shots:int,
     *   completed:bool,points:float,points_raw:int
     * }>
     */
    private function normalizeShooterStages(mixed $json): array
    {
        if (! is_array($json) || (int) ($json['errorCode'] ?? -1) !== 0 || empty($json['payload']['stages'])) {
            return [];
        }

        $stages = [];
        foreach ($json['payload']['stages'] as $stage) {
            $pointsRaw = (int) ($stage['points'] ?? 0);
            $hits = $pointsRaw / 100;
            $stages[] = [
                'id' => (int) ($stage['id'] ?? 0),
                'name' => (string) ($stage['name'] ?? 'Stage'),
                'hits' => $hits,
                'misses' => (int) ($stage['misses'] ?? 0),
                'shots' => (int) ($stage['shotsProgressCount'] ?? 0),
                'max_shots' => (int) ($stage['maxShots'] ?? 0),
                'completed' => (bool) ($stage['completed'] ?? false),
                'points' => $hits,
                'points_raw' => $pointsRaw,
            ];
        }

        return $stages;
    }

    private function client(): PendingRequest
    {
        return ($this->http ?? Http::baseUrl(self::API_BASE))
            ->acceptJson()
            ->timeout(45)
            ->retry(2, 250);
    }
}
