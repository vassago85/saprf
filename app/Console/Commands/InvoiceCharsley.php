<?php

namespace App\Console\Commands;

use App\Services\InvoiceNinja\CharsleyInvoiceSettings;
use App\Services\InvoiceNinja\InvoiceNinjaException;
use App\Services\InvoiceNinja\IssueCharsleyInvoice;
use Illuminate\Console\Command;

class InvoiceCharsley extends Command
{
    protected $signature = 'saprf:invoice-charsley
        {period? : Month to invoice, as YYYY-MM. Defaults to the previous month.}
        {--dry-run : Print the invoice without sending it}
        {--no-email : Create the invoice in Invoice Ninja without emailing it}';

    protected $description = 'Invoice SAPRF in Invoice Ninja for paid match entries in a month';

    public function handle(IssueCharsleyInvoice $issuer, CharsleyInvoiceSettings $settings): int
    {
        $period = $this->argument('period') ?: now()->timezone('Africa/Johannesburg')->subMonth()->format('Y-m');

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $period)) {
            $this->error('Period must be YYYY-MM.');

            return self::FAILURE;
        }

        [$year, $month] = array_map('intval', explode('-', (string) $period));
        $preview = $issuer->preview($year, $month);

        $entryLabel = $preview['entries'] === 1 ? 'entry' : 'entries';

        $this->info(sprintf(
            '%s: %d %s × R%s = R%s',
            $preview['label'],
            $preview['entries'],
            $entryLabel,
            number_format($preview['entry_fee'], 2),
            number_format($preview['total'], 2),
        ));

        if ($this->option('dry-run')) {
            $this->comment('Dry run. Nothing was sent to Invoice Ninja.');

            return self::SUCCESS;
        }

        if (! $settings->enabled()) {
            $this->comment('Charsley invoicing is turned off. Enable it in Site Settings.');

            return self::SUCCESS;
        }

        try {
            if (! $this->option('no-email')) {
                $retried = $issuer->retryUnsent();
                if ($retried > 0) {
                    $this->info("Emailed {$retried} invoice(s) that had been created but not sent.");
                }
            }

            $invoice = $issuer->issue($year, $month, sendEmail: ! $this->option('no-email'));
        } catch (InvoiceNinjaException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($invoice === null) {
            $this->comment('No paid entries for '.$preview['label'].'. Nothing was sent.');

            return self::SUCCESS;
        }

        $number = $invoice->invoice_number ?: $invoice->invoice_ninja_id;
        $this->info('Invoice '.$number.' is '.$invoice->status.' for '.$invoice->period.' (R'.number_format((float) $invoice->total, 2).').');

        return self::SUCCESS;
    }
}
