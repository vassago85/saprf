<?php

namespace App\Http\Controllers;

use App\Models\MatchEvent;
use App\Models\MediaAlbum;
use App\Models\NationalTeam;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin CRUD for photo albums. Album uploads + per-item editing live in
 * the Volt `admin.media.album-manager` component.
 */
class MediaAlbumController extends Controller
{
    public function index(Request $request): View
    {
        $albums = MediaAlbum::query()
            ->with(['cover'])
            ->withCount('items')
            ->orderBy('sort_order')
            ->orderByDesc('event_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.media.albums.index', [
            'albums' => $albums,
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.media.albums.create', [
            'album' => new MediaAlbum(['is_published' => true]),
            'categories' => MediaAlbum::CATEGORIES,
            'teams' => NationalTeam::query()->orderByDesc('year')->get(),
            'matches' => MatchEvent::query()->orderByDesc('match_date')->limit(100)->get(['id', 'name', 'match_date']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->id;

        $album = MediaAlbum::create($data);

        return redirect()
            ->route('media.albums.items', ['album' => $album->slug])
            ->with('status', 'Album created. Upload photos below.');
    }

    public function edit(MediaAlbum $album): View
    {
        return view('admin.media.albums.edit', [
            'album' => $album,
            'categories' => MediaAlbum::CATEGORIES,
            'teams' => NationalTeam::query()->orderByDesc('year')->get(),
            'matches' => MatchEvent::query()->orderByDesc('match_date')->limit(100)->get(['id', 'name', 'match_date']),
        ]);
    }

    public function update(Request $request, MediaAlbum $album): RedirectResponse
    {
        $album->update($this->validated($request, $album));

        return redirect()
            ->route('media.albums.items', ['album' => $album->slug])
            ->with('status', 'Album updated.');
    }

    public function destroy(MediaAlbum $album): RedirectResponse
    {
        $album->delete();

        return redirect()
            ->route('media.albums.index')
            ->with('status', 'Album deleted.');
    }

    public function show(MediaAlbum $album): RedirectResponse
    {
        return redirect()->route('media.albums.items', ['album' => $album->slug]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?MediaAlbum $album = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'slug' => [
                'nullable', 'string', 'max:200', 'alpha_dash',
                Rule::unique('media_albums', 'slug')->ignore($album?->id),
            ],
            'category' => ['required', Rule::in(array_keys(MediaAlbum::CATEGORIES))],
            'match_event_id' => ['nullable', 'exists:matches,id'],
            'national_team_id' => ['nullable', 'exists:national_teams,id'],
            'event_date' => ['nullable', 'date'],
            'venue_label' => ['nullable', 'string', 'max:200'],
            'is_published' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
