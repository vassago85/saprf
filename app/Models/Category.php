<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'is_age_based',
        'min_age',
        'max_age',
        'display_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_age_based' => 'boolean',
            'min_age' => 'integer',
            'max_age' => 'integer',
            'display_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scores(): BelongsToMany
    {
        return $this->belongsToMany(Score::class, 'score_category')
            ->withPivot('category_rank');
    }

    public function standings(): HasMany
    {
        return $this->hasMany(Standing::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeAgeBased($query)
    {
        return $query->where('is_age_based', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order')->orderBy('name');
    }

    public function matchesAge(int $age): bool
    {
        if ($this->min_age !== null && $age < $this->min_age) {
            return false;
        }

        if ($this->max_age !== null && $age > $this->max_age) {
            return false;
        }

        return true;
    }
}
