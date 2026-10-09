<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditRefundRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_DECLINED = 'declined';

    protected $fillable = [
        'user_id',
        'account_credit_id',
        'amount',
        'status',
        'account_holder',
        'bank_name',
        'account_number',
        'branch_code',
        'member_note',
        'decline_reason',
        'payment_reference',
        'paid_at',
        'paid_by',
        'declined_at',
        'declined_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hold(): BelongsTo
    {
        return $this->belongsTo(AccountCredit::class, 'account_credit_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function decliner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declined_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
