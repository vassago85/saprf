<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchExpense extends Model
{
    protected $fillable = [
        'match_id',
        'description',
        'amount',
        'cost_type',
        'category',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public const COST_TYPES = [
        'fixed' => 'Fixed Cost',
        'per_shooter' => 'Per Shooter',
    ];

    public const CATEGORIES = [
        'venue' => 'Venue',
        'targets' => 'Targets / Steel',
        'catering' => 'Catering',
        'prizes' => 'Prizes / Trophies',
        'transport' => 'Transport',
        'equipment' => 'Equipment',
        'insurance' => 'Insurance',
        'other' => 'Other',
    ];

    public function effectiveAmount(?int $shooterCount = null): float
    {
        if ($this->cost_type === 'per_shooter') {
            return (float) $this->amount * ($shooterCount ?? 0);
        }

        return (float) $this->amount;
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(MatchEvent::class, 'match_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
