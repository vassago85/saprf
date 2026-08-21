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
        'measured_sd_fps',
        'measured_sd_n',
        'measured_sd_at',
        'measured_sd_string_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'measured_sd_fps' => 'decimal:2',
            'measured_sd_n' => 'integer',
            'measured_sd_at' => 'datetime',
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

    public function strings(): HasMany
    {
        return $this->hasMany(AmmoString::class);
    }

    /**
     * The specific confirmation string that produced the current measured-SD
     * snapshot. Null unless a string has ever been saved for this load.
     */
    public function measuredSdString(): BelongsTo
    {
        return $this->belongsTo(AmmoString::class, 'measured_sd_string_id');
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
