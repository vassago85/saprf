<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One anonymous answer set for a match. Stored with no user id, no IP
 * and no user-agent so the row cannot be joined back onto the
 * respondent table to recover identity.
 */
class MatchSurveyResponse extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'match_id',
        'answers',
    ];

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(MatchEvent::class, 'match_id');
    }
}
