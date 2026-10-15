<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountCredit extends Model
{
    public const STATUS_POSTED = 'posted';

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_VOIDED = 'voided';

    public const TYPE_CANCELLATION = 'cancellation';

    public const TYPE_REDEMPTION = 'redemption';

    public const TYPE_REFUND = 'refund';

    /** Member withdrew 72h+ out and kept the entry fee as credit. Not cash-refundable. */
    public const TYPE_WITHDRAWAL = 'withdrawal';

    /** Member withdrew and chose a cash refund; posted and held in one go. */
    public const TYPE_WITHDRAWAL_REFUND = 'withdrawal_refund';

    /** Part of a paid surcharge given back after the surcharge was lowered. */
    public const TYPE_SURCHARGE_ADJUSTMENT = 'surcharge_adjustment';

    protected $fillable = [
        'user_id',
        'amount',
        'gateway_fee',
        'withdrawal_credit_amount',
        'status',
        'type',
        'match_id',
        'issued_for_registration_id',
        'applied_to_registration_id',
        'adjusted_registration_id',
        'payment_id',
        'description',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'gateway_fee' => 'decimal:2',
            'withdrawal_credit_amount' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(MatchEvent::class, 'match_id');
    }

    public function issuedForRegistration(): BelongsTo
    {
        return $this->belongsTo(MatchRegistration::class, 'issued_for_registration_id');
    }

    public function appliedToRegistration(): BelongsTo
    {
        return $this->belongsTo(MatchRegistration::class, 'applied_to_registration_id');
    }

    public function adjustedRegistration(): BelongsTo
    {
        return $this->belongsTo(MatchRegistration::class, 'adjusted_registration_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
