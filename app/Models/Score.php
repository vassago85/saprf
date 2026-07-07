<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Score extends Model
{
    protected $fillable = [
        'match_id',
        'score_import_id',
        'shooter_name',
        'user_id',
        'raw_score',
        'day1_raw_score',
        'day2_raw_score',
        'provincial_raw_score',
        'placement',
        'division_id',
        'total_possible_shots',
        'hit_percentage',
        'normalized_score',
        'division_normalized_score',
        'provincial_normalized_score',
        'overall_rank',
        'division_rank',
        'is_member',
        'status',
        'validation_reason',
        'match_date',
        'raw_meta',
        'counts_for_log',
        'counts_for_season',
    ];

    protected function casts(): array
    {
        return [
            'raw_score' => 'decimal:3',
            'day1_raw_score' => 'decimal:3',
            'day2_raw_score' => 'decimal:3',
            'hit_percentage' => 'decimal:3',
            'provincial_raw_score' => 'decimal:3',
            'normalized_score' => 'decimal:4',
            'division_normalized_score' => 'decimal:4',
            'provincial_normalized_score' => 'decimal:4',
            'is_member' => 'boolean',
            'counts_for_log' => 'boolean',
            'counts_for_season' => 'boolean',
            'match_date' => 'date',
            'raw_meta' => 'array',
        ];
    }

    /**
     * Whenever day1_raw_score or day2_raw_score is set/updated, recompute the
     * derived aggregates:
     *   - raw_score = day1 + day2 (or just day1 if day2 is null)
     *   - provincial_raw_score = day1 (when the parent match "also counts for provincial")
     *
     * The match relation is loaded lazily on save if not already present.
     * Boot-time observer keeps this centralised so controllers, imports,
     * seeders and manual entry all get consistent results.
     */
    protected static function booted(): void
    {
        static::saving(function (Score $score): void {
            if ($score->isDirty(['day1_raw_score', 'day2_raw_score'])) {
                $day1 = $score->day1_raw_score !== null ? (float) $score->day1_raw_score : null;
                $day2 = $score->day2_raw_score !== null ? (float) $score->day2_raw_score : null;

                if ($day1 !== null || $day2 !== null) {
                    $score->raw_score = ($day1 ?? 0) + ($day2 ?? 0);
                }

                // Provincial credit = day 1 only, for matches flagged as dual-count.
                $match = $score->match; // triggers lazy load if needed
                if ($match && $match->also_counts_for_provincial && $day1 !== null) {
                    $score->provincial_raw_score = $day1;
                }
            }
        });
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(MatchEvent::class, 'match_id');
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(ScoreImport::class, 'score_import_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shooterLog(): HasOne
    {
        return $this->hasOne(ShooterLog::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }
}
