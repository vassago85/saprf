<?php

namespace App\Console\Commands;

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * One-off reporting tool for post–surcharge-change audits.
 *
 * `surcharge_amount` is written onto the registration at the time of entry
 * (see RegistrationPricingService::calculateBreakdown). When the global
 * `non_member_surcharge` setting is changed mid-season, every existing
 * entry keeps whatever amount it was priced at, so a single open match can
 * end up with some shooters at R200 (pre-change) and others at R100
 * (post-change). That is a complaint magnet — this command surfaces it.
 *
 * By default it only lists matches with more than one distinct surcharge
 * value on their non-member entries (the "mixed" matches, which are the
 * ones generating complaints). Pass `--all-matches` to see every match
 * with surcharge-bearing entries. `--open-only` restricts to matches that
 * have not yet been completed or cancelled, which is usually what you
 * want when deciding whether to retroactively adjust entries.
 *
 * The screen output is a per-match summary; the CSV output (default:
 * storage/app/surcharge-report-<timestamp>.csv) is the per-shooter list
 * you forward to whoever processes the refunds / credits.
 */
class SurchargeMixReportCommand extends Command
{
    protected $signature = 'saprf:surcharge-mix-report
                            {--category=non_member : non_member|lapsed_member|all}
                            {--open-only : Only matches with status not in [completed, cancelled]}
                            {--all-matches : Include matches where every entry paid the same surcharge}
                            {--paid-only : Only include entries with payment_status in [paid, waived]}
                            {--out= : Output CSV path (default: storage/app/surcharge-report-<timestamp>.csv)}';

    protected $description = 'List matches where non-member entries have been priced at different surcharge amounts (e.g. R100 vs R200 after a mid-season reduction), with a per-shooter CSV.';

    /** Match statuses considered "still open" (match has not happened/been called off). */
    private const OPEN_STATUSES_EXCLUDED = ['completed', 'cancelled'];

    public function handle(): int
    {
        $category = (string) $this->option('category');
        if (! in_array($category, ['non_member', 'lapsed_member', 'all'], true)) {
            $this->error("--category must be one of non_member|lapsed_member|all (got: {$category}).");

            return self::FAILURE;
        }

        $categories = $category === 'all' ? ['non_member', 'lapsed_member'] : [$category];
        $openOnly = (bool) $this->option('open-only');
        $allMatches = (bool) $this->option('all-matches');
        $paidOnly = (bool) $this->option('paid-only');

        $this->info('=== saprf:surcharge-mix-report ===');
        $this->line(sprintf('%-14s %s', 'category', implode(', ', $categories)));
        $this->line(sprintf('%-14s %s', 'open-only', $openOnly ? 'yes' : 'no'));
        $this->line(sprintf('%-14s %s', 'mixed-only', $allMatches ? 'no (showing every match)' : 'yes (only matches with 2+ surcharge values)'));
        $this->line(sprintf('%-14s %s', 'paid-only', $paidOnly ? 'yes' : 'no (includes pending/cancelled rows)'));

        $registrations = $this->loadRegistrations($categories, $openOnly, $paidOnly);

        if ($registrations->isEmpty()) {
            $this->newLine();
            $this->warn('No registrations matched the filters.');

            return self::SUCCESS;
        }

        /** @var Collection<int, Collection<int, MatchRegistration>> $byMatch */
        $byMatch = $registrations->groupBy('match_id');

        if (! $allMatches) {
            $byMatch = $byMatch->filter(
                fn (Collection $entries) => $entries->pluck('surcharge_amount')
                    ->map(fn ($v) => (float) $v)
                    ->unique()
                    ->count() > 1
            );
        }

        if ($byMatch->isEmpty()) {
            $this->newLine();
            $this->info('No mixed-surcharge matches found. Add --all-matches to list every match with surcharge-bearing entries.');

            return self::SUCCESS;
        }

        $this->renderSummaryTable($byMatch);
        $this->renderGrandTotals($byMatch);

        $csvPath = $this->writeCsv($byMatch);
        $this->newLine();
        $this->info("CSV written: {$csvPath}");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $categories
     * @return Collection<int, MatchRegistration>
     */
    private function loadRegistrations(array $categories, bool $openOnly, bool $paidOnly): Collection
    {
        $query = MatchRegistration::query()
            ->with(['match' => fn ($q) => $q->select('id', 'name', 'match_date', 'status')])
            ->whereIn('membership_fee_category', $categories)
            ->where('surcharge_amount', '>', 0);

        if ($paidOnly) {
            $query->whereIn('payment_status', ['paid', 'waived']);
        }

        if ($openOnly) {
            $query->whereHas('match', fn ($q) => $q->whereNotIn('status', self::OPEN_STATUSES_EXCLUDED));
        }

        return $query->orderBy('match_id')->orderBy('registered_at')->get();
    }

    /**
     * @param  Collection<int, Collection<int, MatchRegistration>>  $byMatch
     */
    private function renderSummaryTable(Collection $byMatch): void
    {
        $rows = [];

        foreach ($byMatch as $matchId => $entries) {
            /** @var MatchEvent|null $match */
            $match = $entries->first()?->match;

            $surchargeCounts = $entries
                ->groupBy(fn (MatchRegistration $r) => number_format((float) $r->surcharge_amount, 2, '.', ''))
                ->map->count()
                ->sortKeys();

            $breakdown = $surchargeCounts
                ->map(fn (int $count, string $amount) => 'R' . rtrim(rtrim($amount, '0'), '.') . ' x ' . $count)
                ->implode(' / ');

            $maxSurcharge = (float) $entries->max('surcharge_amount');
            $shortfall = $entries->sum(fn (MatchRegistration $r) => $maxSurcharge - (float) $r->surcharge_amount);

            $rows[] = [
                '#' . $matchId,
                $match?->name ?? '(unknown)',
                $match?->match_date?->format('Y-m-d') ?? '-',
                $match?->status ?? '-',
                $entries->count(),
                $breakdown,
                'R' . number_format($shortfall, 2),
            ];
        }

        $this->newLine();
        $this->table(
            ['Match', 'Name', 'Date', 'Status', 'Entries', 'Surcharges paid', 'Harmonise-down liability'],
            $rows,
        );
    }

    /**
     * @param  Collection<int, Collection<int, MatchRegistration>>  $byMatch
     */
    private function renderGrandTotals(Collection $byMatch): void
    {
        $flat = $byMatch->flatten(1);

        $totalEntries = $flat->count();
        $distinctAmounts = $flat->pluck('surcharge_amount')->map(fn ($v) => (float) $v)->unique()->sort()->values();

        $perAmount = $flat
            ->groupBy(fn (MatchRegistration $r) => number_format((float) $r->surcharge_amount, 2, '.', ''))
            ->map(fn (Collection $rows) => [
                'count' => $rows->count(),
                'collected' => (float) $rows->sum('surcharge_amount'),
            ])
            ->sortKeys();

        $this->newLine();
        $this->line('<comment>Totals across the matches above:</comment>');
        $this->line(sprintf('  entries: %d', $totalEntries));
        $this->line(sprintf('  distinct surcharge amounts seen: %s', $distinctAmounts->map(fn (float $v) => 'R' . number_format($v, 2))->implode(', ')));

        foreach ($perAmount as $amount => $info) {
            $this->line(sprintf(
                '  R%s: %d entries, R%s collected',
                $amount,
                $info['count'],
                number_format($info['collected'], 2),
            ));
        }
    }

    /**
     * @param  Collection<int, Collection<int, MatchRegistration>>  $byMatch
     */
    private function writeCsv(Collection $byMatch): string
    {
        $out = $this->option('out');
        $relative = is_string($out) && $out !== ''
            ? $out
            : 'surcharge-report-' . now()->format('Ymd-His') . '.csv';

        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, [
            'match_id', 'match_name', 'match_date', 'match_status',
            'registration_id', 'shooter_name', 'email',
            'category', 'surcharge_amount', 'fee_amount',
            'payment_status', 'registration_status', 'registered_at',
        ]);

        foreach ($byMatch as $entries) {
            /** @var Collection<int, MatchRegistration> $entries */
            foreach ($entries as $r) {
                fputcsv($handle, [
                    $r->match_id,
                    $r->match?->name,
                    $r->match?->match_date?->format('Y-m-d'),
                    $r->match?->status,
                    $r->id,
                    $r->shooter_name,
                    $r->email,
                    $r->membership_fee_category,
                    number_format((float) $r->surcharge_amount, 2, '.', ''),
                    number_format((float) $r->fee_amount, 2, '.', ''),
                    $r->payment_status,
                    $r->registration_status,
                    $r->registered_at?->format('Y-m-d H:i:s'),
                ]);
            }
        }

        rewind($handle);
        Storage::disk('local')->put($relative, stream_get_contents($handle));
        fclose($handle);

        return Storage::disk('local')->path($relative);
    }
}
