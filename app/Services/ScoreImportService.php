<?php

namespace App\Services;

use App\Models\Score;
use App\Models\ScoreImport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ScoreImportService
{
    public function __construct(
        private readonly ScoreValidationService $scoreValidationService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function importCsv(ScoreImport $scoreImport, string $fullPath): int
    {
        $count = 0;
        $scoreImport->update(['import_status' => 'processing']);

        try {
            DB::transaction(function () use ($scoreImport, $fullPath, &$count) {
                $rows = $this->readCsv($fullPath);

                $match = $scoreImport->match;
                $provincialColumns = $this->parseProvincialColumns($match);

                foreach ($rows as $row) {
                    $score = Score::query()->create([
                        'match_id' => $match->id,
                        'score_import_id' => $scoreImport->id,
                        'shooter_name' => (string) ($row['shooter_name'] ?? 'Unknown'),
                        'user_id' => $this->resolveUserId($row),
                        'raw_score' => (float) ($row['raw_score'] ?? 0),
                        'provincial_raw_score' => $this->calculateProvincialScore($row, $provincialColumns),
                        'placement' => isset($row['placement']) ? (int) $row['placement'] : null,
                        'division_id' => $this->resolveDivisionId($row),
                        'status' => 'pending',
                        'is_member' => false,
                        'match_date' => $match->match_date,
                        'raw_meta' => $row,
                    ]);

                    $this->scoreValidationService->evaluateScoreStatus($score);
                    $this->attachScoreCategories($score, $row);
                    $count++;
                }
            });
        } catch (\Throwable $exception) {
            $scoreImport->update([
                'import_status' => 'failed',
                'notes' => trim(($scoreImport->notes ?? '') . PHP_EOL . $exception->getMessage()),
            ]);

            throw $exception;
        }

        $scoreImport->update([
            'import_status' => 'completed',
            'notes' => trim(($scoreImport->notes ?? '') . PHP_EOL . "Imported {$count} rows."),
        ]);

        $this->auditLogService->log(
            auth()->user(),
            'score.import.completed',
            'ScoreImport',
            $scoreImport->id,
            null,
            ['row_count' => $count]
        );

        return $count;
    }

    private function readCsv(string $fullPath): array
    {
        $rows = [];
        $handle = fopen($fullPath, 'r');

        if (! $handle) {
            return $rows;
        }

        $headers = fgetcsv($handle) ?: [];
        $headers = array_map(fn ($value) => $this->normalizeHeader((string) $value), $headers);

        while (($line = fgetcsv($handle)) !== false) {
            if (count($line) === count($headers)) {
                $rows[] = array_combine($headers, $line) ?: [];
            }
        }

        fclose($handle);

        return $rows;
    }

    private function resolveUserId(array $row): ?int
    {
        if (! empty($row['email'])) {
            return User::query()->where('email', $row['email'])->value('id');
        }

        if (! empty($row['shooter_name'])) {
            return User::query()
                ->whereRaw('LOWER(name) = ?', [Str::lower($row['shooter_name'])])
                ->value('id');
        }

        return null;
    }

    private function resolveDivisionId(array $row): ?int
    {
        $value = trim($row['division'] ?? '');
        if ($value === '') {
            return null;
        }

        $lower = Str::lower($value);

        return \App\Models\Division::query()
            ->where(function ($q) use ($lower) {
                $q->whereRaw('LOWER(slug) = ?', [$lower])
                    ->orWhereRaw('LOWER(name) = ?', [$lower]);
            })
            ->value('id');
    }

    private function attachScoreCategories(Score $score, array $row): void
    {
        $value = trim($row['category'] ?? '');
        if ($value === '') {
            return;
        }

        $labels = preg_split('/[|,]/', $value);

        foreach ($labels as $label) {
            $label = trim($label);
            if ($label === '') {
                continue;
            }

            $categoryId = \App\Models\Category::query()
                ->where('slug', $label)
                ->orWhere('name', $label)
                ->value('id');

            if ($categoryId) {
                $score->categories()->attach($categoryId);
            }
        }
    }

    private function parseProvincialColumns(\App\Models\MatchEvent $match): array
    {
        if (! $match->also_counts_for_provincial || blank($match->provincial_stage_columns)) {
            return [];
        }

        return array_map(
            fn ($col) => Str::snake(trim($col)),
            explode(',', $match->provincial_stage_columns),
        );
    }

    private function calculateProvincialScore(array $row, array $columns): ?float
    {
        if (empty($columns)) {
            return null;
        }

        $total = 0.0;
        $found = false;

        foreach ($columns as $col) {
            if (isset($row[$col]) && is_numeric($row[$col])) {
                $total += (float) $row[$col];
                $found = true;
            }
        }

        return $found ? round($total, 3) : null;
    }

    private function normalizeHeader(string $header): string
    {
        $normalized = Str::snake(trim($header));

        return match ($normalized) {
            'name', 'shooter' => 'shooter_name',
            'score', 'total_score', 'stage_points' => 'raw_score',
            'place', 'rank' => 'placement',
            default => $normalized,
        };
    }
}
