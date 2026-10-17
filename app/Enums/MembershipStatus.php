<?php

namespace App\Enums;

use App\Services\MembershipValidationService;
use Illuminate\Database\Eloquent\Builder;

/**
 * The only membership status the UI, reports and counts may show.
 *
 * Derived from membership type and expiry, not the legacy status column.
 * Free accounts are Non-member even when a leftover expiry date is stored.
 * A paid membership whose expiry is in the past stays Lapsed for
 * MembershipValidationService::LAPSED_CUTOFF_DAYS, then becomes Expired.
 */
enum MembershipStatus: string
{
    case Active = 'active';
    case Lapsed = 'lapsed';
    case Expired = 'expired';
    case NonMember = 'non_member';
    case Pending = 'pending';
    case Revoked = 'revoked';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Lapsed => 'Lapsed (in grace)',
            self::Expired => 'Expired',
            self::NonMember => 'Non-member',
            self::Pending => 'Pending',
            self::Revoked => 'Revoked',
            self::Suspended => 'Suspended',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Active => 'Paid membership, and the expiry date is today or later.',
            self::Lapsed => 'Paid membership inside the '.MembershipValidationService::LAPSED_CUTOFF_DAYS.'-day grace window after expiry.',
            self::Expired => 'Paid membership past the grace window.',
            self::NonMember => 'Free account. An expiry date on a free record is not a membership.',
            self::Pending => 'Registered, not yet paid, and no membership window has opened.',
            self::Revoked => 'Manually terminated.',
            self::Suspended => 'Manually suspended.',
        };
    }

    /**
     * @return array{bg: string, text: string, ring: string}
     */
    public function badge(): array
    {
        return match ($this) {
            self::Active => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-700', 'ring' => 'ring-emerald-600/20'],
            self::Lapsed => ['bg' => 'bg-amber-50', 'text' => 'text-amber-800', 'ring' => 'ring-amber-600/20'],
            self::Expired => ['bg' => 'bg-red-50', 'text' => 'text-red-700', 'ring' => 'ring-red-600/20'],
            self::NonMember => ['bg' => 'bg-stone-100', 'text' => 'text-stone-600', 'ring' => 'ring-stone-500/20'],
            self::Pending => ['bg' => 'bg-sky-50', 'text' => 'text-sky-700', 'ring' => 'ring-sky-600/20'],
            self::Revoked, self::Suspended => ['bg' => 'bg-red-100', 'text' => 'text-red-800', 'ring' => 'ring-red-700/30'],
        };
    }

    public function showsExpiry(): bool
    {
        return $this !== self::NonMember;
    }

    public function constrain(Builder $query): Builder
    {
        $today = now()->startOfDay()->toDateString();
        $graceStart = now()->startOfDay()
            ->subDays(MembershipValidationService::LAPSED_CUTOFF_DAYS)
            ->toDateString();

        $paid = fn (Builder $q) => $q
            ->where('memberships.membership_type', '!=', 'free')
            ->where('memberships.status', '!=', 'revoked')
            ->where('memberships.status', '!=', 'suspended');

        return match ($this) {
            self::NonMember => $query->where('memberships.membership_type', 'free'),
            self::Revoked => $query
                ->where('memberships.membership_type', '!=', 'free')
                ->where('memberships.status', 'revoked'),
            self::Suspended => $query
                ->where('memberships.membership_type', '!=', 'free')
                ->where('memberships.status', 'suspended'),
            self::Pending => $paid($query)
                ->where('memberships.status', 'pending')
                ->whereNull('memberships.expiry_date'),
            self::Lapsed => $paid($query)
                ->where(function (Builder $q) {
                    $q->where('memberships.status', '!=', 'pending')
                        ->orWhereNotNull('memberships.expiry_date');
                })
                ->whereDate('memberships.expiry_date', '<', $today)
                ->whereDate('memberships.expiry_date', '>=', $graceStart),
            self::Expired => $paid($query)
                ->whereDate('memberships.expiry_date', '<', $graceStart),
            self::Active => $paid($query)
                ->where(function (Builder $q) {
                    $q->where('memberships.status', '!=', 'pending')
                        ->orWhereNotNull('memberships.expiry_date');
                })
                ->where(function (Builder $q) use ($today) {
                    $q->whereNull('memberships.expiry_date')
                        ->orWhereDate('memberships.expiry_date', '>=', $today);
                }),
        };
    }
}
