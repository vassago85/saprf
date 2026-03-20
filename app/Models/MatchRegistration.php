<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchRegistration extends Model
{
    protected $fillable = [
        'match_id',
        'user_id',
        'rifle_configuration_id',
        'shooter_name',
        'email',
        'phone',
        'membership_fee_category',
        'fee_amount',
        'fee_override_reason',
        'payment_status',
        'registration_status',
        'registered_at',
    ];

    protected function casts(): array
    {
        return [
            'fee_amount' => 'decimal:2',
            'registered_at' => 'datetime',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(MatchEvent::class, 'match_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rifleConfiguration(): BelongsTo
    {
        return $this->belongsTo(RifleConfiguration::class);
    }
}
