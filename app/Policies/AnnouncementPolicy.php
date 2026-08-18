<?php

namespace App\Policies;

use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\User;

/**
 * Gate: `Gate::before` in AppServiceProvider auto-allows every ability
 * for `developer` and `exco`, so the checks here are the fallback for
 * Chair-only actions and for members reading announcements addressed
 * to them. Chair inherits everything from Exco via that bypass anyway.
 */
class AnnouncementPolicy
{
    /**
     * Any authenticated user can hit the /communications archive — they
     * only see rows they were snapshotted onto, but the route itself
     * doesn't need role gating.
     */
    public function viewArchive(User $user): bool
    {
        return true;
    }

    /**
     * Read a specific announcement. Members must be on the recipient
     * snapshot; Exco/Chair see every announcement (via Gate::before).
     */
    public function view(User $user, Announcement $announcement): bool
    {
        return $announcement->recipients()
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * Compose / list from the Exco side. Chair implies exco via
     * user-management, and Gate::before already grants this — this method
     * exists so tests can call `->can('composeAnnouncement')` without
     * relying purely on the global bypass.
     */
    public function compose(User $user): bool
    {
        return $user->isExco();
    }

    /**
     * Only Exco/Chair can edit a draft/scheduled announcement, and only
     * before it starts sending.
     */
    public function update(User $user, Announcement $announcement): bool
    {
        return $user->isExco() && $announcement->status->isEditable();
    }

    /**
     * Approve a Policy change. Enforced additionally by the publisher:
     *   - author cannot approve their own
     *   - approver must be Chair or a *different* Exco user
     *
     * See AnnouncementPublisher::approve() for the runtime check that
     * covers the "self-approval" case Gate::before would otherwise wave
     * through.
     */
    public function approve(User $user, Announcement $announcement): bool
    {
        if (! $user->isExco()) {
            return false;
        }

        return $announcement->created_by !== $user->id;
    }

    /**
     * Cancel a draft/scheduled announcement. Once sending has started
     * we no longer offer the option — trying to un-send is a foot-gun.
     */
    public function cancel(User $user, Announcement $announcement): bool
    {
        return $user->isExco()
            && in_array($announcement->status, [
                AnnouncementStatus::Draft,
                AnnouncementStatus::Scheduled,
            ], true);
    }
}
