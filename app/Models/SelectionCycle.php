<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A selection cycle is a per-(series, season) container for team selection.
 * Example: PR22 2027 IPRF World Championship cycle. Each cycle references a
 * versioned policy (SelectionPolicy) and holds the key dates that gate the
 * pipeline (declaration deadline, results freeze, deliberation window,
 * publication date).
 */
class SelectionCycle extends Model
{
    public const MODE_STRICT = 'strict';

    public const MODE_ASSUME_QUALIFIED = 'assume_qualified';

    public const MODES = [
        self::MODE_STRICT => 'Strict (run policy rules)',
        self::MODE_ASSUME_QUALIFIED => 'Assume qualified (nomination letter is the only gate)',
    ];

    protected $fillable = [
        'series',
        'season',
        'championship_name',
        'qualifying_period_start',
        'qualifying_period_end',
        'declaration_deadline',
        'results_freeze',
        'panel_lock_date',
        'deliberation_start',
        'deliberation_end',
        'publication_date',
        'active_policy_version_id',
        'status',
        'evaluation_mode',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'qualifying_period_start' => 'date',
            'qualifying_period_end' => 'date',
            'declaration_deadline' => 'datetime',
            'results_freeze' => 'date',
            'panel_lock_date' => 'date',
            'deliberation_start' => 'date',
            'deliberation_end' => 'date',
            'publication_date' => 'date',
        ];
    }

    public function activePolicy(): BelongsTo
    {
        return $this->belongsTo(SelectionPolicy::class, 'active_policy_version_id');
    }

    public function policies(): HasMany
    {
        return $this->hasMany(SelectionPolicy::class);
    }

    public function athletes(): HasMany
    {
        return $this->hasMany(SelectionAthlete::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForSeries($query, string $series)
    {
        return $query->where('series', $series);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['open', 'frozen', 'announced']);
    }

    public function isFrozen(): bool
    {
        return in_array($this->status, ['frozen', 'announced', 'closed'], true);
    }

    public function isAssumeQualified(): bool
    {
        return $this->evaluation_mode === self::MODE_ASSUME_QUALIFIED;
    }
}
