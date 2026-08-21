<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A confirmation string — N shots fired at a single load, in order, over a
 * chronograph. Distinct from a ladder session in that the variable is time
 * (shot number), not charge weight. Owner-only; the analyser reads from
 * shots() ordered by sequence and never trusts fired-on for that.
 */
class AmmoString extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'ammo_load_id',
        'barrel_id',
        'ladder_session_id',
        'label',
        'fired_on',
        'temperature_c',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'fired_on' => 'date',
            'temperature_c' => 'decimal:1',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ammoLoad(): BelongsTo
    {
        return $this->belongsTo(AmmoLoad::class);
    }

    public function barrel(): BelongsTo
    {
        return $this->belongsTo(Barrel::class);
    }

    public function ladderSession(): BelongsTo
    {
        return $this->belongsTo(LadderSession::class);
    }

    public function shots(): HasMany
    {
        return $this->hasMany(AmmoStringShot::class)->orderBy('sequence');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
