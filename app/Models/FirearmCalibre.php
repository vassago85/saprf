<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FirearmCalibre extends Model
{
    protected $fillable = ['name', 'category', 'family', 'bullet_diameter', 'is_active'];

    protected function casts(): array
    {
        return [
            'bullet_diameter' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeRifle($query)
    {
        return $query->where('category', 'rifle');
    }
}
