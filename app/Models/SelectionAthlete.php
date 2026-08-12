<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A shooter's per-cycle selection record. State walks the pipeline:
 * registered -> eligible -> declared -> squad_qualified -> scored ->
 * selected|individual|not_selected -> substituted.
 *
 * Phase 1 only goes as far as squad_qualified; scored and beyond are blocked
 * on ExCo answers to open questions Q1/Q2 (SCR-07 arithmetic).
 */
class SelectionAthlete extends Model
{
    public const STATE_REGISTERED = 'registered';
    public const STATE_ELIGIBLE = 'eligible';
    public const STATE_DECLARED = 'declared';
    public const STATE_SQUAD_QUALIFIED = 'squad_qualified';
    public const STATE_SCORED = 'scored';
    public const STATE_SELECTED = 'selected';
    public const STATE_INDIVIDUAL = 'individual';
    public const STATE_NOT_SELECTED = 'not_selected';
    public const STATE_SUBSTITUTED = 'substituted';

    public const STATES = [
        self::STATE_REGISTERED,
        self::STATE_ELIGIBLE,
        self::STATE_DECLARED,
        self::STATE_SQUAD_QUALIFIED,
        self::STATE_SCORED,
        self::STATE_SELECTED,
        self::STATE_INDIVIDUAL,
        self::STATE_NOT_SELECTED,
        self::STATE_SUBSTITUTED,
    ];

    protected $fillable = [
        'selection_cycle_id',
        'user_id',
        'claimed_division_id',
        'state',
        'manual_eligibility_notes',
        'last_evaluated_at',
        'evaluated_against_policy_id',
    ];

    protected function casts(): array
    {
        return [
            'last_evaluated_at' => 'datetime',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(SelectionCycle::class, 'selection_cycle_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function claimedDivision(): BelongsTo
    {
        return $this->belongsTo(Division::class, 'claimed_division_id');
    }

    public function evaluatedAgainstPolicy(): BelongsTo
    {
        return $this->belongsTo(SelectionPolicy::class, 'evaluated_against_policy_id');
    }

    public function declaration(): HasOne
    {
        return $this->hasOne(SelectionDeclaration::class);
    }

    public function waivers(): HasMany
    {
        return $this->hasMany(SelectionWaiver::class);
    }

    public function appeals(): HasMany
    {
        return $this->hasMany(SelectionAppeal::class);
    }

    public function participationSnapshot(): HasOne
    {
        return $this->hasOne(SelectionParticipationSnapshot::class);
    }

    public function ruleEvaluations(): HasMany
    {
        return $this->hasMany(SelectionRuleEvaluation::class);
    }

    public function scopeInState($query, string $state)
    {
        return $query->where('state', $state);
    }

    public function scopeInDivision($query, int $divisionId)
    {
        return $query->where('claimed_division_id', $divisionId);
    }

    public function scopeForCycle($query, int $cycleId)
    {
        return $query->where('selection_cycle_id', $cycleId);
    }
}
