<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProvincialCommittee extends Model
{
    public const POSITIONS = ['chair', 'vice_chair', 'treasurer', 'secretary', 'member'];

    protected $fillable = [
        'province_id',
        'user_id',
        'position',
        'is_active',
        'appointed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'appointed_at' => 'date',
        ];
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForProvince($query, int $provinceId)
    {
        return $query->where('province_id', $provinceId);
    }

    public function positionLabel(): string
    {
        return ucwords(str_replace('_', ' ', $this->position));
    }
}
