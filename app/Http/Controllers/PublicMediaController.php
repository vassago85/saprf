<?php

namespace App\Http\Controllers;

use App\Models\MediaAlbum;
use App\Models\MediaItem;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
        $filterCategory = in_array($category, array_keys(MediaAlbum::CATEGORIES), true)
            ? $category : null;

        // Same output for every visitor at the same category → cache the
        // query results for 15 min. Model booted hooks forget the key on
        // any album / item write.
        [$albums, $featured] = Cache::remember(
            MediaAlbum::indexCacheKey($filterCategory),
            now()->addMinutes(15),
            fn () => $this->fetchIndexData($filterCategory),
        );

        return view('media.index', [
            'albums' => $albums,
            'featured' => $featured,
            'activeCategory' => $filterCategory,
            'categories' => MediaAlbum::CATEGORIES,
        ]);
    }

    /**
     * @return array{0: \Illuminate\Support\Collection, 1: ?MediaItem}
     */
    private function fetchIndexData(?string $filterCategory): array
    {
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

        // Featured item: only honour it when the parent album is published,
        // so an unpublished-album photo can't leak via the feature toggle.
        $featured = MediaItem::query()->featured()->with(['album'])->first();
        if ($featured && ! $featured->album?->is_published) {
            $featured = null;
        }

        return [$albumsQuery->get(), $featured];
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
