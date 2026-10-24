<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StandingsSnapshot extends Model
{
    protected $fillable = [
        'season',
        'series',
        'level',
        'province_id',
        'division_id',
        'user_id',
        'rank',
        'points',
        'taken_at',
        'triggered_by_match_id',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'decimal:3',
            'taken_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(MatchEvent::class, 'triggered_by_match_id');
    }
}
