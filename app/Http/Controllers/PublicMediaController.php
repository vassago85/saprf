<?php

namespace App\Http\Controllers;

use App\Models\MediaAlbum;
use App\Models\MediaItem;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Public photo library:
 *   /media               — featured card + category chips + album grid
 *   /media/{album:slug}  — album header + photo grid + accessible lightbox
 *
 * Only published albums are visible. Unpublished slugs 404 so a URL being
 * accidentally shared before the album is ready still won't leak.
 */
class PublicMediaController extends Controller
{
    public function index(Request $request): View
    {
        $category = $request->string('category')->toString();
        $validCategories = array_keys(MediaAlbum::CATEGORIES);
        $filterCategory = in_array($category, $validCategories, true) ? $category : null;

        $albumsQuery = MediaAlbum::query()
            ->published()
            ->with(['cover'])
            ->withCount('items')
            ->orderBy('sort_order')
            ->orderByDesc('event_date')
            ->orderByDesc('id');

        if ($filterCategory !== null) {
            $albumsQuery->where('category', $filterCategory);
        }

        $albums = $albumsQuery->get();

        // Featured item for the big card at the top. Only shows items whose
        // parent album is published, so an unpublished-album photo can't
        // leak through the feature toggle.
        $featured = MediaItem::query()
            ->featured()
            ->with(['album'])
            ->first();

        if ($featured && ! $featured->album?->is_published) {
            $featured = null;
        }

        return view('media.index', [
            'albums' => $albums,
            'featured' => $featured,
            'activeCategory' => $filterCategory,
            'categories' => MediaAlbum::CATEGORIES,
        ]);
    }

    public function show(Request $request, MediaAlbum $album): View
    {
        abort_unless($album->is_published, 404);

        $album->load([
            'items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            'cover',
            'matchEvent',
            'nationalTeam',
        ]);

        return view('media.show', [
            'album' => $album,
            'items' => $album->items,
        ]);
    }
}
