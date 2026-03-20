<?php

namespace App\Services;

use App\Models\Sponsor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class SponsorService
{
    private const CACHE_PREFIX = 'sponsors_placement_';
    private const CACHE_TTL = 3600;

    public function getActiveByPlacement(string $placement): Collection
    {
        try {
            $result = Cache::remember(self::CACHE_PREFIX . $placement, self::CACHE_TTL, function () use ($placement) {
                return $this->querySponsors($placement);
            });

            if (!$result instanceof Collection) {
                Cache::forget(self::CACHE_PREFIX . $placement);
                return $this->querySponsors($placement);
            }

            return $result;
        } catch (\Throwable $e) {
            Cache::forget(self::CACHE_PREFIX . $placement);
            report($e);

            try {
                return $this->querySponsors($placement);
            } catch (\Throwable) {
                return collect();
            }
        }
    }

    private function querySponsors(string $placement): Collection
    {
        return Sponsor::query()
            ->active()
            ->whereHas('tier', function ($q) use ($placement) {
                $q->active()->whereJsonContains('placement', $placement);
            })
            ->with('tier')
            ->get()
            ->sortBy('tier.display_order')
            ->groupBy('tier.name');
    }

    public function clearCache(): void
    {
        $placements = ['landing_hero', 'landing_section', 'app_sidebar', 'match_pages', 'standings_pages'];

        foreach ($placements as $placement) {
            Cache::forget(self::CACHE_PREFIX . $placement);
        }
    }
}
