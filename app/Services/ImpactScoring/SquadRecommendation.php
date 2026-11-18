<?php

namespace App\Services\ImpactScoring;

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Standing;

/**
 * Even squads for a match, from the current overall season standings.
 *
 * Ladies take one squad when they can fill it, and the same for juniors.
 * When neither group can, they share one squad. Empty places in that squad
 * are the next ranks after the squad in front of it.
 *
 * A ladies or juniors squad keeps the place its best rank gives it. The
 * ranking squads on either side stay in rank order.
 */
class SquadRecommendation
{
    /**
     * @return array{
     *     requested: int,
     *     squads: list<array{number: int, members: list<array{registration_id: int, name: string, rank: ?int, division: string}>}>,
     *     unranked_count: int,
     *     squad_by_registration: array<int, int>
     * }
     */
    public function recommend(MatchEvent $match, int $squads): array
    {
        $shooters = $this->shooters($match);
        $built = $this->build($shooters, $squads);

        $numbered = [];
        $squadByRegistration = [];
        $unranked = 0;
        $number = 1;
        foreach ($built as $squad) {
            $members = $squad['members'];
            usort($members, fn (array $a, array $b) => $this->byRank($a, $b));
            $preview = [];
            foreach ($members as $member) {
                $preview[] = [
                    'registration_id' => $member['registration_id'],
                    'name' => $member['name'],
                    'rank' => $member['rank'],
                    'division' => $member['division'],
                ];
                $squadByRegistration[$member['registration_id']] = $number;
                if ($member['rank'] === null) {
                    $unranked++;
                }
            }
            $numbered[] = ['number' => $number, 'members' => $preview];
            $number++;
        }

        return [
            'requested' => $squads,
            'squads' => $numbered,
            'unranked_count' => $unranked,
            'squad_by_registration' => $squadByRegistration,
        ];
    }

    /**
     * Rebuild a recommendation from squad choices. A missing shooter keeps
     * the recommended squad. A squad number outside 1..requested is ignored.
     *
     * @param  array{
     *     requested: int,
     *     squads: list<array{number: int, members: list<array{registration_id: int, name: string, rank: ?int, division: string}>}>,
     *     unranked_count: int,
     *     squad_by_registration: array<int, int>
     * }  $recommendation
     * @param  array<int|string, int|string>  $placement
     * @return array{
     *     requested: int,
     *     squads: list<array{number: int, members: list<array{registration_id: int, name: string, rank: ?int, division: string}>}>,
     *     unranked_count: int,
     *     squad_by_registration: array<int, int>
     * }
     */
    public function withPlacement(array $recommendation, array $placement): array
    {
        $requested = $recommendation['requested'];
        $chosen = [];
        foreach ($placement as $id => $squad) {
            $squad = (int) $squad;
            if ($squad < 1 || $squad > $requested) {
                continue;
            }
            $chosen[(int) $id] = $squad;
        }

        $buckets = [];
        for ($number = 1; $number <= $requested; $number++) {
            $buckets[$number] = [];
        }

        $squadByRegistration = [];
        foreach ($recommendation['squads'] as $squad) {
            foreach ($squad['members'] as $member) {
                $id = $member['registration_id'];
                $number = $chosen[$id] ?? $recommendation['squad_by_registration'][$id];
                $buckets[$number][] = $member;
                $squadByRegistration[$id] = $number;
            }
        }

        $numbered = [];
        foreach ($buckets as $number => $members) {
            usort($members, fn (array $a, array $b) => $this->byRank($a, $b));
            $numbered[] = ['number' => $number, 'members' => $members];
        }

        return [
            'requested' => $requested,
            'squads' => $numbered,
            'unranked_count' => $recommendation['unranked_count'],
            'squad_by_registration' => $squadByRegistration,
        ];
    }

    /**
     * @return list<array{registration_id: int, user_id: ?int, name: string, rank: ?int, division: string, slug: string}>
     */
    private function shooters(MatchEvent $match): array
    {
        $registrations = $match->registrations()
            ->where('registration_status', '!=', 'cancelled')
            ->with(['user:id,name', 'division:id,name,slug'])
            ->get();

        $userIds = $registrations->pluck('user_id')->filter()->unique()->values();
        $ranks = Standing::query()
            ->where('series', $match->series)
            ->where('season', (string) $match->season)
            ->whereNull('province_id')
            ->whereNull('division_id')
            ->whereIn('user_id', $userIds)
            ->pluck('rank', 'user_id');

        $shooters = [];
        foreach ($registrations as $registration) {
            /** @var MatchRegistration $registration */
            $name = trim((string) ($registration->user?->name ?: $registration->shooter_name));
            $rank = $registration->user_id ? $ranks->get($registration->user_id) : null;
            $shooters[] = [
                'registration_id' => $registration->id,
                'user_id' => $registration->user_id ? (int) $registration->user_id : null,
                'name' => $name,
                'rank' => $rank === null ? null : (int) $rank,
                'division' => (string) ($registration->division?->name ?? ''),
                'slug' => (string) ($registration->division?->slug ?? ''),
            ];
        }

        return $shooters;
    }

    /**
     * @param  list<array{registration_id: int, user_id: ?int, name: string, rank: ?int, division: string, slug: string}>  $shooters
     * @return list<array{order: int, members: list<array{registration_id: int, user_id: ?int, name: string, rank: ?int, division: string, slug: string}>}>
     */
    private function build(array $shooters, int $squadCount): array
    {
        $n = count($shooters);
        $small = intdiv($n, $squadCount);
        $slots = [];
        for ($i = 0; $i < $squadCount; $i++) {
            $slots[] = $i < ($n % $squadCount) ? $small + 1 : $small;
        }

        $groups = $this->categoryGroups($shooters, $small, $squadCount);
        $placedIds = [];
        foreach ($groups as $group) {
            foreach ($group['members'] as $member) {
                $placedIds[$member['registration_id']] = true;
            }
        }

        $pool = array_values(array_filter(
            $shooters,
            fn (array $shooter) => ! isset($placedIds[$shooter['registration_id']])
        ));

        $categorySquads = [];
        foreach ($groups as $group) {
            $size = $this->claimSlot($slots, $small, count($group['members']));
            $members = $group['members'];
            usort($members, fn (array $a, array $b) => $this->byRank($a, $b));
            if (count($members) > $size) {
                $pool = array_merge($pool, array_slice($members, $size));
                $members = array_slice($members, 0, $size);
            }
            $categorySquads[] = [
                'order' => $group['order'],
                'size' => $size,
                'members' => $members,
            ];
        }

        usort($pool, fn (array $a, array $b) => $this->byRank($a, $b));
        usort($categorySquads, fn (array $a, array $b) => $this->bySquadStrength($a, $b));

        $squads = [];
        $rankingOrder = 3;
        $catIndex = 0;
        while ($slots !== [] || $catIndex < count($categorySquads)) {
            $category = $categorySquads[$catIndex] ?? null;
            if ($category !== null && ($slots === [] || $this->categoryGoesBefore($category, $pool))) {
                $need = $category['size'] - count($category['members']);
                if ($need > 0) {
                    $category['members'] = array_merge($category['members'], array_splice($pool, 0, $need));
                }
                $squads[] = ['order' => $category['order'], 'members' => $category['members']];
                $catIndex++;

                continue;
            }

            $size = array_shift($slots);
            $squads[] = [
                'order' => $rankingOrder,
                'members' => array_splice($pool, 0, $size),
            ];
            $rankingOrder++;
        }

        return $squads;
    }

    /**
     * @param  list<array{registration_id: int, user_id: ?int, name: string, rank: ?int, division: string, slug: string}>  $shooters
     * @return list<array{order: int, members: list<array{registration_id: int, user_id: ?int, name: string, rank: ?int, division: string, slug: string}>}>
     */
    private function categoryGroups(array $shooters, int $small, int $squadCount): array
    {
        if ($squadCount === 1) {
            return [];
        }

        $ladies = array_values(array_filter($shooters, fn (array $shooter) => $shooter['slug'] === 'ladies'));
        $juniors = array_values(array_filter($shooters, fn (array $shooter) => $shooter['slug'] === 'junior'));
        $n = count($shooters);
        $ladiesOwn = count($ladies) >= $small && count($ladies) < $n;
        $juniorsOwn = count($juniors) >= $small && count($juniors) < $n;

        if ($ladiesOwn || $juniorsOwn) {
            $groups = [];
            if ($ladiesOwn) {
                $groups[] = ['order' => 0, 'members' => $ladies];
            }
            if ($juniorsOwn) {
                $groups[] = ['order' => 1, 'members' => $juniors];
            }

            return $groups;
        }

        $shared = array_merge($ladies, $juniors);
        if ($shared === [] || count($shared) === $n) {
            return [];
        }

        return [['order' => 2, 'members' => $shared]];
    }

    /**
     * @param  list<int>  $slots
     */
    private function claimSlot(array &$slots, int $small, int $groupCount): int
    {
        $large = $small + 1;
        if (in_array($large, $slots, true) && $groupCount >= $large) {
            $this->pullSlot($slots, $large);

            return $large;
        }
        if (in_array($small, $slots, true)) {
            $this->pullSlot($slots, $small);

            return $small;
        }

        $size = $slots[0];
        $this->pullSlot($slots, $size);

        return $size;
    }

    /**
     * @param  list<int>  $slots
     */
    private function pullSlot(array &$slots, int $size): void
    {
        $index = array_search($size, $slots, true);
        array_splice($slots, (int) $index, 1);
    }

    /**
     * The category squad is next when its best rank is better than the next
     * shooter still waiting in the ranking pool.
     *
     * @param  array{members: list<array{rank: ?int}>}  $category
     * @param  list<array{rank: ?int}>  $pool
     */
    private function categoryGoesBefore(array $category, array $pool): bool
    {
        if ($pool === []) {
            return true;
        }

        $categoryBest = $this->ranksOf($category['members'])[0] ?? null;
        $poolBest = $pool[0]['rank'];
        if ($categoryBest === null) {
            return false;
        }
        if ($poolBest === null) {
            return true;
        }

        return $categoryBest < $poolBest;
    }

    /**
     * @param  array{rank: ?int, name: string}  $a
     * @param  array{rank: ?int, name: string}  $b
     */
    private function byRank(array $a, array $b): int
    {
        if ($a['rank'] === null && $b['rank'] === null) {
            return strcasecmp($a['name'], $b['name']);
        }
        if ($a['rank'] === null) {
            return 1;
        }
        if ($b['rank'] === null) {
            return -1;
        }
        if ($a['rank'] !== $b['rank']) {
            return $a['rank'] <=> $b['rank'];
        }

        return strcasecmp($a['name'], $b['name']);
    }

    /**
     * @param  array{order: int, members: list<array{rank: ?int}>}  $a
     * @param  array{order: int, members: list<array{rank: ?int}>}  $b
     */
    private function bySquadStrength(array $a, array $b): int
    {
        $aRanks = $this->ranksOf($a['members']);
        $bRanks = $this->ranksOf($b['members']);
        if ($aRanks === [] && $bRanks !== []) {
            return 1;
        }
        if ($bRanks === [] && $aRanks !== []) {
            return -1;
        }
        $length = min(count($aRanks), count($bRanks));
        for ($i = 0; $i < $length; $i++) {
            if ($aRanks[$i] !== $bRanks[$i]) {
                return $aRanks[$i] <=> $bRanks[$i];
            }
        }

        return $a['order'] <=> $b['order'];
    }

    /**
     * @param  list<array{rank: ?int}>  $members
     * @return list<int>
     */
    private function ranksOf(array $members): array
    {
        $ranks = [];
        foreach ($members as $member) {
            if ($member['rank'] !== null) {
                $ranks[] = $member['rank'];
            }
        }
        sort($ranks);

        return $ranks;
    }
}
