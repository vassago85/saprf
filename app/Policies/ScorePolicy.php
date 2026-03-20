<?php

namespace App\Policies;

use App\Models\Score;
use App\Models\User;

class ScorePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Score $score): bool
    {
        if ($user->hasRole(['owner', 'admin'])) {
            return true;
        }

        if ($user->hasRole('match_director')) {
            return $score->match?->created_by === $user->id;
        }

        return $user->hasRole('member') && $score->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['owner', 'admin', 'match_director']);
    }

    public function update(User $user, Score $score): bool
    {
        return $user->hasRole(['owner', 'admin']);
    }

    public function override(User $user, Score $score): bool
    {
        return $user->hasRole(['owner', 'admin']);
    }

    public function delete(User $user, Score $score): bool
    {
        return false;
    }
}
