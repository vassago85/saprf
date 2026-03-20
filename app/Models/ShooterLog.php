<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShooterLog extends Model
{
    protected $fillable = [
        'user_id',
        'match_id',
        'score_id',
        'counted',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'counted' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(MatchEvent::class, 'match_id');
    }

    public function score(): BelongsTo
    {
        return $this->belongsTo(Score::class);
    }
}
