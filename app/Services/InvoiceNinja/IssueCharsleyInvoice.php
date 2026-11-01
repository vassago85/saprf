<?php

namespace App\Services\InvoiceNinja;

use App\Models\CharsleyInvoice;
use App\Models\MatchRegistration;
use App\Models\MembershipPayment;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class IssueCharsleyInvoice
{
    public function __construct(
        private InvoiceNinjaClient $client,
        private CharsleyInvoiceSettings $settings,
        private SettingsService $settingsService,
    ) {}

    /**
     * The month's bill mirrors what the platform actually booked.
     *
     *   - Match entries: sum of the stored `platform_fee` for paid, non-club
     *     registrations in the month. Rows where the fee was waived or
     *     settled off-platform (platform_fee = 0) are excluded.
     *   - Club entries: sum of the stored `platform_fee` for paid club
     *     entries. Same waiver rule.
     *   - Membership payments: sum of the paid gross times the membership
     *     platform percent (same calculation the finance dashboard uses).
     *
     * The total therefore equals the "Total Platform Fees" the dashboard
     * reports for the same month, so the invoice never over-bills.
     *
     * @return array{
     *     period: string,
     *     label: string,
     *     year: int,
     *     month: int,
     *     entries: int,
     *     match_entries: int,
     *     match_total: float,
     *     club_entries: int,
     *     club_percent: float,
     *     club_total: float,
     *     membership_payments: int,
     *     membership_percent: float,
     *     membership_total: float,
     *     entry_fee: float,
     *     total: float
     * }
     */
    public function preview(int $year, int $month): array
    {
        $periodStart = Carbon::create($year, $month, 1, 0, 0, 0, 'Africa/Johannesburg');
        $counts = $this->transactions($periodStart);
        $fee = $this->settings->entryFee();

        $total = round($counts['match_total'] + $counts['club_total'] + $counts['membership_total'], 2);

        return [
            'period' => sprintf('%04d-%02d', $year, $month),
            'label' => $periodStart->format('F Y'),
            'year' => $year,
            'month' => $month,
            'entries' => $counts['match_entries'] + $counts['club_entries'] + $counts['membership_payments'],
            'match_entries' => $counts['match_entries'],
            'match_total' => $counts['match_total'],
            'club_entries' => $counts['club_entries'],
            'club_percent' => $counts['club_percent'],
            'club_total' => $counts['club_total'],
            'membership_payments' => $counts['membership_payments'],
            'membership_percent' => $counts['membership_percent'],
            'membership_total' => $counts['membership_total'],
            'entry_fee' => $fee,
            'total' => $total,
        ];
    }

    public function issue(int $year, int $month, bool $sendEmail = true): ?CharsleyInvoice
    {
        $this->assertReady();

        $preview = $this->preview($year, $month);
        $existing = CharsleyInvoice::query()
            ->where('period', $preview['period'])
            ->whereNull('voided_at')
            ->first();

        if (! $this->billable($preview) && ! $existing?->invoice_ninja_id) {
            return null;
        }

        $lock = Cache::lock('charsley-invoice:'.$preview['period'], 90);

        if (! $lock->get()) {
            throw new InvoiceNinjaException('Another invoice for '.$preview['label'].' is already being created.');
        }

        try {
            return $this->issueLocked($preview, $sendEmail);
        } finally {
            $lock->release();
        }
    }

    public function retryUnsent(): int
    {
        if (! $this->settings->isConfigured()) {
            return 0;
        }

        $sent = 0;

        CharsleyInvoice::query()
            ->where('status', CharsleyInvoice::STATUS_CREATED)
            ->whereNotNull('invoice_ninja_id')
            ->orderBy('period')
            ->each(function (CharsleyInvoice $invoice) use (&$sent) {
                try {
                    $this->email($invoice);
                    $sent++;
                } catch (InvoiceNinjaException) {
                    // email() already stored the error. Keep going so a stuck
                    // older invoice does not block this month.
                }
            });

        return $sent;
    }

    /**
     * @return array{
     *     match_entries: int,
     *     match_total: float,
     *     club_entries: int,
     *     club_percent: float,
     *     club_total: float,
     *     membership_payments: int,
     *     membership_percent: float,
     *     membership_total: float,
     * }
     */
    private function transactions(Carbon $periodStart): array
    {
        $start = $periodStart->copy()->startOfMonth();
        $end = $periodStart->copy()->endOfMonth();

        // Match entries that the platform actually billed a fee on. The
        // `platform_fee > 0` guard drops waived rows and off-platform
        // settlements so we never bill Charsley for a shooter the platform
        // did not charge.
        $matchAgg = MatchRegistration::query()
            ->where('payment_status', 'paid')
            ->whereBetween('registered_at', [$start, $end])
            ->where('platform_fee', '>', 0)
            ->where(function ($query) {
                $query->whereNull('membership_fee_category')
                    ->orWhere('membership_fee_category', '!=', 'club_entry');
            })
            ->selectRaw('COUNT(*) as entries, COALESCE(SUM(platform_fee), 0) as total')
            ->first();

        $clubAgg = MatchRegistration::query()
            ->where('payment_status', 'paid')
            ->where('membership_fee_category', 'club_entry')
            ->where('platform_fee', '>', 0)
            ->whereBetween('registered_at', [$start, $end])
            ->selectRaw('COUNT(*) as entries, COALESCE(SUM(platform_fee), 0) as total')
            ->first();

        // Memberships use the same calculation as the finance dashboard:
        // confirmed membership payment gross × membership_platform_fee_pct.
        $membershipAgg = MembershipPayment::query()
            ->where('status', 'confirmed')
            ->whereBetween('payment_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('COUNT(*) as entries, COALESCE(SUM(amount), 0) as gross')
            ->first();

        // Charsley does not take a cut on memberships by default. Operators
        // who want a non-zero share set `membership_platform_fee_pct` from
        // Site Settings.
        $membershipPercent = (float) $this->settingsService->get('membership_platform_fee_pct', 0);
        $membershipTotal = round((float) $membershipAgg->gross * ($membershipPercent / 100), 2);

        return [
            'match_entries' => (int) $matchAgg->entries,
            'match_total' => round((float) $matchAgg->total, 2),
            'club_entries' => (int) $clubAgg->entries,
            'club_percent' => $this->settings->clubPlatformPercent(),
            'club_total' => round((float) $clubAgg->total, 2),
            'membership_payments' => (int) $membershipAgg->entries,
            'membership_percent' => $membershipPercent,
            'membership_total' => $membershipTotal,
        ];
    }

    /**
     * @param  array{match_total: float, club_total: float, membership_total: float}  $preview
     */
    private function billable(array $preview): bool
    {
        return $preview['match_total'] > 0
            || $preview['club_total'] > 0
            || $preview['membership_total'] > 0;
    }

    private function assertReady(): void
    {
        if ($this->settings->clientId() === CharsleyInvoiceSettings::NRAPA_CLIENT_ID) {
            throw new InvoiceNinjaException('That Invoice Ninja client belongs to National Rifle and Pistol Association. Create a SAPRF client and save its id.');
        }

        if (! $this->settings->isConfigured()) {
            throw new InvoiceNinjaException('Invoice Ninja is not configured. Add the API URL, token, and SAPRF client ID in Site Settings.');
        }
    }

    /**
     * @param  array{
     *     period: string,
     *     label: string,
     *     year: int,
     *     month: int,
     *     entries: int,
     *     match_entries: int,
     *     match_total: float,
     *     club_entries: int,
     *     club_percent: float,
     *     club_total: float,
     *     membership_payments: int,
     *     membership_percent: float,
     *     membership_total: float,
     *     entry_fee: float,
     *     total: float
     * }  $preview
     */
    private function issueLocked(array $preview, bool $sendEmail): CharsleyInvoice
    {
        $invoice = $this->claim($preview);

        if ($invoice->status === CharsleyInvoice::STATUS_SENT) {
            return $invoice;
        }

        if ($invoice->invoice_ninja_id) {
            return $sendEmail ? $this->email($invoice) : $invoice;
        }

        if (! $this->billable($preview)) {
            return $invoice;
        }

        try {
            $remote = $this->client->createInvoice($this->payload($preview));
        } catch (InvoiceNinjaException $e) {
            $invoice->update([
                'status' => CharsleyInvoice::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);
            Log::warning('Charsley Invoice Ninja invoice failed', [
                'period' => $preview['period'],
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $remoteId = isset($remote['id']) ? (string) $remote['id'] : '';

        if ($remoteId === '') {
            $invoice->update([
                'status' => CharsleyInvoice::STATUS_FAILED,
                'error' => 'Invoice Ninja did not return an invoice id.',
            ]);

            throw new InvoiceNinjaException('Invoice Ninja did not return an invoice id.');
        }

        $invoice->update([
            'invoice_ninja_id' => $remoteId,
            'invoice_number' => isset($remote['number']) ? (string) $remote['number'] : null,
            'status' => CharsleyInvoice::STATUS_CREATED,
            'error' => null,
        ]);

        $invoice = $invoice->refresh();

        return $sendEmail ? $this->email($invoice) : $invoice;
    }

    /**
     * @param  array{
     *     period: string,
     *     label: string,
     *     year: int,
     *     month: int,
     *     entries: int,
     *     match_entries: int,
     *     match_total: float,
     *     club_entries: int,
     *     club_percent: float,
     *     club_total: float,
     *     membership_payments: int,
     *     membership_percent: float,
     *     membership_total: float,
     *     entry_fee: float,
     *     total: float
     * }  $preview
     */
    private function claim(array $preview): CharsleyInvoice
    {
        $existing = CharsleyInvoice::query()
            ->where('period', $preview['period'])
            ->whereNull('voided_at')
            ->first();

        if ($existing && ($existing->status === CharsleyInvoice::STATUS_SENT || $existing->invoice_ninja_id)) {
            return $existing;
        }

        $attributes = [
            'entry_fee' => $preview['entry_fee'],
            'entries' => $preview['entries'],
            'total' => $preview['total'],
            'status' => CharsleyInvoice::STATUS_PENDING,
            'error' => null,
        ];

        if ($existing) {
            $existing->update($attributes);

            return $existing->refresh();
        }

        try {
            return CharsleyInvoice::create(['period' => $preview['period']] + $attributes);
        } catch (UniqueConstraintViolationException) {
            return CharsleyInvoice::query()
                ->where('period', $preview['period'])
                ->whereNull('voided_at')
                ->firstOrFail();
        }
    }

    private function email(CharsleyInvoice $invoice): CharsleyInvoice
    {
        try {
            $this->client->emailInvoice((string) $invoice->invoice_ninja_id);
        } catch (InvoiceNinjaException $e) {
            $invoice->update(['error' => $e->getMessage()]);
            Log::warning('Charsley Invoice Ninja email failed', [
                'period' => $invoice->period,
                'invoice_ninja_id' => $invoice->invoice_ninja_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $invoice->update([
            'status' => CharsleyInvoice::STATUS_SENT,
            'error' => null,
            'sent_at' => now(),
        ]);

        return $invoice->refresh();
    }

    /**
     * @param  array{
     *     period: string,
     *     label: string,
     *     year: int,
     *     month: int,
     *     entries: int,
     *     match_entries: int,
     *     match_total: float,
     *     club_entries: int,
     *     club_percent: float,
     *     club_total: float,
     *     membership_payments: int,
     *     membership_percent: float,
     *     membership_total: float,
     *     entry_fee: float,
     *     total: float
     * }  $preview
     * @return array<string, mixed>
     */
    private function payload(array $preview): array
    {
        $invoiceDate = Carbon::create($preview['year'], $preview['month'], 1, 0, 0, 0, 'Africa/Johannesburg')
            ->addMonth()
            ->startOfDay();
        $feeLabel = $this->money($preview['entry_fee']);
        $clubPercentLabel = $this->percentLabel($preview['club_percent']);
        $membershipPercentLabel = $this->percentLabel($preview['membership_percent']);

        $lines = [];

        if ($preview['match_total'] > 0) {
            $matchLabel = $preview['match_entries'] === 1 ? 'paid match entry' : 'paid match entries';
            $lines[] = $this->line(
                'SAPRF match entries',
                $preview['label'].': '.$preview['match_entries'].' '.$matchLabel.' at '.$feeLabel.' each.',
                $preview['match_total'],
                1,
            );
        }

        if ($preview['club_total'] > 0) {
            $clubLabel = $preview['club_entries'] === 1 ? 'club entry' : 'club entries';
            $lines[] = $this->line(
                'SAPRF club entries',
                $preview['label'].': '.$preview['club_entries'].' '.$clubLabel.' at '.$clubPercentLabel.' of the club fee.',
                $preview['club_total'],
                1,
            );
        }

        if ($preview['membership_total'] > 0) {
            $membershipLabel = $preview['membership_payments'] === 1 ? 'membership payment' : 'membership payments';
            $lines[] = $this->line(
                'SAPRF memberships',
                $preview['label'].': '.$preview['membership_payments'].' '.$membershipLabel.' at '.$membershipPercentLabel.' of the payment.',
                $preview['membership_total'],
                1,
            );
        }

        return [
            'client_id' => $this->settings->clientId(),
            'date' => $invoiceDate->toDateString(),
            'due_date' => $invoiceDate->copy()->addDays(CharsleyInvoiceSettings::PAYMENT_TERMS_DAYS)->toDateString(),
            'po_number' => 'SAPRF-'.$preview['period'],
            'public_notes' => 'SAPRF platform for '.$preview['label'].'. Match entries at '.$feeLabel.' each (per-match overrides respected), club entries at '.$clubPercentLabel.' of the club fee, membership payments at '.$membershipPercentLabel.' of the payment. Only transactions that incurred a platform fee are billed.',
            'line_items' => $lines,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(string $key, string $notes, float $cost, int $quantity): array
    {
        return [
            'product_key' => $key,
            'notes' => $notes,
            'cost' => $cost,
            'quantity' => $quantity,
            'tax_name1' => '',
            'tax_rate1' => 0,
            'tax_name2' => '',
            'tax_rate2' => 0,
            'tax_name3' => '',
            'tax_rate3' => 0,
        ];
    }

    private function money(float $amount): string
    {
        $decimals = abs($amount - round($amount)) < 0.001 ? 0 : 2;

        return 'R'.number_format($amount, $decimals, '.', '');
    }

    private function percentLabel(float $percent): string
    {
        $rounded = round($percent, 2);
        $text = abs($rounded - round($rounded)) < 0.001
            ? (string) (int) round($rounded)
            : rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');

        return $text.'%';
    }
}
