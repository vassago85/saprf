<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payout extends Model
{
    protected $fillable = [
        'reference',
        'payee_type',
        'payee_user_id',
        'match_id',
        'period_start',
        'period_end',
        'gross_amount',
        'fees_deducted',
        'net_amount',
        'status',
        'paid_amount',
        'paid_at',
        'payment_reference',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'fees_deducted' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    public function payeeUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payee_user_id');
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(MatchEvent::class, 'match_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayoutItem::class);
    }

    public function outstandingBalance(): float
    {
        return max(0, (float) $this->net_amount - (float) $this->paid_amount);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public static function generateReference(): string
    {
        return 'PO-' . now()->format('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
    }
}
