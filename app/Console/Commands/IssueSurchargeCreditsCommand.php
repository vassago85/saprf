<?php

namespace App\Console\Commands;

use App\Models\MatchRegistration;
use App\Models\User;
use App\Services\AccountCreditService;
use Illuminate\Console\Command;

/**
 * Give back part of the surcharge to everyone who paid the old, higher
 * surcharge (e.g. R200 non-member surcharge, now R100 → R100 credit each).
 * Paid and previous-site (waived) entries qualify; cancelled entries and
 * entries already fully credited are skipped. Safe to run more than once.
 */
class IssueSurchargeCreditsCommand extends Command
{
    protected $signature = 'registrations:issue-surcharge-credits
                            {--surcharge=200 : Only entries that paid exactly this surcharge}
                            {--amount=100 : Credit per entry}
                            {--category=non_member : non_member|lapsed_member}
                            {--match= : Only this match id}
                            {--apply : Issue the credits (otherwise dry-run only)}';

    protected $description = 'Credit payers who paid a surcharge that has since been lowered';

    public function handle(AccountCreditService $credits): int
    {
        $surcharge = round((float) $this->option('surcharge'), 2);
        $amount = round((float) $this->option('amount'), 2);
        $category = (string) $this->option('category');
        $matchId = $this->option('match');
        $apply = (bool) $this->option('apply');

        if (! in_array($category, ['non_member', 'lapsed_member'], true)) {
            $this->error("--category must be non_member or lapsed_member (got: {$category}).");

            return self::FAILURE;
        }

        if ($surcharge <= 0 || $amount <= 0 || $amount > $surcharge) {
            $this->error('--surcharge and --amount must be positive, and --amount cannot exceed --surcharge.');

            return self::FAILURE;
        }

        $query = MatchRegistration::query()
            ->with('match:id,name,match_date')
            ->where('membership_fee_category', $category)
            ->where('surcharge_amount', $surcharge)
            ->whereIn('payment_status', ['paid', 'waived'])
            ->where('registration_status', '!=', 'cancelled')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('account_credits')
                ->where(fn ($inner) => $inner
                    ->whereColumn('account_credits.issued_for_registration_id', 'match_registrations.id')
                    ->orWhereColumn('account_credits.adjusted_registration_id', 'match_registrations.id')))
            ->orderBy('match_id')
            ->orderBy('id');

        if ($matchId !== null && $matchId !== '') {
            $query->where('match_id', (int) $matchId);
        }

        $registrations = $query->get();

        $this->info('=== registrations:issue-surcharge-credits ===');
        $this->line(sprintf('%-12s %s', 'category', $category));
        $this->line(sprintf('%-12s R%s → credit R%s each', 'surcharge', number_format($surcharge, 2), number_format($amount, 2)));
        $this->line(sprintf('%-12s %s', 'mode', $apply ? 'APPLY' : 'DRY RUN'));

        if ($registrations->isEmpty()) {
            $this->newLine();
            $this->info('No entries left to credit.');

            return self::SUCCESS;
        }

        $rows = [];
        $total = 0.0;
        $skipped = 0;

        foreach ($registrations as $registration) {
            if ($apply) {
                $credit = $credits->issueSurchargeAdjustment(
                    $registration,
                    $amount,
                    'Surcharge lowered from R'.number_format($surcharge, 2).' — R'.number_format($amount, 2).' credited back',
                );
                $holderId = $credit?->user_id;
                $credited = $credit ? (float) $credit->amount : 0.0;
            } else {
                $holderId = $registration->withdrawalCreditHolderId();
                $credited = $holderId ? $amount : 0.0;
            }

            if ($credited <= 0) {
                $skipped++;
                $this->warn("Skipped #{$registration->id} ({$registration->shooter_name}) — no account to credit.");

                continue;
            }

            $rows[] = [
                '#'.$registration->id,
                $registration->match?->name ?? '#'.$registration->match_id,
                $registration->shooter_name,
                User::query()->whereKey($holderId)->value('name') ?? 'user #'.$holderId,
                $registration->payment_status,
                number_format($credited, 2),
            ];
            $total += $credited;
        }

        if ($rows !== []) {
            $this->newLine();
            $this->table(['Entry', 'Match', 'Shooter', 'Credited account', 'Payment', 'Credit'], $rows);
        }

        $this->newLine();
        if ($apply) {
            $this->info('Issued R '.number_format($total, 2).' across '.count($rows).' surcharge credit(s).');
        } else {
            $this->info('Would issue R '.number_format($total, 2).' across '.count($rows).' surcharge credit(s).');
            $this->warn('Dry run — re-run with --apply to issue the credits and email each payer.');
        }

        if ($skipped > 0) {
            $this->warn("{$skipped} entr".($skipped === 1 ? 'y' : 'ies').' skipped.');
        }

        return self::SUCCESS;
    }
}
