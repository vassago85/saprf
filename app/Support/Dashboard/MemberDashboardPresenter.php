<?php

namespace App\Support\Dashboard;

use App\Models\AnnouncementRecipient;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Standing;
use App\Models\User;
use App\Services\QualificationService;
use App\Support\Dashboard\Concerns\BuildsDashboardShell;
use Illuminate\Support\Carbon;

final class MemberDashboardPresenter
{
    use BuildsDashboardShell;

    public function __construct(
        private readonly QualificationService $qualification,
    ) {}

    public function build(User $user): DashboardPayload
    {
        $season = $this->season();
        $membership = $user->membership;

        $matchesShot = $user->scores()
            ->whereHas('match', fn ($q) => $q->where(function ($q2) use ($season) {
                $q2->where('season', $season)->orWhereYear('match_date', $season);
            }))
            ->count();

        $rank = Standing::query()
            ->where('user_id', $user->id)
            ->whereNull('province_id')
            ->where('season', $season)
            ->orderBy('rank')
            ->value('rank');

        $progress = $this->qualification->getDashboardProgress($user, $season);
        $qualHint = collect($progress)
            ->map(function ($row) {
                $series = $row['series'] ?? '';
                $oop = $row['oop'] ?? [];
                $required = (int) ($oop['required'] ?? 0);
                if ($required === 0) {
                    return "{$series}: no OOP req.";
                }

                return "{$series}: ".((int) ($oop['completed'] ?? 0))."/{$required}";
            })
            ->implode(' · ');
        $anyQualified = collect($progress)->contains(
            fn ($row) => (bool) (($row['oop']['qualified'] ?? false))
        );

        $credit = $user->accountCreditSummary();
        $creditPosted = (float) ($credit['posted'] ?? 0);

        $attention = [];
        if ($membership?->shouldShowDashboardRenewalNotice()) {
            $days = $membership->daysUntilExpiry();
            $attention[] = [
                'count' => max(1, (int) $days),
                'label' => $days === 0
                    ? 'Membership expires today'
                    : ($days === 1 ? 'Membership expires tomorrow' : "Membership expires in {$days} days"),
                'href' => route('my-membership'),
                'tone' => 'amber',
            ];
        }

        $unpaid = MatchRegistration::query()
            ->where('user_id', $user->id)
            ->whereIn('registration_status', ['confirmed', 'pending'])
            ->whereIn('payment_status', ['unpaid', 'pending'])
            ->count();
        if ($unpaid > 0) {
            $attention[] = [
                'count' => $unpaid,
                'label' => $unpaid === 1 ? 'Unpaid entry' : 'Unpaid entries',
                'href' => route('registrations.index'),
                'tone' => 'amber',
            ];
        }

        $unread = AnnouncementRecipient::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();
        if ($unread > 0) {
            $attention[] = [
                'count' => $unread,
                'label' => $unread === 1 ? 'Unread message' : 'Unread messages',
                'href' => route('communications.index'),
                'tone' => 'amber',
            ];
        }

        if ($creditPosted > 0) {
            $attention[] = [
                'count' => 1,
                'label' => 'Entry credit '.money($creditPosted),
                'href' => route('account.refund'),
                'tone' => 'neutral',
            ];
        }

        $actions = [
            [
                'label' => 'View public profile',
                'href' => route('standings.shooter', ['season' => $season, 'user' => $user->id]),
                'variant' => 'secondary',
            ],
        ];
        if ($membership?->shouldShowDashboardRenewalNotice() || ! $membership?->isActiveMember()) {
            $actions[] = [
                'label' => 'Renew membership',
                'href' => route('my-membership'),
                'variant' => 'primary',
            ];
        }

        $kpis = [
            [
                'label' => 'Matches shot',
                'value' => number_format($matchesShot),
                'hint' => $season.' season',
            ],
            [
                'label' => 'National rank',
                'value' => $rank ? '#'.number_format($rank) : '—',
                'hint' => $rank ? 'Best overall standing' : 'No national standing yet',
                'href' => route('standings.public'),
            ],
            [
                'label' => 'Qualification',
                'value' => $anyQualified ? 'On track' : 'In progress',
                'hint' => $qualHint !== '' ? $qualHint : 'No rules configured',
                'href' => route('events.index'),
            ],
        ];

        if ($creditPosted > 0) {
            $kpis[] = [
                'label' => 'Entry credit',
                'value' => money($creditPosted),
                'hint' => 'Applied on your next entry',
                'href' => route('account.refund'),
                'accent' => 'emerald',
            ];
        } else {
            $nextMatch = MatchEvent::query()
                ->where('match_date', '>=', Carbon::today())
                ->where('status', 'open')
                ->orderBy('match_date')
                ->first();
            $kpis[] = [
                'label' => 'Next open event',
                'value' => $nextMatch ? date_short($nextMatch->match_date) : '—',
                'hint' => $nextMatch?->name ?? 'Nothing open yet',
                'href' => $nextMatch ? route('events.show', $nextMatch) : route('events.index'),
            ];
        }

        return new DashboardPayload(
            greeting: $this->greeting($user),
            season: $season,
            actions: $actions,
            attention: $attention,
            kpis: $kpis,
        );
    }
}
