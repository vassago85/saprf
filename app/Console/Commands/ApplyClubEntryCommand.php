<?php

namespace App\Console\Commands;

use App\Models\MatchRegistration;
use App\Services\AuditLogService;
use App\Services\RegistrationPricingService;
use Illuminate\Console\Command;

/**
 * Move unpaid non-member entries on existing club and provincial matches
 * onto the club-entry price. New registrations already land there; this
 * catches rows created before that bracket existed.
 *
 * Paid and waived rows are left alone — rewriting money that has already
 * been collected would desync the payout. Walk-ins stay on their own
 * zero-fee path. Safe to run more than once.
 */
class ApplyClubEntryCommand extends Command
{
    protected $signature = 'registrations:apply-club-entry
                            {--match= : Only this match id}
                            {--apply : Persist the change (otherwise dry-run only)}';

    protected $description = 'Reprice unpaid non-member entries on club and provincial matches onto the club entry fee';

    public function handle(
        RegistrationPricingService $pricing,
        AuditLogService $audit,
    ): int {
        $matchId = $this->option('match');
        $apply = (bool) $this->option('apply');
        $reason = 'Moved onto the club entry price for this club or provincial match.';

        $query = MatchRegistration::query()
            ->with(['match', 'user', 'division'])
            ->where('membership_fee_category', 'non_member')
            ->where('registration_status', '!=', 'cancelled')
            ->where(function ($q) {
                $q->whereNull('registration_source')
                    ->orWhere('registration_source', '!=', 'walk_in');
            })
            ->whereHas('match', function ($q) use ($matchId) {
                $q->whereIn('series_level', ['club', 'provincial']);
                if ($matchId !== null && $matchId !== '') {
                    $q->whereKey((int) $matchId);
                }
            })
            ->orderBy('match_id')
            ->orderBy('id');

        $registrations = $query->get();

        $this->info('=== registrations:apply-club-entry ===');
        $this->line(sprintf('%-12s %s', 'mode', $apply ? 'APPLY' : 'DRY RUN'));
        if ($matchId !== null && $matchId !== '') {
            $this->line(sprintf('%-12s %s', 'match', $matchId));
        }

        $rows = [];
        $updated = 0;
        $skippedPaid = 0;

        foreach ($registrations as $registration) {
            $match = $registration->match;
            if ($match === null || ! $match->supportsClubEntry()) {
                continue;
            }

            if (! $registration->canCorrectCategory()) {
                $skippedPaid++;
                $rows[] = [
                    $registration->id,
                    $match->name,
                    $registration->shooter_name ?: $registration->user?->name,
                    'skipped',
                    $registration->payment_status,
                    number_format((float) $registration->fee_amount, 2),
                    '—',
                ];

                continue;
            }

            $preview = $pricing->calculateBreakdown(
                $match,
                $registration->user,
                $match->match_date ?: now(),
                $registration->division?->slug,
                'club_entry',
                $registration->registered_at,
                RegistrationPricingService::COLLECTED_BY_PLATFORM,
            );

            $rows[] = [
                $registration->id,
                $match->name,
                $registration->shooter_name ?: $registration->user?->name,
                $apply ? 'updated' : 'would update',
                'club_entry',
                number_format((float) $registration->fee_amount, 2),
                number_format((float) $preview['total_fee'], 2),
            ];

            if (! $apply) {
                $updated++;

                continue;
            }

            $result = $pricing->applyCategory($registration, 'club_entry', $reason);
            $audit->log(
                null,
                'registration.category.updated',
                'MatchRegistration',
                $registration->id,
                $result['old'],
                $result['new'],
                $reason,
            );
            $updated++;
        }

        if ($rows === []) {
            $this->newLine();
            $this->info('No non-member entries on club or provincial matches.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['ID', 'Match', 'Shooter', 'Action', 'Status / category', 'Fee now', 'Club fee'],
            $rows,
        );

        $this->newLine();
        $this->line("Unpaid entries ".($apply ? 'updated' : 'that would change').": {$updated}");
        $this->line("Paid or waived entries left unchanged: {$skippedPaid}");

        if (! $apply && $updated > 0) {
            $this->newLine();
            $this->warn('Dry run — re-run with --apply to persist.');
        }

        return self::SUCCESS;
    }
}
