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

                    $divisionId = $this->resolveDivision($row);
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

        $this->autoCompleteMatch($scoreImport->match->fresh());

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
     * Once scores are in, transition the match to `completed` so the events
     * list flips to the results layout (podium + View Results) without an
     * MD having to remember to click "Complete match" separately.
     *
     * Guard rails:
     *  - Never touch a match that's already `completed` or `cancelled`.
     *  - Never close out a match whose effective end date is still in the
     *    future — day-1 of a 2-day national is still an ongoing event, and
     *    a preliminary/partial upload before match day shouldn't shut
     *    registration.
     */
    private function autoCompleteMatch(\App\Models\MatchEvent $match): void
    {
        if (in_array($match->status, ['completed', 'cancelled'], true)) {
            return;
        }

        $effectiveEnd = $match->match_end_date ?? $match->match_date;
        if ($effectiveEnd === null || $effectiveEnd->isFuture()) {
            return;
        }

        $previousStatus = $match->status;
        $match->update(['status' => 'completed']);

        $this->auditLogService->log(
            auth()->user(),
            'match.auto_completed_after_import',
            'MatchEvent',
            $match->id,
            ['status' => $previousStatus],
            ['status' => 'completed'],
        );
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

            // First non-empty value wins when several source columns normalize
            // to the same key. Impact-scoring exports routinely ship Class,
            // Div and Category together — all three alias to `division`, but
            // `Div` is the authoritative one. Plain array_combine would let
            // an empty Category column blank out a populated Div, or let a
            // "Factory,Senior" Category tag overwrite a clean "Senior" Div.
            $row = [];
            foreach ($headers as $idx => $key) {
                $value = $line[$idx] ?? null;
                $existing = $row[$key] ?? null;
                if ($existing === null || $existing === '') {
                    $row[$key] = $value;
                }
            }
            $rows[] = $row;
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
                $exact = $this->exactNameLookup($name);
                if ($exact) {
                    return $exact;
                }

                // Also try the exact lookup with generational suffixes stripped
                // ("Snr", "Jnr", "Sr", "Jr", "II", "III"): CSVs frequently
                // qualify a shooter as "Le Riche Coetzer Snr" while the user
                // account is registered as just "Le Riche Coetzer".
                $suffixStripped = $this->stripHonorificSuffixes($name);
                if ($suffixStripped !== '' && $suffixStripped !== $name) {
                    $exact = $this->exactNameLookup($suffixStripped);
                    if ($exact) {
                        return $exact;
                    }
                }

                // Priority 4: order-insensitive token match. Handles exports that
                // swap name order (e.g. "Mey Aliza" ↔ "Aliza Mey"). Only accept
                // when exactly one user shares the same set of name tokens, so we
                // never collapse two distinct shooters onto one account.
                $swapped = $this->resolveByNameTokens($name);
                if ($swapped !== null) {
                    return $swapped;
                }
            }
        }

        return null;
    }

    /**
     * Exact case-insensitive name lookup, whitespace-normalised on both sides.
     * MariaDB (production) has REGEXP_REPLACE for stripping runs of internal
     * whitespace; sqlite (tests) doesn't, so fall back to a plain LOWER compare
     * on those drivers — imported names are already whitespace-collapsed by
     * the caller, so the difference only matters when a user account itself
     * has irregular whitespace baked into it (rare).
     */
    private function exactNameLookup(string $normalizedName): ?int
    {
        $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();

        $query = User::query();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $query->whereRaw("LOWER(REGEXP_REPLACE(name, '\\\\s+', ' ')) = ?", [$normalizedName]);
        } else {
            $query->whereRaw('LOWER(name) = ?', [$normalizedName]);
        }

        $id = $query->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * Strip trailing generational / honorific tokens ("Snr", "Jnr", "Sr", "Jr",
     * "II", "III", "IV") from a whitespace-normalised, lower-cased name.
     * Preserves everything else so genuinely different names never collapse.
     */
    private function stripHonorificSuffixes(string $normalizedName): string
    {
        $tokens = collect(explode(' ', $normalizedName))
            ->reject(fn ($t) => in_array($t, ['snr', 'jnr', 'sr', 'jr', 'ii', 'iii', 'iv'], true))
            ->filter();

        return $tokens->implode(' ');
    }

    /**
     * Resolve a user by an order-insensitive comparison of name tokens. Returns
     * the user id only when a single user matches; null otherwise. Honorific
     * suffixes are stripped on both sides so "Le Riche Coetzer Snr" (CSV)
     * still lines up with a "Le Riche Coetzer" account.
     */
    private function resolveByNameTokens(string $normalizedName): ?int
    {
        $tokens = collect(explode(' ', $this->stripHonorificSuffixes($normalizedName)))
            ->filter()
            ->sort()
            ->values();
        if ($tokens->count() < 2) {
            return null;
        }
        $key = $tokens->implode(' ');

        $candidates = User::query()
            ->get(['id', 'name'])
            ->filter(function (User $user) use ($key): bool {
                $normalized = Str::lower(preg_replace('/\s+/', ' ', trim((string) $user->name)));
                $userKey = collect(explode(' ', $this->stripHonorificSuffixes($normalized)))
                    ->filter()->sort()->values()->implode(' ');

                return $userKey === $key;
            });

        return $candidates->count() === 1 ? (int) $candidates->first()->id : null;
    }

    /**
     * Resolve division from the CSV row, and infer categories from divisions
     * that Impact scoring uses as pseudo-divisions ("Seniors", "Juniors", "Ladies").
     *
     * Resolve the shooter's division from the CSV row.
     *
     * Impact scoring often labels its own "divisions" as Seniors / Juniors /
     * Ladies. Under the federation's flat-division model these ARE divisions,
     * so we accept them directly (plus a few common aliases). Anything else
     * (Open, Factory, Limited, Production…) also resolves by slug or name.
     */
    private function resolveDivision(array $row): ?int
    {
        $value = trim($row['division'] ?? '');
        if ($value === '') {
            return null;
        }

        // Alias plural forms → canonical slugs.
        $aliases = [
            'seniors' => 'senior',
            'juniors' => 'junior',
            'lady' => 'ladies',
            'mens' => 'open',
            'men' => 'open',
            'male' => 'open',
            'overall' => 'open',
        ];

        // Impact scoring's "Category" column sometimes carries a comma-separated
        // tag list ("Factory,Senior") when a shooter fits multiple demographic
        // categories. Under the flat-division model any single one of them can
        // be a valid division on its own, so try each candidate in order and
        // return the first that resolves to a real division row.
        $candidates = str_contains($value, ',')
            ? array_map('trim', explode(',', $value))
            : [$value];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $lower = Str::lower($candidate);
            $lookup = $aliases[$lower] ?? $lower;

            $id = \App\Models\Division::query()
                ->where(function ($q) use ($lookup) {
                    $q->whereRaw('LOWER(slug) = ?', [$lookup])
                        ->orWhereRaw('LOWER(name) = ?', [$lookup]);
                })
                ->value('id');
            if ($id) {
                return (int) $id;
            }
        }

        return null;
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

            // Division (Impact uses "Div"; also accept "Class"/"Category" since
            // under the flat-division model demographic classes are divisions too).
            'div', 'class', 'category', 'categories', 'cat' => 'division',

            // Email variants
            'e_mail', 'email_address', 'e_mail_address' => 'email',

            // SAPRF number (Impact uses "Member Number")
            'member_number', 'member', 'member_no', 'membership_number' => 'member_number',
            'saprf_number', 'saprf' => 'saprf_number',

            default => $normalized,
        };
    }
}
