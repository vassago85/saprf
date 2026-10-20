<?php

namespace App\Services\InvoiceNinja;

use App\Services\SettingsService;

class CharsleyInvoiceSettings
{
    public const DEFAULT_URL = 'https://invoice.charsley.co.za';

    /**
     * National Rifle and Pistol Association. Never bill this client for SAPRF.
     */
    public const NRAPA_CLIENT_ID = 'VolejRejNm';

    public const DEFAULT_ENTRY_FEE = 50;

    public const PAYMENT_TERMS_DAYS = 30;

    public function __construct(private SettingsService $settings) {}

    public function enabled(): bool
    {
        return filter_var($this->settings->get('invoice_ninja_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    public function apiUrl(): string
    {
        $url = rtrim((string) $this->settings->get('invoice_ninja_api_url', self::DEFAULT_URL), '/');
        $url = preg_replace('#/api/v1$#', '', $url) ?? $url;

        return $url !== '' ? $url : self::DEFAULT_URL;
    }

    public function apiToken(): string
    {
        return trim((string) $this->settings->get('invoice_ninja_api_token', ''));
    }

    public function clientId(): string
    {
        return trim((string) $this->settings->get('invoice_ninja_client_id', ''));
    }

    public function entryFee(): float
    {
        $stored = $this->settings->get('invoice_ninja_entry_fee', self::DEFAULT_ENTRY_FEE);

        return round((float) ($stored === '' || $stored === null ? self::DEFAULT_ENTRY_FEE : $stored), 2);
    }

    public function isConfigured(): bool
    {
        return $this->apiUrl() !== ''
            && $this->apiToken() !== ''
            && $this->clientId() !== ''
            && $this->clientId() !== self::NRAPA_CLIENT_ID;
    }
}
