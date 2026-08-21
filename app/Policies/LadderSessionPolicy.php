<?php

namespace App\Policies;

use App\Models\LadderSession;
use App\Models\User;

/**
 * A load recipe is personal intellectual property and several of these
 * shooters compete against each other. Ladder sessions are visible only to
 * the owning member — never to other members, match directors, exco, or the
 * federation admin. AppServiceProvider carves the developer/exco global gate
 * bypass out for this policy so ownership is the whole authorisation model.
 */
class LadderSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, LadderSession $session): bool
    {
        return $session->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, LadderSession $session): bool
    {
        return $session->user_id === $user->id;
    }

    public function delete(User $user, LadderSession $session): bool
    {
        return $session->user_id === $user->id;
    }
}
