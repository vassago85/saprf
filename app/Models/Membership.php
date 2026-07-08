<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Membership extends Model
{
    protected $fillable = [
        'user_id',
        'saprf_number',
        'membership_type',
        'status',
        'payment_status',
        'start_date',
        'expiry_date',
        'revoked_at',
        'revocation_reason',
        'revoked_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'expiry_date' => 'date',
            'revoked_at' => 'datetime',
        ];
    }

    public function isRevoked(): bool
    {
        return $this->status === 'revoked' && $this->revoked_at !== null;
    }

    /**
     * A single, human-friendly status derived from the facts we trust — the
     * expiry date and the membership type — rather than the messy legacy
     * "status"/"payment_status" flags. This is what should be shown to users:
     * a membership is simply Active (paid up, not yet expired) or Expired.
     *
     * Returns one of: non_member | revoked | pending | expired | active
     */
    public function getEffectiveStatusAttribute(): string
    {
        if ($this->membership_type === 'free') {
            return 'non_member';
        }

        if ($this->status === 'revoked') {
            return 'revoked';
        }

        // Awaiting first payment/approval and no window opened yet.
        if ($this->status === 'pending' && ! $this->expiry_date) {
            return 'pending';
        }

        if ($this->expiry_date && $this->expiry_date->lt(now()->startOfDay())) {
            return 'expired';
        }

        return 'active';
    }

    public function getEffectiveStatusLabelAttribute(): string
    {
        return match ($this->effective_status) {
            'non_member' => 'Non-member',
            'revoked' => 'Revoked',
            'pending' => 'Pending',
            'expired' => 'Expired',
            default => 'Active',
        };
    }

    public function revokedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(MembershipPayment::class);
    }

    public function gatewayPayments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }
}
