<?php

namespace App\Support\Dashboard;

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\User;
use App\Support\Dashboard\Concerns\BuildsDashboardShell;
use Illuminate\Support\Carbon;

final class MatchDirectorDashboardPresenter
{
    use BuildsDashboardShell;

    public function build(User $user): DashboardPayload
    {
        $myMatchIds = MatchEvent::query()->where('created_by', $user->id)->pluck('id');

        $myMatchesCount = $myMatchIds->count();
        $myUpcoming = MatchEvent::query()
            ->where('created_by', $user->id)
            ->where('match_date', '>=', Carbon::today())
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->count();
        $pendingRegistrations = MatchRegistration::query()
            ->whereIn('match_id', $myMatchIds)
            ->where('registration_status', 'pending')
            ->count();
        $unpaidEntries = MatchRegistration::query()
            ->whereIn('match_id', $myMatchIds)
            ->whereIn('registration_status', ['confirmed', 'pending'])
            ->whereIn('payment_status', ['unpaid', 'pending'])
            ->count();

        $attention = [];
        if ($pendingRegistrations > 0) {
            $attention[] = [
                'count' => $pendingRegistrations,
                'label' => 'Pending registrations',
                'href' => route('registrations.index'),
                'tone' => 'amber',
            ];
        }
        if ($unpaidEntries > 0) {
            $attention[] = [
                'count' => $unpaidEntries,
                'label' => 'Unpaid entries',
                'href' => route('registrations.index', ['payment_status' => 'unpaid']),
                'tone' => 'amber',
            ];
        }

        $pendingRows = MatchRegistration::query()
            ->with(['match', 'user'])
            ->whereIn('match_id', $myMatchIds)
            ->where('registration_status', 'pending')
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (MatchRegistration $r) => [
                'type' => 'activity',
                'dot' => 'amber',
                'title' => $r->user?->name ?? $r->shooter_name ?? 'Shooter',
                'body' => 'pending on '.($r->match?->name ?? 'a match'),
                'meta' => $r->created_at?->diffForHumans() ?? '',
                'href' => route('registrations.show', $r),
            ])
            ->all();

        return new DashboardPayload(
            greeting: $this->greeting($user),
            actions: [
                [
                    'label' => 'Create match',
                    'href' => route('matches.create'),
                    'variant' => 'primary',
                ],
                [
                    'label' => 'Upload scores',
                    'href' => route('score-imports.index'),
                    'variant' => 'secondary',
                ],
            ],
            attention: $attention,
            kpis: [
                [
                    'label' => 'My matches',
                    'value' => number_format($myMatchesCount),
                    'href' => route('matches.index', ['mine' => true]),
                    'hint' => 'All time',
                ],
                [
                    'label' => 'Upcoming',
                    'value' => number_format($myUpcoming),
                    'href' => route('matches.index', ['mine' => true]),
                    'hint' => 'From today forward',
                ],
                [
                    'label' => 'Pending registrations',
                    'value' => number_format($pendingRegistrations),
                    'href' => route('registrations.index'),
                    'hint' => 'Need confirmation',
                    'accent' => $pendingRegistrations > 0 ? 'amber' : null,
                ],
                [
                    'label' => 'Unpaid entries',
                    'value' => number_format($unpaidEntries),
                    'href' => route('registrations.index', ['payment_status' => 'unpaid']),
                    'hint' => 'Outstanding fees',
                    'accent' => $unpaidEntries > 0 ? 'amber' : null,
                ],
            ],
            primary: [
                'title' => 'My upcoming matches',
                'href' => route('matches.index', ['mine' => true]),
                'href_label' => 'All my matches',
                'rows' => $this->matchPanelRows(
                    $this->upcomingMatches(5, fn ($q) => $q->where('created_by', $user->id))
                ),
            ],
            secondary: [
                'title' => 'Pending registrations',
                'href' => route('registrations.index'),
                'href_label' => 'All registrations',
                'rows' => $pendingRows !== [] ? $pendingRows : [[
                    'type' => 'empty',
                    'body' => 'No pending registrations on your matches.',
                ]],
            ],
        );
    }
}
