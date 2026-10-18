<?php

namespace App\Support\Dashboard\Shooter;

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\User;
use App\Services\QualificationService;
use Illuminate\Support\Carbon;

final class NextMatchesBuilder
{
    public function __construct(
        private readonly QualificationService $qualification,
    ) {}

    /**
     * @param  list<string>  $disciplines
     * @return list<array<string, mixed>>
     */
    public function build(User $user, array $disciplines, string $filter = 'all', ?string $season = null): array
    {
        $season = $season ?: (string) Carbon::now()->year;
        $series = $filter === 'all'
            ? $disciplines
            : array_values(array_intersect($disciplines, [$filter]));

        if ($series === []) {
            return [];
        }

        $candidates = MatchEvent::query()
            ->with('province')
            ->where('published', true)
            ->where('match_date', '>=', Carbon::today())
            ->whereNotIn('status', ['cancelled'])
            ->whereIn('series', $series)
            ->orderBy('match_date')
            ->orderBy('id')
            ->limit(25)
            ->get();

        $registeredIds = MatchRegistration::query()
            ->where('user_id', $user->id)
            ->whereIn('match_id', $candidates->pluck('id'))
            ->whereNotIn('registration_status', ['cancelled'])
            ->pluck('match_id')
            ->all();

        $firstThree = $candidates->take(3);
        $rows = $firstThree->values();

        if ($rows->count() === 3 && $rows->every(fn (MatchEvent $m) => in_array($m->id, $registeredIds, true))) {
            $extra = $candidates
                ->slice(3)
                ->first(fn (MatchEvent $m) => ! in_array($m->id, $registeredIds, true));

            if ($extra) {
                $rows = $rows->push($extra);
            }
        }

        $oopBySeries = [];
        foreach ($disciplines as $disc) {
            $oopBySeries[$disc] = $this->qualification->getQualificationStatus($user, $disc, $season);
        }

        return $rows
            ->map(fn (MatchEvent $match) => $this->row(
                $match,
                $user,
                in_array($match->id, $registeredIds, true),
                $oopBySeries[$match->series] ?? ['required' => 0, 'qualified' => true],
            ))
            ->all();
    }

    /**
     * @param  array{required:int, completed?:int, qualified:bool}  $oop
     * @return array<string, mixed>
     */
    private function row(MatchEvent $match, User $user, bool $registered, array $oop): array
    {
        $days = Carbon::today()->diffInDays($match->match_date, false);
        $level = match ($match->series_level) {
            'national' => 'National',
            'provincial' => 'Provincial',
            'final' => 'Final',
            default => ucfirst((string) $match->series_level),
        };

        $metaParts = array_filter([
            $match->province?->name,
            $level,
            ($days >= 0 && $days <= 14) ? 'in '.(int) $days.' '.( $days === 1 ? 'day' : 'days') : null,
        ]);

        return [
            'id' => $match->id,
            'href' => route('events.show', $match),
            'month' => strtoupper($match->match_date?->timezone('Africa/Johannesburg')->format('M') ?? ''),
            'day' => $match->match_date?->timezone('Africa/Johannesburg')->format('d') ?? '',
            'series' => $match->series,
            'name' => $match->name,
            'meta' => implode(' · ', $metaParts),
            'counts_for_finals' => $this->countsForFinals($match, $user, $oop),
            'status' => $this->statusLabel($match, $registered),
            'status_tone' => $this->statusTone($match, $registered),
        ];
    }

    /**
     * @param  array{required:int, qualified:bool}  $oop
     */
    private function countsForFinals(MatchEvent $match, User $user, array $oop): bool
    {
        if (($oop['required'] ?? 0) <= 0 || ($oop['qualified'] ?? false)) {
            return false;
        }

        if ($match->series_level !== 'national') {
            return false;
        }

        if (! $user->province_id || ! $match->province_id) {
            return false;
        }

        return (int) $match->province_id !== (int) $user->province_id;
    }

    private function statusLabel(MatchEvent $match, bool $registered): string
    {
        if ($registered) {
            return 'Registered';
        }

        if ($match->registration_open_date && $match->registration_open_date->isFuture()) {
            return 'Opens '.$match->registration_open_date->format('d M');
        }

        if ($match->isRegistrationOpen() || $match->registration_status === 'waitlist') {
            return 'Entries open';
        }

        return 'Entries closed';
    }

    private function statusTone(MatchEvent $match, bool $registered): string
    {
        if ($registered) {
            return 'registered';
        }

        if ($match->registration_open_date && $match->registration_open_date->isFuture()) {
            return 'opens';
        }

        if ($match->isRegistrationOpen() || $match->registration_status === 'waitlist') {
            return 'open';
        }

        return 'closed';
    }
}
