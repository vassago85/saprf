<?php

namespace App\Services;

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\User;
use App\Notifications\MatchWhatsappInviteNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Emails the match WhatsApp invite to confirmed entrants who are allowed
 * to see it. One address receives one mail, even when a parent has two
 * juniors on the match. The mail's only button is the group link.
 */
class MatchWhatsappInviteMailer
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @return array{ok: bool, message: string}
     */
    public function send(User $actor, MatchEvent $match): array
    {
        $inviteUrl = $match->whatsappInviteUrl();
        if ($inviteUrl === null) {
            return [
                'ok' => false,
                'message' => 'Add a WhatsApp group link on match settings before emailing it.',
            ];
        }

        $registrations = MatchRegistration::query()
            ->with(['user.parent', 'registeredBy'])
            ->where('match_id', $match->id)
            ->where('registration_status', 'confirmed')
            ->get();

        $registrations->each(fn (MatchRegistration $registration) => $registration->setRelation('match', $match));

        $sent = [];
        $noEmail = 0;

        foreach ($registrations as $registration) {
            if (! $registration->canAccessWhatsappInvite()) {
                continue;
            }

            $audience = $this->audience($registration);
            if ($audience === null) {
                $noEmail++;

                continue;
            }

            if (isset($sent[$audience['email']])) {
                continue;
            }

            $notification = new MatchWhatsappInviteNotification(
                $match,
                $inviteUrl,
                $audience['user'] ? null : $audience['name'],
            );

            if ($audience['user']) {
                $audience['user']->notify($notification);
            } else {
                Notification::route('mail', $audience['email'])->notify($notification);
            }

            $sent[$audience['email']] = true;
        }

        $count = count($sent);

        if ($count === 0) {
            return [
                'ok' => false,
                'message' => 'Nobody was emailed. Confirmed entrants who have paid, or are on a free entry, and have a real email address receive the link.',
            ];
        }

        $this->auditLogService->log(
            $actor,
            'match.whatsapp_invite.emailed',
            'MatchEvent',
            $match->id,
            null,
            [
                'recipient_count' => $count,
                'no_email' => $noEmail,
            ],
        );

        $message = 'Emailed the WhatsApp group link to '.$count.' '.($count === 1 ? 'person' : 'people').'. Each mail has one button to join the group.';
        if ($noEmail > 0) {
            $message .= ' '.$noEmail.' confirmed '.($noEmail === 1 ? 'entrant had' : 'entrants had').' no email address.';
        }

        return ['ok' => true, 'message' => $message];
    }

    /**
     * @return array{user: ?User, email: string, name: string}|null
     */
    private function audience(MatchRegistration $registration): ?array
    {
        $entrant = $registration->user;
        $target = $entrant;

        if ($entrant && $entrant->isManaged()) {
            $target = $registration->registeredBy ?? $entrant->parent;
        }

        if ($target && $this->isDeliverable($target->email)) {
            return [
                'user' => $target,
                'email' => strtolower($target->email),
                'name' => $target->name,
            ];
        }

        $fallback = trim((string) $registration->email);
        if ($this->isDeliverable($fallback)) {
            return [
                'user' => null,
                'email' => strtolower($fallback),
                'name' => $registration->shooter_name ?: 'there',
            ];
        }

        return null;
    }

    private function isDeliverable(?string $email): bool
    {
        $email = strtolower(trim((string) $email));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return ! str_ends_with($email, '@saprf.walkin');
    }
}
