<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\User;

class PayFastService
{
    public function __construct(
        private readonly SettingsService $settingsService,
    ) {}

    public function isConfigured(): bool
    {
        $merchantId = $this->getMerchantId();
        $merchantKey = $this->getMerchantKey();

        return ! empty($merchantId) && ! empty($merchantKey);
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settingsService->get('payments_enabled', '0')
            && $this->isConfigured();
    }

    public function isSandbox(): bool
    {
        $setting = $this->settingsService->get('payfast_sandbox');

        if ($setting !== null && $setting !== '') {
            return filter_var($setting, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) config('payfast.sandbox', true);
    }

    public function getFormActionUrl(): string
    {
        return $this->isSandbox()
            ? config('payfast.urls.sandbox')
            : config('payfast.urls.live');
    }

    /**
     * Build the PayFast checkout payload (non-blank fields only) + signature.
     *
     * Empty fields are omitted entirely — PayFast regenerates the signature from
     * submitted fields and blank inputs cause "signature does not match" errors.
     */
    public function buildPaymentData(Payment $payment, User $user): array
    {
        $firstName = $this->extractFirstName($user->name);
        $lastName = $this->extractLastName($user->name);

        // PayFast buyer fields: if only one name part exists, put it in name_first
        // and leave name_last out (blank fields must not be posted).
        $data = [
            'merchant_id' => $this->getMerchantId(),
            'merchant_key' => $this->getMerchantKey(),
            'return_url' => route('payments.return', ['m_payment_id' => $payment->m_payment_id]),
            'cancel_url' => route('payments.cancel', ['m_payment_id' => $payment->m_payment_id]),
            'notify_url' => route('payments.notify'),
            'name_first' => $firstName !== '' ? $firstName : 'Shooter',
            'name_last' => $lastName,
            'email_address' => (string) $user->email,
            'm_payment_id' => $payment->m_payment_id,
            'amount' => number_format((float) $payment->amount, 2, '.', ''),
            'item_name' => $this->buildItemName($payment),
            'item_description' => $this->buildItemDescription($payment),
        ];

        // Drop blanks before signing and before the form is rendered.
        $data = array_filter($data, fn ($val) => $val !== null && trim((string) $val) !== '');

        $data['signature'] = $this->generateSignature($data);

        return $data;
    }

    /**
     * PayFast custom-integration signature (attribute order, not alphabetical).
     *
     * @see https://developers.payfast.co.za/docs#step_2_signature
     */
    public function generateSignature(array $data, ?string $passphrase = null): string
    {
        $passphrase = $passphrase ?? $this->getPassphrase();

        unset($data['signature']);

        $pfOutput = '';
        foreach ($data as $key => $val) {
            $val = trim(stripslashes((string) $val));
            if ($val !== '') {
                $pfOutput .= $key.'='.urlencode($val).'&';
            }
        }

        $getString = rtrim($pfOutput, '&');

        if ($passphrase !== null && trim($passphrase) !== '') {
            $getString .= '&passphrase='.urlencode(trim(stripslashes($passphrase)));
        }

        return md5($getString);
    }

    /**
     * Validate an Instant Transaction Notification (ITN) from PayFast.
     *
     * ITN signatures are built differently from checkout signatures: every posted
     * field up to (but not including) `signature` is included — even blanks —
     * and parameter order is the order PayFast posted them.
     *
     * @see https://developers.payfast.co.za/docs#step_3_confirm_payment
     */
    public function validateItnRequest(array $data, string $requestIp): array
    {
        $errors = [];

        if (! $this->validateItnServerIp($requestIp)) {
            $errors[] = 'Invalid source IP: '.$requestIp;
        }

        if (! $this->itnSignatureIsValid($data)) {
            $errors[] = 'Signature mismatch';
        }

        return $errors;
    }

    /**
     * Accept either the official ITN signature (includes blanks) or the
     * checkout-style signature (skips blanks) — PayFast/sandbox has been
     * observed to vary.
     */
    public function itnSignatureIsValid(array $data): bool
    {
        $provided = (string) ($data['signature'] ?? '');
        if ($provided === '') {
            return false;
        }

        if (hash_equals($this->generateItnSignature($data), $provided)) {
            return true;
        }

        $withoutSignature = $data;
        unset($withoutSignature['signature']);

        return hash_equals($this->generateSignature($withoutSignature), $provided);
    }

    /**
     * Build + hash the ITN parameter string exactly as PayFast documents.
     */
    public function generateItnSignature(array $pfData, ?string $passphrase = null): string
    {
        $passphrase ??= $this->getPassphrase();

        $normalized = [];
        foreach ($pfData as $key => $val) {
            $normalized[$key] = stripslashes((string) $val);
        }

        $pfParamString = '';
        foreach ($normalized as $key => $val) {
            if ($key === 'signature') {
                break;
            }
            $pfParamString .= $key.'='.urlencode($val).'&';
        }
        $pfParamString = rtrim($pfParamString, '&');

        if ($passphrase !== null && $passphrase !== '') {
            $pfParamString .= '&passphrase='.urlencode($passphrase);
        }

        return md5($pfParamString);
    }

    public function validateItnServerIp(string $ip): bool
    {
        if ($this->isSandbox()) {
            return true;
        }

        $validHosts = config('payfast.valid_hosts', []);
        $validIps = [];

        foreach ($validHosts as $host) {
            $ips = gethostbynamel($host);
            if ($ips) {
                $validIps = array_merge($validIps, $ips);
            }
        }

        return in_array($ip, array_unique($validIps), true);
    }

    private function getMerchantId(): string
    {
        return trim((string) ($this->settingsService->get('payfast_merchant_id')
            ?: config('payfast.merchant_id', '')));
    }

    private function getMerchantKey(): string
    {
        return trim((string) ($this->settingsService->get('payfast_merchant_key')
            ?: config('payfast.merchant_key', '')));
    }

    private function getPassphrase(): string
    {
        return trim((string) ($this->settingsService->get('payfast_passphrase')
            ?: config('payfast.passphrase', '')));
    }

    private function extractFirstName(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName), 2) ?: [];

        return $parts[0] ?? '';
    }

    private function extractLastName(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName), 2) ?: [];

        return $parts[1] ?? '';
    }

    private function buildItemName(Payment $payment): string
    {
        $payable = $payment->payable;

        if ($payable instanceof \App\Models\MatchRegistration) {
            return 'Match Registration: '.($payable->match?->name ?? 'Match #'.$payable->match_id);
        }

        if ($payable instanceof \App\Models\Membership) {
            return 'SAPRF Membership '.($payable->saprf_number ?? '');
        }

        return 'SAPRF Payment';
    }

    private function buildItemDescription(Payment $payment): string
    {
        $payable = $payment->payable;

        if ($payable instanceof \App\Models\MatchRegistration) {
            return 'Entry fee for '.($payable->match?->name ?? 'match');
        }

        if ($payable instanceof \App\Models\Membership) {
            return 'Annual membership fee';
        }

        return 'Payment to SAPRF';
    }
}
