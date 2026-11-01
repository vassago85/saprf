<?php

namespace App\Services\InvoiceNinja;

use App\Models\CharsleyInvoice;
use App\Models\MatchRegistration;
use App\Models\MembershipPayment;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class IssueCharsleyInvoice
{
    public function __construct(
        private InvoiceNinjaClient $client,
        private CharsleyInvoiceSettings $settings,
    ) {}

    /**
     * Paid transactions whose date falls in the calendar month: match
     * registrations by registered_at, and confirmed membership payments by
     * payment_date. Pending, failed, unpaid, waived, and refunded rows are
     * not transactions.
     *
     * @return array{
     *     period: string,
     *     label: string,
     *     year: int,
     *     month: int,
     *     entries: int,
     *     match_entries: int,
     *     membership_payments: int,
     *     club_entries: int,
     *     club_percent: float,
     *     club_total: float,
     *     entry_fee: float,
     *     total: float
     * }
     */
    public function preview(int $year, int $month): array
    {
        $periodStart = Carbon::create($year, $month, 1, 0, 0, 0, 'Africa/Johannesburg');
        $counts = $this->transactions($periodStart);
        $fee = $this->settings->entryFee();
        $flatEntries = $counts['match_entries'] + $counts['membership_payments'];

        return [
            'period' => sprintf('%04d-%02d', $year, $month),
            'label' => $periodStart->format('F Y'),
            'year' => $year,
            'month' => $month,
            'entries' => $flatEntries + $counts['club_entries'],
            'match_entries' => $counts['match_entries'],
            'membership_payments' => $counts['membership_payments'],
            'club_entries' => $counts['club_entries'],
            'club_percent' => $counts['club_percent'],
            'club_total' => $counts['club_total'],
            'entry_fee' => $fee,
            'total' => round(($flatEntries * $fee) + $counts['club_total'], 2),
        ];
    }

    public function issue(int $year, int $month, bool $sendEmail = true): ?CharsleyInvoice
    {
        $this->assertReady();

        $preview = $this->preview($year, $month);
        $existing = CharsleyInvoice::query()->where('period', $preview['period'])->first();

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
     * @return array{match_entries: int, membership_payments: int, club_entries: int, club_percent: float, club_total: float}
     */
    private function transactions(Carbon $periodStart): array
    {
        $start = $periodStart->copy()->startOfMonth();
        $end = $periodStart->copy()->endOfMonth();
        $percent = $this->settings->clubPlatformPercent();

        $matches = MatchRegistration::query()
            ->where('payment_status', 'paid')
            ->whereBetween('registered_at', [$start, $end])
            ->where(function ($query) {
                $query->whereNull('membership_fee_category')
                    ->orWhere('membership_fee_category', '!=', 'club_entry');
            })
            ->count();

        $clubFees = MatchRegistration::query()
            ->where('payment_status', 'paid')
            ->where('membership_fee_category', 'club_entry')
            ->where('platform_fee', '>', 0)
            ->whereBetween('registered_at', [$start, $end])
            ->pluck('fee_amount');

        $clubTotal = round($clubFees->sum(
            fn ($fee) => round((float) $fee * ($percent / 100), 2)
        ), 2);

        $memberships = MembershipPayment::query()
            ->where('status', 'confirmed')
            ->whereBetween('payment_date', [$start->toDateString(), $end->toDateString()])
            ->count();

        return [
            'match_entries' => $matches,
            'membership_payments' => $memberships,
            'club_entries' => $clubFees->count(),
            'club_percent' => $percent,
            'club_total' => $clubTotal,
        ];
    }

    /**
     * @param  array{match_entries: int, membership_payments: int, club_total: float}  $preview
     */
    private function billable(array $preview): bool
    {
        return $preview['match_entries'] > 0
            || $preview['membership_payments'] > 0
            || $preview['club_total'] > 0;
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
     *     membership_payments: int,
     *     club_entries: int,
     *     club_percent: float,
     *     club_total: float,
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
     *     membership_payments: int,
     *     club_entries: int,
     *     club_percent: float,
     *     club_total: float,
     *     entry_fee: float,
     *     total: float
     * }  $preview
     */
    private function claim(array $preview): CharsleyInvoice
    {
        $existing = CharsleyInvoice::query()->where('period', $preview['period'])->first();

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
            return CharsleyInvoice::query()->where('period', $preview['period'])->firstOrFail();
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
     *     membership_payments: int,
     *     club_entries: int,
     *     club_percent: float,
     *     club_total: float,
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
        $percentLabel = $this->percentLabel($preview['club_percent']);

        $lines = [];

        if ($preview['match_entries'] > 0) {
            $matchLabel = $preview['match_entries'] === 1 ? 'paid match entry' : 'paid match entries';
            $lines[] = $this->line(
                'SAPRF match entries',
                $preview['label'].': '.$preview['match_entries'].' '.$matchLabel.' at '.$feeLabel.' each.',
                $preview['entry_fee'],
                $preview['match_entries'],
            );
        }

        if ($preview['club_total'] > 0) {
            $clubLabel = $preview['club_entries'] === 1 ? 'club entry' : 'club entries';
            $lines[] = $this->line(
                'SAPRF club entries',
                $preview['label'].': '.$preview['club_entries'].' '.$clubLabel.' at '.$percentLabel.' of the club fee.',
                $preview['club_total'],
                1,
            );
        }

        if ($preview['membership_payments'] > 0) {
            $membershipLabel = $preview['membership_payments'] === 1 ? 'membership payment' : 'membership payments';
            $lines[] = $this->line(
                'SAPRF memberships',
                $preview['label'].': '.$preview['membership_payments'].' '.$membershipLabel.' at '.$feeLabel.' each.',
                $preview['entry_fee'],
                $preview['membership_payments'],
            );
        }

        return [
            'client_id' => $this->settings->clientId(),
            'date' => $invoiceDate->toDateString(),
            'due_date' => $invoiceDate->copy()->addDays(CharsleyInvoiceSettings::PAYMENT_TERMS_DAYS)->toDateString(),
            'po_number' => 'SAPRF-'.$preview['period'],
            'public_notes' => 'SAPRF platform for '.$preview['label'].'. '.$feeLabel.' for each match entry and membership payment. Club entries at '.$percentLabel.' of the club fee.',
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
