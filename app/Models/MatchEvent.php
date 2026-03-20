<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MatchEvent extends Model
{
    protected $table = 'matches';

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
        'description',
        'match_date',
        'registration_open_date',
        'registration_close_date',
        'status',
        'created_by',
        'active_member_fee',
        'non_member_fee',
        'lapsed_member_fee',
        'max_competitors',
        'waitlist_enabled',
        'is_featured',
        'published',
    ];

    protected function casts(): array
    {
        return [
            'match_date' => 'date',
            'registration_open_date' => 'date',
            'registration_close_date' => 'date',
            'active_member_fee' => 'decimal:2',
            'non_member_fee' => 'decimal:2',
            'lapsed_member_fee' => 'decimal:2',
            'max_competitors' => 'integer',
            'waitlist_enabled' => 'boolean',
            'is_featured' => 'boolean',
            'published' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MatchEvent $match) {
            $match->slug = Str::slug($match->name) . '-' . Str::random(5);
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

    // ── Computed Accessors ──

    protected function registrationStatus(): Attribute
    {
        return Attribute::get(function () {
            if ($this->status === 'cancelled') {
                return 'cancelled';
            }
            if ($this->status === 'completed') {
                return 'closed';
            }
            if ($this->registration_open_date && $this->registration_open_date->isFuture()) {
                return 'upcoming';
            }
            if ($this->registration_close_date && $this->registration_close_date->isPast()) {
                return 'closed';
            }
            if ($this->isFull()) {
                return $this->waitlist_enabled ? 'waitlist' : 'full';
            }
            if ($this->status === 'open') {
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

            return $this->match_date->format('D, j M Y');
        });
    }

    protected function locationDisplay(): Attribute
    {
        return Attribute::get(function () {
            $parts = array_filter([
                $this->city ?: $this->venue_location,
                $this->province?->name,
            ]);

            return implode(', ', array_unique($parts));
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
        return $query->where('published', true);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('match_date', '>=', now()->startOfDay())
            ->whereIn('status', ['open', 'closed', 'draft']);
    }

    public function scopePast(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('match_date', '<', now()->startOfDay())
                ->orWhere('status', 'completed');
        });
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
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
