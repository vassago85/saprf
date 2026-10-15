<?php

namespace App\Http\Controllers;

use App\Models\MediaItem;
use App\Models\NationalTeam;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin CRUD for the team-year records (venue, dates, cover photo).
 * Appearances are managed via the existing NationalTeamAppearanceController.
 */
class NationalTeamController extends Controller
{
    public function index(): View
    {
        $teams = NationalTeam::query()
            ->with('cover')
            ->withCount('appearances')
            ->orderByDesc('year')
            ->orderBy('championship_name')
            ->get();

        return view('admin.national-teams.index', [
            'teams' => $teams,
        ]);
    }

    public function create(): View
    {
        return view('admin.national-teams.create', [
            'defaultYear' => now()->year,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:1990,'.(now()->year + 2)],
            'championship_name' => ['required', 'string', 'max:200'],
            'discipline' => ['required', Rule::in(['centrefire', 'rimfire'])],
            'host_country' => ['nullable', 'string', 'size:2'],
            'venue' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:200'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);

        // The (year, championship_name) pair is unique at the DB level,
        // so surface the conflict as a friendly validation error rather
        // than letting the user see a 500.
        if (NationalTeam::where('year', $data['year'])
            ->where('championship_name', $data['championship_name'])
            ->exists()
        ) {
            return redirect()->back()->withInput()->withErrors([
                'championship_name' => "A {$data['year']} team year with this championship name already exists. Edit it instead.",
            ]);
        }

        $team = NationalTeam::create($data);

        return redirect()
            ->route('national-teams.edit', ['nationalTeam' => $team->id])
            ->with('status', 'Team year created. Add the roster + cover photo below.');
    }

    public function edit(NationalTeam $nationalTeam): View
    {
        // Cover picker pool: any photo in an album linked to this team, or
        // any photo in a team_sa-category album.
        $pickable = MediaItem::query()
            ->with('album')
            ->whereHas('album', function ($q) use ($nationalTeam) {
                $q->where('national_team_id', $nationalTeam->id)
                    ->orWhere('category', 'team_sa');
            })
            ->orderByDesc('id')
            ->get();

        // Full roster for this team year, with the role-aware sort that
        // matches the public page: managers/captains first, then shooters,
        // then officials.
        $appearances = $nationalTeam->appearances()
            ->with(['user.membership', 'division'])
            ->get()
            ->sortBy([
                fn ($a, $b) => $this->rolePriority($a->role) <=> $this->rolePriority($b->role),
                fn ($a, $b) => strcmp((string) $a->user?->name, (string) $b->user?->name),
            ])
            ->values();

        return view('admin.national-teams.edit', [
            'team' => $nationalTeam,
            'pickableItems' => $pickable,
            'appearances' => $appearances,
        ]);
    }

    /**
     * Sort order used in the admin roster table — managers first (they
     * run the team), then the captain, then shooters, then officials.
     */
    private function rolePriority(?string $role): int
    {
        return match ($role) {
            \App\Models\NationalTeamAppearance::ROLE_MANAGER => 0,
            \App\Models\NationalTeamAppearance::ROLE_CAPTAIN => 1,
            \App\Models\NationalTeamAppearance::ROLE_OFFICIAL => 3,
            default => 2, // shooter / null
        };
    }

    public function update(Request $request, NationalTeam $nationalTeam): RedirectResponse
    {
        $data = $request->validate([
            'championship_name' => ['required', 'string', 'max:200'],
            'discipline' => ['required', Rule::in(['centrefire', 'rimfire'])],
            'venue' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:200'],
            'host_country' => ['nullable', 'string', 'size:2'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'cover_media_item_id' => ['nullable', 'exists:media_items,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $nationalTeam->update($data);

        return redirect()
            ->route('national-teams.edit', ['nationalTeam' => $nationalTeam->id])
            ->with('status', 'Team year updated.');
    }
}
