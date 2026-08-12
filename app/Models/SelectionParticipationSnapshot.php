<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cached PART-* auto-eval result: per-athlete match counts across the
 * qualifying period. Recomputed on demand by ParticipationEvaluator; the raw
 * PART-01..05 pass/fail decisions live in selection_rule_evaluations.
 */
class SelectionParticipationSnapshot extends Model
{
    protected $fillable = [
        'selection_athlete_id',
        'provincial_1d_count',
        'national_2d_count',
        'international_2d_count',
        'out_of_home_province_2d_count',
        'sa_champs_shot',
        'counted_score_ids',
        'computed_at',
        'computed_against_policy_id',
    ];

    protected function casts(): array
    {
        return [
            'sa_champs_shot' => 'boolean',
            'counted_score_ids' => 'array',
            'computed_at' => 'datetime',
        ];
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(SelectionAthlete::class, 'selection_athlete_id');
    }

    public function computedAgainstPolicy(): BelongsTo
    {
        return $this->belongsTo(SelectionPolicy::class, 'computed_against_policy_id');
    }
}
