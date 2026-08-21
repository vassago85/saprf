<?php

namespace App\Policies;

use App\Models\AmmoString;
use App\Models\User;

/**
 * Confirmation strings are the same class of personal intellectual property
 * as ladders — they reveal exactly what any given member is shooting and how
 * well it's grouping. Owner-only; the developer/exco gate bypass in
 * AppServiceProvider is explicitly carved out for AmmoString the same way
 * it is for Barrel and LadderSession.
 */
class AmmoStringPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AmmoString $string): bool
    {
        return $string->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, AmmoString $string): bool
    {
        return $string->user_id === $user->id;
    }

    public function delete(User $user, AmmoString $string): bool
    {
        return $string->user_id === $user->id;
    }
}
