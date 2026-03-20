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

                foreach ($rows as $row) {
                    $score = Score::query()->create([
                        'match_id' => $scoreImport->match_id,
                        'score_import_id' => $scoreImport->id,
                        'shooter_name' => (string) ($row['shooter_name'] ?? 'Unknown'),
                        'user_id' => $this->resolveUserId($row),
                        'raw_score' => (float) ($row['raw_score'] ?? 0),
                        'placement' => isset($row['placement']) ? (int) $row['placement'] : null,
                        'division' => $row['division'] ?? null,
                        'category' => $row['category'] ?? null,
                        'status' => 'pending',
                        'is_member' => false,
                        'match_date' => $scoreImport->match->match_date,
                        'raw_meta' => $row,
                    ]);

                    $this->scoreValidationService->evaluateScoreStatus($score);
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
