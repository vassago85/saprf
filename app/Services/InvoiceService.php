<?php

namespace App\Services;

use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\Payment;
use Illuminate\Support\Carbon;

/**
 * Shapes a completed Payment into the view model the invoice PDF (and the
 * /invoices listing page) consume.
 *
 * Why this lives behind a service:
 *   - The invoice header flips between "Invoice" and "Tax Invoice" based on
 *     whether SAPRF has a VAT number configured in Site Settings. Keeping
 *     the switch here means the Blade view just reads $isTaxInvoice.
 *   - The line-item label depends on the polymorphic payable type
 *     (MatchRegistration vs Membership). Views shouldn't be branching on
 *     model classes.
 *   - The listing page needs the same label resolution ("what did I pay
 *     for?") so pulling it into one service avoids drift.
 *
 * VAT handling: as of this feature SAPRF is NOT VAT-registered, so the
 * default is a plain "Invoice" with no VAT breakdown and an explicit
 * footer line saying so. The moment an admin saves a `invoice_vat_number`
 * in Site Settings the document flips to a VAT-compliant tax invoice at
 * 15% (SA standard rate), inclusive of the stored amount — PayFast
 * charges the gross amount and we have to break the VAT back out of it.
 */
class InvoiceService
{
    /** South African VAT standard rate. */
    private const VAT_RATE = 0.15;

    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * View model for one invoice. Expects a completed payment; the caller
     * is responsible for the authorization check and the "is this
     * invoiceable" filter.
     *
     * @return array{
     *     payment: Payment,
     *     invoice_number: string,
     *     issued_at: Carbon,
     *     is_tax_invoice: bool,
     *     business: array{name: string, address: ?string, registration_number: ?string, vat_number: ?string, contact_email: ?string},
     *     logo_data_uri: ?string,
     *     bill_to: array{name: string, email: ?string, saprf_number: ?string},
     *     line_item: array{description: string, detail: ?string},
     *     totals: array{subtotal: float, vat: float, total: float},
     *     payment_method: string,
     *     footer_notes: ?string
     * }
     */
    public function buildViewModel(Payment $payment): array
    {
        $payment->loadMissing(['user', 'payable']);

        $business = $this->businessDetails();
        $isTaxInvoice = ! empty($business['vat_number']);
        $billTo = $this->resolveBillTo($payment);
        $lineItem = $this->resolveLineItem($payment);
        $totals = $this->resolveTotals((float) $payment->amount, $isTaxInvoice);
        $issuedAt = $payment->paid_at ?? $payment->updated_at ?? now();

        return [
            'payment' => $payment,
            // Reuse m_payment_id as the invoice number. It is unique on the
            // payments table, prefixed by payable type (MEM/REG/CRD), and
            // already appears on the matching PayFast / credit statement —
            // so finance can reconcile without a second ID to look up.
            'invoice_number' => (string) $payment->m_payment_id,
            'issued_at' => Carbon::parse($issuedAt),
            'is_tax_invoice' => $isTaxInvoice,
            'business' => $business,
            'logo_data_uri' => $this->logoDataUri(),
            'bill_to' => $billTo,
            'line_item' => $lineItem,
            'totals' => $totals,
            'payment_method' => $this->paymentMethodLabel($payment),
            'footer_notes' => $this->footerNotes($isTaxInvoice),
        ];
    }

    /**
     * A completed payment with a payable we know how to describe. Pending,
     * failed, or cancelled payments are intentionally excluded — those
     * aren't invoiceable transactions.
     */
    public function isInvoiceable(Payment $payment): bool
    {
        if ($payment->status !== 'completed') {
            return false;
        }

        if ((float) $payment->amount <= 0) {
            return false;
        }

        $payment->loadMissing('payable');

        return $payment->payable instanceof MatchRegistration
            || $payment->payable instanceof Membership;
    }

    /**
     * SAPRF's registered legal details, taken verbatim from the Terms &
     * Conditions ("'we' or 'us' refers to … a Non Profit Company
     * registration – 2019/030615/08"). These are deliberately hardcoded
     * so a fresh install produces a legally correct invoice without any
     * Site Settings seeding; the Owner can still override any one of
     * them from Site Settings → Invoice details.
     */
    private const DEFAULT_BUSINESS_NAME = 'South African Practical Precision Rifle Federation (NPC)';

    private const DEFAULT_BUSINESS_ADDRESS = "186 Olympic Duel, Northlands Business Park\nNewmarket St, North Riding\nRandburg, Gauteng, South Africa";

    private const DEFAULT_REGISTRATION_NUMBER = '2019/030615/08';

    /**
     * @return array{name: string, address: ?string, registration_number: ?string, vat_number: ?string, contact_email: ?string}
     */
    private function businessDetails(): array
    {
        return [
            'name' => $this->settingValue('invoice_business_name')
                ?? self::DEFAULT_BUSINESS_NAME,
            'address' => $this->settingValue('invoice_business_address')
                ?? self::DEFAULT_BUSINESS_ADDRESS,
            'registration_number' => $this->settingValue('invoice_registration_number')
                ?? self::DEFAULT_REGISTRATION_NUMBER,
            'vat_number' => $this->settingValue('invoice_vat_number'),
            'contact_email' => $this->settingValue('invoice_contact_email')
                ?? $this->settings->secretaryEmail()
                ?? $this->settings->ownerEmail(),
        ];
    }

    /**
     * The person the invoice is billed to is the one who paid — not
     * necessarily the shooter the registration/membership is for. Sponsor
     * paying for someone else's entry gets the invoice in their own name
     * because they're the one who parted with the money.
     *
     * @return array{name: string, email: ?string, saprf_number: ?string}
     */
    private function resolveBillTo(Payment $payment): array
    {
        $payer = $payment->user;

        return [
            'name' => $payer?->name ?? 'Unknown payer',
            'email' => $payer?->email,
            // Membership number only makes sense when it's the payer's own
            // membership transaction. For a match entry (or a sponsored
            // registration) we skip it to avoid putting a stranger's SAPRF
            // number on the sponsor's invoice.
            'saprf_number' => $this->payerSaprfNumber($payment),
        ];
    }

    private function payerSaprfNumber(Payment $payment): ?string
    {
        $payer = $payment->user;

        if ($payer === null) {
            return null;
        }

        return $payer->membership?->saprf_number;
    }

    /**
     * @return array{description: string, detail: ?string}
     */
    private function resolveLineItem(Payment $payment): array
    {
        $payable = $payment->payable;

        if ($payable instanceof MatchRegistration) {
            $payable->loadMissing(['match', 'division', 'user']);
            $match = $payable->match;
            $description = $match
                ? "Match entry — {$match->name}"
                : 'Match entry';

            $parts = array_filter([
                $payable->division?->name,
                $payable->feeCategoryLabel() !== '—' ? $payable->feeCategoryLabel() : null,
                $match?->match_date?->format('d M Y'),
                // When the payer isn't the shooter, name the shooter so
                // the invoice reads clearly ("you paid R850 for X").
                $payable->user && $payable->user_id !== $payment->user_id
                    ? 'Shooter: '.$payable->user->name
                    : null,
            ]);

            return [
                'description' => $description,
                'detail' => $parts === [] ? null : implode(' · ', $parts),
            ];
        }

        if ($payable instanceof Membership) {
            $payable->loadMissing(['feeTier', 'user']);
            $tierName = $payable->feeTier?->name ?? 'Annual';
            $description = "SAPRF Membership — {$tierName}";

            // Period this specific payment covered. `start_date` on the
            // Membership is the *lifetime* start of the record and
            // doesn't change across renewals, so it would show "from
            // 2024" on every renewal invoice forever. The correct period
            // is `expiry_date - fee_tier.duration_months → expiry_date`
            // — i.e. the twelve months (or whatever the tier length is)
            // that this payment just bought.
            $durationMonths = $payable->feeTier?->duration_months ?? 12;
            $periodEnd = $payable->expiry_date;
            $periodStart = $periodEnd?->copy()->subMonths($durationMonths);
            // Plain " to " rather than a Unicode arrow — DomPDF's
            // default WinAnsi font set renders U+2192 as `?`.
            $periodLabel = $periodStart && $periodEnd
                ? $periodStart->format('d M Y').' to '.$periodEnd->format('d M Y')
                : null;

            $parts = array_filter([
                $payable->saprf_number ? 'SAPRF #'.$payable->saprf_number : null,
                $periodLabel,
                $payable->user && $payable->user_id !== $payment->user_id
                    ? 'Member: '.$payable->user->name
                    : null,
            ]);

            return [
                'description' => $description,
                'detail' => $parts === [] ? null : implode(' · ', $parts),
            ];
        }

        // Shouldn't reach here — isInvoiceable() guards it — but fall
        // back to a sensible generic label rather than crashing the PDF.
        return [
            'description' => 'SAPRF transaction',
            'detail' => $payment->m_payment_id,
        ];
    }

    /**
     * @return array{subtotal: float, vat: float, total: float}
     */
    private function resolveTotals(float $gross, bool $isTaxInvoice): array
    {
        if (! $isTaxInvoice) {
            return [
                'subtotal' => round($gross, 2),
                'vat' => 0.0,
                'total' => round($gross, 2),
            ];
        }

        // Amount stored is VAT-inclusive (PayFast charges the gross). Pull
        // the VAT portion out of it so the invoice shows subtotal + VAT.
        $vat = round($gross * self::VAT_RATE / (1 + self::VAT_RATE), 2);

        return [
            'subtotal' => round($gross - $vat, 2),
            'vat' => $vat,
            'total' => round($gross, 2),
        ];
    }

    private function paymentMethodLabel(Payment $payment): string
    {
        return match ($payment->gateway) {
            'account_credit' => 'Account credit',
            'payfast' => 'PayFast (card)',
            default => (string) ($payment->gateway ?: 'Online payment'),
        };
    }

    /**
     * Footer text under the totals block. Returns the custom admin-set
     * line verbatim when present, otherwise a short VAT-inclusive note
     * on tax invoices, otherwise nothing. We deliberately do NOT render
     * a "this is not a VAT invoice" disclaimer by default — the absence
     * of a VAT line is self-evident and SAPRF prefers an uncluttered
     * footer.
     */
    private function footerNotes(bool $isTaxInvoice): ?string
    {
        $custom = $this->settingValue('invoice_footer_notes');

        if ($custom !== null) {
            return $custom;
        }

        return $isTaxInvoice
            ? 'VAT @ 15% is inclusive of the amount shown.'
            : null;
    }

    /**
     * Trim and null-out blank settings so the view only has to check for null.
     */
    private function settingValue(string $key): ?string
    {
        $value = $this->settings->get($key);

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Base64-encoded SAPRF logo for inline embedding in the PDF. We cannot
     * reference `/saprf-logo-black-text.png` by URL because DomPDF runs
     * with `isRemoteEnabled = false` — so the image is read off disk here
     * and inlined as a data URI, mirroring the pattern already used for
     * the membership certificate's logo. Returns null when the file is
     * missing so a broken public dir degrades gracefully (invoice renders
     * without a logo rather than 500ing).
     */
    private function logoDataUri(): ?string
    {
        $path = public_path('saprf-logo-black-text.png');

        if (! is_file($path)) {
            return null;
        }

        $binary = @file_get_contents($path);

        if ($binary === false || $binary === '') {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($binary);
    }
}
