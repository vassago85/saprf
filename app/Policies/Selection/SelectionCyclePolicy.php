<?php

namespace App\Policies\Selection;

use App\Models\SelectionCycle;
use App\Models\User;

class SelectionCyclePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['owner', 'admin', 'iprf_selector']);
    }

    public function view(User $user, SelectionCycle $cycle): bool
    {
        return $user->hasAnyRole(['owner', 'admin', 'iprf_selector']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['owner']);
    }

    public function update(User $user, SelectionCycle $cycle): bool
    {
        return $user->hasAnyRole(['owner']);
    }

    public function importPolicy(User $user, SelectionCycle $cycle): bool
    {
        return $user->hasAnyRole(['owner']);
    }

    public function reevaluate(User $user, SelectionCycle $cycle): bool
    {
        return $user->hasAnyRole(['owner', 'admin', 'iprf_selector']);
    }
}
