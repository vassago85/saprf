<?php

namespace App\Http\Controllers;

use App\Models\MediaItem;
use App\Models\NationalTeam;
use App\Models\NationalTeamAppearance;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin CRUD for team-year records (venue, dates, cover photo).
 * Appearances are managed via NationalTeamAppearanceController.
 */
class NationalTeamController extends Controller
{
    public function index(): View
    {
        return view('admin.national-teams.index', [
            'teams' => NationalTeam::query()
                ->with('cover')
                ->withCount('appearances')
                ->orderByDesc('year')
                ->orderBy('championship_name')
                ->get(),
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
        $data = $request->validate($this->rules(forCreate: true));

        // (year, championship_name) is unique at the DB level — surface the
        // conflict as a friendly validation error, not a 500.
        if (NationalTeam::where('year', $data['year'])
            ->where('championship_name', $data['championship_name'])
            ->exists()
        ) {
            return back()->withInput()->withErrors([
                'championship_name' => "A {$data['year']} team year with this championship name already exists. Edit it instead.",
            ]);
        }

        $team = NationalTeam::create($data);

        return redirect()
            ->route('team-years.edit', ['teamYear' => $team->id])
            ->with('status', 'Team year created. Add the roster + cover photo below.');
    }

    public function edit(NationalTeam $teamYear): View
    {
        return view('admin.national-teams.edit', [
            'team' => $teamYear,
            // Cover picker: any photo in an album linked to this team, or
            // any photo in a team_sa-category album.
            'pickableItems' => MediaItem::query()
                ->with('album')
                ->whereHas('album', fn ($q) => $q
                    ->where('national_team_id', $teamYear->id)
                    ->orWhere('category', 'team_sa'))
                ->orderByDesc('id')
                ->get(),
            // Roster for the inline table — managers → captains → shooters → officials.
            'appearances' => $teamYear->appearances()
                ->with(['user.membership', 'division'])
                ->get()
                ->sortBy([
                    fn ($a, $b) => NationalTeamAppearance::rolePriority($a->role)
                        <=> NationalTeamAppearance::rolePriority($b->role),
                    fn ($a, $b) => strcmp((string) $a->user?->name, (string) $b->user?->name),
                ])
                ->values(),
        ]);
    }

    public function update(Request $request, NationalTeam $teamYear): RedirectResponse
    {
        $teamYear->update($request->validate($this->rules(forCreate: false)));

        return redirect()
            ->route('team-years.edit', ['teamYear' => $teamYear->id])
            ->with('status', 'Team year updated.');
    }

    /**
     * Validation rules for store + update. `year` is required on create
     * (immutable afterwards); `cover_media_item_id` + `notes` are
     * editable only on update.
     */
    private function rules(bool $forCreate): array
    {
        $base = [
            'championship_name' => ['required', 'string', 'max:200'],
            'discipline' => ['required', Rule::in(['centrefire', 'rimfire'])],
            'host_country' => ['nullable', 'string', 'size:2'],
            'venue' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:200'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ];

        return $forCreate
            ? $base + ['year' => ['required', 'integer', 'between:1990,'.(now()->year + 2)]]
            : $base + [
                'cover_media_item_id' => ['nullable', 'exists:media_items,id'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ];
    }
}
