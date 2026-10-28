<?php

namespace App\Services\ImpactScoring;

/**
 * Impact stores a multi-day match as one list of stages. Later days are
 * usually named ("Day 2 Stage 1"); day 1 is often just "stage 1". The
 * match-level points total is both days added together, and a day view
 * that is not built from the individual stage scores comes back as zero.
 */
class ImpactStageDays
{
    /**
     * @param  list<array{id?:int|string,name?:string}>  $stages
     * @return array<int, list<int>> day number => stage ids
     */
    public static function idsByDay(array $stages): array
    {
        $explicit = [];
        $unlabeled = [];

        foreach ($stages as $stage) {
            $id = (int) ($stage['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $day = self::dayNumber((string) ($stage['name'] ?? ''));
            if ($day === null) {
                $unlabeled[] = $id;
            } else {
                $explicit[$day][] = $id;
            }
        }

        if ($explicit === []) {
            $all = [];
            foreach ($stages as $stage) {
                $id = (int) ($stage['id'] ?? 0);
                if ($id > 0) {
                    $all[] = $id;
                }
            }

            return $all === [] ? [] : [1 => $all];
        }

        if ($unlabeled !== []) {
            $explicit[1] = array_values(array_merge($explicit[1] ?? [], $unlabeled));
        }

        ksort($explicit);

        return $explicit;
    }

    /**
     * @param  list<array{id?:int|string,name?:string}>  $stages
     * @return array<int, int> stage id => day number
     */
    public static function dayByStageId(array $stages): array
    {
        $map = [];
        foreach (self::idsByDay($stages) as $day => $ids) {
            foreach ($ids as $id) {
                $map[$id] = (int) $day;
            }
        }

        return $map;
    }

    public static function dayNumber(string $name): ?int
    {
        if (preg_match('/\bday[\s-]*(\d+)\b/i', $name, $matches)) {
            $day = (int) $matches[1];

            return $day > 0 ? $day : null;
        }

        return null;
    }
}
