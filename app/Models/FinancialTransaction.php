<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialTransaction extends Model
{
    protected $table = 'financial_transactions';

    protected $fillable = [
        'type',
        'source_type',
        'source_id',
        'user_id',
        'amount',
        'description',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'meta' => 'array',
        ];
    }

    public const TYPES = [
        'payment' => 'Payment',
        'refund' => 'Refund',
        'adjustment' => 'Adjustment',
        'payout' => 'Payout',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
