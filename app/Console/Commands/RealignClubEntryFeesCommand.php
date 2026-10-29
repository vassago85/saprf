<?php

namespace App\Console\Commands;

use App\Models\AccountCredit;
use App\Models\FinancialTransaction;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\User;
use App\Notifications\SurchargeAdjustmentCreditNotification;
use App\Services\AccountCreditService;
use App\Services\AuditLogService;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Re-align the SAPRF levy and platform fee on already-paid club-entry rows
 * so they match the current club-entry split (3%/3% by default).
 *
 * Context
 * -------
 * Non-members on club and provincial matches used to pay the non-member
 * fee + a R100 surcharge, with R50 SAPRF + R50 platform fixed on top. The
 * club-entry bracket introduced after they paid charges 3% SAPRF + 3%
 * platform of the club fee instead. For a R600 club fee that's R18 each,
 * so each of them is now owed back R32 SAPRF + R32 platform.
 *
 * What this command does, for every paid PayFast-settled club-entry row
 * on the given match:
 *   - If the stored surcharge is still > 0, remove it via the existing
 *     {@see AccountCreditService::issueSurchargeAdjustment} path (drops
 *     fee + surcharge together, credits the payer back the surcharge, and
 *     emails them).
 *   - Compute the club-entry levies as `club_entry_saprf_percent %` and
 *     `club_entry_platform_percent %` of the current (post-surcharge) fee.
 *   - If the stored SAPRF or platform is above those, credit the payer
 *     the difference, update the stored amounts, and recompute md_net.
 *
 * Idempotent: running it a second time is a no-op because the second pass
 * sees stored levies already match the club rates.
 */
class RealignClubEntryFeesCommand extends Command
{
    protected $signature = 'registrations:realign-club-entry-fees
                            {match : Match id}
                            {--apply : Issue credits and update rows (otherwise dry-run)}';

    protected $description = 'Credit paid club-entry rows back any surcharge still on them and align SAPRF/platform levies to the club rate.';

    private const DEFAULT_SAPRF_PERCENT = 3.0;

    private const DEFAULT_PLATFORM_PERCENT = 3.0;

    private AccountCreditService $credits;

    public function handle(
        AccountCreditService $credits,
        AuditLogService $auditLog,
        SettingsService $settings,
    ): int {
        $this->credits = $credits;
        $matchId = (int) $this->argument('match');
        $apply = (bool) $this->option('apply');

        $match = MatchEvent::query()->find($matchId);
        if (! $match) {
            $this->error("Match #{$matchId} not found.");

            return self::FAILURE;
        }

        $saprfPct = (float) $settings->get('club_entry_saprf_percent', self::DEFAULT_SAPRF_PERCENT);
        $platformPct = (float) $settings->get('club_entry_platform_percent', self::DEFAULT_PLATFORM_PERCENT);

        $this->info('=== registrations:realign-club-entry-fees ===');
        $this->line(sprintf('%-16s #%d — %s', 'match', $match->id, $match->name));
        $this->line(sprintf('%-16s SAPRF %s%% + platform %s%% of the club fee', 'club rates',
            $this->prettyPercent($saprfPct), $this->prettyPercent($platformPct)));
        $this->line(sprintf('%-16s %s', 'mode', $apply ? 'APPLY' : 'DRY RUN'));

        $rows = MatchRegistration::query()
            ->with(['payments', 'user:id,name', 'match:id,name'])
            ->where('match_id', $match->id)
            ->where('membership_fee_category', 'club_entry')
            ->where('payment_status', 'paid')
            ->where('registration_status', '!=', 'cancelled')
            ->orderBy('id')
            ->get()
            ->filter(fn (MatchRegistration $r) => $r->settledOnPlatform())
            ->values();

        if ($rows->isEmpty()) {
            $this->newLine();
            $this->info('No PayFast-settled club-entry rows to realign.');

            return self::SUCCESS;
        }

        $table = [];
        $totalCredited = 0.0;
        $skipped = 0;

        foreach ($rows as $registration) {
            $surchargeBefore = round((float) $registration->surcharge_amount, 2);
            $feeBefore = round((float) $registration->fee_amount, 2);
            $saprfBefore = round((float) $registration->saprf_fee, 2);
            $platformBefore = round((float) $registration->platform_fee, 2);

            // Step 1 — surcharge still on the row. Delegate to the existing
            // service so audit, email, and the adjusted_registration_id link
            // land exactly like `registrations:issue-surcharge-credits`.
            if ($surchargeBefore > 0) {
                if ($apply) {
                    $credit = $credits->issueSurchargeAdjustment(
                        $registration,
                        $surchargeBefore,
                        'Club entry — surcharge removed as part of levy realignment',
                    );
                    if ($credit === null) {
                        $skipped++;
                        $this->warn("#{$registration->id} ({$registration->shooter_name}) — surcharge credit refused, skipping.");

                        continue;
                    }
                    $totalCredited += (float) $credit->amount;
                    $registration->refresh();
                } else {
                    $totalCredited += $surchargeBefore;
                }
            }

            $feeAfter = round((float) $registration->fee_amount, 2);
            $expectedSaprf = round($feeAfter * ($saprfPct / 100), 2);
            $expectedPlatform = round($feeAfter * ($platformPct / 100), 2);

            $saprfDiff = round((float) $registration->saprf_fee - $expectedSaprf, 2);
            $platformDiff = round((float) $registration->platform_fee - $expectedPlatform, 2);
            $levyDiff = round(max($saprfDiff, 0) + max($platformDiff, 0), 2);

            $credited = $surchargeBefore;

            if ($levyDiff > 0.0049) {
                if ($apply) {
                    $levyCredit = $this->issueLevyAdjustment(
                        $credits,
                        $auditLog,
                        $registration,
                        $expectedSaprf,
                        $expectedPlatform,
                        $levyDiff,
                    );
                    if ($levyCredit === null) {
                        $skipped++;
                        $this->warn("#{$registration->id} ({$registration->shooter_name}) — levy adjustment refused, skipping.");

                        continue;
                    }
                    $totalCredited += (float) $levyCredit->amount;
                    $credited += (float) $levyCredit->amount;
                } else {
                    $totalCredited += $levyDiff;
                    $credited += $levyDiff;
                }
            }

            if ($credited <= 0) {
                continue;
            }

            $table[] = [
                '#'.$registration->id,
                $registration->shooter_name,
                'R '.number_format($feeBefore, 2),
                'R '.number_format($surchargeBefore, 2),
                'R '.number_format($saprfBefore, 2).' → R '.number_format($expectedSaprf, 2),
                'R '.number_format($platformBefore, 2).' → R '.number_format($expectedPlatform, 2),
                'R '.number_format($credited, 2),
            ];
        }

        $this->newLine();
        if ($table !== []) {
            $this->table(
                ['Entry', 'Shooter', 'Fee', 'Surcharge', 'SAPRF', 'Platform', 'Credit'],
                $table,
            );
        } else {
            $this->info('Every PayFast club-entry row already matches the club rate — nothing to do.');
        }

        $this->newLine();
        if ($apply) {
            $this->info('Issued R '.number_format($totalCredited, 2).' in credits across '.count($table).' row(s).');
        } else {
            $this->info('Would issue R '.number_format($totalCredited, 2).' in credits across '.count($table).' row(s).');
            $this->warn('Dry run — re-run with --apply to issue the credits and update the rows.');
        }

        if ($skipped > 0) {
            $this->warn("{$skipped} row(s) skipped — inspect the warnings above.");
        }

        return self::SUCCESS;
    }

    /**
     * Credit the payer the SAPRF/platform overcharge on a club-entry row,
     * update the stored levies, and recompute md_net. Logged as a surcharge
     * adjustment on the ledger so the finance report trail stays uniform.
     */
    private function issueLevyAdjustment(
        AccountCreditService $credits,
        AuditLogService $auditLog,
        MatchRegistration $registration,
        float $expectedSaprf,
        float $expectedPlatform,
        float $totalDiff,
    ): ?AccountCredit {
        return DB::transaction(function () use ($auditLog, $registration, $expectedSaprf, $expectedPlatform, $totalDiff) {
            $locked = MatchRegistration::query()->whereKey($registration->id)->lockForUpdate()->first();
            if (! $locked) {
                return null;
            }

            $holderId = $locked->withdrawalCreditHolderId();
            if (! $holderId) {
                return null;
            }

            $saprfBefore = round((float) $locked->saprf_fee, 2);
            $platformBefore = round((float) $locked->platform_fee, 2);
            $matchName = $registration->match?->name ?? 'event';

            $credit = AccountCredit::query()->create([
                'user_id' => $holderId,
                'amount' => $totalDiff,
                'status' => AccountCredit::STATUS_POSTED,
                'type' => AccountCredit::TYPE_FEE_ADJUSTMENT,
                'match_id' => $locked->match_id,
                'description' => 'Club entry levy adjustment: '.$matchName.' ('.$locked->shooter_name.')',
            ]);

            $saprfAfter = min($saprfBefore, $expectedSaprf);
            $platformAfter = min($platformBefore, $expectedPlatform);

            $newNet = round(
                (float) $locked->fee_amount
                    - (float) $locked->surcharge_amount
                    - $saprfAfter
                    - $platformAfter
                    - (float) $locked->gateway_fee,
                2,
            );

            $locked->update([
                'saprf_fee' => $saprfAfter,
                'platform_fee' => $platformAfter,
                'md_net_amount' => $newNet,
            ]);

            FinancialTransaction::query()->create([
                'type' => 'refund',
                'source_type' => 'match_registration',
                'source_id' => $locked->id,
                'user_id' => $holderId,
                'amount' => $totalDiff,
                'description' => 'Club entry levy adjustment — '.$matchName,
                'meta' => [
                    'account_credit_id' => $credit->id,
                    'shooter_name' => $locked->shooter_name,
                    'match_id' => $locked->match_id,
                    'saprf_before' => $saprfBefore,
                    'saprf_after' => $saprfAfter,
                    'platform_before' => $platformBefore,
                    'platform_after' => $platformAfter,
                    'reason' => 'club_entry_levy_realignment',
                ],
            ]);

            $auditLog->log(
                null,
                'registration.levy_adjustment.issued',
                'MatchRegistration',
                $locked->id,
                [
                    'saprf_fee' => $saprfBefore,
                    'platform_fee' => $platformBefore,
                ],
                [
                    'saprf_fee' => $saprfAfter,
                    'platform_fee' => $platformAfter,
                    'md_net_amount' => $newNet,
                    'account_credit_id' => $credit->id,
                    'credited_user_id' => $holderId,
                    'amount' => $totalDiff,
                ],
                'Club entry levy realigned to the current club rate.',
            );

            $this->notifyLevyCredit($registration, $credit);

            return $credit;
        });
    }

    private function notifyLevyCredit(MatchRegistration $registration, AccountCredit $credit): void
    {
        $user = User::query()->find($credit->user_id);
        if (! $user) {
            return;
        }

        try {
            $balance = (float) ($this->credits->summary($user)['posted'] ?? 0);
            $user->notify(new SurchargeAdjustmentCreditNotification(
                $registration->match?->name ?? 'event',
                (string) $registration->shooter_name,
                (float) $credit->amount,
                $balance,
            ));
        } catch (\Throwable $e) {
            Log::warning('Failed to send levy credit notification', [
                'user_id' => $user->id,
                'registration_id' => $registration->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function prettyPercent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
