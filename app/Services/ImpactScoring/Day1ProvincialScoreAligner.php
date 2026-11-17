<?php

namespace App\Services\ImpactScoring;

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Score;
use App\Models\User;
use App\Services\ScoreValidationService;
use App\Services\StandingsCalculationService;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * A day-1 provincial is the first day of a 2-day national. The shooter paid
 * once, on the national, so that entry's division is the one that counts.
 *
 * Rewrites scores already stored on the provincial sibling, and links
 * scoreboard names that exactly match a national entry.
 */
class Day1ProvincialScoreAligner
{
    public function __construct(
        private readonly ImpactNameMatcher $names,
        private readonly ImpactLiveImportService $imports,
        private readonly ScoreValidationService $validation,
        private readonly StandingsCalculationService $standings,
    ) {}

    /**
     * @return array{divisions:int, linked:int, unmatched:list<string>}
     */
    public function align(MatchEvent $sibling, User $actor): array
    {
        if ($sibling->source_national_match_id === null) {
            throw new InvalidArgumentException('Only a day-1 provincial can be aligned to its national entry.');
        }

        $registrations = $this->registrations($sibling->paidEntryMatch());
        $byUser = $registrations
            ->filter(fn (MatchRegistration $registration) => $registration->user_id !== null)
            ->keyBy(fn (MatchRegistration $registration) => (int) $registration->user_id);

        $candidates = $registrations->map(fn (MatchRegistration $registration) => [
            'key' => 'reg:'.$registration->id,
            'name' => $registration->user?->name ?: (string) $registration->shooter_name,
        ])->all();

        $divisions = 0;
        $linked = 0;
        $unmatched = [];

        $scores = Score::query()->where('match_id', $sibling->id)->get();

        foreach ($scores as $score) {
            $waiting = ! empty($score->raw_meta['impact']['needs_member_link']);
            if ($waiting) {
                $linkedNow = $this->linkExact($sibling, $score, $registrations, $candidates, $actor);
                if ($linkedNow) {
                    $linked++;
                } else {
                    $unmatched[] = (string) $score->shooter_name;
                }

                continue;
            }

            if ($score->user_id === null) {
                continue;
            }

            $registration = $byUser->get((int) $score->user_id);
            if ($registration === null || $registration->division_id === null) {
                continue;
            }

            if ((int) $score->division_id === (int) $registration->division_id) {
                continue;
            }

            $score->division_id = (int) $registration->division_id;
            $score->save();
            $this->validation->evaluateScoreStatus($score->fresh());
            $divisions++;
        }

        if ($divisions > 0 || $linked > 0) {
            $this->standings->recalculateForMatch($sibling->fresh());
        }

        return [
            'divisions' => $divisions,
            'linked' => $linked,
            'unmatched' => $unmatched,
        ];
    }

    /**
     * @return Collection<int, MatchRegistration>
     */
    private function registrations(MatchEvent $entryMatch): Collection
    {
        return $entryMatch->registrations()
            ->with(['user:id,name', 'division:id,name,slug'])
            ->where(function ($query) {
                $query->whereNull('registration_status')
                    ->orWhere('registration_status', '!=', 'cancelled');
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, MatchRegistration>  $registrations
     * @param  list<array{key:string, name:string}>  $candidates
     */
    private function linkExact(
        MatchEvent $sibling,
        Score $score,
        Collection $registrations,
        array $candidates,
        User $actor,
    ): bool {
        $hit = $this->names->match((string) $score->shooter_name, $candidates);
        if ($hit['status'] !== 'exact' || $hit['key'] === null) {
            return false;
        }

        $registration = $registrations->first(
            fn (MatchRegistration $registration) => 'reg:'.$registration->id === $hit['key']
        );
        if ($registration === null) {
            return false;
        }

        if ($registration->user_id !== null) {
            $taken = Score::query()
                ->where('match_id', $sibling->id)
                ->where('user_id', $registration->user_id)
                ->where('id', '!=', $score->id)
                ->exists();
            if ($taken) {
                return false;
            }
        }

        $this->imports->linkScoreToRegistration($score, $registration, $actor);

        return true;
    }
}
