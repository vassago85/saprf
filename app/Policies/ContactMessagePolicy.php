<?php

namespace App\Policies;

use App\Models\ContactMessage;
use App\Models\User;

/**
 * developer + exco bypass via AppServiceProvider::Gate::before. This
 * policy therefore only needs to grant owner + admin access — they are
 * the people expected to triage the shared inbox.
 */
class ContactMessagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['owner', 'admin']);
    }

    public function view(User $user, ContactMessage $message): bool
    {
        return $user->hasRole(['owner', 'admin']);
    }

    public function update(User $user, ContactMessage $message): bool
    {
        return $user->hasRole(['owner', 'admin']);
    }

    public function delete(User $user, ContactMessage $message): bool
    {
        return $user->hasRole('owner');
    }
}
