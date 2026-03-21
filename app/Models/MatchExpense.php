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

    public function match(): BelongsTo
    {
        return $this->belongsTo(MatchEvent::class, 'match_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
