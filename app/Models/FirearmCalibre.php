<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FirearmCalibre extends Model
{
    protected $fillable = ['name', 'category', 'family', 'bullet_diameter', 'is_active', 'user_submitted', 'is_approved'];

    protected function casts(): array
    {
        return [
            'bullet_diameter' => 'decimal:3',
            'is_active' => 'boolean',
            'user_submitted' => 'boolean',
            'is_approved' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->where('is_approved', true);
    }

    public function scopePendingApproval($query)
    {
        return $query->where('is_approved', false);
    }

    public function scopeRifle($query)
    {
        return $query->where('category', 'rifle');
    }

    public function scopeRifleOrRimfire($query)
    {
        return $query->whereIn('category', ['rifle', 'rimfire']);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where('name', 'like', "%{$term}%");
    }
}
