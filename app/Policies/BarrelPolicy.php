<?php

namespace App\Policies;

use App\Models\Barrel;
use App\Models\User;

/**
 * Barrels are private to the owning member. Nothing on a barrel is visible to
 * other members, match directors, or the federation admin — a barrel and its
 * round count are personal equipment records. The global Gate::before bypass
 * for developer/exco is deliberately overridden in AppServiceProvider so
 * ownership is the only authorisation path here.
 */
class BarrelPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Barrel $barrel): bool
    {
        return $barrel->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Barrel $barrel): bool
    {
        return $barrel->user_id === $user->id;
    }

    public function delete(User $user, Barrel $barrel): bool
    {
        return $barrel->user_id === $user->id;
    }
}
