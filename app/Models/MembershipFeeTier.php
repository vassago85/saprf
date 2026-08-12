<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipFeeTier extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'price',
        'duration_months',
        'display_order',
        'is_active',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration_months' => 'integer',
            'display_order' => 'integer',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'fee_tier_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order')->orderBy('name');
    }

    /**
     * The tier that drives the fallback price and the pre-selected option on
     * the join page: the flagged default if present, otherwise the first
     * active tier by display order.
     */
    public static function defaultTier(): ?self
    {
        return static::query()->active()->where('is_default', true)->ordered()->first()
            ?? static::query()->active()->ordered()->first();
    }
}
