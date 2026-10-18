<?php

namespace App\Support\Dashboard;

use App\Models\MatchEvent;
use App\Models\User;
use App\Support\Dashboard\Concerns\BuildsDashboardShell;
use Illuminate\Support\Carbon;

final class ProvincialAdminDashboardPresenter
{
    use BuildsDashboardShell;

    public function build(User $user): DashboardPayload
    {
        $committeePositions = $user->committeePositions()
            ->where('is_active', true)
            ->with('province')
            ->get();

        $provinceIds = $committeePositions->pluck('province_id')->unique()->values()->all();

        $membersCount = $provinceIds === []
            ? 0
            : User::query()->whereIn('province_id', $provinceIds)->count();

        $activeCount = $provinceIds === []
            ? 0
            : User::query()
                ->whereIn('province_id', $provinceIds)
                ->whereHas('membership', fn ($q) => $q->active())
                ->count();

        $upcoming = $provinceIds === []
            ? collect()
            : MatchEvent::query()
                ->with('province')
                ->withCount([
                    'registrations as registrations_count' => fn ($q) => $q
                        ->whereIn('registration_status', ['confirmed', 'waitlisted']),
                ])
                ->whereIn('province_id', $provinceIds)
                ->where('match_date', '>=', Carbon::today())
                ->whereNotIn('status', ['cancelled'])
                ->orderBy('match_date')
                ->limit(5)
                ->get();

        $attention = [];
        if ($committeePositions->isEmpty()) {
            $attention[] = [
                'count' => 1,
                'label' => 'No committee position assigned',
                'href' => route('provincial-committees.index'),
                'tone' => 'amber',
            ];
        }

        $secondaryRows = [
            [
                'type' => 'link',
                'label' => 'Provincial members',
                'href' => route('provincial-members.index'),
                'hint' => number_format($membersCount).' members',
            ],
        ];
        if ($user->hasAnyRole(['exco', 'owner', 'developer'])) {
            $secondaryRows[] = [
                'type' => 'link',
                'label' => 'SASCOC report',
                'href' => route('sascoc-report.index'),
                'hint' => 'Export for the federation',
            ];
        }

        $provinceLabel = $committeePositions->count() === 1
            ? ($committeePositions->first()->province?->name ?? 'your province')
            : 'your provinces';

        return new DashboardPayload(
            greeting: $this->greeting($user),
            attention: $attention,
            kpis: [
                [
                    'label' => 'Members in '.$provinceLabel,
                    'value' => number_format($membersCount),
                    'href' => route('provincial-members.index'),
                    'hint' => 'All statuses',
                ],
                [
                    'label' => 'Active memberships',
                    'value' => number_format($activeCount),
                    'href' => route('provincial-members.index'),
                    'hint' => 'Paid and current',
                ],
                [
                    'label' => 'Upcoming matches',
                    'value' => number_format($upcoming->count()),
                    'href' => route('matches.index'),
                    'hint' => 'In your province'.($committeePositions->count() === 1 ? '' : 's'),
                ],
                [
                    'label' => 'Committee seats',
                    'value' => number_format($committeePositions->count()),
                    'href' => route('provincial-committees.index'),
                    'hint' => $committeePositions->pluck('province.name')->filter()->implode(', ') ?: 'None assigned',
                ],
            ],
            primary: [
                'title' => 'Upcoming matches',
                'href' => route('matches.index'),
                'href_label' => 'All matches',
                'rows' => $this->matchPanelRows($upcoming),
            ],
            secondary: [
                'title' => 'Quick links',
                'rows' => $secondaryRows,
            ],
        );
    }
}
