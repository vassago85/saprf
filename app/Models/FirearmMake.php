<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FirearmMake extends Model
{
    protected $fillable = ['name', 'country', 'is_active', 'user_submitted'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'user_submitted' => 'boolean',
        ];
    }

    public function models(): HasMany
    {
        return $this->hasMany(FirearmModel::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where('name', 'like', "%{$term}%");
    }
}
