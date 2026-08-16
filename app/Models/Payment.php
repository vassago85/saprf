<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    protected $fillable = [
        'payable_type',
        'payable_id',
        'user_id',
        'amount',
        'amount_gross',
        'amount_fee',
        'amount_net',
        'currency',
        'gateway',
        'gateway_payment_id',
        'm_payment_id',
        'status',
        'gateway_response',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_gross' => 'decimal:2',
            'amount_fee' => 'decimal:2',
            'amount_net' => 'decimal:2',
            'gateway_response' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * Gross / fee / net from a PayFast ITN. Fee is stored as a positive cost
     * because PayFast posts it as a negative deduction (e.g. -4.60).
     *
     * @param  array<string, mixed>  $itn
     * @return array{gross: ?float, fee: ?float, net: ?float}
     */
    public static function settlementFromItn(array $itn): array
    {
        return [
            'gross' => self::optionalDecimal($itn['amount_gross'] ?? null),
            'fee' => self::optionalAbsoluteDecimal($itn['amount_fee'] ?? null),
            'net' => self::optionalDecimal($itn['amount_net'] ?? null),
        ];
    }

    private static function optionalDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }

    private static function optionalAbsoluteDecimal(mixed $value): ?float
    {
        $decimal = self::optionalDecimal($value);

        return $decimal === null ? null : abs($decimal);
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public static function generateReference(string $prefix = 'PAY'): string
    {
        return $prefix . '-' . now()->format('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 8));
    }
}
