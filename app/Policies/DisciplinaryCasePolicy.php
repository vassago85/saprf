<?php

namespace App\Policies;

use App\Models\DisciplinaryCase;
use App\Models\User;

/**
 * Disciplinary cases are strictly ExCo/Chair (and developer via the
 * global Gate::before). Owner, admin, MDs, provincial admins and
 * members must never see them — the route middleware refuses them
 * before this policy runs, and every method here mirrors that check
 * so a stray controller call cannot leak a row.
 */
class DisciplinaryCasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isExco();
    }

    public function view(User $user, DisciplinaryCase $case): bool
    {
        return $user->isExco();
    }

    public function create(User $user): bool
    {
        return $user->isExco();
    }

    public function update(User $user, DisciplinaryCase $case): bool
    {
        return $user->isExco();
    }

    /**
     * A case can only be deleted while it is empty of notes and
     * attachments — otherwise the timeline of who added what would
     * disappear silently. Enforced in the controller as well.
     */
    public function delete(User $user, DisciplinaryCase $case): bool
    {
        return $user->isExco();
    }
}
