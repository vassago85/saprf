<?php

namespace App\Policies\Selection;

use App\Models\SelectionAppeal;
use App\Models\User;

class SelectionAppealPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['owner', 'admin', 'iprf_selector']);
    }

    public function view(User $user, SelectionAppeal $appeal): bool
    {
        return $user->hasAnyRole(['owner', 'admin', 'iprf_selector']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['owner', 'admin', 'iprf_selector']);
    }

    public function update(User $user, SelectionAppeal $appeal): bool
    {
        return $user->hasAnyRole(['owner', 'admin', 'iprf_selector']);
    }

    public function decide(User $user, SelectionAppeal $appeal): bool
    {
        return $user->hasAnyRole(['owner']);
    }
}
