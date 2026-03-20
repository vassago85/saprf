<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RifleConfiguration extends Model
{
    protected $fillable = [
        'user_id',
        'nickname',
        'firearm_make_id',
        'firearm_model_id',
        'firearm_calibre_id',
        'optic_description',
        'bullet_description',
        'barrel_length',
        'twist_rate',
        'notes',
        'is_primary',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function make(): BelongsTo
    {
        return $this->belongsTo(FirearmMake::class, 'firearm_make_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(FirearmModel::class, 'firearm_model_id');
    }

    public function calibre(): BelongsTo
    {
        return $this->belongsTo(FirearmCalibre::class, 'firearm_calibre_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(MatchRegistration::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function displayName(): string
    {
        $parts = array_filter([
            $this->make?->name,
            $this->model?->name,
        ]);

        return $this->nickname ?: (implode(' ', $parts) ?: 'Unnamed Rifle');
    }
}
