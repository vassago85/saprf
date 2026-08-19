<?php

namespace App\Console\Commands;

use App\Models\MatchRegistration;
use App\Services\AuditLogService;
use App\Services\RegistrationPricingService;
use Illuminate\Console\Command;

/**
 * Admin one-off: recategorise an existing unpaid registration and rebuild
 * the fee row (e.g. lapsed → active so the surcharge drops). Defaults to
 * dry-run — pass --apply to persist.
 */
class RepriceRegistrationCommand extends Command
{
    protected $signature = 'registrations:reprice
                            {id : MatchRegistration ID}
                            {--category= : Fee bracket (active_member|lapsed_member|non_member)}
                            {--reason= : Why the category is being overridden (stored as fee_override_reason)}
                            {--apply : Persist the change (otherwise dry-run only)}';

    protected $description = 'Recategorise an unpaid registration and recalculate its fee breakdown';

    public function handle(
        RegistrationPricingService $pricingService,
        AuditLogService $auditLogService,
    ): int {
        $id = (int) $this->argument('id');
        $category = $this->option('category') !== null ? (string) $this->option('category') : '';
        $reason = $this->option('reason') !== null ? trim((string) $this->option('reason')) : '';
        $apply = (bool) $this->option('apply');

        if ($id <= 0) {
            $this->error('A valid registration ID is required.');

            return self::FAILURE;
        }

        if (! in_array($category, RegistrationPricingService::CATEGORIES, true)) {
            $this->error(
                '--category must be one of '.implode('|', RegistrationPricingService::CATEGORIES)
                .' (got: '.($category !== '' ? $category : 'empty').').'
            );

            return self::FAILURE;
        }

        if ($reason === '') {
            $this->error('--reason is required (recorded on the entry as fee_override_reason).');

            return self::FAILURE;
        }

        $registration = MatchRegistration::with(['match', 'user', 'division', 'payments'])->find($id);
        if (! $registration) {
            $this->error("Registration not found: {$id}");

            return self::FAILURE;
        }

        if (! $registration->canCorrectCategory()) {
            $this->error(
                "Registration #{$id} cannot be repriced (payment_status={$registration->payment_status}, registration_status={$registration->registration_status})."
            );

            return self::FAILURE;
        }

        $previewBreakdown = $pricingService->calculateBreakdown(
            $registration->match,
            $registration->user,
            $registration->match?->match_date ?: now(),
            $registration->division?->slug,
            $category,
        );

        $this->info('=== registrations:reprice ===');
        $this->line(sprintf('%-22s %s', 'registration', '#'.$registration->id));
        $this->line(sprintf('%-22s %s', 'shooter', $registration->user?->name ?? $registration->shooter_name));
        $this->line(sprintf('%-22s %s', 'match', $registration->match?->name ?? '#' . $registration->match_id));
        $this->line(sprintf('%-22s %s → %s', 'category', $registration->membership_fee_category, $previewBreakdown['category']));
        $this->line(sprintf('%-22s %s → %s', 'fee_amount', number_format((float) $registration->fee_amount, 2), number_format((float) $previewBreakdown['total_fee'], 2)));
        $this->line(sprintf('%-22s %s → %s', 'surcharge', number_format((float) $registration->surcharge_amount, 2), number_format((float) $previewBreakdown['surcharge'], 2)));
        $this->line(sprintf('%-22s %s', 'reason', $reason));

        if (! $apply) {
            $this->newLine();
            $this->warn('Dry run — re-run with --apply to persist.');

            return self::SUCCESS;
        }

        $result = $pricingService->applyCategory($registration, $category, $reason);

        $auditLogService->log(
            null,
            'registration.category.updated',
            'MatchRegistration',
            $registration->id,
            $result['old'],
            $result['new'],
            $reason,
        );

        $this->newLine();
        $this->info("Registration #{$registration->id} updated to {$previewBreakdown['category']}.");
        if ($result['cancelled_payments'] > 0) {
            $this->warn("Cancelled {$result['cancelled_payments']} pending checkout(s) so Pay Now uses the new amount.");
        }

        return self::SUCCESS;
    }
}
