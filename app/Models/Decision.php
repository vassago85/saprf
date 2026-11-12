<?php

namespace App\Models;

use App\Enums\DecisionStatus;
use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Decision extends Model
{
    /** Fallback when the site setting is missing or invalid. */
    public const DEFAULT_REACTION_WINDOW_DAYS = 7;

    public const MIN_REACTIONS_FOR_RESULTS = 10;

    protected $fillable = [
        'title',
        'body',
        'reference',
        'status',
        'published_at',
        'created_by',
        'announcement_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => DecisionStatus::class,
            'published_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    public function tally(): HasOne
    {
        return $this->hasOne(DecisionTally::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', DecisionStatus::Published)
            ->whereNotNull('published_at');
    }

    public function isPublished(): bool
    {
        return $this->status === DecisionStatus::Published
            && $this->published_at !== null;
    }

    public function isDraft(): bool
    {
        return $this->status === DecisionStatus::Draft;
    }

    public function reactionWindowDays(): int
    {
        $days = (int) app(SettingsService::class)->get(
            SettingsService::DECISION_REACTION_WINDOW_DAYS,
            self::DEFAULT_REACTION_WINDOW_DAYS,
        );

        return $days >= 1 ? $days : self::DEFAULT_REACTION_WINDOW_DAYS;
    }

    public function reactionWindowClosesAt(): ?Carbon
    {
        if ($this->published_at === null) {
            return null;
        }

        return $this->published_at->copy()->addDays($this->reactionWindowDays());
    }

    public function reactionsAreOpen(): bool
    {
        $closesAt = $this->reactionWindowClosesAt();

        return $this->isPublished()
            && $closesAt !== null
            && now()->lt($closesAt);
    }

    public function totalReactions(): int
    {
        $tally = $this->tally;

        if ($tally === null) {
            return 0;
        }

        return (int) $tally->likes + (int) $tally->dislikes + (int) $tally->neutrals;
    }

    public function hasEnoughReactions(): bool
    {
        return $this->totalReactions() >= self::MIN_REACTIONS_FOR_RESULTS;
    }

    /**
     * Whole-number percentages that always sum to 100 when there is at
     * least one reaction (largest-remainder method). Returns null when
     * there are none.
     *
     * @return array{for: int, against: int, neutral: int}|null
     */
    public function reactionPercentages(): ?array
    {
        $total = $this->totalReactions();

        if ($total === 0) {
            return null;
        }

        $counts = [
            'for' => (int) $this->tally->likes,
            'against' => (int) $this->tally->dislikes,
            'neutral' => (int) $this->tally->neutrals,
        ];

        $floors = [];
        $fracs = [];
        foreach ($counts as $key => $count) {
            $raw = ($count / $total) * 100;
            $floors[$key] = (int) floor($raw);
            $fracs[$key] = $raw - $floors[$key];
        }

        $remaining = 100 - array_sum($floors);
        arsort($fracs);
        foreach (array_keys($fracs) as $key) {
            if ($remaining <= 0) {
                break;
            }
            $floors[$key]++;
            $remaining--;
        }

        return $floors;
    }

    public function memberHasReacted(int $memberId): bool
    {
        return DecisionMember::query()
            ->where('decision_id', $this->id)
            ->where('member_id', $memberId)
            ->exists();
    }
}
