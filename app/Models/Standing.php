<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Standing extends Model
{
    public const DIVISIONS = [
        'Open',
        'Ladies',
        'Juniors',
        'Seniors',
        'Factory',
        'Production',
        'Heavy',
    ];

    protected $fillable = [
        'user_id',
        'series',
        'season',
        'division',
        'province_id',
        'points',
        'rank',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'decimal:3',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }
}
