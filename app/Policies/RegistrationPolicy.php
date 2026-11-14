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
     *   - anyone who has completed payment for it (a pending checkout
     *     does not count — that row can be created by any member)
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

        // A completed payment is a real relationship: the sponsor paid and
        // may return to the receipt. A pending checkout is not — any member
        // can start one, and treating it as access leaked the shooter's
        // email on this page.
        return $registration->payments()
            ->where('user_id', $user->id)
            ->where('status', 'completed')
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
