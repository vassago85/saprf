<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Participation-only row — proves that a shooter has submitted the
 * post-match survey for a given match. Deliberately has no answer
 * column, no timestamps, no columns beyond match + user: the system
 * knows that a shooter replied, never what they said.
 *
 * Mirrors the decision-register split; see [DecisionMember].
 */
class MatchSurveyRespondent extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = [
        'match_id',
        'user_id',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(MatchEvent::class, 'match_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
