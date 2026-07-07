<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualificationRule extends Model
{
    protected $fillable = [
        'series',
        'season',
        'scoring_mode',
        'min_out_of_province_matches',
        'best_of_count',
        'total_qualifying_matches',
        'weighted_final_enabled',
        'weighted_final_multiplier',
        'provincial_pool_best_of',
        'provincial_pool_weight_pct',
        'national_pool_best_of',
        'national_pool_weight_pct',
        'champs_pool_best_of',
        'champs_pool_weight_pct',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'weighted_final_enabled' => 'boolean',
            'weighted_final_multiplier' => 'decimal:2',
            'provincial_pool_weight_pct' => 'decimal:2',
            'national_pool_weight_pct' => 'decimal:2',
            'champs_pool_weight_pct' => 'decimal:2',
        ];
    }

    public function isPooledScoring(): bool
    {
        return $this->scoring_mode === 'weighted_pools';
    }

    public function totalPoolWeight(): float
    {
        return (float) $this->provincial_pool_weight_pct
            + (float) $this->national_pool_weight_pct
            + (float) $this->champs_pool_weight_pct;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
