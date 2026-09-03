<?php

namespace App\Policies;

use App\Models\MatchEvent;
use App\Models\User;

class MatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['owner', 'admin', 'match_director']);
    }

    public function view(User $user, MatchEvent $match): bool
    {
        if ($user->hasAnyRole(['owner', 'admin'])) {
            return true;
        }

        return $user->hasRole('match_director') && $match->created_by === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['owner', 'admin', 'match_director']);
    }

    public function update(User $user, MatchEvent $match): bool
    {
        if ($user->hasAnyRole(['owner', 'admin'])) {
            return true;
        }

        return $user->hasRole('match_director') && $match->created_by === $user->id;
    }

    public function delete(User $user, MatchEvent $match): bool
    {
        return false;
    }

    // Reassigning ownership is an EXCO-level operational override. Owner /
    // admin / the match director themselves must not be able to hand a
    // match off — that has to route through EXCO (or developer/chair).
    public function changeDirector(User $user, MatchEvent $match): bool
    {
        return $user->hasAnyRole(['developer', 'exco', 'chair']);
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
