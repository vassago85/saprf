<?php

namespace App\View\Components;

use App\Models\MediaItem;
use App\Models\NationalTeam;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * The "Team South Africa" band on the public landing page.
 *
 * Fallback order (cached under MediaItem::HOME_FEATURE_CACHE_KEY):
 *   1. The MediaItem flagged `is_home_feature = true`.
 *   2. The cover photo of the most-recent NationalTeam row (we still
 *      want to showcase the latest team even if no one has toggled a
 *      specific photo as the homepage feature).
 *   3. Nothing — the band is hidden.
 *
 * The cache is invalidated on any MediaItem write (see MediaItem::booted).
 */
class HomeFeatureBand extends Component
{
    public function render(): View
    {
        $data = Cache::remember(
            MediaItem::HOME_FEATURE_CACHE_KEY,
            now()->addHour(),
            fn () => $this->resolveBand(),
        );

        return view('components.home-feature-band', $data);
    }

    /**
     * @return array{mode:?string, item:?MediaItem, team:?NationalTeam, label:?string, headline:?string, summary:?string, primaryUrl:?string, primaryLabel:?string, secondaryUrl:?string}
     */
    private function resolveBand(): array
    {
        $featured = MediaItem::query()
            ->featured()
            ->with(['album'])
            ->first();

        // Only honour a featured item whose parent album is published.
        if ($featured && ! $featured->album?->is_published) {
            $featured = null;
        }

        if ($featured) {
            return [
                'mode' => 'featured',
                'item' => $featured,
                'team' => null,
                'label' => $featured->home_label ?: 'Team South Africa',
                'headline' => $featured->home_headline ?: $featured->album?->title,
                'summary' => $featured->home_summary,
                'primaryUrl' => $featured->homePrimaryUrl(),
                'primaryLabel' => $featured->homePrimaryLabel(),
                'secondaryUrl' => $featured->album
                    ? route('media.show', ['album' => $featured->album->slug])
                    : route('media.index'),
                'caption' => $featured->caption
                    ?? trim(($featured->album?->title ?? '').($featured->album?->venue_label ? ', '.$featured->album->venue_label : '')),
            ];
        }

        $latestTeam = NationalTeam::query()
            ->with('cover.album')
            ->whereNotNull('cover_media_item_id')
            ->orderByDesc('year')
            ->first();

        if ($latestTeam?->cover) {
            return [
                'mode' => 'team-cover',
                'item' => $latestTeam->cover,
                'team' => $latestTeam,
                'label' => 'Team South Africa',
                'headline' => "Flying the flag at the {$latestTeam->year} {$latestTeam->championship_name}",
                'summary' => $latestTeam->city
                    ? "The South African team that represented the country in {$latestTeam->city}."
                    : "The South African team that represented the country at the {$latestTeam->championship_name}.",
                'primaryUrl' => route('sa-team.year', ['year' => $latestTeam->year]),
                'primaryLabel' => 'Meet the team',
                'secondaryUrl' => route('media.index'),
                'caption' => "Team South Africa · {$latestTeam->year} {$latestTeam->championship_name}"
                    .($latestTeam->city ? ", {$latestTeam->city}" : ''),
            ];
        }

        return [
            'mode' => null,
            'item' => null,
            'team' => null,
            'label' => null,
            'headline' => null,
            'summary' => null,
            'primaryUrl' => null,
            'primaryLabel' => null,
            'secondaryUrl' => null,
            'caption' => null,
        ];
    }
}
