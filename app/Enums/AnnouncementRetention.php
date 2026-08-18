<?php

namespace App\Enums;

/**
 * How long an announcement lives in the member-facing Communications
 * view. Every announcement has exactly one retention mode; it drives
 * the Inbox vs Archive split and the "match ended, get this out of my
 * face" behaviour of MD bulletins.
 *
 *   Permanent       — Policy change, AGM/governance, Urgent. Always
 *                     visible in the Archive tab; shown in the Inbox
 *                     tab while `sent_at >= now - 60 days` so it
 *                     doesn't clutter the current view forever.
 *
 *   ExpiresOnDate   — Routine Announcement, Match calendar, Platform
 *                     update. Shown in Inbox while `expires_at` is in
 *                     the future (or null = no set expiry). Once
 *                     expired, drops off Inbox but stays searchable
 *                     in Archive.
 *
 *   MatchScoped    — MD bulletins tied to a specific match. Visible
 *                     everywhere while the linked match's status is
 *                     `open`, `draft`, or `closed`. The moment the
 *                     match transitions to `completed` or `cancelled`
 *                     the row vanishes from BOTH Inbox and Archive —
 *                     "as soon as the match is finished then they
 *                     must go away" was the explicit product ask.
 *
 * Each `AnnouncementCategory` has a `defaultRetention()`; the composer
 * may allow the operator to override it per-message for the routine
 * categories, but Policy/Urgent/MatchBulletin are pinned.
 */
enum AnnouncementRetention: string
{
    case Permanent = 'permanent';
    case ExpiresOnDate = 'expires_on_date';
    case MatchScoped = 'match_scoped';

    public function label(): string
    {
        return match ($this) {
            self::Permanent => 'Permanent (kept in Archive forever)',
            self::ExpiresOnDate => 'Expires on date (moves to Archive after the expiry date)',
            self::MatchScoped => 'Match-scoped (removed when the match finishes)',
        };
    }

    /**
     * @return array<string, string> value => label, ready for `<select>`.
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $c) => [$c->value => $c->label()])
            ->all();
    }
}
