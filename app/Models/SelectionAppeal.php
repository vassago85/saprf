<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * APL-* record. Appeals must be lodged within 3 working days of team
 * announcement (APL-04) and carry an R5,000 fee refundable if upheld
 * (APL-05).
 */
class SelectionAppeal extends Model
{
    public const OUTCOME_PENDING = 'pending';
    public const OUTCOME_UPHELD = 'upheld';
    public const OUTCOME_DISMISSED = 'dismissed';
    public const OUTCOME_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'selection_athlete_id',
        'lodged_at',
        'reason',
        'fee_paid_at',
        'fee_amount',
        'outcome',
        'decided_by',
        'decided_at',
        'refund_issued_at',
    ];

    protected function casts(): array
    {
        return [
            'lodged_at' => 'datetime',
            'fee_paid_at' => 'datetime',
            'fee_amount' => 'decimal:2',
            'decided_at' => 'datetime',
            'refund_issued_at' => 'datetime',
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
}
