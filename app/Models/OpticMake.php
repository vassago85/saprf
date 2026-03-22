<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpticMake extends Model
{
    protected $fillable = ['name', 'country', 'is_active', 'user_submitted', 'is_approved'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'user_submitted' => 'boolean',
            'is_approved' => 'boolean',
        ];
    }

    public function models(): HasMany
    {
        return $this->hasMany(OpticModel::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->where('is_approved', true);
    }

    public function scopePendingApproval($query)
    {
        return $query->where('is_approved', false);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where('name', 'like', "%{$term}%");
    }
}
