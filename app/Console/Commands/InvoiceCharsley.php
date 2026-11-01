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

    protected $description = 'Invoice SAPRF in Invoice Ninja for paid transactions in a month';

    public function handle(IssueCharsleyInvoice $issuer, CharsleyInvoiceSettings $settings): int
    {
        $period = $this->argument('period') ?: now()->timezone('Africa/Johannesburg')->startOfMonth()->subMonthNoOverflow()->format('Y-m');

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $period)) {
            $this->error('Period must be YYYY-MM.');

            return self::FAILURE;
        }

        [$year, $month] = array_map('intval', explode('-', (string) $period));
        $preview = $issuer->preview($year, $month);

        $this->info(sprintf(
            '%s: %d match (R%s) + %d club (R%s) + %d membership (R%s) = R%s',
            $preview['label'],
            $preview['match_entries'],
            number_format($preview['match_total'], 2),
            $preview['club_entries'],
            number_format($preview['club_total'], 2),
            $preview['membership_payments'],
            number_format($preview['membership_total'], 2),
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
