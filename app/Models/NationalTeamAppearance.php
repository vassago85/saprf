<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per year a shooter shot for South Africa at an IPRF world
 * championship (or comparable national event).
 *
 * The `awarded_colours` flag marks the ONE appearance that granted the
 * shooter their Protea Colours — a career-once honour. Subsequent
 * appearances by the same shooter are national-team appearances that do
 * NOT re-award colours.
 *
 * Invariant: at most one appearance per user has `awarded_colours = true`.
 * Enforced by NationalTeamAppearanceController (MySQL doesn't support
 * partial unique indexes). If you write code that flips the flag on a
 * row, use the `awardColoursTo()` helper below so the invariant is
 * preserved.
 */
class NationalTeamAppearance extends Model
{
    protected $fillable = [
        'user_id',
        'year',
        'division_id',
        'division_label',
        'championship_name',
        'host_country',
        'placing',
        'selection_cycle_id',
        'awarded_colours',
        'appeared_at',
        'recorded_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'placing' => 'integer',
            'awarded_colours' => 'boolean',
            'appeared_at' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function selectionCycle(): BelongsTo
    {
        return $this->belongsTo(SelectionCycle::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeAwardedColours(Builder $query): Builder
    {
        return $query->where('awarded_colours', true);
    }

    public function divisionName(): ?string
    {
        return $this->division?->name ?? $this->division_label;
    }

    public function hostCountryName(): ?string
    {
        if (! $this->host_country) {
            return null;
        }

        return User::COUNTRY_OPTIONS[$this->host_country] ?? $this->host_country;
    }
}
