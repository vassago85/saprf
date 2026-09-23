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

    protected $fillable = [
        'user_id',
        'amount',
        'status',
        'type',
        'match_id',
        'issued_for_registration_id',
        'applied_to_registration_id',
        'payment_id',
        'description',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
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

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
