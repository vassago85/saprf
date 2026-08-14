<?php

namespace App\Console\Commands;

use App\Models\MatchRegistration;
use App\Services\AuditLogService;
use Illuminate\Console\Command;

/**
 * Retroactive one-off correction for withdrawals recorded under the old
 * refund policy — before calculateRefund() started respecting payment_status.
 *
 * Any registration that was cancelled while still unpaid used to have its
 * fee_amount put through the deadline math, so the audit trail shows a
 * "refund" and "admin fee" for money that was never collected. This command
 * zeroes those two fields on affected rows and drops a
 * `registration.refund.corrected` audit entry alongside the original
 * `registration.withdrawn` so the correction is discoverable.
 *
 * Defaults to dry-run. Pass --apply to persist changes.
 */
class FixUnpaidWithdrawalRefundsCommand extends Command
{
    protected $signature = 'registrations:fix-unpaid-refunds
                            {--apply : Persist the changes (otherwise dry-run only)}
                            {--id=* : Only touch these MatchRegistration IDs}';

    protected $description = 'Zero refund_amount/admin_fee_charged on withdrawn registrations that were never paid';

    public function handle(AuditLogService $auditLogService): int
    {
        $query = MatchRegistration::query()
            ->where('registration_status', 'cancelled')
            ->whereIn('payment_status', ['pending', 'unpaid'])
            ->where(function ($q) {
                $q->where('refund_amount', '>', 0)
                    ->orWhere('admin_fee_charged', '>', 0);
            });

        $ids = array_filter(array_map('intval', (array) $this->option('id')));

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        $affected = $query->orderBy('id')->get();

        if ($affected->isEmpty()) {
            $this->info('No unpaid withdrawals with lingering refund/admin-fee amounts. Nothing to do.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Shooter', 'Match', 'payment_status', 'refund_amount', 'admin_fee_charged'],
            $affected->map(fn (MatchRegistration $r) => [
                $r->id,
                $r->shooter_name,
                $r->match?->name ?? '—',
                $r->payment_status,
                number_format((float) $r->refund_amount, 2),
                number_format((float) $r->admin_fee_charged, 2),
            ])->all(),
        );

        $apply = (bool) $this->option('apply');

        if (! $apply) {
            $this->warn('Dry run — re-run with --apply to persist the correction.');

            return self::SUCCESS;
        }

        foreach ($affected as $registration) {
            $old = [
                'refund_amount' => (float) $registration->refund_amount,
                'admin_fee_charged' => (float) $registration->admin_fee_charged,
            ];

            $registration->update([
                'refund_amount' => 0,
                'admin_fee_charged' => 0,
            ]);

            $auditLogService->log(
                null,
                'registration.refund.corrected',
                'MatchRegistration',
                $registration->id,
                $old,
                [
                    'refund_amount' => 0,
                    'admin_fee_charged' => 0,
                    'reason' => 'unpaid_withdrawal_retroactive_correction',
                ],
            );
        }

        $this->info("Corrected {$affected->count()} registration(s).");

        return self::SUCCESS;
    }
}
