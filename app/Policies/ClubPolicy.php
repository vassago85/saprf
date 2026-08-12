<?php

namespace App\Policies;

use App\Models\Club;
use App\Models\User;

/**
 * Governs the /clubs admin surface. developer + exco bypass via
 * AppServiceProvider::Gate::before, so this policy only needs to grant
 * access to owner + admin. Deleting is restricted to owner because it can
 * strand user records if the club still has members (we hard-block that in
 * the controller too).
 */
class ClubPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['owner', 'admin']);
    }

    public function view(User $user, Club $club): bool
    {
        return $user->hasRole(['owner', 'admin']);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['owner', 'admin']);
    }

    public function update(User $user, Club $club): bool
    {
        return $user->hasRole(['owner', 'admin']);
    }

    public function delete(User $user, Club $club): bool
    {
        return $user->hasRole('owner');
    }

    public function merge(User $user, Club $club): bool
    {
        return $user->hasRole('owner');
    }
}
