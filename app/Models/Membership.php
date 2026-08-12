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
        'fee_tier_id',
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

    protected static function booted(): void
    {
        // New memberships continue the federation's legacy sequential numbering
        // (plain integers). Explicit numbers set by imports/scrapers
        // (e.g. SAPRF-IMPORT-… stubs) are left untouched.
        static::creating(function (Membership $membership): void {
            if (blank($membership->saprf_number)) {
                $membership->saprf_number = static::nextSaprfNumber();
            }
        });
    }

    /**
     * Next SAPRF membership number following the legacy scheme: the highest
     * existing purely-numeric number plus one (e.g. 2025 → 2026). Non-numeric
     * legacy values (SAPRF-IMPORT-…, SAPRF-YYYY-…) are ignored.
     */
    public static function nextSaprfNumber(): string
    {
        $max = static::query()
            ->whereNotNull('saprf_number')
            ->pluck('saprf_number')
            ->map(fn ($number) => trim((string) $number))
            ->filter(fn (string $number) => $number !== '' && ctype_digit($number))
            ->map(fn (string $number) => (int) $number)
            ->max();

        $next = ($max ?? 0) + 1;

        while (static::where('saprf_number', (string) $next)->exists()) {
            $next++;
        }

        return (string) $next;
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

    /**
     * True when this person should be treated as a current federation member
     * in the UI (dashboard badge, membership card, etc.).
     *
     * Uses expiry + payment rather than requiring membership_type === 'paid',
     * because legacy imports often used other type labels (or left type as
     * "free" while still stamping a real SAPRF number and paid window).
     */
    public function isActiveMember(): bool
    {
        if ($this->status === 'revoked') {
            return false;
        }

        // Explicit free/non-member registrations — unless they clearly have a
        // real paid membership window (legacy bad data).
        if ($this->membership_type === 'free') {
            $looksLikeRealMember = filled($this->saprf_number)
                && ! str_starts_with((string) $this->saprf_number, 'SAPRF-IMPORT-')
                && in_array($this->payment_status, ['paid', 'waived'], true)
                && $this->expiry_date
                && $this->expiry_date->gte(now()->startOfDay());

            return $looksLikeRealMember;
        }

        if (! in_array($this->payment_status, ['paid', 'waived'], true) && $this->status !== 'active') {
            return false;
        }

        if ($this->expiry_date && $this->expiry_date->lt(now()->startOfDay())) {
            return false;
        }

        // Paid/waived with a current window, or active status with no expiry yet.
        return in_array($this->payment_status, ['paid', 'waived'], true)
            || ($this->status === 'active' && filled($this->saprf_number));
    }

    public function revokedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function feeTier(): BelongsTo
    {
        return $this->belongsTo(MembershipFeeTier::class, 'fee_tier_id');
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
