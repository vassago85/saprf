<?php

namespace App\Console\Commands;

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\User;
use App\Services\AccountCreditService;
use App\Services\AuditLogService;
use App\Services\RegistrationPricingService;
use Illuminate\Console\Command;

/**
 * Credit the paying account for junior entries that were paid at a higher
 * base fee than the match's current junior fee. Unpaid junior entries are
 * repriced to the new fee so the next payment is the reduced amount.
 * Safe to run more than once.
 */
class IssueJuniorFeeCreditsCommand extends Command
{
    protected $signature = 'registrations:issue-junior-fee-credits
                            {--match= : Match id}
                            {--fee= : Junior base fee to credit down to. Defaults to the match junior fee}
                            {--apply : Issue the credits (otherwise dry-run only)}';

    protected $description = 'Credit payers the difference after a junior entry fee is lowered';

    public function handle(
        AccountCreditService $credits,
        RegistrationPricingService $pricing,
        AuditLogService $auditLog,
    ): int {
        $matchId = (int) $this->option('match');
        $apply = (bool) $this->option('apply');

        if ($matchId <= 0) {
            $this->error('--match is required.');

            return self::FAILURE;
        }

        $match = MatchEvent::query()->find($matchId);
        if (! $match) {
            $this->error("Match not found: {$matchId}");

            return self::FAILURE;
        }

        $feeOption = $this->option('fee');
        $target = ($feeOption !== null && $feeOption !== '')
            ? round((float) $feeOption, 2)
            : ($match->junior_fee !== null ? round((float) $match->junior_fee, 2) : null);

        if ($target === null || $target < 0) {
            $this->error('This match has no junior fee. Set it on the match, or pass --fee.');

            return self::FAILURE;
        }

        if ($match->junior_fee === null || round((float) $match->junior_fee, 2) !== $target) {
            $current = $match->junior_fee === null ? 'blank' : 'R'.number_format((float) $match->junior_fee, 2);
            $this->error('Match junior fee is '.$current.'. Refusing to credit down to R'.number_format($target, 2).' while the match charges a different junior fee.');

            return self::FAILURE;
        }

        $registrations = MatchRegistration::query()
            ->with(['match:id,name,match_date,junior_fee', 'division:id,slug,name'])
            ->where('match_id', $match->id)
            ->where('registration_status', '!=', 'cancelled')
            ->where('registration_source', '!=', 'walk_in')
            ->whereHas('division', fn ($query) => $query->where('slug', 'junior'))
            ->orderBy('id')
            ->get();

        $reason = 'Junior fee lowered to R'.number_format($target, 2).' — difference credited to the paying account';

        $this->info('=== registrations:issue-junior-fee-credits ===');
        $this->line(sprintf('%-12s %s', 'match', $match->name.' (#'.$match->id.')'));
        $this->line(sprintf('%-12s R%s', 'junior fee', number_format($target, 2)));
        $this->line(sprintf('%-12s %s', 'mode', $apply ? 'APPLY' : 'DRY RUN'));

        $creditRows = [];
        $repriceRows = [];
        $creditTotal = 0.0;
        $skipped = 0;

        foreach ($registrations as $registration) {
            $oldFee = (float) $registration->fee_amount;
            $surcharge = (float) $registration->surcharge_amount;
            $base = round($oldFee - $surcharge, 2);
            $difference = round($base - $target, 2);

            if ($difference <= 0) {
                continue;
            }

            if (in_array($registration->payment_status, ['paid', 'waived'], true)) {
                if ($apply) {
                    $credit = $credits->issueJuniorFeeCredit($registration, $target, $reason);
                    $holderId = $credit?->user_id;
                    $credited = $credit ? (float) $credit->amount : 0.0;
                } else {
                    $holderId = $registration->hasIssuedCredit()
                        || $registration->feeAdjustmentCreditIssued() > 0
                        ? null
                        : $registration->withdrawalCreditHolderId();
                    $credited = $holderId ? $difference : 0.0;
                }

                if ($credited <= 0) {
                    $skipped++;
                    $this->warn("Skipped #{$registration->id} ({$registration->shooter_name}) — no account to credit, or already credited.");

                    continue;
                }

                $creditRows[] = [
                    '#'.$registration->id,
                    $registration->shooter_name,
                    User::query()->whereKey($holderId)->value('name') ?? 'user #'.$holderId,
                    $registration->payment_status,
                    number_format($oldFee, 2),
                    number_format($target + $surcharge, 2),
                    number_format($credited, 2),
                ];
                $creditTotal += $credited;

                continue;
            }

            if ($apply) {
                $result = $pricing->reduceUnpaidBaseFee($registration, $target, $reason);
                if ($result === null) {
                    $skipped++;
                    $this->warn("Skipped unpaid #{$registration->id} ({$registration->shooter_name}).");

                    continue;
                }

                $auditLog->log(
                    null,
                    'registration.junior_fee.repriced',
                    'MatchRegistration',
                    $registration->id,
                    $result['old'],
                    $result['new'],
                    $reason,
                );
                $registration->refresh();
            }

            $repriceRows[] = [
                '#'.$registration->id,
                $registration->shooter_name,
                $registration->payment_status,
                number_format($oldFee, 2),
                number_format($target + $surcharge, 2),
            ];
        }

        if ($creditRows !== []) {
            $this->newLine();
            $this->line('Paid junior entries');
            $this->table(['Entry', 'Shooter', 'Credited account', 'Payment', 'Was', 'Now', 'Credit'], $creditRows);
        }

        if ($repriceRows !== []) {
            $this->newLine();
            $this->line('Unpaid junior entries (repriced, no credit)');
            $this->table(['Entry', 'Shooter', 'Payment', 'Was', 'Now'], $repriceRows);
        }

        $this->newLine();
        if ($creditRows === [] && $repriceRows === []) {
            $this->info('No junior entries left above R'.number_format($target, 2).'.');
        } elseif ($apply) {
            $this->info('Issued R '.number_format($creditTotal, 2).' across '.count($creditRows).' junior fee credit(s).');
            if ($repriceRows !== []) {
                $this->info('Repriced '.count($repriceRows).' unpaid junior entr'.(count($repriceRows) === 1 ? 'y' : 'ies').'.');
            }
        } else {
            $this->info('Would issue R '.number_format($creditTotal, 2).' across '.count($creditRows).' junior fee credit(s).');
            if ($repriceRows !== []) {
                $this->info('Would reprice '.count($repriceRows).' unpaid junior entr'.(count($repriceRows) === 1 ? 'y' : 'ies').'.');
            }
            $this->warn('Dry run — re-run with --apply to issue the credits and email each payer.');
        }

        if ($skipped > 0) {
            $this->warn("{$skipped} entr".($skipped === 1 ? 'y' : 'ies').' skipped.');
        }

        return self::SUCCESS;
    }
}
