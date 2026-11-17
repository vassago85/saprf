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
 * go to shooters whose rank sits between the group, closest to the middle.
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
            } elseif (count($members) < $size) {
                $members = array_merge($members, $this->takeFillers($members, $pool, $size - count($members)));
            }
            $categorySquads[] = ['order' => $group['order'], 'members' => $members];
        }

        usort($pool, fn (array $a, array $b) => $this->byRank($a, $b));
        $ranking = [];
        $offset = 0;
        $rankingOrder = 3;
        foreach ($slots as $size) {
            $ranking[] = [
                'order' => $rankingOrder,
                'members' => array_slice($pool, $offset, $size),
            ];
            $offset += $size;
            $rankingOrder++;
        }

        $squads = array_merge($categorySquads, $ranking);
        usort($squads, fn (array $a, array $b) => $this->bySquadStrength($a, $b));

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
     * @param  list<array{registration_id: int, user_id: ?int, name: string, rank: ?int, division: string, slug: string}>  $core
     * @param  list<array{registration_id: int, user_id: ?int, name: string, rank: ?int, division: string, slug: string}>  $pool
     * @return list<array{registration_id: int, user_id: ?int, name: string, rank: ?int, division: string, slug: string}>
     */
    private function takeFillers(array $core, array &$pool, int $need): array
    {
        $ranks = $this->ranksOf($core);
        $median = $this->median($ranks);
        $min = $ranks[0] ?? null;
        $max = $ranks === [] ? null : $ranks[array_key_last($ranks)];
        $chosen = [];

        while (count($chosen) < $need && $pool !== []) {
            $pick = $this->bestFiller($pool, $median, $min, $max, true)
                ?? $this->bestFiller($pool, $median, $min, $max, false);
            if ($pick === null) {
                break;
            }
            $chosen[] = $pick;
            $pool = array_values(array_filter(
                $pool,
                fn (array $shooter) => $shooter['registration_id'] !== $pick['registration_id']
            ));
        }

        return $chosen;
    }

    /**
     * @param  list<array{registration_id: int, user_id: ?int, name: string, rank: ?int, division: string, slug: string}>  $pool
     * @return array{registration_id: int, user_id: ?int, name: string, rank: ?int, division: string, slug: string}|null
     */
    private function bestFiller(array $pool, ?float $median, ?int $min, ?int $max, bool $betweenOnly): ?array
    {
        $candidates = [];
        foreach ($pool as $shooter) {
            $rank = $shooter['rank'];
            if ($betweenOnly) {
                if ($rank === null || $min === null || $max === null || $rank <= $min || $rank >= $max) {
                    continue;
                }
            }
            $candidates[] = $shooter;
        }
        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $a, array $b) use ($median): int {
            if ($a['rank'] === null && $b['rank'] === null) {
                return strcasecmp($a['name'], $b['name']);
            }
            if ($a['rank'] === null) {
                return 1;
            }
            if ($b['rank'] === null) {
                return -1;
            }
            if ($median !== null) {
                $distance = abs($a['rank'] - $median) <=> abs($b['rank'] - $median);
                if ($distance !== 0) {
                    return $distance;
                }
            }
            if ($a['rank'] !== $b['rank']) {
                return $a['rank'] <=> $b['rank'];
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return $candidates[0];
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

    /**
     * @param  list<int>  $ranks
     */
    private function median(array $ranks): ?float
    {
        if ($ranks === []) {
            return null;
        }
        $count = count($ranks);
        $mid = intdiv($count, 2);
        if ($count % 2 === 1) {
            return (float) $ranks[$mid];
        }

        return ($ranks[$mid - 1] + $ranks[$mid]) / 2;
    }
}
