<?php

namespace App\Services;

use App\Models\Payout;
use Illuminate\Contracts\Support\Responsable;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\Facades\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Render an MD payout statement to PDF or CSV from the frozen snapshot.
 *
 * The statement reads exclusively from the payout's `items` relation and
 * `statement_snapshot` JSON, never from live match/registration data —
 * downstream edits or refunds therefore cannot change a previously
 * emitted statement unless the admin explicitly regenerates it.
 */
class PayoutStatementRenderer
{
    /**
     * Render the statement to a downloadable PDF response.
     *
     * Returns Responsable, not Illuminate\Http\Response: Spatie's PdfBuilder
     * is chainable and only turns itself into a BinaryFileResponse inside
     * `toResponse()` when the router unpacks it. A stricter return type
     * crashes the request with a TypeError before any PDF bytes get built.
     */
    public function pdfDownload(Payout $payout): Responsable
    {
        return $this->pdf($payout)->download($this->filename($payout, 'pdf'));
    }

    /**
     * Render the statement to disk and return the absolute path.
     * Used by {@see \App\Notifications\MdPayoutStatementNotification}.
     */
    public function pdfToTempFile(Payout $payout): string
    {
        $path = tempnam(sys_get_temp_dir(), 'saprf_payout_') . '.pdf';
        $this->pdf($payout)->save($path);

        return $path;
    }

    public function csvDownload(Payout $payout): StreamedResponse
    {
        $filename = $this->filename($payout, 'csv');

        return response()->streamDownload(function () use ($payout): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, [
                'Line',
                'Shooter',
                'Category',
                'Payment Status',
                'Payment Method',
                'Fee (R)',
                'Surcharge (R)',
                'SAPRF (R)',
                'Platform (R)',
                'Gateway (R)',
                'MD Net (R)',
                'Excluded',
                'Excluded Reason',
            ]);

            foreach ($payout->items()->orderBy('sort_order')->get() as $i => $item) {
                fputcsv($out, [
                    $i + 1,
                    $item->shooter_name ?? $item->description,
                    $item->membership_fee_category,
                    $item->payment_status,
                    $item->methodLabel(),
                    number_format((float) $item->gross_amount, 2, '.', ''),
                    number_format((float) $item->surcharge_amount, 2, '.', ''),
                    number_format((float) $item->saprf_fee, 2, '.', ''),
                    number_format((float) $item->platform_fee, 2, '.', ''),
                    number_format((float) $item->gateway_fee, 2, '.', ''),
                    number_format((float) $item->net_amount, 2, '.', ''),
                    $item->excluded ? 'Yes' : 'No',
                    $item->excluded_reason,
                ]);
            }

            // Blank then totals row for easy spreadsheet reconciliation.
            fputcsv($out, []);
            $summary = $payout->statement_snapshot['summary'] ?? [];
            fputcsv($out, [
                'TOTALS (paid only)',
                '', '', '', '',
                number_format((float) ($summary['gross_revenue'] ?? 0), 2, '.', ''),
                number_format((float) ($summary['surcharges'] ?? 0), 2, '.', ''),
                number_format((float) ($summary['saprf_fees'] ?? 0), 2, '.', ''),
                number_format((float) ($summary['platform_fees'] ?? 0), 2, '.', ''),
                number_format((float) ($summary['gateway_fees'] ?? 0), 2, '.', ''),
                number_format((float) ($summary['md_net'] ?? 0), 2, '.', ''),
                '', '',
            ]);
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function pdf(Payout $payout)
    {
        $items = $payout->items()->orderBy('sort_order')->get();
        $paidItems = $items->where('excluded', false)->values();
        $excludedItems = $items->where('excluded', true)->values();
        $snapshot = $payout->statement_snapshot ?? [];
        $payments = $payout->paymentHistory();

        return Pdf::view('financials.pdf.payout-statement', [
            'payout' => $payout,
            'snapshot' => $snapshot,
            'match' => $snapshot['match'] ?? null,
            'summary' => $snapshot['summary'] ?? [],
            'paidItems' => $paidItems,
            'excludedItems' => $excludedItems,
            'payments' => $payments,
            'outstanding' => $payout->outstandingBalance(),
            'generatedAt' => now(),
        ])->format(Format::A4);
    }

    private function filename(Payout $payout, string $ext): string
    {
        $match = $payout->match?->name
            ? preg_replace('/[^A-Za-z0-9\-_]+/', '-', $payout->match->name)
            : 'match';

        return "{$payout->reference}-{$match}.{$ext}";
    }
}
