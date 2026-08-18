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

    public function label(): string
    {
        return match ($this) {
            self::PolicyChange => 'Policy change',
            self::Announcement => 'Announcement',
            self::MatchCalendar => 'Match / calendar',
            self::AgmGovernance => 'AGM / governance',
            self::PlatformUpdate => 'Website / platform update',
            self::Urgent => 'Urgent',
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
     * @return array<string, string> value => label, ready for `<select>` options.
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $c) => [$c->value => $c->label()])
            ->all();
    }
}
