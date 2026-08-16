<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * Resolves who receives federation staff mail.
 *
 * Site Settings can name dedicated ExCo and owner inboxes. When those are
 * set, they are the destinations (plus any developer accounts so the
 * platform operator still sees copies). Role users whose personal address
 * is not one of those inboxes are skipped — that is what stops a shared
 * admin@ address being the dump for every notification.
 *
 * When no dedicated inbox is configured, every user holding the given
 * roles is notified, which is the original behaviour.
 */
class StaffInboxService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function notify(
        Notification $notification,
        array $roles,
        bool $includeExcoInbox = false,
        bool $includeOwnerInbox = false,
    ): void {
        $inboxes = collect();

        if ($includeExcoInbox && ($email = $this->settings->excoEmail())) {
            $inboxes->push($email);
        }

        if ($includeOwnerInbox && ($email = $this->settings->ownerEmail())) {
            $inboxes->push($email);
        }

        $inboxes = $inboxes
            ->map(fn (string $email) => strtolower($email))
            ->unique()
            ->values();

        $users = User::role($roles)->whereNotNull('email')->get();

        if ($inboxes->isNotEmpty()) {
            $users = $users->filter(function (User $user) use ($inboxes) {
                return $user->hasRole('developer')
                    || $inboxes->contains(strtolower((string) $user->email));
            });
        }

        $userEmails = $users
            ->pluck('email')
            ->map(fn ($email) => strtolower((string) $email));

        if ($users->isNotEmpty()) {
            NotificationFacade::send($users, $notification);
        }

        foreach ($inboxes->diff($userEmails) as $email) {
            NotificationFacade::route('mail', $email)->notify($notification);
        }
    }
}
