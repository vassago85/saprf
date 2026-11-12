<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Participation-only row. Deliberately has no reaction column and no
 * timestamps — the system knows that a member reacted, never how.
 */
class DecisionMember extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = [
        'decision_id',
        'member_id',
    ];

    public function decision(): BelongsTo
    {
        return $this->belongsTo(Decision::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }
}
