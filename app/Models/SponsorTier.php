<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SponsorTier extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'display_order',
        'price_per_year',
        'logo_max_height',
        'placement',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'placement' => 'array',
            'is_active' => 'boolean',
            'price_per_year' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $tier) {
            if (empty($tier->slug)) {
                $tier->slug = Str::slug($tier->name);
            }
        });
    }

    public function sponsors(): HasMany
    {
        return $this->hasMany(Sponsor::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order');
    }
}
