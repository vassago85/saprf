<?php

namespace App\Support;

/**
 * Impact returns a shooter's stages in bay order, which is not the
 * stage number printed on the name ("Stage 5" can sit between
 * "Stage 2" and "Stage 3"). Every surface that shows those stages
 * sorts through here so the course reads 1, 2, 3… and Day 1 before Day 2.
 */
class ImpactStages
{
    /**
     * @param  list<array<string, mixed>>  $stages
     * @return list<array<string, mixed>>
     */
    public static function sort(array $stages): array
    {
        $rows = [];
        foreach (array_values($stages) as $index => $stage) {
            if (! is_array($stage)) {
                continue;
            }
            $rows[] = ['stage' => $stage, 'index' => $index];
        }

        usort($rows, function (array $a, array $b): int {
            return self::key($a['stage'], $a['index']) <=> self::key($b['stage'], $b['index']);
        });

        return array_values(array_map(fn (array $row): array => $row['stage'], $rows));
    }

    /**
     * Shape used by the public results row and the shooter dashboard.
     * Stages with no name are dropped — they cannot be labelled.
     *
     * @return list<array{label: string, points: ?float, number: ?int, day: int}>
     */
    public static function forDisplay(mixed $stages): array
    {
        if (! is_array($stages) || $stages === []) {
            return [];
        }

        $out = [];
        foreach (self::sort($stages) as $stage) {
            $label = trim((string) ($stage['name'] ?? $stage['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $points = $stage['points'] ?? $stage['hits'] ?? null;
            $out[] = [
                'label' => $label,
                'points' => is_numeric($points) ? (float) $points : null,
                'number' => self::stageNumber($label),
                'day' => self::dayNumber($label, $stage['day'] ?? null),
            ];
        }

        return $out;
    }

    public static function stageNumber(string $name): ?int
    {
        if (preg_match('/stage\s*(\d+)/i', $name, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    public static function dayNumber(string $name, mixed $stored = null): int
    {
        if (is_numeric($stored) && (int) $stored > 0) {
            return (int) $stored;
        }

        if (preg_match('/day\s*(\d+)/i', $name, $matches) === 1) {
            return (int) $matches[1];
        }

        return 1;
    }

    /**
     * @param  array<string, mixed>  $stage
     * @return array{0: int, 1: int, 2: int}
     */
    private static function key(array $stage, int $index): array
    {
        $name = (string) ($stage['name'] ?? $stage['label'] ?? '');
        $number = self::stageNumber($name);

        return [
            self::dayNumber($name, $stage['day'] ?? null),
            $number ?? (10000 + $index),
            $index,
        ];
    }
}
