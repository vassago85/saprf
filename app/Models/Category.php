<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'display_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
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

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_category');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order')->orderBy('name');
    }
}
