<?php

namespace App\Policies;

use App\Models\MatchRegistration;
use App\Models\User;

class RegistrationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MatchRegistration $registration): bool
    {
        if ($user->hasRole(['owner', 'admin'])) {
            return true;
        }

        if ($user->hasRole('match_director')) {
            return $registration->match?->created_by === $user->id;
        }

        return $user->hasRole('member') && $registration->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, MatchRegistration $registration): bool
    {
        if ($user->hasRole(['owner', 'admin'])) {
            return true;
        }

        return $user->hasRole('match_director') && $registration->match?->created_by === $user->id;
    }

    public function delete(User $user, MatchRegistration $registration): bool
    {
        return false;
    }
}
