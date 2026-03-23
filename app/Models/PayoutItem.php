<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutItem extends Model
{
    protected $fillable = [
        'payout_id',
        'source_type',
        'source_id',
        'description',
        'gross_amount',
        'platform_fee',
        'gateway_fee',
        'saprf_fee',
        'net_amount',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'platform_fee' => 'decimal:2',
            'gateway_fee' => 'decimal:2',
            'saprf_fee' => 'decimal:2',
            'net_amount' => 'decimal:2',
        ];
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }
}
