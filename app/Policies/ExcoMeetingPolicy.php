<?php

namespace App\Policies;

use App\Enums\ExcoMeetingStatus;
use App\Models\ExcoMeeting;
use App\Models\User;

/**
 * Gate: `Gate::before` in AppServiceProvider auto-allows every ability
 * for `developer` and `exco`. Chair inherits from Exco via the
 * user-management assignment rule, so `isExco()` is the single source
 * of truth for "can act on ExCo meetings". Owner and admin have no
 * business reading these rows and are refused here.
 *
 * Route middleware (`role:developer|exco|chair`) is the primary gate;
 * this policy is the belt-and-braces check for controller actions that
 * call `authorize()` explicitly.
 */
class ExcoMeetingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isExco();
    }

    public function view(User $user, ExcoMeeting $meeting): bool
    {
        return $user->isExco();
    }

    public function create(User $user): bool
    {
        return $user->isExco();
    }

    /**
     * Every ExCo member can edit meeting metadata (title, date, notes)
     * up until the sitting is closed. Once closed the record is
     * historical — re-opening requires a policy change / migration.
     * Archived meetings are also read-only regardless of status.
     */
    public function update(User $user, ExcoMeeting $meeting): bool
    {
        return $user->isExco()
            && $meeting->status !== ExcoMeetingStatus::Closed
            && ! $meeting->isArchived();
    }

    /**
     * Hard deletion is allowed for draft and held meetings so ExCo can
     * clean up test sittings or abandoned sessions. Closed meetings
     * cannot be hard-deleted — use archive() instead to keep the audit
     * trail and any linked action items intact.
     */
    public function delete(User $user, ExcoMeeting $meeting): bool
    {
        return $user->isExco()
            && $meeting->status !== ExcoMeetingStatus::Closed;
    }

    /**
     * Archive is the escape hatch for a closed meeting created by
     * mistake or superseded. Soft-hide, fully reversible via
     * unarchive(). Only closed meetings qualify — draft/held have
     * hard delete for the same "get rid of it" use case.
     */
    public function archive(User $user, ExcoMeeting $meeting): bool
    {
        return $user->isExco()
            && $meeting->status === ExcoMeetingStatus::Closed
            && ! $meeting->isArchived();
    }

    public function unarchive(User $user, ExcoMeeting $meeting): bool
    {
        return $user->isExco() && $meeting->isArchived();
    }
}
