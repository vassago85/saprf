<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AmmoLoad extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'rifle_configuration_id',
        'nickname',
        'firearm_calibre_id',
        'bullet_make',
        'bullet_model',
        'bullet_weight',
        'bullet_type',
        'brass',
        'primer',
        'powder',
        'charge_weight',
        'coal',
        'cbto',
        'velocity',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rifleConfiguration(): BelongsTo
    {
        return $this->belongsTo(RifleConfiguration::class);
    }

    public function calibre(): BelongsTo
    {
        return $this->belongsTo(FirearmCalibre::class, 'firearm_calibre_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(MatchRegistration::class);
    }

    public function ladderSessions(): HasMany
    {
        return $this->hasMany(LadderSession::class);
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
        if ($this->nickname) {
            return $this->nickname;
        }

        $parts = array_filter([
            $this->bullet_make,
            $this->bullet_weight,
            $this->bullet_type,
        ]);

        return implode(' ', $parts) ?: 'Unnamed Load';
    }

    public function bulletSummary(): string
    {
        return trim(collect([
            $this->bullet_make,
            $this->bullet_weight,
            $this->bullet_type,
        ])->filter()->implode(' '));
    }
}
