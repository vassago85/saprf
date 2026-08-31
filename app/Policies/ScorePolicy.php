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

    /**
     * Anyone who can plausibly answer "did this person actually turn up?"
     * for a match: senior admins, the MD who owns the match, and the person
     * who uploaded the score sheet (often but not always the same MD).
     * Non-elevated match directors can only act on their own matches.
     */
    public function confirmParticipation(User $user, Score $score): bool
    {
        if ($user->hasRole(['owner', 'admin', 'exco', 'developer'])) {
            return true;
        }

        if ($score->match?->created_by === $user->id) {
            return true;
        }

        return $score->import?->uploaded_by === $user->id;
    }
}
