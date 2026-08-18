<?php

namespace App\Enums;

/**
 * Announcement categories drive iconography, whether members can mute the
 * category, and whether an acknowledgement is required.
 *
 * `policy_change` and `urgent` are *mandatory* — they ignore per-member mute
 * preferences on every non-database channel. Every other category can be
 * silenced by the recipient via their profile.
 */
enum AnnouncementCategory: string
{
    case PolicyChange = 'policy_change';
    case Announcement = 'announcement';
    case MatchCalendar = 'match_calendar';
    case AgmGovernance = 'agm_governance';
    case PlatformUpdate = 'platform_update';
    case Urgent = 'urgent';
    case MatchBulletin = 'match_bulletin';

    public function label(): string
    {
        return match ($this) {
            self::PolicyChange => 'Policy change',
            self::Announcement => 'Announcement',
            self::MatchCalendar => 'Match / calendar',
            self::AgmGovernance => 'AGM / governance',
            self::PlatformUpdate => 'Website / platform update',
            self::Urgent => 'Urgent',
            self::MatchBulletin => 'Match bulletin',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::PolicyChange => 'shield-check',
            self::Announcement => 'megaphone',
            self::MatchCalendar => 'calendar-days',
            self::AgmGovernance => 'building-library',
            self::PlatformUpdate => 'sparkles',
            self::Urgent => 'exclamation-triangle',
            self::MatchBulletin => 'chat-bubble-left-right',
        };
    }

    /**
     * Mandatory categories cannot be muted and always attempt every channel
     * the recipient can receive on. Members can opt out of everything else
     * from their profile.
     */
    public function isMandatory(): bool
    {
        return match ($this) {
            self::PolicyChange, self::Urgent => true,
            default => false,
        };
    }

    /**
     * Whether the composer should default `requires_acknowledgement` to true
     * for this category. Policy changes have disciplinary / eligibility
     * consequences, so they get a receipt trail by default.
     */
    public function defaultRequiresAcknowledgement(): bool
    {
        return $this === self::PolicyChange;
    }

    /**
     * Whether composing this category requires a second Exco/Chair approver
     * when the author is Exco but not Chair.
     */
    public function requiresSecondApproval(): bool
    {
        return $this === self::PolicyChange;
    }

    /**
     * How long an announcement of this category should stick around in
     * the member-facing inbox by default. The composer surfaces this as
     * the pre-selected value on the Retention dropdown — for routine
     * categories the operator can override, for pinned categories
     * (Policy / Urgent / MatchBulletin) the retention is enforced
     * server-side.
     *
     *   PolicyChange, Urgent, AgmGovernance  → permanent
     *   Announcement, MatchCalendar,
     *   PlatformUpdate                        → expires_on_date
     *   MatchBulletin                         → match_scoped
     */
    public function defaultRetention(): AnnouncementRetention
    {
        return match ($this) {
            self::PolicyChange,
            self::Urgent,
            self::AgmGovernance => AnnouncementRetention::Permanent,

            self::MatchBulletin => AnnouncementRetention::MatchScoped,

            self::Announcement,
            self::MatchCalendar,
            self::PlatformUpdate => AnnouncementRetention::ExpiresOnDate,
        };
    }

    /**
     * Categories whose retention the composer must NOT let the operator
     * change. Match bulletins have to be match-scoped or the match-end
     * "go away" behaviour breaks; Policy/Urgent stay permanent so a
     * critical safety notice isn't accidentally set to expire.
     */
    public function retentionIsFixed(): bool
    {
        return match ($this) {
            self::PolicyChange, self::Urgent, self::MatchBulletin => true,
            default => false,
        };
    }

    /**
     * @return array<string, string> value => label, ready for `<select>` options.
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $c) => [$c->value => $c->label()])
            ->all();
    }
}
