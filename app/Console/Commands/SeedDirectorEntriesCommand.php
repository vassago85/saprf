<?php

namespace App\Console\Commands;

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\GuestShooterService;
use App\Services\RegistrationPricingService;
use Illuminate\Console\Command;

/**
 * Book a squad onto one match the same way Match settings → Add a shooter
 * does: confirmed, marked paid, and priced as money the match director
 * collected. On a club or provincial match a non-member becomes a club
 * entry (no surcharge, and SAPRF / platform / gateway stay at zero).
 *
 * Someone already entered on the match is skipped, including a junior
 * whose list name only adds "Jnr". An email that belongs to a different
 * person is not reused.
 */
class SeedDirectorEntriesCommand extends Command
{
    protected $signature = 'registrations:seed-director-entries
                            {match : Match id}
                            {file : CSV with name,email columns}
                            {--apply : Persist the entries (otherwise dry-run only)}';

    protected $description = 'Add a squad list to one match as director-collected, confirmed entries';

    public function handle(
        GuestShooterService $guests,
        RegistrationPricingService $pricing,
        AuditLogService $audit,
    ): int {
        $match = MatchEvent::query()->find((int) $this->argument('match'));
        if ($match === null) {
            $this->error('Match not found.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("CSV not found: {$path}");

            return self::FAILURE;
        }

        $roster = $this->readRoster($path);
        if ($roster === null) {
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $reason = 'Seeded from the club squad list. The match director collected the fee off the platform.';

        $this->info('=== registrations:seed-director-entries ===');
        $this->line(sprintf('%-12s %s', 'mode', $apply ? 'APPLY' : 'DRY RUN'));
        $this->line(sprintf('%-12s %s (%s)', 'match', $match->id, $match->name));

        $existing = $match->registrations()
            ->with('user:id,name')
            ->where('registration_status', '!=', 'cancelled')
            ->get();

        $rows = [];
        $created = 0;
        $skipped = 0;

        foreach ($roster as $person) {
            $email = $person['email'];
            $note = '';
            $resolved = $guests->findExisting($person['name'], $email);

            if ($resolved !== null && $email !== null && $this->nameKey($resolved->name) !== $this->nameKey($person['name'])) {
                $note = 'email belongs to '.$resolved->name.'; not used';
                $email = null;
                $resolved = $guests->findExisting($person['name'], null);
            }

            $already = $this->alreadyEntered($existing, $resolved, $person['name']);
            if ($already !== null) {
                $skipped++;
                $rows[] = $this->row($person['name'], 'skipped', $already->membership_fee_category, $already, 'already entered #'.$already->id.($note !== '' ? '; '.$note : ''));

                continue;
            }

            if ($resolved !== null && $resolved->is_managed_account) {
                $skipped++;
                $rows[] = $this->row($person['name'], 'skipped', '—', null, 'managed family account; enter via the parent');

                continue;
            }

            if ($resolved !== null && ! $resolved->is_active) {
                $skipped++;
                $rows[] = $this->row($person['name'], 'skipped', '—', null, 'inactive account');

                continue;
            }

            $breakdown = $pricing->calculateBreakdown(
                $match,
                $resolved,
                $match->match_date ?: now(),
                null,
                null,
                null,
                RegistrationPricingService::COLLECTED_BY_DIRECTOR,
            );
            $gatewayFee = 0.0;
            $mdNet = round(
                (float) $breakdown['total_fee']
                - (float) $breakdown['saprf_fee']
                - (float) $breakdown['platform_fee']
                - (float) $breakdown['surcharge']
                - $gatewayFee,
                2,
            );

            $rows[] = [
                $person['name'],
                $apply ? 'added' : 'would add',
                $breakdown['category'],
                number_format((float) $breakdown['total_fee'], 2),
                number_format((float) $breakdown['surcharge'], 2),
                number_format((float) $breakdown['saprf_fee'], 2),
                number_format((float) $breakdown['platform_fee'], 2),
                number_format($gatewayFee, 2),
                number_format($mdNet, 2),
                $note !== '' ? $note : ($resolved ? 'existing account' : 'new account'),
            ];

            if (! $apply) {
                $created++;

                continue;
            }

            $shooter = $guests->findOrCreate($person['name'], $email);
            $registration = MatchRegistration::query()->create([
                'match_id' => $match->id,
                'user_id' => $shooter->id,
                'registered_by_user_id' => $match->created_by,
                'shooter_name' => $shooter->name,
                'email' => $shooter->email,
                'phone' => $shooter->phone,
                'membership_fee_category' => $breakdown['category'],
                'fee_amount' => $breakdown['total_fee'],
                'surcharge_amount' => $breakdown['surcharge'],
                'saprf_fee' => $breakdown['saprf_fee'],
                'platform_fee' => $breakdown['platform_fee'],
                'gateway_fee' => $gatewayFee,
                'md_net_amount' => $mdNet,
                'fee_override_reason' => $reason,
                'payment_status' => 'paid',
                'registration_status' => 'confirmed',
                'registered_at' => now(),
            ]);

            $audit->log(
                null,
                'registration.admin_added',
                'MatchRegistration',
                $registration->id,
                null,
                array_merge($registration->toArray(), [
                    'seeded_via' => 'registrations:seed-director-entries',
                ]),
                $reason,
            );
            $created++;
        }

        $this->newLine();
        $this->table(
            ['Shooter', 'Action', 'Category', 'Fee', 'Surcharge', 'SAPRF', 'Platform', 'Gateway', 'MD net', 'Note'],
            $rows,
        );

        $this->newLine();
        $this->line(($apply ? 'Added' : 'Would add').": {$created}");
        $this->line("Left unchanged: {$skipped}");

        if (! $apply && $created > 0) {
            $this->newLine();
            $this->warn('Dry run — re-run with --apply to persist.');
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array{name: string, email: ?string}>|null
     */
    private function readRoster(string $path): ?array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->error("Could not read {$path}");

            return null;
        }

        $header = fgetcsv($handle);
        if ($header === false || array_map(strtolower(...), $header) !== ['name', 'email']) {
            fclose($handle);
            $this->error('CSV header must be name,email');

            return null;
        }

        $roster = [];
        while (($line = fgetcsv($handle)) !== false) {
            $name = trim(preg_replace('/\s+/', ' ', (string) ($line[0] ?? '')));
            if ($name === '') {
                continue;
            }
            $email = trim((string) ($line[1] ?? ''));
            $roster[] = [
                'name' => $name,
                'email' => $email !== '' ? strtolower($email) : null,
            ];
        }
        fclose($handle);

        if ($roster === []) {
            $this->error('CSV has no shooters.');

            return null;
        }

        return $roster;
    }

    private function alreadyEntered($existing, ?User $user, string $rosterName): ?MatchRegistration
    {
        if ($user !== null) {
            $byUser = $existing->firstWhere('user_id', $user->id);
            if ($byUser !== null) {
                return $byUser;
            }
        }

        $wanted = $this->nameKey($rosterName);

        return $existing->first(function (MatchRegistration $registration) use ($wanted) {
            $names = array_filter([
                $registration->shooter_name,
                $registration->user?->name,
            ]);

            foreach ($names as $name) {
                if ($this->nameKey($name) === $wanted) {
                    return true;
                }
            }

            return false;
        });
    }

    private function nameKey(string $name): string
    {
        $name = strtolower(trim(preg_replace('/\s+/', ' ', $name)));
        $name = preg_replace('/\b(jnr|jr|junior|snr|sr)\b\.?/', '', $name) ?? $name;

        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }

    /**
     * @return list<string>
     */
    private function row(string $name, string $action, string $category, ?MatchRegistration $registration, string $note): array
    {
        return [
            $name,
            $action,
            $category,
            $registration ? number_format((float) $registration->fee_amount, 2) : '—',
            $registration ? number_format((float) $registration->surcharge_amount, 2) : '—',
            $registration ? number_format((float) $registration->saprf_fee, 2) : '—',
            $registration ? number_format((float) $registration->platform_fee, 2) : '—',
            $registration ? number_format((float) $registration->gateway_fee, 2) : '—',
            $registration ? number_format((float) $registration->md_net_amount, 2) : '—',
            $note,
        ];
    }
}
