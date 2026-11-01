<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Single source of truth for comparing shooter names across CSV imports,
 * fuzzy matching, and admin reports.
 *
 * Historically the same strtolower + trim + preg_replace('/\s+/', ' ', …)
 * recipe was open-coded in at least five services (ScoreImportService,
 * ImpactLiveImportService, CsvScoreAssignmentService, GuestShooterService,
 * every seeder command). Any drift between those copies caused real false
 * negatives — e.g. "Terblanche De Jager" and "Terblanche de Jager" would
 * canonicalise identically only when every call-site remembered to apply
 * the exact same transforms. Routing every comparison through here keeps
 * them in lock-step.
 */
final class NameNormalizer
{
    private function __construct() {}

    /**
     * Canonical form used for equality comparisons between shooter names.
     * Lower-cases, strips surrounding whitespace, collapses interior
     * runs of whitespace to a single space, and drops the handful of
     * punctuation characters we routinely see in CSV exports.
     */
    public static function canonical(?string $raw): string
    {
        if ($raw === null) {
            return '';
        }

        // Normalise unicode so visually-equal names (e.g. an accented é
        // encoded as a single code point vs e + combining acute) compare
        // as equal. Fall back gracefully if the intl ext is unavailable.
        $trimmed = trim($raw);
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($trimmed, \Normalizer::FORM_C);
            if (is_string($normalized)) {
                $trimmed = $normalized;
            }
        }

        $lower = mb_strtolower($trimmed, 'UTF-8');

        // Strip the punctuation that routinely drifts between source
        // systems — periods after initials, commas in "Last, First",
        // underscores and double-hyphens in generated usernames. A single
        // hyphen inside a surname (Van-Der-Merwe) is preserved so we
        // don't collapse two genuinely distinct names together.
        $lower = (string) preg_replace('/[,._]+/u', ' ', $lower);

        // Collapse any run of whitespace (incl. non-breaking space) to one.
        $lower = (string) preg_replace('/\s+/u', ' ', $lower);

        return trim($lower);
    }

    /**
     * Convenience: are two raw names considered the same shooter under
     * the canonical rule? Returns false when either side is empty so a
     * blank CSV cell can never accidentally match an existing user.
     */
    public static function equals(?string $a, ?string $b): bool
    {
        $ca = self::canonical($a);
        $cb = self::canonical($b);

        return $ca !== '' && $ca === $cb;
    }
}
