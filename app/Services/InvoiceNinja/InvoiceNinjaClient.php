<?php

namespace App\Services\InvoiceNinja;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class InvoiceNinjaClient
{
    public function __construct(private CharsleyInvoiceSettings $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function client(string $clientId): array
    {
        $response = $this->send('get', '/api/v1/clients/'.rawurlencode($clientId));

        return $response->json('data') ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createInvoice(array $payload): array
    {
        $response = $this->send('post', '/api/v1/invoices', $payload);

        return $response->json('data') ?? [];
    }

    public function emailInvoice(string $invoiceId): void
    {
        $this->send('post', '/api/v1/invoices/bulk', [
            'action' => 'email',
            'ids' => [$invoiceId],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function send(string $method, string $path, ?array $payload = null): Response
    {
        if (! $this->settings->isConfigured()) {
            throw new InvoiceNinjaException('Invoice Ninja is not configured. Add the API URL, token, and SAPRF client ID in Site Settings.');
        }

        if ($this->settings->clientId() === CharsleyInvoiceSettings::NRAPA_CLIENT_ID) {
            throw new InvoiceNinjaException('That Invoice Ninja client belongs to National Rifle and Pistol Association. Create a SAPRF client and save its id.');
        }

        $request = Http::withHeaders([
            'X-API-TOKEN' => $this->settings->apiToken(),
            'X-Requested-With' => 'XMLHttpRequest',
        ])->acceptJson()->asJson()->timeout(30);

        $url = $this->settings->apiUrl().$path;
        $response = $payload === null
            ? $request->{$method}($url)
            : $request->{$method}($url, $payload);

        if ($response->failed()) {
            throw new InvoiceNinjaException($this->errorMessage($response));
        }

        return $response;
    }

    private function errorMessage(Response $response): string
    {
        $body = $response->json();
        $message = is_array($body) ? ($body['message'] ?? null) : null;

        if (is_string($message) && $message !== '') {
            return 'Invoice Ninja rejected the request: '.$message;
        }

        return 'Invoice Ninja returned HTTP '.$response->status().'.';
    }
}
