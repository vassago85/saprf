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
        $skippedRows = [];
        $scoreImport->update(['import_status' => 'processing']);

        try {
            $parsed = $this->readCsv($fullPath);

            // Validate required columns are present. Fail fast with a clear message
            // rather than importing zero-score garbage.
            //
            // Shooter name: accept either a combined "shooter_name" column, OR
            // both a "first" and "last" column (Impact scoring format).
            $hasName = in_array('shooter_name', $parsed['headers'], true)
                || (in_array('first', $parsed['headers'], true) && in_array('last', $parsed['headers'], true));
            $hasScore = in_array('raw_score', $parsed['headers'], true);

            if (! $hasName || ! $hasScore) {
                $missing = [];
                if (! $hasName) {
                    $missing[] = 'shooter_name (or first + last)';
                }
                if (! $hasScore) {
                    $missing[] = 'raw_score (or impacts / total / points)';
                }
                throw new \RuntimeException(
                    'CSV is missing required column(s): ' . implode(', ', $missing) .
                    '. Found columns: ' . implode(', ', $parsed['headers']) . '.'
                );
            }

            DB::transaction(function () use ($scoreImport, $parsed, &$count, &$skippedRows) {
                $match = $scoreImport->match;
                $provincialColumns = $this->parseProvincialColumns($match);
                $day = $scoreImport->day; // 1 or 2 for 2-day matches, null otherwise

                foreach ($parsed['rows'] as $index => $rawRow) {
                    // Normalize the row: combine first+last, alias Impact-scoring columns, etc.
                    $row = $this->normalizeRow($rawRow);

                    // Skip rows with no shooter name or score
                    $shooterName = trim((string) ($row['shooter_name'] ?? ''));
                    if ($shooterName === '') {
                        $skippedRows[] = "Row " . ($index + 2) . ": missing shooter name";
                        continue;
                    }

                    if (! isset($row['raw_score']) || ! is_numeric($row['raw_score'])) {
                        $skippedRows[] = "Row " . ($index + 2) . " ({$shooterName}): non-numeric or missing raw_score";
                        continue;
                    }

                    [$divisionId, $inferredCategories] = $this->resolveDivisionAndInferredCategories($row);
                    $userId = $this->resolveUserId($row);
                    $csvScore = (float) $row['raw_score'];

                    // Day-tagged imports upsert onto the same score row for this shooter
                    // in this match, so day-1 and day-2 CSVs merge cleanly.
                    // Untagged (null day) imports create a fresh row (legacy behaviour).
                    if ($day !== null && $userId !== null) {
                        $score = Score::firstOrNew([
                            'match_id' => $match->id,
                            'user_id' => $userId,
                        ]);
                    } else {
                        $score = new Score();
                    }

                    $attributes = [
                        'match_id' => $match->id,
                        'score_import_id' => $scoreImport->id,
                        'shooter_name' => $shooterName,
                        'user_id' => $userId,
                        'placement' => isset($row['placement']) && is_numeric($row['placement']) ? (int) $row['placement'] : null,
                        'division_id' => $divisionId,
                        'status' => 'pending',
                        'is_member' => false,
                        'match_date' => $match->match_date,
                        'raw_meta' => array_merge($score->raw_meta ?? [], ['day_' . ($day ?? 1) => $row]),
                    ];

                    if ($day === 2) {
                        $attributes['day2_raw_score'] = $csvScore;
                    } elseif ($day === 1) {
                        $attributes['day1_raw_score'] = $csvScore;
                    } else {
                        // Legacy: whole-match single upload. Store as day1 so the model
                        // hook computes raw_score = day1 + day2 = day1. Also set the
                        // provincial_raw_score via stage-column summing if configured.
                        $attributes['day1_raw_score'] = $csvScore;
                        $provincial = $this->calculateProvincialScore($row, $provincialColumns);
                        if ($provincial !== null) {
                            $attributes['provincial_raw_score'] = $provincial;
                        }
                    }

                    $score->fill($attributes);
                    $score->save();

                    $this->scoreValidationService->evaluateScoreStatus($score);
                    $this->attachScoreCategories($score, $row, $inferredCategories);
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

        $summary = "Imported {$count} rows.";
        if (! empty($skippedRows)) {
            $summary .= ' Skipped ' . count($skippedRows) . ' rows:' . PHP_EOL . implode(PHP_EOL, $skippedRows);
        }

        $scoreImport->update([
            'import_status' => 'completed',
            'notes' => trim(($scoreImport->notes ?? '') . PHP_EOL . $summary),
        ]);

        // Recalculate standings for this match now that scores are in.
        try {
            app(\App\Services\StandingsCalculationService::class)
                ->recalculateForMatch($scoreImport->match);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Standings recalculation after import failed', [
                'score_import_id' => $scoreImport->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->auditLogService->log(
            auth()->user(),
            'score.import.completed',
            'ScoreImport',
            $scoreImport->id,
            null,
            ['row_count' => $count, 'skipped_count' => count($skippedRows)]
        );

        return $count;
    }

    /**
     * @return array{headers: array<int, string>, rows: array<int, array<string, mixed>>}
     */
    private function readCsv(string $fullPath): array
    {
        $rows = [];
        $handle = fopen($fullPath, 'r');

        if (! $handle) {
            throw new \RuntimeException("Unable to open uploaded file at {$fullPath}.");
        }

        // Strip UTF-8 BOM if present (Excel loves adding this to CSV UTF-8 exports)
        $bom = pack('H*', 'EFBBBF');
        $first = fread($handle, 3);
        if ($first !== $bom) {
            rewind($handle);
        }

        $headers = fgetcsv($handle) ?: [];
        $headers = array_map(fn ($value) => $this->normalizeHeader((string) $value), $headers);
        $headerCount = count($headers);

        while (($line = fgetcsv($handle)) !== false) {
            // Ignore completely blank lines
            if (count($line) === 1 && trim((string) $line[0]) === '') {
                continue;
            }

            // Pad or trim to match header count so trailing-comma rows still import
            if (count($line) < $headerCount) {
                $line = array_pad($line, $headerCount, null);
            } elseif (count($line) > $headerCount) {
                $line = array_slice($line, 0, $headerCount);
            }

            $row = array_combine($headers, $line);
            if ($row !== false) {
                $rows[] = $row;
            }
        }

        fclose($handle);

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Normalize a raw parsed row into the shape the importer expects.
     * Handles Impact-scoring's split first/last name columns, aliases,
     * and cleans numeric values (strips % signs, whitespace, etc.).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        // Combine first + last into shooter_name when the CSV splits them
        // (Impact scoring exports do this).
        if (empty($row['shooter_name'])) {
            $first = trim((string) ($row['first'] ?? $row['first_name'] ?? ''));
            $last = trim((string) ($row['last'] ?? $row['last_name'] ?? $row['surname'] ?? ''));
            if ($first !== '' || $last !== '') {
                $row['shooter_name'] = trim($first . ' ' . $last);
            }
        }

        // Impact scoring uses "Impacts" as the primary score metric —
        // aliased by normalizeHeader() already, but keep as safety net.
        if (empty($row['raw_score']) && isset($row['impacts']) && is_numeric($row['impacts'])) {
            $row['raw_score'] = $row['impacts'];
        }

        // Strip trailing % from any percentage-formatted values
        foreach (['raw_score', 'match_percent', 'match_percentage'] as $key) {
            if (isset($row[$key]) && is_string($row[$key])) {
                $row[$key] = str_replace('%', '', $row[$key]);
            }
        }

        return $row;
    }

    private function resolveUserId(array $row): ?int
    {
        // Priority 1: email match (most reliable), skipping managed-account placeholders
        if (! empty($row['email'])) {
            $email = trim((string) $row['email']);
            if ($email !== '' && ! Str::endsWith($email, '@managed.saprf.co.za')) {
                $userId = User::query()->where('email', $email)->value('id');
                if ($userId) {
                    return $userId;
                }
            }
        }

        // Priority 2: SAPRF number match (Impact scoring "Member Number" column)
        $memberNumber = trim((string) ($row['member_number'] ?? $row['saprf_number'] ?? ''));
        if ($memberNumber !== '') {
            // Try exact match first
            $userId = \App\Models\Membership::query()
                ->where('saprf_number', $memberNumber)
                ->value('user_id');
            if ($userId) {
                return $userId;
            }

            // Then try a suffix match — Impact exports often strip the "SAPRF-YYYY-" prefix
            // and may have leading zeros (e.g. "0165" for member 165)
            $stripped = ltrim($memberNumber, '0');
            if ($stripped !== '' && ctype_digit($stripped)) {
                $userId = \App\Models\Membership::query()
                    ->where('saprf_number', 'like', '%-' . $stripped)
                    ->value('user_id');
                if ($userId) {
                    return $userId;
                }
            }
        }

        // Priority 3: name match (case-insensitive, whitespace-normalized)
        if (! empty($row['shooter_name'])) {
            $name = Str::lower(preg_replace('/\s+/', ' ', trim((string) $row['shooter_name'])));
            if ($name !== '') {
                return User::query()
                    ->whereRaw("LOWER(REGEXP_REPLACE(name, '\\\\s+', ' ')) = ?", [$name])
                    ->value('id');
            }
        }

        return null;
    }

    /**
     * Resolve division from the CSV row, and infer categories from divisions
     * that Impact scoring uses as pseudo-divisions ("Seniors", "Juniors", "Ladies").
     *
     * @return array{0: ?int, 1: array<int, int>}  [division_id, inferred_category_ids]
     */
    private function resolveDivisionAndInferredCategories(array $row): array
    {
        $value = trim($row['division'] ?? '');
        if ($value === '') {
            return [null, []];
        }

        $lower = Str::lower($value);

        // Impact scoring uses "Seniors" / "Juniors" / "Ladies" as pseudo-divisions,
        // but on our side these are categories, and shooters are still assigned
        // to a real division (Open by default). Map them accordingly.
        $pseudoDivisionMap = [
            'seniors' => ['open', 'senior'],
            'senior' => ['open', 'senior'],
            'juniors' => ['open', 'junior'],
            'junior' => ['open', 'junior'],
            'ladies' => ['open', 'ladies'],
            'lady' => ['open', 'ladies'],
        ];

        if (isset($pseudoDivisionMap[$lower])) {
            [$divisionSlug, $categorySlug] = $pseudoDivisionMap[$lower];
            $divisionId = \App\Models\Division::query()
                ->whereRaw('LOWER(slug) = ?', [$divisionSlug])
                ->value('id');
            $categoryId = \App\Models\Category::query()
                ->whereRaw('LOWER(slug) = ?', [$categorySlug])
                ->value('id');

            return [$divisionId, $categoryId ? [$categoryId] : []];
        }

        // Otherwise look up by slug or name
        $divisionId = \App\Models\Division::query()
            ->where(function ($q) use ($lower) {
                $q->whereRaw('LOWER(slug) = ?', [$lower])
                    ->orWhereRaw('LOWER(name) = ?', [$lower]);
            })
            ->value('id');

        return [$divisionId, []];
    }

    /**
     * Attach category tags to the score. Merges any explicitly-listed categories
     * from the `category` column with any inferred from the division column
     * (e.g. Impact's "Seniors" division adds the Senior category).
     *
     * @param  array<int, int>  $inferredCategoryIds
     */
    private function attachScoreCategories(Score $score, array $row, array $inferredCategoryIds = []): void
    {
        $categoryIds = $inferredCategoryIds;

        $value = trim($row['category'] ?? '');
        if ($value !== '') {
            $labels = preg_split('/[|,\/]/', $value);

            foreach ($labels as $label) {
                $label = Str::lower(trim($label));
                if ($label === '') {
                    continue;
                }

                // Impact-scoring's "Mens" / "Male" / "Overall" are effectively
                // no categories — skip them so we don't create empty slugs.
                if (in_array($label, ['mens', 'men', 'male', 'overall', 'open'], true)) {
                    continue;
                }

                // Map common Impact values to our slugs
                $label = match ($label) {
                    'lady' => 'ladies',
                    default => $label,
                };

                $categoryId = \App\Models\Category::query()
                    ->whereRaw('LOWER(slug) = ?', [$label])
                    ->orWhereRaw('LOWER(name) = ?', [$label])
                    ->value('id');

                if ($categoryId) {
                    $categoryIds[] = $categoryId;
                }
            }
        }

        $categoryIds = array_values(array_unique($categoryIds));
        if (! empty($categoryIds)) {
            $score->categories()->sync($categoryIds);
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
        // Lowercase first so all-caps headers like "SHOOTER NAME" don't get
        // mangled into "s_h_o_o_t_e_r_n_a_m_e" by Str::snake.
        $normalized = Str::snake(Str::lower(trim($header)));

        return match ($normalized) {
            // Split-name columns (Impact scoring uses "Last" + "First")
            'last', 'last_name', 'surname' => 'last',
            'first', 'first_name', 'given_name' => 'first',

            // Full name aliases
            'name', 'shooter', 'competitor', 'competitor_name', 'full_name', 'first_and_last_name' => 'shooter_name',

            // Score column — Impact scoring uses "Impacts" (hit count on target)
            'impacts', 'hits', 'score', 'total_score', 'stage_points', 'total_points', 'points', 'match_score', 'total' => 'raw_score',

            // Match percentage (Impact) — preserved in raw_meta but not primary metric
            'match_percent', 'match_percentage' => 'match_percentage',

            // Placement / rank
            'place', 'rank', 'position' => 'placement',

            // Division (Impact uses "Div"; some use "Class" for classification)
            'div' => 'division',

            // Category (Impact uses "Category")
            'cat', 'categories' => 'category',

            // Email variants
            'e_mail', 'email_address', 'e_mail_address' => 'email',

            // SAPRF number (Impact uses "Member Number")
            'member_number', 'member', 'member_no', 'membership_number' => 'member_number',
            'saprf_number', 'saprf' => 'saprf_number',

            default => $normalized,
        };
    }
}
