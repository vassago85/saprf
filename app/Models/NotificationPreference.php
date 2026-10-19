<?php

namespace App\Models;

use App\Enums\AnnouncementCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id',
        'muted_email_categories',
        'push_enabled',
        'notify_own_match_scores',
        'notify_all_prs_scores',
        'notify_all_pr22_scores',
        'score_prefs_configured_at',
    ];

    protected function casts(): array
    {
        return [
            'muted_email_categories' => 'array',
            'push_enabled' => 'boolean',
            'notify_own_match_scores' => 'boolean',
            'notify_all_prs_scores' => 'boolean',
            'notify_all_pr22_scores' => 'boolean',
            'score_prefs_configured_at' => 'datetime',
        ];
    }

    /**
     * Default DB-level values for the three score toggles. Mirrors the
     * migration defaults so a brand-new row (or a user with no prefs
     * row yet) behaves predictably: own-match on, series feeds off.
     *
     * Used by the one-time prompt + the audience resolver when a user
     * has not yet configured prefs.
     */
    public const DEFAULT_SCORE_PREFS = [
        'notify_own_match_scores' => true,
        'notify_all_prs_scores' => false,
        'notify_all_pr22_scores' => false,
    ];

    /**
     * The post-login prompt fires once per user. We treat "never saved"
     * (`score_prefs_configured_at` is null) as "ask me". Saving the
     * profile prefs form also stamps the column — hitting Save with the
     * defaults still counts as "I made a choice", so we never nag again.
     */
    public function hasConfiguredScorePrefs(): bool
    {
        return $this->score_prefs_configured_at !== null;
    }

    public function wantsOwnMatchScoreNotifications(): bool
    {
        return (bool) $this->notify_own_match_scores;
    }

    public function wantsSeriesScoreNotifications(string $matchType): bool
    {
        return match (strtoupper($matchType)) {
            'PRS' => (bool) $this->notify_all_prs_scores,
            'PR22' => (bool) $this->notify_all_pr22_scores,
            default => false,
        };
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Should we send an email for this category to this user?
     * Mandatory categories (Policy change, Urgent) bypass mutes.
     */
    public function allowsEmailFor(AnnouncementCategory $category): bool
    {
        if ($category->isMandatory()) {
            return true;
        }

        $muted = $this->muted_email_categories ?? [];

        return ! in_array($category->value, $muted, true);
    }

    /**
     * Should we send a push notification for this category to this user?
     * Mandatory categories still fire even when push_enabled is false
     * (Urgent is the whole point of the channel).
     */
    public function allowsPushFor(AnnouncementCategory $category): bool
    {
        if ($category->isMandatory()) {
            return true;
        }

        return (bool) $this->push_enabled;
    }
}
