<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sponsor extends Model
{
    protected $fillable = [
        'sponsor_tier_id',
        'name',
        'logo_path',
        'website_url',
        'contact_name',
        'contact_email',
        'starts_at',
        'expires_at',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'expires_at' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(SponsorTier::class, 'sponsor_tier_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where('starts_at', '<=', now()->toDateString())
            ->where('expires_at', '>=', now()->toDateString());
    }

    public function scopeExpired($query)
    {
        return $query->where('is_active', true)
            ->where('expires_at', '<', now()->toDateString());
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function logoUrl(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        return asset('storage/' . $this->logo_path);
    }
}
