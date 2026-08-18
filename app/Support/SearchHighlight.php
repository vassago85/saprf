<?php

namespace App\Support;

/**
 * Wrap every query-token match in the given text with a <mark> tag,
 * without ever letting user input become raw HTML.
 *
 * The two-step design is important:
 *   1. htmlspecialchars() the whole input first, so <, > and & from
 *      section body text can't inject HTML.
 *   2. Then run a case-insensitive regex over the *escaped* string,
 *      wrapping only tokens that are pure ASCII word chars (already
 *      filtered upstream by DocumentSearch::tokenize()).
 *
 * This makes it safe to output the result via Blade's `{!! !!}`
 * unescaped print.
 */
class SearchHighlight
{
    /**
     * @param  array<int, string>  $tokens  lowercase ASCII word tokens
     */
    public static function apply(string $text, array $tokens): string
    {
        $safe = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($tokens === [] || $safe === '') {
            return $safe;
        }

        // Longest-first so overlapping tokens don't wreck each other.
        usort($tokens, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $patterns = [];
        foreach ($tokens as $t) {
            $t = trim($t);
            if ($t === '' || ! preg_match('/^[A-Za-z0-9]+$/', $t)) {
                // DocumentSearch guarantees ASCII word tokens; this is a
                // defence-in-depth guard against any future caller that
                // hands us something exotic.
                continue;
            }
            $patterns[] = preg_quote($t, '/');
        }
        if ($patterns === []) {
            return $safe;
        }

        $regex = '/(' . implode('|', $patterns) . ')/iu';
        return preg_replace($regex, '<mark class="bg-yellow-200 text-stone-900 rounded px-0.5">$1</mark>', $safe) ?? $safe;
    }
}
