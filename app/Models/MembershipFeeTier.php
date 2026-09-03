<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class MembershipFeeTier extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'price',
        'duration_months',
        'display_order',
        'is_active',
        'is_default',
        'min_age',
        'max_age',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration_months' => 'integer',
            'display_order' => 'integer',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'min_age' => 'integer',
            'max_age' => 'integer',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'fee_tier_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order')->orderBy('name');
    }

    /**
     * The tier that drives the fallback price and the pre-selected option on
     * the join page: the flagged default if present, otherwise the first
     * active tier by display order.
     */
    public static function defaultTier(): ?self
    {
        return static::query()->active()->where('is_default', true)->ordered()->first()
            ?? static::query()->active()->ordered()->first();
    }

    /**
     * Is this tier available to a subject whose age is `$age` today? A null
     * `$age` (subject with no DOB on file) matches only unrestricted tiers
     * — we won't guess someone into a discount they might not qualify for.
     */
    public function isAvailableForAge(?int $age): bool
    {
        if ($this->min_age !== null && ($age === null || $age < $this->min_age)) {
            return false;
        }

        if ($this->max_age !== null && ($age === null || $age > $this->max_age)) {
            return false;
        }

        return true;
    }

    /**
     * Active tiers the subject qualifies for today, ordered for the picker.
     * When the subject has no DOB, age-restricted tiers are excluded — the
     * caller is expected to redirect the user to set a DOB before applying.
     *
     * @return Collection<int, self>
     */
    public static function availableForUser(User $subject): Collection
    {
        $age = $subject->date_of_birth ? $subject->getAgeOn(now()) : null;

        return static::query()
            ->active()
            ->ordered()
            ->get()
            ->filter(fn (self $tier) => $tier->isAvailableForAge($age))
            ->values();
    }

    /**
     * Server-side authorization for a directly-POSTed `fee_tier_id`: is
     * `$tierId` an active tier the subject is old/young enough for? Closes
     * the direct-POST bypass where the client hides the radio but a curl
     * request submits any tier id.
     */
    public static function isTierAllowedForUser(int $tierId, User $subject): bool
    {
        $tier = static::query()->active()->find($tierId);

        if (! $tier) {
            return false;
        }

        $age = $subject->date_of_birth ? $subject->getAgeOn(now()) : null;

        return $tier->isAvailableForAge($age);
    }

    /**
     * Lowest-priced tier the subject qualifies for today, or null when the
     * subject qualifies for nothing (e.g. missing DOB). Prefer
     * preferredForUser() for join/renew UI and unpaid auto-pick.
     */
    public static function cheapestForUser(User $subject): ?self
    {
        return static::availableForUser($subject)
            ->sortBy(fn (self $tier) => (float) $tier->price)
            ->first();
    }

    /**
     * Tier to pre-select / auto-charge when the applicant doesn't choose:
     * the flagged default among age-eligible active tiers (Adult), otherwise
     * the cheapest eligible tier (Junior-only applicants, etc.).
     */
    public static function preferredForUser(User $subject): ?self
    {
        $available = static::availableForUser($subject);

        if ($available->isEmpty()) {
            return null;
        }

        $default = $available->first(fn (self $tier) => $tier->is_default);

        if ($default) {
            return $default;
        }

        return $available
            ->sortBy(fn (self $tier) => (float) $tier->price)
            ->first();
    }
}
