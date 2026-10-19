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
        'match_registration_id',
        'description',
        'shooter_name',
        'membership_fee_category',
        'payment_status',
        'payment_method',
        'gross_amount',
        'surcharge_amount',
        'platform_fee',
        'gateway_fee',
        'saprf_fee',
        'net_amount',
        'excluded',
        'excluded_reason',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'surcharge_amount' => 'decimal:2',
            'platform_fee' => 'decimal:2',
            'gateway_fee' => 'decimal:2',
            'saprf_fee' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'excluded' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function methodLabel(): string
    {
        return match ($this->payment_method) {
            'card' => 'Card',
            'instant_eft' => 'Instant EFT',
            'card_or_eft' => 'Card / EFT',
            'manual' => 'Manual',
            'waived' => 'Comp',
            'walk_in' => 'Walk-in',
            'pending' => 'Pending',
            default => '—',
        };
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }
}
