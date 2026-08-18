<?php

namespace App\Console\Commands;

use App\Models\MatchEvent;
use App\Models\User;
use App\Services\WalkInRegistrationService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Confirm walk-in shooters from a CSV.
 *
 * Typical workflow when MD uploads scores for a completed match:
 *
 *   1. Run `scores:apply-stage-pivot --dry-run` — it lists shooters on the
 *      score sheet with no MatchRegistration ("walk-in candidates").
 *   2. MD produces a small CSV confirming each walk-in with their division,
 *      membership status, and a note (paid R500 cash, receipt #123, etc).
 *   3. Run this command to write the walk-in MatchRegistration rows.
 *   4. Re-run `scores:apply-stage-pivot --create-missing` — those shooters
 *      now have MatchRegistrations, so the score creation flow finds their
 *      division automatically.
 *
 * CSV shape:
 *
 *     name,email,division_slug,membership_status,note
 *     "John Doe",jdoe@example.com,open,active_member,"Paid R500 cash, receipt #123"
 *     "Fred Bloggs",,factory,non_member,"Regular local shooter, will invite later"
 *
 * Notes:
 *   - `email` may be empty. Shadow user is created if no user matches.
 *   - `division_slug` is required and must match a Division row.
 *   - `membership_status` accepts active_member / lapsed_member / non_member.
 *     Empty auto-classifies from the user's current membership state.
 *   - `note` is required — exco sees it on the audit report.
 */
class RegisterWalkInsCommand extends Command
{
    protected $signature = 'scores:register-walk-ins
        {match : MatchEvent ID}
        {csv : Path to the walk-ins CSV (absolute or base_path-relative)}
        {--md= : Email of the admin/MD user confirming the walk-ins (recorded as walk_in_confirmed_by)}
        {--dry-run : Report what would be created without writing}';

    protected $description = 'Confirm walk-in shooters from a CSV and create walk-in MatchRegistration rows';

    public function handle(WalkInRegistrationService $service): int
    {
        $matchId = (int) $this->argument('match');
        $match = MatchEvent::find($matchId);
        if (! $match) {
            $this->error("MatchEvent #{$matchId} not found.");
            return self::FAILURE;
        }

        $csvArg = (string) $this->argument('csv');
        $csvPath = is_file($csvArg) ? $csvArg : base_path($csvArg);
        if (! is_file($csvPath)) {
            $this->error("CSV not found: {$csvPath}");
            return self::FAILURE;
        }

        $mdEmail = $this->option('md') ? strtolower((string) $this->option('md')) : null;
        $mdUser = null;
        if ($mdEmail !== null) {
            $mdUser = User::whereRaw('LOWER(email) = ?', [$mdEmail])->first();
            if (! $mdUser) {
                $this->error("--md user '{$mdEmail}' does not exist. Provide the email of an existing admin/MD account.");
                return self::FAILURE;
            }
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = $this->readCsv($csvPath);
        if ($rows === []) {
            $this->error('CSV parsed to zero data rows.');
            return self::FAILURE;
        }

        $this->info('=== Walk-in registration ===');
        $this->line("Match:   #{$match->id}  {$match->name}");
        $this->line('Date:    '.($match->match_date?->toDateString() ?? '—'));
        $this->line('CSV:     '.$csvPath);
        $this->line('Rows:    '.count($rows));
        $this->line('MD:      '.($mdUser ? $mdUser->email : '(system)'));
        $this->line('Mode:    '.($dryRun ? 'DRY RUN' : 'WRITE'));
        $this->newLine();

        $summary = ['created' => 0, 'errors' => 0];

        foreach ($rows as $i => $row) {
            $label = 'Row '.($i + 1).' ('.($row['name'] ?? '?').')';

            if ($dryRun) {
                $this->line("  ✎ {$label}: would confirm walk-in (division={$row['division_slug']}, status={$row['membership_status']}, note='{$row['note']}')");
                continue;
            }

            try {
                $registration = $service->confirmWalkIn($match, $row, $mdUser);
                $this->line("  ✓ {$label}: created MR #{$registration->id} — SAPRF R"
                    .number_format((float) $registration->saprf_fee, 2)
                    .' + Platform R'.number_format((float) $registration->platform_fee, 2)
                    .' = deduct R'.number_format(abs((float) $registration->md_net_amount), 2)
                    .' from range payout');
                $summary['created']++;
            } catch (Throwable $e) {
                $this->error("  ✗ {$label}: {$e->getMessage()}");
                $summary['errors']++;
            }
        }

        $this->newLine();
        if ($dryRun) {
            $this->info('[dry-run] Would have processed '.count($rows).' walk-in row(s). No writes.');
        } else {
            $this->info("Created: {$summary['created']}   Errors: {$summary['errors']}");
        }

        return $summary['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Read a CSV with a header row and return each row as an associative array
     * keyed by the trimmed lowercased header names, aliased to canonical keys.
     *
     * @return list<array{name:string,email:?string,division_slug:string,membership_status:?string,note:?string}>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            return [];
        }

        // BOM strip so the first header doesn't come back as "\u{FEFF}name".
        $bom = pack('H*', 'EFBBBF');
        $first = fread($handle, 3);
        if ($first !== $bom) {
            rewind($handle);
        }

        $headers = fgetcsv($handle);
        if ($headers === false || $headers === null) {
            fclose($handle);
            return [];
        }
        $normalisedHeaders = array_map(
            fn ($h) => strtolower(trim((string) $h)),
            $headers,
        );

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $row = array_map(fn ($c) => trim((string) $c), $row);
            if (implode('', $row) === '') {
                continue;
            }
            $assoc = [];
            foreach ($normalisedHeaders as $i => $h) {
                if ($h === '') {
                    continue;
                }
                $assoc[$h] = $row[$i] ?? '';
            }

            $rows[] = [
                'name' => $assoc['name'] ?? '',
                'email' => $assoc['email'] ?? '',
                'division_slug' => $assoc['division_slug'] ?? $assoc['division'] ?? '',
                'membership_status' => $assoc['membership_status'] ?? $assoc['status'] ?? '',
                'note' => $assoc['note'] ?? '',
            ];
        }
        fclose($handle);

        return $rows;
    }
}
