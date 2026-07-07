<?php

namespace App\Console\Commands;

use App\Models\Club;
use App\Models\Division;
use App\Models\Membership;
use App\Models\Province;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Import real member data from an exco/committee CSV, merging into any
 * existing stub Users created by pr22:import-scraped (or similar).
 *
 * Match order (first hit wins):
 *   1. SAPRF number  (via memberships.saprf_number)
 *   2. Real email    (users.email exact match)
 *   3. Placeholder email derived from the row's name (first.last@import.saprf.local)
 *   4. Normalized name (LOWER(users.name) = LOWER(row.name), whitespace collapsed)
 *
 * Safety rails:
 *   - Never touches the password column
 *   - Never overwrites email_verified_at once set
 *   - Never removes roles (only adds)
 *   - Never overwrites the email of a @saprf.co.za staff user
 *   - Any blank/empty CSV cell leaves the existing DB value alone
 */
class ImportMembersCommand extends Command
{
    protected $signature = 'users:import-members
        {file? : Path to the CSV (relative to project root, or absolute)}
        {--dry-run : Parse everything and report intended changes without writing}
        {--strict : Fail the whole run if any row has validation errors (default: skip bad rows)}
        {--no-create : Skip rows that do not match any existing user (default: create new users for them)}
        {--role= : Assign this role to every imported user in addition to any per-row roles}
        {--template : Print a canonical CSV template to stdout, then exit}';

    protected $description = 'Import/merge real member records from an exco CSV into the users + memberships tables';

    private const CANONICAL_COLUMNS = [
        'name', 'email', 'phone', 'sa_id_number', 'date_of_birth',
        'province', 'saprf_number', 'membership_type', 'status',
        'payment_status', 'start_date', 'expiry_date', 'division',
        'club', 'role', 'is_active',
    ];

    private const HEADER_ALIASES = [
        'full_name' => 'name', 'shooter_name' => 'name', 'member_name' => 'name',
        'firstname' => 'first', 'first_name' => 'first', 'given_name' => 'first',
        'lastname' => 'last', 'last_name' => 'last', 'surname' => 'last',
        'e_mail' => 'email', 'email_address' => 'email',
        'mobile' => 'phone', 'contact' => 'phone', 'cell' => 'phone', 'cellphone' => 'phone', 'tel' => 'phone',
        'id_number' => 'sa_id_number', 'id' => 'sa_id_number', 'national_id' => 'sa_id_number', 'rsa_id' => 'sa_id_number',
        'dob' => 'date_of_birth', 'birth_date' => 'date_of_birth', 'birthdate' => 'date_of_birth', 'birthday' => 'date_of_birth',
        'province_name' => 'province', 'province_abbr' => 'province', 'province_code' => 'province',
        'member_number' => 'saprf_number', 'membership_number' => 'saprf_number', 'saprf' => 'saprf_number', 'saprf_no' => 'saprf_number', 'saprfnr' => 'saprf_number',
        'type' => 'membership_type', 'membership' => 'membership_type',
        'membership_status' => 'status',
        'payment' => 'payment_status',
        'join_date' => 'start_date', 'joined_at' => 'start_date',
        'renewal_date' => 'expiry_date', 'renew_by' => 'expiry_date', 'expires' => 'expiry_date', 'expires_at' => 'expiry_date',
        'default_division' => 'division',
        'shooting_club' => 'club', 'primary_shooting_club' => 'club', 'club_name' => 'club', 'home_club' => 'club',
        'roles' => 'role',
        'active' => 'is_active',
    ];

    public function handle(): int
    {
        if ($this->option('template')) {
            $this->printTemplate();
            return self::SUCCESS;
        }

        $file = (string) $this->argument('file');
        if ($file === '') {
            $this->error('Missing CSV file argument. Use --template to print the canonical CSV format.');
            return self::FAILURE;
        }

        $path = str_starts_with($file, '/') || preg_match('/^[A-Za-z]:/', $file) ? $file : base_path($file);
        if (!is_file($path)) {
            $this->error("CSV not found: {$path}");
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $strict = (bool) $this->option('strict');
        $createMissing = !$this->option('no-create');
        $bulkRole = $this->option('role');

        $this->info('=== users:import-members ===');
        $this->line("File:        {$path}");
        $this->line('Dry run:     '.($dryRun ? 'YES' : 'no'));
        $this->line('Strict:      '.($strict ? 'yes (rejects on any bad row)' : 'no (skips bad rows)'));
        $this->line('Create new:  '.($createMissing ? 'yes' : 'no (only merge into existing)'));
        if ($bulkRole) $this->line("Bulk role:   {$bulkRole}");
        $this->newLine();

        $parsed = $this->readCsv($path);
        $this->line('Parsed '.count($parsed['rows']).' data rows. Headers: '.implode(', ', $parsed['headers']));
        $this->newLine();

        $provinces = Province::all();
        $divisions = Division::all();

        $report = [
            'created' => 0, 'merged' => 0, 'unchanged' => 0,
            'skipped_no_match' => 0, 'skipped_invalid' => 0,
            'errors' => [],
        ];

        $tx = $dryRun ? null : DB::beginTransaction();
        try {
            foreach ($parsed['rows'] as $i => $rawRow) {
                $lineNo = $i + 2;
                $row = $this->normalizeRow($rawRow);

                $err = $this->validateRow($row);
                if ($err) {
                    $report['skipped_invalid']++;
                    $report['errors'][] = "Line {$lineNo}: {$err}";
                    if ($strict) throw new \RuntimeException("Strict mode: line {$lineNo} rejected — {$err}");
                    continue;
                }

                $user = $this->findExistingUser($row);

                if (!$user && !$createMissing) {
                    $report['skipped_no_match']++;
                    $this->line("  Line {$lineNo}: no match for '{$row['name']}' (skipped, --no-create)");
                    continue;
                }

                $result = $dryRun
                    ? $this->planUserChanges($user, $row, $provinces, $divisions, $bulkRole)
                    : $this->applyUserChanges($user, $row, $provinces, $divisions, $bulkRole);

                $report[$result['status']]++;
                if ($this->getOutput()->isVerbose() || $dryRun) {
                    $this->line("  Line {$lineNo}: {$result['status']} — {$row['name']}: ".implode(', ', $result['changes'] ?: ['no changes']));
                }
            }

            if (!$dryRun) DB::commit();
        } catch (\Throwable $e) {
            if (!$dryRun) DB::rollBack();
            $this->error('Import aborted: '.$e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('--- Summary ---');
        $this->line("Created:            {$report['created']}");
        $this->line("Merged into stubs:  {$report['merged']}");
        $this->line("Unchanged:          {$report['unchanged']}");
        $this->line("Skipped (no match): {$report['skipped_no_match']}");
        $this->line("Skipped (invalid):  {$report['skipped_invalid']}");

        if ($report['errors']) {
            $this->newLine();
            $this->warn('Errors:');
            foreach ($report['errors'] as $e) $this->line('  '.$e);
        }

        if ($dryRun) {
            $this->newLine();
            $this->comment('DRY RUN — no changes were written.');
        }
        return self::SUCCESS;
    }

    private function findExistingUser(array $row): ?User
    {
        $rowSaprf = trim((string) ($row['saprf_number'] ?? ''));

        if ($rowSaprf !== '') {
            $viaSaprf = Membership::where('saprf_number', $rowSaprf)->first();
            if ($viaSaprf) return $viaSaprf->user;
        }

        // Weaker fallbacks (email / placeholder / name). These must not hijack a
        // user who already belongs to a *different real* SAPRF number — that would
        // silently merge two distinct people who happen to share a name or email.
        // Stubs (SAPRF-IMPORT-… numbers) stay mergeable so legacy member data can
        // still enrich the pr22/prs stub accounts.
        $candidate = null;

        if (!empty($row['email'])) {
            $candidate = User::where('email', $row['email'])->first();
        }
        if (!$candidate) {
            $placeholder = $this->placeholderEmailFor($row['name']);
            $candidate = User::where('email', $placeholder)->first();
        }
        if (!$candidate) {
            $candidate = User::whereRaw('LOWER(name) = ?', [strtolower($row['name'])])->first();
        }

        if ($candidate && $rowSaprf !== '' && $this->belongsToDifferentRealMember($candidate, $rowSaprf)) {
            return null;
        }

        return $candidate;
    }

    /**
     * True when a fallback (name/email) match would hijack a genuine, already
     * populated account that carries a *different real* SAPRF number. Stub
     * accounts (placeholder @import.saprf.local email, or a SAPRF-IMPORT-…
     * membership number) stay mergeable so scraper stubs can still be enriched.
     */
    private function belongsToDifferentRealMember(User $user, string $rowSaprf): bool
    {
        if ($this->isMergeableStub($user)) {
            return false;
        }
        $existing = trim((string) ($user->membership?->saprf_number ?? ''));
        return $existing !== '' && $existing !== $rowSaprf;
    }

    private function isMergeableStub(User $user): bool
    {
        if (str_ends_with(strtolower((string) $user->email), '@import.saprf.local')) {
            return true;
        }
        $saprf = trim((string) ($user->membership?->saprf_number ?? ''));
        return $saprf === '' || str_starts_with($saprf, 'SAPRF-IMPORT-');
    }

    private function applyUserChanges(?User $existing, array $row, $provinces, $divisions, ?string $bulkRole): array
    {
        $changes = [];
        $status = $existing ? 'merged' : 'created';

        $provinceId = $this->resolveProvinceId($row['province'] ?? null, $provinces);
        $divisionId = $this->resolveDivisionId($row['division'] ?? null, $divisions);
        $clubId = $this->resolveClubId($row['club'] ?? null);

        if (!$existing) {
            $email = $row['email'] ?: $this->placeholderEmailFor($row['name']);
            while (User::where('email', $email)->exists()) {
                $email = preg_replace('/(@)/', rand(100, 999).'$1', $email, 1);
            }
            $existing = User::create([
                'name' => $row['name'],
                'email' => $email,
                'password' => Hash::make(Str::random(40)),
                'phone' => $row['phone'] ?: null,
                'sa_id_number' => $row['sa_id_number'] ?: null,
                'date_of_birth' => $this->parseDate($row['date_of_birth'] ?? null),
                'province_id' => $provinceId,
                'division_id' => $divisionId,
                'club_id' => $clubId,
                'is_active' => $this->parseBool($row['is_active'] ?? '1', true),
                'email_verified_at' => null,
                'must_change_password' => true,
            ]);
            $changes[] = 'new user';
        } else {
            $userUpdates = [];

            if (!Str::endsWith($existing->email, '@saprf.co.za')
                && !empty($row['email'])
                && $existing->email !== $row['email']
                && (Str::endsWith($existing->email, '@import.saprf.local') || $this->option('dry-run'))) {
                $userUpdates['email'] = $row['email'];
                if (!$existing->email_verified_at) {
                    $userUpdates['email_verified_at'] = null;
                    $userUpdates['must_change_password'] = true;
                }
            } elseif (!Str::endsWith($existing->email, '@saprf.co.za')
                && !empty($row['email'])
                && $existing->email !== $row['email']
                && Str::endsWith($existing->email, '@import.saprf.local')) {
                $userUpdates['email'] = $row['email'];
            }

            foreach ([
                'phone' => $row['phone'] ?? '',
                'sa_id_number' => $row['sa_id_number'] ?? '',
            ] as $k => $v) {
                if ($v !== '' && $existing->{$k} !== $v) $userUpdates[$k] = $v;
            }

            $dob = $this->parseDate($row['date_of_birth'] ?? null);
            if ($dob && (!$existing->date_of_birth || !$existing->date_of_birth->isSameDay($dob))) {
                $userUpdates['date_of_birth'] = $dob;
            }

            if ($provinceId && $existing->province_id !== $provinceId) {
                $userUpdates['province_id'] = $provinceId;
            }
            if ($divisionId && $existing->division_id !== $divisionId) {
                $userUpdates['division_id'] = $divisionId;
            }
            if ($clubId && $existing->club_id !== $clubId) {
                $userUpdates['club_id'] = $clubId;
            }

            if (!empty($row['is_active']) || $row['is_active'] === '0') {
                $active = $this->parseBool($row['is_active'], true);
                if ($existing->is_active !== $active) $userUpdates['is_active'] = $active;
            }

            if (!empty($userUpdates)) {
                $existing->fill($userUpdates)->save();
                $changes[] = 'user('.implode(',', array_keys($userUpdates)).')';
            }
        }

        $membershipChanges = $this->upsertMembership($existing, $row);
        if ($membershipChanges) $changes[] = 'membership('.implode(',', $membershipChanges).')';

        $rolesToAdd = array_filter(array_merge(
            array_map('trim', explode(',', (string) ($row['role'] ?? ''))),
            $bulkRole ? [$bulkRole] : [],
        ));
        if (!in_array('member', $existing->getRoleNames()->toArray(), true)) {
            $rolesToAdd[] = 'member';
        }
        foreach (array_unique(array_filter($rolesToAdd)) as $role) {
            if (!$existing->hasRole($role)) {
                try {
                    $existing->assignRole($role);
                    $changes[] = "role+{$role}";
                } catch (\Throwable $e) {
                    $changes[] = "role-fail({$role})";
                }
            }
        }

        if (empty($changes)) $status = 'unchanged';
        return ['status' => $status, 'changes' => $changes];
    }

    private function planUserChanges(?User $existing, array $row, $provinces, $divisions, ?string $bulkRole): array
    {
        $changes = [];
        $status = $existing ? 'merged' : 'created';

        if (!$existing) {
            $changes[] = "would create with email='{$row['email']}'";
            return ['status' => 'created', 'changes' => $changes];
        }

        if (Str::endsWith($existing->email, '@import.saprf.local') && !empty($row['email'])) {
            $changes[] = "email {$existing->email} -> {$row['email']}";
        }
        foreach (['phone', 'sa_id_number'] as $k) {
            if (!empty($row[$k]) && $existing->{$k} !== $row[$k]) {
                $changes[] = "{$k} '{$existing->{$k}}' -> '{$row[$k]}'";
            }
        }

        $dob = $this->parseDate($row['date_of_birth'] ?? null);
        if ($dob && (!$existing->date_of_birth || !$existing->date_of_birth->isSameDay($dob))) {
            $changes[] = 'date_of_birth -> '.$dob->toDateString();
        }

        $provinceId = $this->resolveProvinceId($row['province'] ?? null, $provinces);
        if ($provinceId && $existing->province_id !== $provinceId) {
            $changes[] = "province -> {$provinces->firstWhere('id', $provinceId)?->name}";
        }

        $clubName = trim((string) ($row['club'] ?? ''));
        if ($clubName !== '') {
            $existingClub = Club::whereRaw('LOWER(name) = ?', [strtolower($clubName)])->first();
            if (!$existingClub) {
                $changes[] = "club -> {$clubName} (new)";
            } elseif ($existing->club_id !== $existingClub->id) {
                $changes[] = "club -> {$existingClub->name}";
            }
        }

        if (!empty($row['saprf_number']) && (!$existing->membership || $existing->membership->saprf_number !== $row['saprf_number'])) {
            $changes[] = "saprf# -> {$row['saprf_number']}";
        }
        if (!empty($row['expiry_date'])) {
            $changes[] = "expiry -> {$row['expiry_date']}";
        }

        if (empty($changes)) $status = 'unchanged';
        return ['status' => $status, 'changes' => $changes];
    }

    private function upsertMembership(User $user, array $row): array
    {
        $mship = $user->membership;
        $changes = [];

        $attrs = [];
        if (!empty($row['saprf_number'])) $attrs['saprf_number'] = $row['saprf_number'];
        if (!empty($row['membership_type'])) $attrs['membership_type'] = strtolower($row['membership_type']);
        if (!empty($row['status'])) $attrs['status'] = strtolower($row['status']);
        if (!empty($row['payment_status'])) $attrs['payment_status'] = strtolower($row['payment_status']);

        $start = $this->parseDate($row['start_date'] ?? null);
        $expiry = $this->parseDate($row['expiry_date'] ?? null);
        if ($start) $attrs['start_date'] = $start;
        if ($expiry) $attrs['expiry_date'] = $expiry;

        if (!$mship) {
            if (empty($attrs['saprf_number'])) {
                $attrs['saprf_number'] = 'SAPRF-IMPORT-'.strtoupper(Str::random(6));
                while (Membership::where('saprf_number', $attrs['saprf_number'])->exists()) {
                    $attrs['saprf_number'] = 'SAPRF-IMPORT-'.strtoupper(Str::random(6));
                }
            }
            $attrs['membership_type'] ??= 'paid';
            $attrs['status'] ??= 'active';
            $attrs['payment_status'] ??= 'paid';

            $mship = new Membership(array_merge($attrs, ['user_id' => $user->id]));
            $mship->save();
            $changes[] = 'created';
            return $changes;
        }

        foreach ($attrs as $k => $v) {
            $current = in_array($k, ['start_date', 'expiry_date'], true)
                ? ($mship->{$k}?->toDateString())
                : (string) $mship->{$k};
            $incoming = in_array($k, ['start_date', 'expiry_date'], true)
                ? $v->toDateString()
                : (string) $v;
            if ($current !== $incoming) {
                $mship->{$k} = $v;
                $changes[] = $k;
            }
        }
        if ($changes) $mship->save();
        return $changes;
    }

    private function normalizeRow(array $row): array
    {
        if (empty($row['name'])) {
            $first = trim((string) ($row['first'] ?? ''));
            $last = trim((string) ($row['last'] ?? ''));
            if ($first !== '' || $last !== '') {
                $row['name'] = trim($first.' '.$last);
            }
        }

        foreach ($row as $k => $v) {
            if (is_string($v)) $row[$k] = trim(preg_replace('/\s+/', ' ', $v));
        }
        $row['name'] = $row['name'] ?? '';
        foreach (self::CANONICAL_COLUMNS as $c) $row[$c] ??= '';

        return $row;
    }

    private function validateRow(array $row): ?string
    {
        if ($row['name'] === '') return 'missing name';

        if ($row['email'] !== '' && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            return "invalid email '{$row['email']}'";
        }
        foreach (['date_of_birth', 'start_date', 'expiry_date'] as $k) {
            if ($row[$k] !== '' && $this->parseDate($row[$k]) === null) {
                return "unparseable {$k}='{$row[$k]}' (expected YYYY-MM-DD, DD/MM/YYYY or DD-MM-YYYY)";
            }
        }
        return null;
    }

    private function resolveProvinceId(?string $value, $provinces): ?int
    {
        if (!$value) return null;
        $v = strtolower($value);
        return $provinces->firstWhere(fn ($p) => strtolower($p->name) === $v || strtolower($p->abbreviation) === $v)?->id;
    }

    private function resolveDivisionId(?string $value, $divisions): ?int
    {
        if (!$value) return null;
        $v = strtolower($value);
        return $divisions->firstWhere(fn ($d) => strtolower($d->slug) === $v || strtolower($d->name) === $v)?->id;
    }

    private function resolveClubId(?string $name): ?int
    {
        return Club::findOrCreateByName($name)?->id;
    }

    private function parseDate(?string $s): ?Carbon
    {
        if (!$s || trim($s) === '') return null;
        $s = trim($s);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'd M Y', 'j M Y', 'd F Y', 'j F Y'] as $fmt) {
            try {
                $d = Carbon::createFromFormat($fmt, $s);
                if ($d && $d->format($fmt) === $s) return $d->startOfDay();
            } catch (\Throwable) {
            }
        }
        try {
            return Carbon::parse($s)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseBool(?string $s, bool $default = false): bool
    {
        if ($s === null || $s === '') return $default;
        return in_array(strtolower(trim($s)), ['1', 'true', 'yes', 'y', 'active', 'on'], true);
    }

    private function placeholderEmailFor(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        $first = Str::slug($parts[0] ?? 'shooter') ?: 'shooter';
        $last = count($parts) > 1 ? Str::slug(end($parts)) : '';
        return $first.($last ? '.'.$last : '').'@import.saprf.local';
    }

    /**
     * @return array{headers: array<int,string>, rows: array<int, array<string, string>>}
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if (!$handle) throw new \RuntimeException("Cannot open {$path}");

        $bom = pack('H*', 'EFBBBF');
        if (fread($handle, 3) !== $bom) rewind($handle);

        $rawHeaders = fgetcsv($handle) ?: [];
        $headers = array_map(fn ($h) => $this->normalizeHeader((string) $h), $rawHeaders);
        $count = count($headers);

        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if (count($line) === 1 && trim((string) $line[0]) === '') continue;
            if (count($line) < $count) $line = array_pad($line, $count, '');
            if (count($line) > $count) $line = array_slice($line, 0, $count);
            $rows[] = array_combine($headers, array_map(fn ($v) => (string) $v, $line));
        }
        fclose($handle);

        return ['headers' => $headers, 'rows' => $rows];
    }

    private function normalizeHeader(string $raw): string
    {
        $h = strtolower(trim($raw));
        $h = preg_replace('/[^a-z0-9]+/', '_', $h);
        $h = trim($h, '_');
        return self::HEADER_ALIASES[$h] ?? $h;
    }

    private function printTemplate(): void
    {
        $rows = [
            self::CANONICAL_COLUMNS,
            [
                'Jane Doe', 'jane@example.com', '+27 82 555 1234', '8501015001080',
                '1985-01-01', 'Gauteng', 'SAPRF-2026-00042', 'paid', 'active', 'paid',
                '2026-01-01', '2026-12-31', 'Ladies', 'Pretoria Precision Rifle Club (PPRC)', 'member', '1',
            ],
            [
                'John Smith', 'john@example.com', '', '', '30/06/1978', 'WC', '',
                'paid', 'active', 'paid', '', '2026-12-31', 'Open',
                'Krokodilspruit Skietklub', 'match_director,member', '1',
            ],
        ];
        foreach ($rows as $r) $this->line($this->csvLine($r));
    }

    private function csvLine(array $fields): string
    {
        $fp = fopen('php://memory', 'r+');
        fputcsv($fp, $fields);
        rewind($fp);
        $line = stream_get_contents($fp);
        fclose($fp);
        return rtrim($line, "\r\n");
    }
}
