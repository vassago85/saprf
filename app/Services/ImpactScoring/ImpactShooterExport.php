<?php

namespace App\Services\ImpactScoring;

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use Illuminate\Support\Collection;

/**
 * One CSV row per non-cancelled registration.
 *
 * Impact's shooter CSV guide requires every Email in the file to be unique
 * (https://www.impactscoring.net/match/view-shooters-csv-format). A managed
 * member's entry stores the parent's address, so two registrations can share
 * one email. The person who owns that address keeps it. The other row is
 * still written, with Email left blank, so the shooter stays on the list
 * for indemnity with their own name, phone, division, category and member
 * number. A registration with no email at all is omitted and reported.
 */
class ImpactShooterExport
{
    /**
     * @var array<string, string>
     */
    private const CATEGORY_BY_SLUG = [
        'ladies' => 'Lady',
        'junior' => 'Junior',
        'senior' => 'Senior',
    ];

    /**
     * @return array{headers: list<string>, rows: list<list<string>>, omitted: list<array{id: int, name: string}>}
     */
    public function build(MatchEvent $match): array
    {
        $registrations = $match->registrations()
            ->where('registration_status', '!=', 'cancelled')
            ->with(['user.membership', 'user.parent', 'division'])
            ->orderBy('registered_at')
            ->orderBy('id')
            ->get();

        $emails = [];
        foreach ($registrations as $registration) {
            $emails[$registration->id] = $this->contactEmail($registration);
        }

        $claimed = $this->claimEmails($registrations, $emails);

        $rows = [];
        $omitted = [];

        foreach ($registrations as $registration) {
            $email = $claimed[$registration->id] ?? '';
            if ($emails[$registration->id] === '') {
                $omitted[] = [
                    'id' => $registration->id,
                    'name' => $this->nameFor($registration),
                ];

                continue;
            }

            $rows[] = $this->row($registration, $email);
        }

        return [
            'headers' => ['Email', 'Name', 'Phone', 'Squad', 'Division', 'Categories', 'Member Number'],
            'rows' => $rows,
            'omitted' => $omitted,
        ];
    }

    /**
     * The account that owns an address keeps it. Everyone else sharing it
     * gets a blank Email cell. The earliest registration keeps an address
     * that no account on the match actually owns.
     *
     * @param  Collection<int, MatchRegistration>  $registrations
     * @param  array<int, string>  $emails
     * @return array<int, string>
     */
    private function claimEmails($registrations, array $emails): array
    {
        $ownerIdByEmail = [];
        foreach ($registrations as $registration) {
            $email = $emails[$registration->id];
            if ($email === '') {
                continue;
            }
            $key = strtolower($email);
            $own = strtolower(trim((string) ($registration->user?->email ?? '')));
            if ($own === $key && ! isset($ownerIdByEmail[$key])) {
                $ownerIdByEmail[$key] = $registration->id;
            }
        }

        $claimedBy = [];
        $final = [];
        foreach ($registrations as $registration) {
            $email = $emails[$registration->id];
            if ($email === '') {
                $final[$registration->id] = '';

                continue;
            }
            $key = strtolower($email);
            $ownerId = $ownerIdByEmail[$key] ?? null;
            if ($ownerId === $registration->id || ($ownerId === null && ! isset($claimedBy[$key]))) {
                $final[$registration->id] = $email;
                $claimedBy[$key] = $registration->id;

                continue;
            }
            $final[$registration->id] = '';
        }

        return $final;
    }

    private function contactEmail(MatchRegistration $registration): string
    {
        $user = $registration->user;
        $own = trim((string) ($user?->email ?? ''));
        if ($own !== '' && ! $this->isPlaceholder($own)) {
            return $own;
        }

        $onEntry = trim((string) ($registration->email ?? ''));
        if ($onEntry !== '') {
            return $onEntry;
        }

        if ($user?->isManaged()) {
            return trim((string) ($user->parent?->email ?? ''));
        }

        return '';
    }

    private function nameFor(MatchRegistration $registration): string
    {
        $name = trim((string) ($registration->user?->name ?? ''));

        return $name !== '' ? $name : trim((string) $registration->shooter_name);
    }

    private function memberNumber(MatchRegistration $registration): string
    {
        return trim((string) ($registration->user?->membership?->saprf_number ?? ''));
    }

    /**
     * @return list<string>
     */
    private function row(MatchRegistration $registration, string $email): array
    {
        $slug = $registration->division?->slug;
        $category = $slug !== null ? (self::CATEGORY_BY_SLUG[$slug] ?? '') : '';
        $division = $category !== '' ? '' : ($registration->division?->name ?: '');

        return [
            $email,
            $this->nameFor($registration),
            $registration->contactPhone(),
            '',
            $division,
            $category,
            $this->memberNumber($registration),
        ];
    }

    private function isPlaceholder(string $email): bool
    {
        return str_ends_with(strtolower($email), '@managed.saprf.co.za');
    }
}
