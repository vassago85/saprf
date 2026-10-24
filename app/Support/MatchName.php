<?php

namespace App\Support;

/**
 * Rewrites free-text match names into a canonical shape so the site uses one
 * spelling for everything shooters see. Canonical form:
 *
 *   - "Centrefire" (one word, British spelling) — regardless of how the match
 *     director typed it: "Centre Fire", "CentreFire", "CenterFire", or
 *     "Centerfire" all collapse to "Centrefire".
 *   - Durations use "N-Day" with a hyphen and a capital D: "1-Day", "2-Day".
 *   - Day labels use "Day N" with a space and a capital D: "Day 1".
 *   - Collapses runs of whitespace that scraped names often carry over.
 *
 * The rest of the title is left alone — a creative name like "Hippo Brawl 2026"
 * stays as the match director wrote it. Applied by the create/update form
 * requests and the scraped-PRS importer so the normalisation runs on every
 * write path, not after the fact.
 */
class MatchName
{
    public static function normalize(?string $name): string
    {
        $name = (string) $name;
        $name = trim($name);

        if ($name === '') {
            return '';
        }

        // "Centre Fire", "CentreFire", "Centre-Fire", "CenterFire", "Centerfire"
        // → "Centrefire". The spelling is "centre" (British) and one word.
        $name = preg_replace('/\bcent(?:re|er)[\s\-]*fire\b/i', 'Centrefire', $name) ?? $name;

        // "1day", "1 day", "1-day" → "1-Day" (same for 2, 3, ...).
        $name = preg_replace_callback(
            '/\b(\d+)[\s\-]*day\b/i',
            fn ($m) => $m[1].'-Day',
            $name
        ) ?? $name;

        // "day1", "day 1", "day-1" → "Day 1".
        $name = preg_replace_callback(
            '/\bday[\s\-]*(\d+)\b/i',
            fn ($m) => 'Day '.$m[1],
            $name
        ) ?? $name;

        // Collapse any run of whitespace that the rewrites may have left and
        // that scraped names often carry over.
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return trim($name);
    }
}
