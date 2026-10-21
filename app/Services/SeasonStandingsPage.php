<?php

namespace App\Services;

use Illuminate\Support\Collection;

class SeasonStandingsPage
{
    /**
     * @param  Collection<int, \App\Models\Standing>  $standings
     */
    public function __construct(
        public Collection $standings,
        public int $totalRanked,
        public string $sort,
        public string $direction,
        public bool $showProvinceRank,
    ) {}
}
