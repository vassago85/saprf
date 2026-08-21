<?php

namespace App\Models;

use App\Enums\LadderVariable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One ladder — a series of test strings varying a single variable (charge
 * weight, seating depth, ...) over a chronograph. The unit column is derived
 * from the variable on save; every UI label reads from the enum so seating
 * ladders are a UI-only task later.
 */
class LadderSession extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'barrel_id',
        'ammo_load_id',
        'match_id',
        'variable',
        'unit',
        'name',
        'fired_on',
        'notes',
        'barrel_round_count_at_session',
        'temperature_c',
        'powder',
        'bullet',
        'brass',
        'primer',
    ];

    protected function casts(): array
    {
        return [
            'variable' => LadderVariable::class,
            'fired_on' => 'date',
            'barrel_round_count_at_session' => 'integer',
            'temperature_c' => 'decimal:1',
        ];
    }

    /**
     * Keep unit in lockstep with the chosen variable so the DB row is always
     * self-consistent even if someone flips variable in a raw update.
     */
    protected static function booted(): void
    {
        static::saving(function (LadderSession $session) {
            $variable = $session->variable instanceof LadderVariable
                ? $session->variable
                : LadderVariable::tryFrom((string) $session->variable) ?? LadderVariable::ChargeWeight;

            $session->unit = $variable->unit();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function barrel(): BelongsTo
    {
        return $this->belongsTo(Barrel::class);
    }

    public function ammoLoad(): BelongsTo
    {
        return $this->belongsTo(AmmoLoad::class);
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(MatchEvent::class, 'match_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(LadderStep::class)->orderBy('sort_order')->orderBy('value');
    }

    public function shots(): HasManyThrough
    {
        return $this->hasManyThrough(LadderShot::class, LadderStep::class);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function variableEnum(): LadderVariable
    {
        return $this->variable instanceof LadderVariable
            ? $this->variable
            : (LadderVariable::tryFrom((string) $this->variable) ?? LadderVariable::ChargeWeight);
    }
}
