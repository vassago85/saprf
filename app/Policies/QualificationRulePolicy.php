<?php

namespace App\Policies;

use App\Models\QualificationRule;
use App\Models\User;

class QualificationRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['owner', 'admin']);
    }

    public function view(User $user, QualificationRule $rule): bool
    {
        return $user->hasRole(['owner', 'admin']);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('owner');
    }

    public function update(User $user, QualificationRule $rule): bool
    {
        return $user->hasRole('owner');
    }

    public function delete(User $user, QualificationRule $rule): bool
    {
        return false;
    }
}
