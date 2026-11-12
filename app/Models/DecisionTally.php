<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DecisionTally extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'decision_id',
        'likes',
        'dislikes',
        'neutrals',
    ];

    protected function casts(): array
    {
        return [
            'likes' => 'integer',
            'dislikes' => 'integer',
            'neutrals' => 'integer',
        ];
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(Decision::class);
    }
}
