<?php

namespace App\Services\InvoiceNinja;

use App\Models\CharsleyInvoice;
use App\Models\MatchRegistration;
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
     * Paid match entries whose registered_at falls in the calendar month.
     * Pending, failed, unpaid, waived, and refunded rows are not transactions.
     *
     * @return array{
     *     period: string,
     *     label: string,
     *     year: int,
     *     month: int,
     *     entries: int,
     *     entry_fee: float,
     *     total: float
     * }
     */
    public function preview(int $year, int $month): array
    {
        $periodStart = Carbon::create($year, $month, 1, 0, 0, 0, 'Africa/Johannesburg');
        $entries = $this->paidEntries($periodStart);
        $fee = $this->settings->entryFee();

        return [
            'period' => sprintf('%04d-%02d', $year, $month),
            'label' => $periodStart->format('F Y'),
            'year' => $year,
            'month' => $month,
            'entries' => $entries,
            'entry_fee' => $fee,
            'total' => round($entries * $fee, 2),
        ];
    }

    public function issue(int $year, int $month, bool $sendEmail = true): ?CharsleyInvoice
    {
        $this->assertReady();

        $preview = $this->preview($year, $month);
        $existing = CharsleyInvoice::query()->where('period', $preview['period'])->first();

        if ($preview['entries'] === 0 && ! $existing?->invoice_ninja_id) {
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

    private function paidEntries(Carbon $periodStart): int
    {
        $start = $periodStart->copy()->startOfMonth();
        $end = $periodStart->copy()->endOfMonth();

        return MatchRegistration::query()
            ->where('payment_status', 'paid')
            ->whereBetween('registered_at', [$start, $end])
            ->count();
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

        if ($preview['entries'] === 0) {
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
        $entryLabel = $preview['entries'] === 1 ? 'paid entry' : 'paid entries';

        return [
            'client_id' => $this->settings->clientId(),
            'date' => $invoiceDate->toDateString(),
            'due_date' => $invoiceDate->copy()->addDays(CharsleyInvoiceSettings::PAYMENT_TERMS_DAYS)->toDateString(),
            'po_number' => 'SAPRF-'.$preview['period'],
            'public_notes' => 'SAPRF platform for '.$preview['label'].'. '.$feeLabel.' for each paid entry in the month.',
            'line_items' => [
                $this->line(
                    'SAPRF entries',
                    $preview['label'].': '.$preview['entries'].' '.$entryLabel.' at '.$feeLabel.' each.',
                    $preview['entry_fee'],
                    $preview['entries'],
                ),
            ],
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
}
