<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Log;

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

        if ($setting !== null) {
            return (bool) $setting;
        }

        return (bool) config('payfast.sandbox', true);
    }

    public function getFormActionUrl(): string
    {
        return $this->isSandbox()
            ? config('payfast.urls.sandbox')
            : config('payfast.urls.live');
    }

    public function buildPaymentData(Payment $payment, User $user): array
    {
        $data = [
            'merchant_id' => $this->getMerchantId(),
            'merchant_key' => $this->getMerchantKey(),
            'return_url' => route('payments.return', ['m_payment_id' => $payment->m_payment_id]),
            'cancel_url' => route('payments.cancel', ['m_payment_id' => $payment->m_payment_id]),
            'notify_url' => route('payments.notify'),
            'name_first' => $this->extractFirstName($user->name),
            'name_last' => $this->extractLastName($user->name),
            'email_address' => $user->email,
            'm_payment_id' => $payment->m_payment_id,
            'amount' => number_format($payment->amount, 2, '.', ''),
            'item_name' => $this->buildItemName($payment),
            'item_description' => $this->buildItemDescription($payment),
        ];

        $data['signature'] = $this->generateSignature($data);

        return $data;
    }

    public function generateSignature(array $data, ?string $passphrase = null): string
    {
        $passphrase = $passphrase ?? $this->getPassphrase();

        $pfOutput = '';
        foreach ($data as $key => $val) {
            if ($val !== '') {
                $pfOutput .= $key . '=' . urlencode(trim((string) $val)) . '&';
            }
        }

        $getString = substr($pfOutput, 0, -1);

        if (! empty($passphrase)) {
            $getString .= '&passphrase=' . urlencode(trim($passphrase));
        }

        return md5($getString);
    }

    public function validateItnRequest(array $data, string $requestIp): array
    {
        $errors = [];

        if (! $this->validateItnServerIp($requestIp)) {
            $errors[] = 'Invalid source IP: ' . $requestIp;
        }

        $signatureData = $data;
        unset($signatureData['signature']);

        $expectedSignature = $this->generateSignature($signatureData);
        if ($expectedSignature !== ($data['signature'] ?? '')) {
            $errors[] = 'Signature mismatch';
        }

        return $errors;
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
        return (string) ($this->settingsService->get('payfast_merchant_id')
            ?: config('payfast.merchant_id', ''));
    }

    private function getMerchantKey(): string
    {
        return (string) ($this->settingsService->get('payfast_merchant_key')
            ?: config('payfast.merchant_key', ''));
    }

    private function getPassphrase(): string
    {
        return (string) ($this->settingsService->get('payfast_passphrase')
            ?: config('payfast.passphrase', ''));
    }

    private function extractFirstName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName), 2);

        return $parts[0] ?? '';
    }

    private function extractLastName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName), 2);

        return $parts[1] ?? '';
    }

    private function buildItemName(Payment $payment): string
    {
        $payable = $payment->payable;

        if ($payable instanceof \App\Models\MatchRegistration) {
            return 'Match Registration: ' . ($payable->match?->name ?? 'Match #' . $payable->match_id);
        }

        if ($payable instanceof \App\Models\Membership) {
            return 'SAPRF Membership ' . ($payable->saprf_number ?? '');
        }

        return 'SAPRF Payment';
    }

    private function buildItemDescription(Payment $payment): string
    {
        $payable = $payment->payable;

        if ($payable instanceof \App\Models\MatchRegistration) {
            return 'Entry fee for ' . ($payable->match?->name ?? 'match');
        }

        if ($payable instanceof \App\Models\Membership) {
            return 'Annual membership fee';
        }

        return 'Payment to SAPRF';
    }
}
