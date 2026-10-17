<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Membership extends Model
{
    /** How early an active member may renew (My Membership + PayFast). */
    public const RENEWAL_WINDOW_DAYS = 60;

    /** Shooter dashboard banner starts this many days before expiry. */
    public const DASHBOARD_RENEWAL_NOTICE_DAYS = 30;

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
     * Signed day count until expiry (0 = expires today, negative = already past).
     */
    public function daysUntilExpiry(): ?int
    {
        if (! $this->expiry_date) {
            return null;
        }

        $today = now()->startOfDay();
        $expiry = $this->expiry_date->copy()->startOfDay();

        if ($expiry->lt($today)) {
            return -((int) $expiry->diffInDays($today));
        }

        return (int) $today->diffInDays($expiry);
    }

    /**
     * Active paid members may renew once expiry is within RENEWAL_WINDOW_DAYS.
     */
    public function isWithinRenewalWindow(): bool
    {
        if (! $this->isActiveMember() || ! $this->expiry_date) {
            return false;
        }

        $days = $this->daysUntilExpiry();

        return $days !== null && $days >= 0 && $days <= self::RENEWAL_WINDOW_DAYS;
    }

    /**
     * Dashboard call-to-action — tighter than the checkout window so the
     * banner only appears when expiry is close (30 days).
     */
    public function shouldShowDashboardRenewalNotice(): bool
    {
        if (! $this->isActiveMember() || ! $this->expiry_date) {
            return false;
        }

        $days = $this->daysUntilExpiry();

        return $days !== null && $days >= 0 && $days <= self::DASHBOARD_RENEWAL_NOTICE_DAYS;
    }

    /**
     * Next expiry after a successful renewal payment. Early renewals stack
     * from the current expiry so unused days are not lost; otherwise the
     * window starts from today.
     */
    public function computeRenewedExpiryDate(?int $durationMonths = null): \Carbon\CarbonInterface
    {
        $months = $durationMonths ?? $this->feeTier?->duration_months ?? 12;
        $today = now()->startOfDay();

        $base = ($this->expiry_date && $this->expiry_date->copy()->startOfDay()->gte($today))
            ? $this->expiry_date->copy()->startOfDay()
            : $today;

        return $base->addMonths($months);
    }

    /**
     * The status every screen must show. Free records are always Non-member;
     * a stored expiry on a free row is ignored. Paid rows past expiry are
     * Lapsed until the grace cutoff, then Expired.
     */
    public function membershipStatus(): MembershipStatus
    {
        if ($this->membership_type === 'free') {
            return MembershipStatus::NonMember;
        }

        if ($this->status === 'revoked') {
            return MembershipStatus::Revoked;
        }

        if ($this->status === 'suspended') {
            return MembershipStatus::Suspended;
        }

        if ($this->status === 'pending' && ! $this->expiry_date) {
            return MembershipStatus::Pending;
        }

        if ($this->expiry_date && $this->expiry_date->copy()->startOfDay()->lt(now()->startOfDay())) {
            $graceStart = now()->startOfDay()->subDays(\App\Services\MembershipValidationService::LAPSED_CUTOFF_DAYS);

            return $this->expiry_date->copy()->startOfDay()->gte($graceStart)
                ? MembershipStatus::Lapsed
                : MembershipStatus::Expired;
        }

        return MembershipStatus::Active;
    }

    /**
     * @deprecated Read membershipStatus() instead. Kept so older callers
     * still receive the enum value string.
     */
    public function getEffectiveStatusAttribute(): string
    {
        return $this->membershipStatus()->value;
    }

    public function getEffectiveStatusLabelAttribute(): string
    {
        return $this->membershipStatus()->label();
    }

    /**
     * Current federation member: the display status is Active.
     * Free accounts never qualify, including legacy rows that still carry
     * a SAPRF number and a future expiry.
     */
    public function isActiveMember(): bool
    {
        return $this->membershipStatus() === MembershipStatus::Active;
    }

    /**
     * Paid memberships whose expiry is today or later. Same cohort as
     * membershipStatus() === Active, including the admin list filter and
     * the owner dashboard count.
     */
    public function scopeActive(Builder $query): Builder
    {
        return MembershipStatus::Active->constrain($query);
    }

    public function scopeActiveMembers(Builder $query): Builder
    {
        return $this->scopeActive($query);
    }

    public function scopeDisplayStatus(Builder $query, MembershipStatus|string $status): Builder
    {
        $status = $status instanceof MembershipStatus
            ? $status
            : MembershipStatus::from($status);

        return $status->constrain($query);
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
