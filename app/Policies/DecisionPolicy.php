<?php

namespace App\Policies;

use App\Models\Decision;
use App\Models\User;

/**
 * ExCo-only register. Route middleware (`role:developer|exco|chair`) is
 * the primary gate; this policy mirrors ExcoMeetingPolicy so owner/admin
 * stay out even if a controller calls authorize() without the middleware.
 *
 * Gate::before auto-allows developer|exco; chair reaches here via isExco().
 */
class DecisionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isExco();
    }

    public function view(User $user, Decision $decision): bool
    {
        return $user->isExco();
    }

    public function create(User $user): bool
    {
        return $user->isExco();
    }

    public function update(User $user, Decision $decision): bool
    {
        return $user->isExco();
    }

    public function publish(User $user, Decision $decision): bool
    {
        return $user->isExco() && $decision->isDraft();
    }

    public function delete(User $user, Decision $decision): bool
    {
        return $user->isExco() && $decision->isDraft();
    }
}
