<?php

namespace App\Policies;

use App\Models\MatchRegistration;
use App\Models\User;

class RegistrationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Who may open a registration's detail page:
     *   - owner / admin — always
     *   - match director — their own matches
     *   - the shooter themselves
     *   - the account that created the entry (parent, sponsor)
     *   - anyone who has paid for it (sponsor paying an unpaid entry)
     */
    public function view(User $user, MatchRegistration $registration): bool
    {
        if ($user->hasRole(['owner', 'admin'])) {
            return true;
        }

        if ($user->hasRole('match_director') && $registration->match?->created_by === $user->id) {
            return true;
        }

        if ($registration->user_id === $user->id) {
            return true;
        }

        if ($registration->registered_by_user_id === $user->id) {
            return true;
        }

        // Any past or in-flight payer of this entry can see it, so a sponsor
        // who paid another member's fee can return to the receipt page.
        return $registration->payments()
            ->where('user_id', $user->id)
            ->exists();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, MatchRegistration $registration): bool
    {
        if ($user->hasRole(['owner', 'admin'])) {
            return true;
        }

        return $user->hasRole('match_director') && $registration->match?->created_by === $user->id;
    }

    public function delete(User $user, MatchRegistration $registration): bool
    {
        return false;
    }
}
