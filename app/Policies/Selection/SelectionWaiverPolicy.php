<?php

namespace App\Policies\Selection;

use App\Models\SelectionWaiver;
use App\Models\User;

class SelectionWaiverPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['owner', 'admin', 'iprf_selector']);
    }

    public function view(User $user, SelectionWaiver $waiver): bool
    {
        return $user->hasAnyRole(['owner', 'admin', 'iprf_selector']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['owner', 'admin', 'iprf_selector']);
    }

    public function update(User $user, SelectionWaiver $waiver): bool
    {
        return $user->hasAnyRole(['owner', 'admin', 'iprf_selector']);
    }

    public function decide(User $user, SelectionWaiver $waiver): bool
    {
        return $user->hasAnyRole(['owner', 'iprf_selector']);
    }
}
