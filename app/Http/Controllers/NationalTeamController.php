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

        return view('admin.national-teams.edit', [
            'team' => $nationalTeam,
            'pickableItems' => $pickable,
        ]);
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
