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
        'participation_confirmed_at',
        'participation_confirmed_by',
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
            'participation_confirmed_at' => 'datetime',
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

    public function participationConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'participation_confirmed_by');
    }

    /**
     * A raw_score of 0 is ambiguous: it might mean the shooter genuinely
     * zeroed the match, or that they were on the score sheet by mistake
     * and never turned up. Non-zero scores never need confirmation, and
     * scores already confirmed by an MD are excluded from the prompt.
     */
    public function needsParticipationConfirmation(): bool
    {
        return (float) $this->raw_score === 0.0
            && $this->participation_confirmed_at === null;
    }

    /**
     * The rank we show to the shooter for this row: the raw placement from the
     * source CSV when present, otherwise the overall_rank computed by
     * StandingsCalculationService. Day-1 dual-count provincial rows never
     * carry a raw placement (the CSV only ever has one for the "real" row), so
     * without this fallback they would show a blank "—" in the recent-match
     * history table even though the shooter clearly placed somewhere. Returns
     * null when nothing is known.
     */
    public function displayRank(): ?int
    {
        return $this->placement ?? $this->overall_rank;
    }
}
