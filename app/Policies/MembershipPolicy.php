<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\User;

class MembershipPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['owner', 'admin', 'member']);
    }

    public function view(User $user, Membership $membership): bool
    {
        if ($user->hasRole(['owner', 'admin'])) {
            return true;
        }

        return $user->hasRole('member') && $membership->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['owner', 'admin']);
    }

    public function update(User $user, Membership $membership): bool
    {
        return $user->hasRole(['owner', 'admin']);
    }

    public function delete(User $user, Membership $membership): bool
    {
        return false;
    }
}
