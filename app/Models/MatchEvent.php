<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MatchEvent extends Model
{
    protected $table = 'matches';

    protected $appends = ['is_featured'];

    protected $fillable = [
        'name',
        'slug',
        'match_type',
        'series_level',
        'series',
        'season',
        'province_id',
        'venue_name',
        'venue_location',
        'city',
        'match_director',
        'match_director_contact',
        'description',
        'match_date',
        'match_end_date',
        'registration_open_date',
        'registration_close_date',
        'status',
        'created_by',
        'active_member_fee',
        'non_member_fee',
        'lapsed_member_fee',
        'junior_fee',
        'platform_fee_type',
        'platform_fee_value',
        'saprf_fee_type',
        'saprf_fee_value',
        'max_competitors',
        'estimated_shooters',
        'waitlist_enabled',
        'published',
        'also_counts_for_provincial',
        'everyone_counts',
        'provincial_stage_columns',
    ];

    protected function casts(): array
    {
        return [
            'match_date' => 'date',
            'match_end_date' => 'date',
            'registration_open_date' => 'date',
            'registration_close_date' => 'date',
            'active_member_fee' => 'decimal:2',
            'non_member_fee' => 'decimal:2',
            'lapsed_member_fee' => 'decimal:2',
            'junior_fee' => 'decimal:2',
            'platform_fee_value' => 'decimal:2',
            'saprf_fee_value' => 'decimal:2',
            'max_competitors' => 'integer',
            'estimated_shooters' => 'integer',
            'waitlist_enabled' => 'boolean',
            'published' => 'boolean',
            'also_counts_for_provincial' => 'boolean',
            'everyone_counts' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MatchEvent $match) {
            $match->slug = Str::slug($match->name) . '-' . Str::random(5);
        });

        static::saving(function (MatchEvent $match) {
            $match->published = $match->status !== 'draft';
        });
    }

    // ── Relationships ──

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(MatchRegistration::class, 'match_id');
    }

    public function scoreImports(): HasMany
    {
        return $this->hasMany(ScoreImport::class, 'match_id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(Score::class, 'match_id');
    }

    public function divisions(): BelongsToMany
    {
        return $this->belongsToMany(Division::class, 'match_division', 'match_id', 'division_id');
    }

    /**
     * Divisions a shooter may enter for this match: the divisions explicitly
     * assigned to the match, falling back to every active division when none
     * have been configured.
     *
     * @return \Illuminate\Support\Collection<int, Division>
     */
    public function availableDivisions(): \Illuminate\Support\Collection
    {
        $divisions = $this->divisions()
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return $divisions->isNotEmpty()
            ? $divisions
            : Division::query()->active()->ordered()->get();
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(MatchExpense::class, 'match_id');
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(MatchAnnouncement::class, 'match_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class, 'match_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Computed Accessors ──

    /**
     * Display name for the match director: the scraped/entered MD name when
     * present, otherwise the account that owns the match.
     */
    protected function directorName(): Attribute
    {
        return Attribute::get(fn () => $this->match_director ?: $this->creator?->name);
    }

    protected function registrationStatus(): Attribute
    {
        return Attribute::get(function () {
            if ($this->status === 'cancelled') {
                return 'cancelled';
            }
            if ($this->status === 'completed') {
                return 'closed';
            }
            if (! $this->hasRequiredSetup()) {
                return 'setup_incomplete';
            }
            if ($this->registration_close_date && $this->registration_close_date->isPast()) {
                return 'closed';
            }
            if ($this->isFull()) {
                return $this->waitlist_enabled ? 'waitlist' : 'full';
            }
            if ($this->status === 'open' && $this->published) {
                return 'open';
            }

            return 'closed';
        });
    }

    protected function availableSlots(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->max_competitors) {
                return null;
            }

            $confirmed = $this->registrations()
                ->whereIn('registration_status', ['confirmed', 'pending'])
                ->count();

            return max(0, $this->max_competitors - $confirmed);
        });
    }

    protected function formattedDate(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->match_date) {
                return '';
            }

            if ($this->match_end_date && ! $this->match_end_date->isSameDay($this->match_date)) {
                if ($this->match_date->month === $this->match_end_date->month) {
                    return $this->match_date->format('j') . '–' . $this->match_end_date->format('j M Y');
                }

                return $this->match_date->format('j M') . ' – ' . $this->match_end_date->format('j M Y');
            }

            return $this->match_date->format('D, j M Y');
        });
    }

    public function isMultiDay(): bool
    {
        return $this->match_end_date && ! $this->match_end_date->isSameDay($this->match_date);
    }

    protected function locationDisplay(): Attribute
    {
        return Attribute::get(function () {
            $cityPart = $this->city ?: $this->venue_location;
            $provinceName = $this->province?->name;

            if (! $cityPart && ! $provinceName) {
                return '';
            }

            if ($provinceName && $cityPart && str_contains($cityPart, $provinceName)) {
                return $cityPart;
            }

            return implode(', ', array_filter([$cityPart, $provinceName]));
        });
    }

    // ── Helper Methods ──

    public function isFull(): bool
    {
        if (! $this->max_competitors) {
            return false;
        }

        return $this->registrations()
            ->whereIn('registration_status', ['confirmed', 'pending'])
            ->count() >= $this->max_competitors;
    }

    /**
     * A match only accepts sign-ups once it is fully set up: a named match
     * director and a configured entry fee. A NULL fee means "not set yet"
     * (stays closed); R0 is a valid free match and passes this check.
     */
    public function hasRequiredSetup(): bool
    {
        return filled($this->match_director) && $this->active_member_fee !== null;
    }

    public function isRegistrationOpen(): bool
    {
        return $this->registration_status === 'open';
    }

    public function isWaitlistOpen(): bool
    {
        return $this->registration_status === 'waitlist';
    }

    public function confirmedRegistrationCount(): int
    {
        return $this->registrations()
            ->whereIn('registration_status', ['confirmed', 'pending'])
            ->count();
    }

    public function userRegistration(?User $user): ?MatchRegistration
    {
        if (! $user) {
            return null;
        }

        return $this->registrations()
            ->where('user_id', $user->id)
            ->whereNotIn('registration_status', ['cancelled'])
            ->first();
    }

    // ── Query Scopes ──

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('published', true)->where('status', '!=', 'draft');
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('match_date', '>=', now()->startOfDay())
                ->orWhere(function (Builder $q2) {
                    $q2->whereNotNull('match_end_date')
                        ->where('match_end_date', '>=', now()->startOfDay());
                });
        })->whereIn('status', ['open', 'closed', 'draft']);
    }

    public function scopePast(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where(function (Builder $q2) {
                $q2->whereNull('match_end_date')
                    ->where('match_date', '<', now()->startOfDay());
            })->orWhere(function (Builder $q2) {
                $q2->whereNotNull('match_end_date')
                    ->where('match_end_date', '<', now()->startOfDay());
            })->orWhere('status', 'completed');
        });
    }

    public function scopeFeatured(Builder $query): Builder
    {
        $ids = static::nextUpcomingNationalIds();

        return $query->whereIn('id', $ids);
    }

    protected static ?array $featuredIdsCache = null;

    /**
     * Returns the IDs of the next upcoming national for each series (PRS + PR22).
     * Cached per request to avoid repeated queries when rendering card lists.
     */
    public static function nextUpcomingNationalIds(): array
    {
        if (static::$featuredIdsCache !== null) {
            return static::$featuredIdsCache;
        }

        $ids = [];

        foreach (['PRS', 'PR22'] as $series) {
            $match = static::query()
                ->published()
                ->where('match_type', $series)
                ->whereIn('series_level', ['national', 'final'])
                ->where(function (Builder $q) {
                    $q->where('match_date', '>=', now()->startOfDay())
                        ->orWhere(function (Builder $q2) {
                            $q2->whereNotNull('match_end_date')
                                ->where('match_end_date', '>=', now()->startOfDay());
                        });
                })
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->orderBy('match_date')
                ->first(['id']);

            if ($match) {
                $ids[] = $match->id;
            }
        }

        return static::$featuredIdsCache = $ids;
    }

    public static function clearFeaturedCache(): void
    {
        static::$featuredIdsCache = null;
    }

    public function getIsFeaturedAttribute(): bool
    {
        return in_array($this->id, static::nextUpcomingNationalIds());
    }

    public function scopeForDiscipline(Builder $query, ?string $discipline): Builder
    {
        return $discipline ? $query->where('match_type', $discipline) : $query;
    }

    public function scopeForLevel(Builder $query, ?string $level): Builder
    {
        return $level ? $query->where('series_level', $level) : $query;
    }

    public function scopeForProvince(Builder $query, ?int $provinceId): Builder
    {
        return $provinceId ? $query->where('province_id', $provinceId) : $query;
    }

    public function scopeForStatus(Builder $query, ?string $status): Builder
    {
        if (! $status) {
            return $query;
        }

        return match ($status) {
            'open' => $query->where('status', 'open'),
            'closed' => $query->where('status', 'closed'),
            'completed' => $query->where('status', 'completed'),
            'cancelled' => $query->where('status', 'cancelled'),
            'upcoming' => $query->upcoming(),
            'past' => $query->past(),
            default => $query,
        };
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('venue_name', 'like', "%{$term}%")
                ->orWhere('venue_location', 'like', "%{$term}%")
                ->orWhere('city', 'like', "%{$term}%");
        });
    }
}
