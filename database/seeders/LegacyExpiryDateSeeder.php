<?php

namespace Database\Seeders;

use App\Models\Membership;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Backfill membership start/expiry dates from the legacy precisionrifle.co.za
 * database for paid members who already exist on this platform.
 *
 * Match order (first unambiguous hit wins):
 *   1. SA ID number (13 digits)
 *   2. Real email address
 *   3. Normalized full name
 *   4. First name + surname tokens
 *
 * Only updates existing paid memberships. Does not create users or memberships.
 *
 * Run manually:
 *   LEGACY_EXPIRY_DRY_RUN=1 php artisan db:seed --class=LegacyExpiryDateSeeder --force
 *   php artisan db:seed --class=LegacyExpiryDateSeeder --force
 */
class LegacyExpiryDateSeeder extends Seeder
{
    private const DATA_FILE = 'database/data/legacy-full-member-expiries.csv';

    /** @var array<string, true> */
    private array $reportedAmbiguous = [];

    /** @var array<string, Collection<int, Membership>> */
    private array $index = [];

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
            'errors' => 0,
            'matched_by_id' => 0,
            'matched_by_email' => 0,
            'matched_by_name' => 0,
            'matched_by_name_parts' => 0,
        ];

        $this->buildPaidMembershipIndex();

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
            $match = $this->resolveMembership($row, $displayName);

            if ($match === null) {
                $stats['skipped_no_match']++;

                continue;
            }

            if ($match['ambiguous']) {
                $stats['skipped_ambiguous']++;
                $key = $match['method'].':'.$displayName;
                if (! isset($this->reportedAmbiguous[$key])) {
                    $this->reportedAmbiguous[$key] = true;
                    $this->command?->warn("Ambiguous {$match['method']} match for '{$displayName}'");
                }

                continue;
            }

            /** @var Membership $membership */
            $membership = $match['membership'];
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
            $stats['matched_by_'.$match['method']]++;
            if ($this->command?->getOutput()->isVerbose()) {
                $this->command?->line("  [{$match['method']}] {$displayName}: ".implode(', ', $changes));
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

    private function buildPaidMembershipIndex(): void
    {
        $this->index = [
            'id' => [],
            'email' => [],
            'name' => [],
            'name_parts' => [],
        ];

        $memberships = Membership::query()
            ->where('membership_type', 'paid')
            ->with('user:id,name,email,sa_id_number')
            ->get();

        foreach ($memberships as $membership) {
            $user = $membership->user;
            if (! $user) {
                continue;
            }

            $id = $this->realIdNumber($user->sa_id_number);
            if ($id) {
                $this->index['id'][$id] ??= collect();
                $this->index['id'][$id]->push($membership);
            }

            $email = $this->realEmail($user->email);
            if ($email) {
                $this->index['email'][$email] ??= collect();
                $this->index['email'][$email]->push($membership);
            }

            $name = $this->normalizeName($user->name);
            if ($name !== '') {
                $this->index['name'][$name] ??= collect();
                $this->index['name'][$name]->push($membership);
            }

            $parts = $this->namePartsKey($user->name);
            if ($parts !== '') {
                $this->index['name_parts'][$parts] ??= collect();
                $this->index['name_parts'][$parts]->push($membership);
            }
        }
    }

    /**
     * @param  array{first_name: string, surname: string, email: string, sa_id_number: string, start_date: string, expiry_date: string}  $row
     * @return array{membership: Membership, method: string, ambiguous: bool}|null
     */
    private function resolveMembership(array $row, string $displayName): ?array
    {
        $strategies = [];

        $id = $this->realIdNumber($row['sa_id_number'] ?? '');
        if ($id) {
            $strategies[] = ['method' => 'id', 'key' => $id, 'bucket' => 'id'];
        }

        $email = $this->realEmail($row['email'] ?? '');
        if ($email) {
            $strategies[] = ['method' => 'email', 'key' => $email, 'bucket' => 'email'];
        }

        $name = $this->normalizeName($displayName);
        if ($name !== '') {
            $strategies[] = ['method' => 'name', 'key' => $name, 'bucket' => 'name'];
        }

        $parts = $this->namePartsKey($displayName);
        if ($parts !== '') {
            $strategies[] = ['method' => 'name_parts', 'key' => $parts, 'bucket' => 'name_parts'];
        }

        foreach ($strategies as $strategy) {
            /** @var Collection<int, Membership>|null $hits */
            $hits = $this->index[$strategy['bucket']][$strategy['key']] ?? null;
            if ($hits === null || $hits->isEmpty()) {
                continue;
            }

            $unique = $hits->unique('id')->values();
            if ($unique->count() > 1) {
                return ['membership' => $unique->first(), 'method' => $strategy['method'], 'ambiguous' => true];
            }

            return ['membership' => $unique->first(), 'method' => $strategy['method'], 'ambiguous' => false];
        }

        return null;
    }

    /**
     * @return array<int, array{first_name: string, surname: string, email: string, sa_id_number: string, start_date: string, expiry_date: string}>
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
                'email' => trim((string) ($data['email'] ?? '')),
                'sa_id_number' => trim((string) ($data['sa_id_number'] ?? '')),
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

    private function namePartsKey(string $name): string
    {
        $normalized = $this->normalizeName($name);
        $tokens = array_values(array_filter(explode(' ', $normalized)));
        if (count($tokens) < 2) {
            return '';
        }

        return $tokens[0].' '.$tokens[count($tokens) - 1];
    }

    private function realEmail(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        $email = strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        if (preg_match('/@(example\.co\.za|import\.saprf\.local|saprf\.co\.za)$/', $email)) {
            return null;
        }

        return $email;
    }

    private function realIdNumber(?string $id): ?string
    {
        if ($id === null || trim($id) === '') {
            return null;
        }

        $id = trim($id);
        if (! preg_match('/^\d{13}$/', $id)) {
            return null;
        }

        return $id;
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
