<?php

namespace App\Support\Dashboard\Shooter;

use App\Models\MatchRegistration;
use App\Models\User;

/**
 * Membership strip at the foot of the shooter dashboard.
 *
 * One flat associative array:
 *   status label + tone, SAPRF number, membership type, expiry date,
 *   whether the member is in the renewal window, count of linked family
 *   members, and the sum/count of outstanding match-entry fees.
 *
 * Payment status is derived from the same `hasOutstandingPayment()`
 * contract used by the registration list — a row is "unpaid" when
 * payment_status is not paid/waived AND fee_amount > 0. This keeps the
 * dashboard's "unpaid" count aligned with every other screen that uses
 * that method.
 */
final class MembershipStripBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        $m = $user->membership;

        $statusLabel = $m?->effective_status_label ?? 'Free account';
        $tone = match (true) {
            $m === null => 'neutral',
            str_contains($statusLabel, 'Active') => 'ok',
            str_contains($statusLabel, 'Lapsed') => 'warn',
            str_contains($statusLabel, 'Expired') => 'bad',
            default => 'neutral',
        };

        $typeLabel = $m ? ($m->membership_type === 'free' ? 'free' : 'paid') : null;

        $familyCount = $user->juniors()->count();

        $outstanding = MatchRegistration::query()
            ->where('user_id', $user->id)
            ->whereIn('registration_status', ['pending', 'confirmed'])
            ->whereNotIn('payment_status', ['paid', 'waived'])
            ->where('fee_amount', '>', 0)
            ->get(['id', 'fee_amount']);

        return [
            'has_membership' => $m !== null,
            'status_label' => $statusLabel,
            'status_tone' => $tone,
            'type_label' => $typeLabel,
            'saprf_number' => $m?->saprf_number,
            'expiry_date' => $m?->expiry_date?->format('d M Y'),
            'in_renewal_window' => $m?->isWithinRenewalWindow() ?? false,
            'family_count' => $familyCount,
            'unpaid_count' => $outstanding->count(),
            'unpaid_total' => (float) $outstanding->sum('fee_amount'),
        ];
    }
}
