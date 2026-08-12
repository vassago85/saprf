<?php

namespace App\Policies\Selection;

use App\Models\SelectionAthlete;
use App\Models\User;

class SelectionAthletePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['owner', 'admin', 'iprf_selector']);
    }

    public function view(User $user, SelectionAthlete $athlete): bool
    {
        return $user->hasAnyRole(['owner', 'admin', 'iprf_selector']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['owner', 'admin', 'iprf_selector']);
    }

    public function update(User $user, SelectionAthlete $athlete): bool
    {
        return $user->hasAnyRole(['owner', 'admin', 'iprf_selector']);
    }

    public function delete(User $user, SelectionAthlete $athlete): bool
    {
        return $user->hasAnyRole(['owner']);
    }

    public function reevaluate(User $user, SelectionAthlete $athlete): bool
    {
        return $user->hasAnyRole(['owner', 'admin', 'iprf_selector']);
    }
}
