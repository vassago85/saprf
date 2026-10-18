<?php

namespace App\Support\Dashboard\Concerns;

use App\Models\AuditLog;
use App\Models\MatchEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

trait BuildsDashboardShell
{
    protected function greeting(User $user): string
    {
        return (string) Str::before($user->name, ' ');
    }

    protected function season(?string $season = null): string
    {
        return $season ?: (string) Carbon::now()->year;
    }

    /**
     * @param  Collection<int, MatchEvent>|iterable<MatchEvent>  $matches
     * @return list<array<string, mixed>>
     */
    protected function matchPanelRows(iterable $matches): array
    {
        $rows = [];

        foreach ($matches as $match) {
            $confirmed = $match->registrations_count
                ?? $match->registrations()
                    ->whereIn('registration_status', ['confirmed', 'waitlisted'])
                    ->count();

            $cap = $match->max_competitors;
            $meta = $cap ? "{$confirmed} / {$cap}" : (string) $confirmed;

            [$statusLabel, $statusTone] = $this->matchStatusBadge($match->status);

            $rows[] = [
                'type' => 'match',
                'month' => $match->match_date?->timezone('Africa/Johannesburg')->format('M'),
                'day' => $match->match_date?->timezone('Africa/Johannesburg')->format('d'),
                'title' => $match->name,
                'subtitle' => collect([
                    $match->province?->name,
                    $match->venue_name,
                ])->filter()->implode(' · ') ?: '—',
                'meta' => $meta,
                'meta_label' => 'entries',
                'status' => $statusLabel,
                'status_tone' => $statusTone,
                'href' => route('matches.show', $match),
            ];
        }

        return $rows;
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function matchStatusBadge(?string $status): array
    {
        return match ($status) {
            'open' => ['Open', 'emerald'],
            'closed' => ['Closed', 'amber'],
            'completed' => ['Completed', 'sky'],
            'cancelled' => ['Cancelled', 'red'],
            'draft' => ['Draft', 'stone'],
            default => [ucfirst((string) $status) ?: '—', 'stone'],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function auditActivityRows(int $limit = 5): array
    {
        return AuditLog::query()
            ->with('user')
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(function (AuditLog $log) {
                $who = $log->user?->name ?? 'System';
                $action = str_replace('.', ' ', (string) $log->action);

                return [
                    'type' => 'activity',
                    'dot' => 'emerald',
                    'title' => $who,
                    'body' => $action,
                    'meta' => $log->created_at?->diffForHumans() ?? '',
                ];
            })
            ->all();
    }

    /**
     * Upcoming matches for a season window (today forward).
     *
     * @return Collection<int, MatchEvent>
     */
    protected function upcomingMatches(int $limit = 5, ?callable $scope = null): Collection
    {
        $query = MatchEvent::query()
            ->with(['province'])
            ->withCount([
                'registrations as registrations_count' => fn ($q) => $q
                    ->whereIn('registration_status', ['confirmed', 'waitlisted']),
            ])
            ->where('match_date', '>=', Carbon::today())
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('match_date')
            ->limit($limit);

        if ($scope) {
            $scope($query);
        }

        return $query->get();
    }
}
