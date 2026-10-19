<?php

namespace App\Services\ImpactScoring;

use Illuminate\Support\Str;

/**
 * Match Impact shooter names to SAPRF registration / user names.
 * Exact and token matches are confident; fuzzy suggestions need UI confirmation.
 */
class ImpactNameMatcher
{
    /** Minimum similar_text() percent for a fuzzy suggestion. */
    public const FUZZY_THRESHOLD = 78.0;

    /**
     * @param  list<array{key:string,name:string,label?:string}>  $candidates
     * @return array{status:string,key:?string,score:?float,candidates:list<array{key:string,name:string,score:float}>}
     */
    public function match(string $impactName, array $candidates): array
    {
        $needle = $this->normalize($impactName);
        if ($needle === '' || $candidates === []) {
            return ['status' => 'unmatched', 'key' => null, 'score' => null, 'candidates' => []];
        }

        $exact = [];
        foreach ($candidates as $c) {
            if ($this->normalize($c['name']) === $needle) {
                $exact[] = $c;
            }
        }
        if (count($exact) === 1) {
            return ['status' => 'exact', 'key' => $exact[0]['key'], 'score' => 100.0, 'candidates' => []];
        }

        $token = [];
        $needleTokens = $this->tokens($needle);
        foreach ($candidates as $c) {
            if ($this->tokens($this->normalize($c['name'])) === $needleTokens) {
                $token[] = $c;
            }
        }
        if (count($token) === 1) {
            return ['status' => 'exact', 'key' => $token[0]['key'], 'score' => 99.0, 'candidates' => []];
        }

        // Compact (Van der Merwe ↔ VanderMerwe)
        $needleCompact = str_replace(' ', '', $needle);
        $compact = [];
        foreach ($candidates as $c) {
            if (str_replace(' ', '', $this->normalize($c['name'])) === $needleCompact) {
                $compact[] = $c;
            }
        }
        if (count($compact) === 1) {
            return ['status' => 'exact', 'key' => $compact[0]['key'], 'score' => 98.0, 'candidates' => []];
        }

        $fuzzy = [];
        foreach ($candidates as $c) {
            $score = $this->similarity($needle, $this->normalize($c['name']));
            if ($score >= self::FUZZY_THRESHOLD) {
                $fuzzy[] = [
                    'key' => $c['key'],
                    'name' => $c['name'],
                    'score' => round($score, 1),
                ];
            }
        }

        usort($fuzzy, fn ($a, $b) => $b['score'] <=> $a['score']);

        if ($fuzzy === []) {
            return ['status' => 'unmatched', 'key' => null, 'score' => null, 'candidates' => []];
        }

        // Single strong fuzzy → still needs confirmation
        return [
            'status' => 'fuzzy',
            'key' => null,
            'score' => $fuzzy[0]['score'],
            'candidates' => array_slice($fuzzy, 0, 5),
        ];
    }

    public function normalize(string $name): string
    {
        $name = Str::lower(trim(preg_replace('/\s+/', ' ', $name) ?? ''));
        // Strip common honorifics
        $name = preg_replace('/\b(snr|jnr|sr|jr|ii|iii)\b\.?/u', '', $name) ?? $name;

        return trim(preg_replace('/\s+/', ' ', $name) ?? '');
    }

    /**
     * @return list<string>
     */
    private function tokens(string $normalized): array
    {
        $parts = array_values(array_filter(explode(' ', $normalized)));
        sort($parts);

        return $parts;
    }

    private function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        similar_text($a, $b, $percent);

        // Boost Chris ↔ Christiaan style first-name prefixes when surname matches
        $aParts = explode(' ', $a);
        $bParts = explode(' ', $b);
        if (count($aParts) >= 2 && count($bParts) >= 2) {
            $aSur = end($aParts);
            $bSur = end($bParts);
            $aFirst = $aParts[0];
            $bFirst = $bParts[0];
            if ($aSur === $bSur && (
                str_starts_with($aFirst, $bFirst) || str_starts_with($bFirst, $aFirst)
            ) && min(strlen($aFirst), strlen($bFirst)) >= 3) {
                $percent = max($percent, 88.0);
            }
        }

        return (float) $percent;
    }
}
