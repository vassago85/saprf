<?php

namespace App\Http\Controllers;

use App\Models\Division;
use App\Models\NationalTeam;
use App\Models\NationalTeamAppearance;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Public "Represented South Africa" roster at /sa-team and /sa-team/{year}.
 *
 * Renders the full-width hero + year switcher + division cards + past
 * teams + honour-roll search, modelled on docs/mockups/saprf-sa-team/.
 *
 * Visibility rules (POPIA parity with ShooterProfileController):
 *   - Juniors with public_profile_visibility = 'members_only' are hidden
 *     from guests but visible to any authed user.
 *   - 'hidden' users never show up.
 */
class PublicNationalTeamController extends Controller
{
    public function index(Request $request, ?int $year = null): View
    {
        $viewer = $request->user();

        // Deliberately NOT caching the raw Eloquent collections. Caching
        // deeply-eager-loaded models round-trips badly through PHP's
        // serialize() on Redis — the second read returns an incomplete
        // class and crashes. If this page ever becomes a bottleneck,
        // cache a shaped array payload (via toArray/DTO), not the raw
        // models. The underlying queries are 2 selects + eager joins.
        [$teams, $allAppearances] = $this->fetchDataset($viewer);

        // Pick the selected year — honour the URL, else default to the
        // newest year we have appearances for.
        $yearsAvailable = $allAppearances->pluck('year')
            ->map(fn ($y) => (int) $y)
            ->unique()
            ->sortDesc()
            ->values();

        $selectedYear = $year !== null && $yearsAvailable->contains($year)
            ? $year
            : $yearsAvailable->first();

        $selectedAppearances = $selectedYear !== null
            ? $allAppearances->where('year', $selectedYear)->values()
            : collect();

        // One team-year page can hold multiple championships (e.g. a
        // Centrefire team AND a Rimfire team in 2026). Group by the
        // championship string so each gets its own header + divisions.
        $selectedTeams = $selectedAppearances
            ->groupBy('championship_name')
            ->map(function (Collection $champAppearances) use ($teams) {
                $first = $champAppearances->first();
                $team = $teams->first(fn (NationalTeam $t) =>
                    $t->year === (int) $first->year
                    && $t->championship_name === $first->championship_name
                );

                return [
                    'team' => $team,
                    'championship' => $first->championship_name,
                    'host_country_name' => $first->hostCountryName(),
                    'captain' => $champAppearances->firstWhere('role', NationalTeamAppearance::ROLE_CAPTAIN),
                    'manager' => $champAppearances->firstWhere('role', NationalTeamAppearance::ROLE_MANAGER),
                    'squad_size' => $champAppearances
                        ->whereIn('role', [
                            NationalTeamAppearance::ROLE_SHOOTER,
                            NationalTeamAppearance::ROLE_CAPTAIN,
                        ])
                        ->count(),
                    'divisions' => $this->groupByDivision($champAppearances),
                    // Reserves hang off their own structure so the view
                    // can render them in a dedicated section below the
                    // shooting divisions. The grouping key is the division
                    // label (not division_id) because that's how reserves
                    // are listed in the published roster (one per division).
                    'reserves' => $this->groupReservesByDivision($champAppearances),
                ];
            })
            ->values();

        $selectedTeamForHero = $selectedYear !== null
            ? $teams->first(fn (NationalTeam $t) => $t->year === $selectedYear)
            : null;

        $pastTeams = $teams
            ->filter(fn (NationalTeam $t) => $t->year !== $selectedYear)
            ->map(function (NationalTeam $t) use ($allAppearances) {
                return [
                    'team' => $t,
                    'squad_size' => $allAppearances
                        ->where('national_team_id', $t->id)
                        ->whereIn('role', [
                            NationalTeamAppearance::ROLE_SHOOTER,
                            NationalTeamAppearance::ROLE_CAPTAIN,
                        ])
                        ->count(),
                ];
            })
            ->values();

        return view('national-team.public', [
            'yearsAvailable' => $yearsAvailable,
            'selectedYear' => $selectedYear,
            'selectedTeams' => $selectedTeams,
            'selectedHeroCover' => $selectedTeamForHero?->cover,
            'pastTeams' => $pastTeams,
            'honourRoll' => $this->honourRoll($allAppearances),
            'statUniqueProteas' => $allAppearances
                ->whereIn('role', [
                    NationalTeamAppearance::ROLE_SHOOTER,
                    NationalTeamAppearance::ROLE_CAPTAIN,
                ])
                ->pluck('user_id')
                ->unique()
                ->count(),
            'statAppearances' => $allAppearances->count(),
            'statColours' => $allAppearances
                ->where('awarded_colours', true)
                ->pluck('user_id')
                ->unique()
                ->count(),
        ]);
    }

    /**
     * Fetch the two base collections used by the rest of index(): all
     * teams, and all visible appearances. Guest results are cacheable
     * because every guest sees the same POPIA-filtered set.
     *
     * @return array{0: Collection, 1: Collection}
     */
    private function fetchDataset(?User $viewer): array
    {
        $teams = NationalTeam::query()
            ->with(['cover.album'])
            ->orderByDesc('year')
            ->orderBy('championship_name')
            ->get();

        $allAppearances = NationalTeamAppearance::query()
            ->with(['user.membership', 'user.province', 'division', 'nationalTeam'])
            ->orderByDesc('year')
            ->orderBy('championship_name')
            ->get()
            ->filter(fn (NationalTeamAppearance $a) => $a->user !== null
                && $a->user->isProfileVisibleTo($viewer))
            ->values();

        return [$teams, $allAppearances];
    }

    /**
     * Shape appearances into the division-card order used on the page.
     * Divisions in the DB order, with a trailing 'Other' bucket for
     * historical rows whose division is only in free-text form.
     */
    private function groupByDivision(Collection $champAppearances): array
    {
        $shooters = $champAppearances
            ->whereIn('role', [
                NationalTeamAppearance::ROLE_SHOOTER,
                NationalTeamAppearance::ROLE_CAPTAIN,
            ]);

        $divisions = Division::ordered()->get();
        $cards = [];

        foreach ($divisions as $division) {
            $members = $shooters
                ->filter(fn (NationalTeamAppearance $a) =>
                    $a->division_id === $division->id
                    || (empty($a->division_id) && strcasecmp((string) $a->division_label, $division->name) === 0)
                )
                ->sortBy(fn (NationalTeamAppearance $a) => strtolower((string) ($a->user?->name ?? '')))
                ->values();

            if ($members->isNotEmpty()) {
                $cards[] = [
                    'name' => $division->name,
                    'members' => $members,
                ];
            }
        }

        // Anything with a label we didn't match goes in a trailing "Other"
        // card so historical rows aren't silently dropped.
        $other = $shooters
            ->filter(function (NationalTeamAppearance $a) use ($divisions) {
                if ($a->division_id && $divisions->contains('id', $a->division_id)) {
                    return false;
                }
                $label = (string) $a->division_label;
                return $label !== '' && ! $divisions->contains(fn ($d) => strcasecmp($d->name, $label) === 0);
            })
            ->sortBy(fn (NationalTeamAppearance $a) => strtolower((string) ($a->user?->name ?? '')))
            ->values();

        if ($other->isNotEmpty()) {
            $cards[] = ['name' => 'Other', 'members' => $other];
        }

        return $cards;
    }

    /**
     * Build the Reserves roster strip for a championship. One entry per
     * reserve, ordered by division (so the strip reads Open · Ladies ·
     * Juniors · Seniors · Factory the same way the official SAPRF
     * infographics present them). Returned as a flat list because the
     * view renders reserves as a single horizontal strip, not division
     * cards (there's usually only one reserve per division).
     *
     * @return \Illuminate\Support\Collection<int, array{appearance: NationalTeamAppearance, division_label: string}>
     */
    private function groupReservesByDivision(Collection $champAppearances): Collection
    {
        $divisionOrder = Division::ordered()->get()->pluck('display_order', 'id');

        return $champAppearances
            ->where('role', NationalTeamAppearance::ROLE_RESERVE)
            ->sortBy(fn (NationalTeamAppearance $a) =>
                // Primary sort: division's display_order (so Open comes
                // before Ladies etc.). Fall back to the label alphabetically
                // for historical reserves with no division_id.
                $a->division_id
                    ? ($divisionOrder[$a->division_id] ?? 999)
                    : 500 + ord(strtolower(substr((string) $a->division_label, 0, 1)))
            )
            ->map(fn (NationalTeamAppearance $a) => [
                'appearance' => $a,
                'division_label' => $a->division?->name ?? $a->division_label ?? 'Reserve',
            ])
            ->values();
    }

    /**
     * A to Z list of everyone who has ever represented SA, with the years
     * they were on a team and an optional role label for officials.
     */
    private function honourRoll(Collection $appearances): Collection
    {
        $byUser = $appearances->groupBy('user_id');

        return $byUser
            ->map(function (Collection $userAppearances) {
                $first = $userAppearances->first();
                $user = $first->user;

                $years = $userAppearances
                    ->pluck('year')
                    ->map(fn ($y) => (int) $y)
                    ->unique()
                    ->sortDesc()
                    ->values()
                    ->all();

                // Pick the loudest role across all their appearances for
                // the honour-roll label — captain beats manager beats
                // shooter so a 2024-captain / 2026-shooter reads correctly.
                // Captain beats manager beats shooter beats reserve beats
                // official so a 2024-captain / 2026-shooter reads as
                // "Captain", and a 2025-reserve / 2026-shooter reads
                // without the Reserve diminutive.
                $rolePriority = [
                    NationalTeamAppearance::ROLE_CAPTAIN => 0,
                    NationalTeamAppearance::ROLE_MANAGER => 1,
                    NationalTeamAppearance::ROLE_SHOOTER => 2,
                    NationalTeamAppearance::ROLE_RESERVE => 3,
                    NationalTeamAppearance::ROLE_OFFICIAL => 4,
                ];
                $bestRole = $userAppearances
                    ->sortBy(fn ($a) => $rolePriority[$a->role] ?? 9)
                    ->first()
                    ->role;

                $roleLabel = match ($bestRole) {
                    NationalTeamAppearance::ROLE_CAPTAIN => 'Captain',
                    NationalTeamAppearance::ROLE_MANAGER => 'Manager',
                    NationalTeamAppearance::ROLE_OFFICIAL => 'Official',
                    NationalTeamAppearance::ROLE_RESERVE => 'Reserve',
                    default => null,
                };

                $saprfNumber = $user?->membership?->saprf_number;

                return [
                    'name' => (string) $user?->name,
                    'years' => $years,
                    'years_label' => implode(', ', $years),
                    'role_label' => $roleLabel,
                    'profile_url' => $saprfNumber
                        ? route('shooters.show', ['saprfNumber' => $saprfNumber])
                        : null,
                ];
            })
            ->filter(fn ($entry) => $entry['name'] !== '')
            ->sortBy(fn ($entry) => strtolower($entry['name']))
            ->values();
    }
}
