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

        // Extra given name on the scoreboard: "Petrus Bosman Le Roux" is
        // "Petrus Le Roux" when that is the only entry contained in the name.
        $contained = [];
        foreach ($candidates as $c) {
            $candidateTokens = $this->tokens($this->normalize($c['name']));
            if (count($candidateTokens) < 2 || count($needleTokens) < 2) {
                continue;
            }
            if ($candidateTokens === $needleTokens) {
                continue;
            }
            $shorter = count($candidateTokens) < count($needleTokens) ? $candidateTokens : $needleTokens;
            $longer = count($candidateTokens) < count($needleTokens) ? $needleTokens : $candidateTokens;
            if (count($shorter) < 2) {
                continue;
            }
            if (array_diff($shorter, $longer) === []) {
                $contained[] = $c;
            }
        }
        if (count($contained) === 1) {
            return ['status' => 'exact', 'key' => $contained[0]['key'], 'score' => 97.0, 'candidates' => []];
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
        // Rörich and Rorich are the same shooter.
        $name = Str::ascii($name);
        // O'Neill and Oneill are the same shooter.
        $name = str_replace(["'", '’', '`'], '', $name);
        // Kim-Leigh and Kim Leigh are the same shooter.
        $name = str_replace(['-', '–', '—'], ' ', $name);
        // Strip common honorifics
        $name = preg_replace('/\b(snr|jnr|sr|jr|ii|iii)\b\.?/u', '', $name) ?? $name;
        // "Le Roux" and "Leroux", "Van der Merwe" and "Vandermerwe".
        $particles = 'le|la|van|von|de|du|da|den|der|ten|ter';
        $previous = '';
        while ($previous !== $name) {
            $previous = $name;
            $name = preg_replace('/\b('.$particles.')\s+(?=\S)/u', '$1', $name) ?? $name;
        }

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
            $shorter = strlen($aFirst) <= strlen($bFirst) ? $aFirst : $bFirst;
            $longer = strlen($aFirst) <= strlen($bFirst) ? $bFirst : $aFirst;
            $sameFirst = (str_starts_with($longer, $shorter) || (strlen($shorter) >= 4 && str_contains($longer, $shorter)))
                && strlen($shorter) >= 3;
            if ($aSur === $bSur && $sameFirst) {
                $percent = max($percent, 88.0);
            }
        }

        return (float) $percent;
    }
}
