<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SeasonShooterClassification extends Model
{
    protected $fillable = [
        'season',
        'user_id',
        'classification_date',
        'age_on_classification_date',
        'effective_division_id',
        'is_locked',
        'override_applied',
        'override_reason',
    ];

    protected function casts(): array
    {
        return [
            'classification_date' => 'date',
            'age_on_classification_date' => 'integer',
            'is_locked' => 'boolean',
            'override_applied' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function effectiveDivision(): BelongsTo
    {
        return $this->belongsTo(Division::class, 'effective_division_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            Category::class,
            'season_classification_categories',
            'classification_id',
            'category_id',
        );
    }
}
