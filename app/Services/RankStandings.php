<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Standard competition ranking for a set of season-standings rows.
 *
 * Equal points (to 4 decimals, the precision the season total is stored at)
 * share a rank and the next place is skipped. A null seed keeps export order:
 * points desc, surname, first name. A seed shuffles only inside each tie group.
 *
 * Rows that would look tied at 1 decimal but are not equal at 4 decimals are
 * ranked apart, and those rows are flagged to display all four decimals.
 */
class RankStandings
{
    /**
     * @param  Collection<int, array<string, mixed>>  $rows  each row needs points and name
     * @return Collection<int, array<string, mixed>>
     */
    public function rank(Collection $rows, ?int $seed = null): Collection
    {
        $prepared = $rows
            ->map(fn (array $row) => $this->prepare($row))
            ->sort(fn (array $a, array $b) => $this->compare($a, $b))
            ->values();

        if ($seed !== null) {
            $prepared = $this->shuffleTies($prepared, $seed);
        }

        return $this->assignRanks($prepared);
    }

    /**
     * Official order for PDF, CSV and archived results. Ignores any visit seed.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public function export(Collection $rows): Collection
    {
        return $this->rank($rows, null);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function prepare(array $row): array
    {
        [$surname, $first] = $this->splitName((string) ($row['name'] ?? ''));
        $points = (float) ($row['points'] ?? 0);
        $row['tenths'] = $this->tenths($points);
        $row['precise'] = $this->precise($points);
        $row['surname_key'] = mb_strtolower($surname, 'UTF-8');
        $row['first_key'] = mb_strtolower($first, 'UTF-8');
        $row['id'] = (int) ($row['id'] ?? 0);

        return $row;
    }

    /**
     * One-decimal bucket, used only to decide when the table must show the
     * extra digits. Ranking itself uses precise().
     */
    private function tenths(float $points): int
    {
        return (int) round($points * 10);
    }

    /**
     * Season total to 4 decimals, as an integer, so float noise cannot split
     * or merge a real tie.
     */
    private function precise(float $points): int
    {
        return (int) round($points * 10000);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $full): array
    {
        $parts = preg_split('/\s+/', trim($full)) ?: [];
        $parts = array_values(array_filter($parts, fn ($part) => $part !== ''));

        if ($parts === []) {
            return ['', ''];
        }
        if (count($parts) === 1) {
            return [$parts[0], ''];
        }

        $surname = array_pop($parts);

        return [$surname, implode(' ', $parts)];
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function compare(array $a, array $b): int
    {
        return [
            $b['precise'],
            $a['surname_key'],
            $a['first_key'],
            $a['id'],
        ] <=> [
            $a['precise'],
            $b['surname_key'],
            $b['first_key'],
            $b['id'],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $ordered
     * @return Collection<int, array<string, mixed>>
     */
    private function shuffleTies(Collection $ordered, int $seed): Collection
    {
        $groups = [];

        foreach ($ordered as $row) {
            $last = array_key_last($groups);
            if ($last === null || $groups[$last][0]['precise'] !== $row['precise']) {
                $groups[] = [];
                $last = array_key_last($groups);
            }
            $groups[$last][] = $row;
        }

        $shuffled = [];
        foreach ($groups as $group) {
            foreach ($this->shuffleGroup($group, $seed) as $row) {
                $shuffled[] = $row;
            }
        }

        return collect($shuffled);
    }

    /**
     * Laravel 13's Collection::shuffle() takes no seed and throws if one is
     * passed. Mt19937 is the seeded shuffle that keeps one visit stable.
     *
     * @param  array<int, array<string, mixed>>  $group
     * @return array<int, array<string, mixed>>
     */
    private function shuffleGroup(array $group, int $seed): array
    {
        if (count($group) < 2) {
            return $group;
        }

        $randomizer = new Randomizer(new Mt19937($seed));

        return $randomizer->shuffleArray($group);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $ordered
     * @return Collection<int, array<string, mixed>>
     */
    private function assignRanks(Collection $ordered): Collection
    {
        $counts = $ordered->countBy('precise');
        $splitTenths = $ordered
            ->groupBy('tenths')
            ->filter(fn (Collection $group) => $group->unique('precise')->count() > 1)
            ->keys()
            ->flip();
        $rank = 0;
        $previous = null;

        return $ordered->values()->map(function (array $row, int $index) use ($counts, $splitTenths, &$rank, &$previous) {
            $precise = $row['precise'];
            if ($previous === null || $precise !== $previous) {
                $rank = $index + 1;
                $previous = $precise;
            }

            $tied = (int) ($counts[$precise] ?? 0) > 1;
            $row['rank'] = $rank;
            $row['rank_label'] = $tied ? 'T'.$rank : (string) $rank;
            $row['is_tied'] = $tied;
            $row['points_decimals'] = $splitTenths->has($row['tenths']) ? 4 : 1;
            unset($row['tenths'], $row['precise'], $row['surname_key'], $row['first_key']);

            return $row;
        })->values();
    }
}
