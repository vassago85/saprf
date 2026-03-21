<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualificationRule extends Model
{
    protected $fillable = [
        'series',
        'season',
        'min_out_of_province_matches',
        'best_of_count',
        'total_qualifying_matches',
        'weighted_final_enabled',
        'weighted_final_multiplier',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'weighted_final_enabled' => 'boolean',
            'weighted_final_multiplier' => 'decimal:2',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
