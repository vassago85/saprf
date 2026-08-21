<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A physical barrel. A rifle gets rebarrelled over its life, so round count,
 * throat erosion and truing data all hang off the barrel rather than the
 * RifleConfiguration. round_count is manually maintained for now; a later
 * task drives it from match participation and logged practice.
 */
class Barrel extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'rifle_configuration_id',
        'label',
        'chambering',
        'maker',
        'length_mm',
        'twist_rate',
        'round_count',
        'installed_on',
        'retired_on',
    ];

    protected function casts(): array
    {
        return [
            'length_mm' => 'integer',
            'round_count' => 'integer',
            'installed_on' => 'date',
            'retired_on' => 'date',
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

    public function ladderSessions(): HasMany
    {
        return $this->hasMany(LadderSession::class);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('retired_on');
    }

    public function displayName(): string
    {
        $parts = array_filter([$this->label, $this->chambering]);

        return implode(' · ', $parts) ?: 'Unnamed Barrel';
    }
}
