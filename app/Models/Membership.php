<?php

namespace App\Models;

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

    /**
     * SQL translation of `isActiveMember()`. Kept in lockstep with the
     * predicate above and with MembershipController::buildIndexQuery so
     * the Notification Centre "Active members" audience picks the same
     * cohort the admin membership list does.
     *
     * A row counts as an active member when:
     *   - status is NOT 'revoked'
     *   - membership_type is not 'free' (free registrants are non-members
     *     — the legacy "looks like a real member" exception in the PHP
     *     predicate is not replicated here; those rows have `type = paid`
     *     after cleanup, and the tiny set that don't are ineligible for
     *     federation broadcasts)
     *   - either payment_status is paid/waived OR status is 'active'
     *   - expiry_date is null OR today or later
     */
    public function scopeActiveMembers(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        $today = now()->startOfDay()->toDateString();

        return $query
            ->where('memberships.status', '!=', 'revoked')
            ->where('memberships.membership_type', '!=', 'free')
            ->where(function ($q) {
                $q->whereIn('memberships.payment_status', ['paid', 'waived'])
                    ->orWhere('memberships.status', 'active');
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('memberships.expiry_date')
                    ->orWhereDate('memberships.expiry_date', '>=', $today);
            });
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
