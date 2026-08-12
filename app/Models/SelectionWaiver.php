<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WAV-* record. A waiver applies only to PART-* squad qualification rules
 * (never to ELG-* or SCR-09). Recorded per (athlete, rule_id) with the
 * request text and the Selectors' written response, per WAV-05.
 */
class SelectionWaiver extends Model
{
    public const OUTCOME_PENDING = 'pending';
    public const OUTCOME_GRANTED = 'granted';
    public const OUTCOME_DENIED = 'denied';

    protected $fillable = [
        'selection_athlete_id',
        'waived_rule_id',
        'request_text',
        'request_file_path',
        'response_text',
        'outcome',
        'decided_by',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(SelectionAthlete::class, 'selection_athlete_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopeGranted($query)
    {
        return $query->where('outcome', self::OUTCOME_GRANTED);
    }
}
