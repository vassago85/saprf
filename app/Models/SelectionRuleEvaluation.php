<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADM-04 audit trail: one row per (athlete, rule, evaluation run). Persists
 * the outcome and diagnostic detail (e.g. counts, reasons) with a copy of
 * the policy version in force at the time.
 */
class SelectionRuleEvaluation extends Model
{
    public const OUTCOME_PASS = 'pass';
    public const OUTCOME_FAIL = 'fail';
    public const OUTCOME_MANUAL = 'manual';
    public const OUTCOME_NA = 'na';
    public const OUTCOME_BLOCKED = 'blocked';

    protected $fillable = [
        'selection_athlete_id',
        'rule_id',
        'outcome',
        'detail',
        'policy_version',
        'evaluated_at',
    ];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'evaluated_at' => 'datetime',
        ];
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(SelectionAthlete::class, 'selection_athlete_id');
    }
}
