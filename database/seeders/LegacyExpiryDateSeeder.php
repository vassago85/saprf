<?php

namespace Database\Seeders;

use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Backfill membership start/expiry dates from the legacy precisionrifle.co.za
 * database for paid members who already exist on this platform.
 *
 * Only updates rows we can match unambiguously by normalized full name against
 * an existing paid membership. Does not create users or memberships.
 *
 * Run manually: php artisan db:seed --class=LegacyExpiryDateSeeder
 */
class LegacyExpiryDateSeeder extends Seeder
{
    private const DATA_FILE = 'database/data/legacy-full-member-expiries.csv';

    /** @var array<string, true> */
    private array $reportedAmbiguous = [];

    public function run(): void
    {
        $path = base_path(self::DATA_FILE);
        if (! is_file($path)) {
            $this->command?->error('Legacy expiry CSV not found: '.self::DATA_FILE);

            return;
        }

        $today = now()->startOfDay();
        $dryRun = (bool) env('LEGACY_EXPIRY_DRY_RUN', false);

        $stats = [
            'rows' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped_past' => 0,
            'skipped_no_match' => 0,
            'skipped_ambiguous' => 0,
            'skipped_not_paid' => 0,
            'errors' => 0,
        ];

        $this->command?->info('=== LegacyExpiryDateSeeder ===');
        $this->command?->line('File: '.self::DATA_FILE);
        $this->command?->line('Dry run: '.($dryRun ? 'YES' : 'no'));
        $this->command?->newLine();

        foreach ($this->readRows($path) as $lineNo => $row) {
            $stats['rows']++;

            $expiry = $this->parseDate($row['expiry_date']);
            $start = $this->parseDate($row['start_date']);
            if (! $expiry || ! $start) {
                $stats['errors']++;
                $this->command?->warn("Line {$lineNo}: unparseable dates — skipped");

                continue;
            }

            if ($expiry->lt($today)) {
                $stats['skipped_past']++;

                continue;
            }

            $displayName = trim($row['first_name'].' '.$row['surname']);
            $normalized = $this->normalizeName($displayName);
            if ($normalized === '') {
                $stats['errors']++;

                continue;
            }

            $memberships = $this->findPaidMembershipsByName($normalized);
            if ($memberships->isEmpty()) {
                $stats['skipped_no_match']++;

                continue;
            }

            if ($memberships->count() > 1) {
                $stats['skipped_ambiguous']++;
                if (! isset($this->reportedAmbiguous[$normalized])) {
                    $this->reportedAmbiguous[$normalized] = true;
                    $this->command?->warn("Ambiguous name '{$displayName}' — {$memberships->count()} paid memberships");
                }

                continue;
            }

            /** @var Membership $membership */
            $membership = $memberships->first();
            $changes = $this->plannedChanges($membership, $start, $expiry);

            if ($changes === []) {
                $stats['unchanged']++;

                continue;
            }

            if (! $dryRun) {
                $membership->fill([
                    'start_date' => $start,
                    'expiry_date' => $expiry,
                    'status' => 'active',
                    'payment_status' => 'paid',
                ])->save();
            }

            $stats['updated']++;
            if ($this->command?->getOutput()->isVerbose()) {
                $this->command?->line("  {$displayName}: ".implode(', ', $changes));
            }
        }

        $this->command?->newLine();
        $this->command?->info('--- Summary ---');
        foreach ($stats as $key => $value) {
            $this->command?->line(sprintf('%-18s %d', str_replace('_', ' ', $key).':', $value));
        }

        if ($dryRun) {
            $this->command?->newLine();
            $this->command?->comment('DRY RUN — no changes were written.');
        }
    }

    /**
     * @return array<int, array{first_name: string, surname: string, start_date: string, expiry_date: string}>
     */
    private function readRows(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open {$path}");
        }

        $bom = pack('H*', 'EFBBBF');
        if (fread($handle, 3) !== $bom) {
            rewind($handle);
        }

        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);

            return [];
        }

        $headers = array_map(fn ($h) => strtolower(trim((string) $h)), $headers);
        $rows = [];
        $lineNo = 1;

        while (($line = fgetcsv($handle)) !== false) {
            $lineNo++;
            if (count($line) === 1 && trim((string) $line[0]) === '') {
                continue;
            }

            $data = array_combine($headers, array_pad($line, count($headers), ''));
            if ($data === false) {
                continue;
            }

            $rows[$lineNo] = [
                'first_name' => trim((string) ($data['first_name'] ?? '')),
                'surname' => trim((string) ($data['surname'] ?? '')),
                'start_date' => trim((string) ($data['start_date'] ?? '')),
                'expiry_date' => trim((string) ($data['expiry_date'] ?? '')),
            ];
        }

        fclose($handle);

        return $rows;
    }

    private function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? ''));
        $name = preg_replace('/[^a-z0-9 ]/', '', $name) ?? '';

        return trim($name);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Membership>
     */
    private function findPaidMembershipsByName(string $normalizedName): \Illuminate\Support\Collection
    {
        return Membership::query()
            ->where('membership_type', 'paid')
            ->with('user:id,name')
            ->get()
            ->filter(function (Membership $membership) use ($normalizedName): bool {
                $userName = $membership->user?->name;
                if (! $userName) {
                    return false;
                }

                return $this->normalizeName($userName) === $normalizedName;
            })
            ->values();
    }

    /**
     * @return list<string>
     */
    private function plannedChanges(Membership $membership, Carbon $start, Carbon $expiry): array
    {
        $changes = [];

        if ($membership->start_date?->toDateString() !== $start->toDateString()) {
            $changes[] = 'start_date';
        }
        if ($membership->expiry_date?->toDateString() !== $expiry->toDateString()) {
            $changes[] = 'expiry_date';
        }
        if ($membership->status !== 'active') {
            $changes[] = 'status';
        }
        if ($membership->payment_status !== 'paid') {
            $changes[] = 'payment_status';
        }

        return $changes;
    }

    private function parseDate(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($value))->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
