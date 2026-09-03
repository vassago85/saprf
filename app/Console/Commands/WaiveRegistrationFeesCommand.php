<?php

namespace App\Console\Commands;

use App\Models\MatchRegistration;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retroactively zero the platform fee on any registration that falls inside
 * the pre-billing grace period. The SAPRF R50 is deliberately left in place —
 * SAPRF continues to collect its levy for the grace window, only the platform
 * fee is being waived (that was the fee schedule change that didn't reach the
 * match directors in time).
 *
 * By default the cut-off is read from the `billing_start_date` setting;
 * override with `--date=YYYY-MM-DD` for a one-off. The command is
 * idempotent — rows with platform_fee already at 0 are skipped, and the
 * amount that used to sit in `platform_fee` is rolled back into
 * `md_net_amount` so `fee_amount = saprf_fee + platform_fee + surcharge +
 * gateway_fee + md_net_amount` continues to hold.
 *
 * Companion to RegistrationPricingService::isFeeWaived() — the pricing
 * service handles new registrations, this command handles existing rows.
 */
class WaiveRegistrationFeesCommand extends Command
{
    protected $signature = 'saprf:waive-fees-before-date
                            {--date= : Cut-off date YYYY-MM-DD. Defaults to the billing_start_date setting.}
                            {--dry-run : Report what would change without writing.}';

    protected $description = 'Zero the platform fee on registrations with registered_at before the cut-off (grace period backfill). SAPRF R50 is retained.';

    public function handle(SettingsService $settings): int
    {
        $cutoff = $this->resolveCutoff($settings);
        if ($cutoff === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->info('=== saprf:waive-fees-before-date ===');
        $this->line(sprintf('%-14s %s', 'cut-off', $cutoff->toDateString()));
        $this->line(sprintf('%-14s %s', 'mode', $dryRun ? 'DRY RUN' : 'APPLY'));

        $query = MatchRegistration::query()
            ->where('registered_at', '<', $cutoff)
            ->where('platform_fee', '>', 0);

        $summary = $query->clone()
            ->selectRaw('COUNT(*) AS row_count, COALESCE(SUM(platform_fee), 0) AS platform_total')
            ->first();

        $rows = (int) $summary->row_count;
        $platformTotal = (float) $summary->platform_total;

        $this->newLine();
        $this->line(sprintf('%-14s %d registration(s)', 'affected', $rows));
        $this->line(sprintf('%-14s R %s', 'platform total', number_format($platformTotal, 2)));
        $this->line(sprintf('%-14s R %s (added back to md_net_amount; saprf_fee is untouched)', 'md_net delta', number_format($platformTotal, 2)));

        if ($rows === 0) {
            $this->newLine();
            $this->info('Nothing to do — all registrations before the cut-off already have platform_fee = 0.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('Dry run — re-run without --dry-run to persist.');

            return self::SUCCESS;
        }

        // Do the update in a single SQL round trip so we don't rehydrate
        // thousands of models for a straightforward arithmetic rewrite.
        // md_net_amount grows by whatever platform_fee currently is; then
        // platform_fee is zeroed. Idempotent because the WHERE clause
        // guarantees platform_fee > 0.
        $updated = DB::transaction(function () use ($query) {
            return $query->update([
                'md_net_amount' => DB::raw('md_net_amount + platform_fee'),
                'platform_fee' => 0,
                'updated_at' => now(),
            ]);
        });

        $this->newLine();
        $this->info("Waived platform fees on {$updated} registration(s).");
        $this->warn('Note: MD payouts that were generated before this run were computed against the OLD (net-of-fee) md_net_amount. If any pre-cut-off registrations were already paid out, top-ups need to be issued manually.');

        return self::SUCCESS;
    }

    private function resolveCutoff(SettingsService $settings): ?Carbon
    {
        $raw = $this->option('date');
        $source = '--date';

        if ($raw === null || trim((string) $raw) === '') {
            $raw = trim((string) $settings->get('billing_start_date', ''));
            $source = 'billing_start_date setting';
        }

        if ($raw === '' || $raw === null) {
            $this->error('No cut-off date provided and billing_start_date setting is empty. Pass --date=YYYY-MM-DD.');

            return null;
        }

        try {
            return Carbon::parse($raw)->startOfDay();
        } catch (\Throwable $e) {
            $this->error("Could not parse cut-off date from {$source} ({$raw}): {$e->getMessage()}");

            return null;
        }
    }
}
