<?php

namespace App\Policies;

use App\Models\MatchEvent;
use App\Models\User;

class MatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['owner', 'admin', 'match_director']);
    }

    public function view(User $user, MatchEvent $match): bool
    {
        if ($user->hasRole(['owner', 'admin', 'member'])) {
            return true;
        }

        return $user->hasRole('match_director') && $match->created_by === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['owner', 'admin', 'match_director']);
    }

    public function update(User $user, MatchEvent $match): bool
    {
        if ($user->hasRole(['owner', 'admin'])) {
            return true;
        }

        return $user->hasRole('match_director') && $match->created_by === $user->id;
    }

    public function delete(User $user, MatchEvent $match): bool
    {
        return false;
    }

    public function restore(User $user, MatchEvent $match): bool
    {
        return false;
    }

    public function forceDelete(User $user, MatchEvent $match): bool
    {
        return false;
    }
}
