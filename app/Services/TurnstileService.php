<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cloudflare Turnstile verification. Turnstile is Cloudflare's privacy-first
 * CAPTCHA replacement — invisible for most real users, surfaces a one-click
 * checkbox when the visitor looks bot-y, and leaks zero PII to Google (which
 * matters for POPIA).
 *
 * Keys live in Site Settings (owner UI) with .env fallback. Three keys:
 *   turnstile_site_key   — public, baked into the widget's data-sitekey
 *   turnstile_secret     — private, sent server-to-server in verify()
 *   turnstile_enabled    — kill switch so we can disable the challenge
 *                          without wiping the keys when debugging a bug
 *
 * Graceful degradation: when Turnstile is not configured (empty keys OR the
 * kill switch is off), verify() returns true for every token. This means
 * forms built against the Turnstile component keep working on local Laragon
 * / fresh clones that don't have CF keys plumbed in. Production enforces the
 * challenge the moment keys are pasted into Site Settings.
 */
class TurnstileService
{
    private const VERIFY_ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private const VERIFY_TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * True when Turnstile is configured AND the admin kill switch is on.
     * Any form that doesn't want to surface the widget when disabled should
     * gate its render on this.
     */
    public function isEnabled(): bool
    {
        if (! (bool) $this->settings->get('turnstile_enabled', true)) {
            return false;
        }

        return $this->siteKey() !== '' && $this->secretKey() !== '';
    }

    /**
     * Public site key — safe to render into HTML. Returns empty string when
     * not configured, so the Blade component can skip rendering the widget
     * instead of emitting an invalid "data-sitekey=".
     */
    public function siteKey(): string
    {
        $db = (string) $this->settings->get('turnstile_site_key', '');
        if ($db !== '') {
            return $db;
        }

        return (string) config('services.turnstile.site_key', '');
    }

    /**
     * Private secret key. NEVER render this into HTML or logs.
     */
    public function secretKey(): string
    {
        $db = (string) $this->settings->get('turnstile_secret', '');
        if ($db !== '') {
            return $db;
        }

        return (string) config('services.turnstile.secret', '');
    }

    /**
     * Verify a client-side Turnstile token with Cloudflare's siteverify
     * endpoint. Returns true when the token is valid, OR when Turnstile is
     * not configured at all (graceful degradation — see class docblock).
     *
     * Any transport error (timeout, DNS, 5xx from Cloudflare) is logged and
     * returns FALSE so a Cloudflare outage fails closed on protected forms.
     * The alternative — fail open — would let a bot bypass the challenge
     * every time it managed to black-hole its own outbound HTTPS to
     * challenges.cloudflare.com.
     *
     * @param  string|null  $token      The `cf-turnstile-response` from the form.
     * @param  string|null  $remoteIp   Optional — included in the verify call
     *                                  so Cloudflare's heuristics can tie the
     *                                  challenge to the submitting IP.
     */
    public function verify(?string $token, ?string $remoteIp = null): bool
    {
        if (! $this->isEnabled()) {
            return true;
        }

        if ($token === null || trim($token) === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(self::VERIFY_TIMEOUT_SECONDS)
                ->post(self::VERIFY_ENDPOINT, array_filter([
                    'secret' => $this->secretKey(),
                    'response' => $token,
                    'remoteip' => $remoteIp,
                ]));
        } catch (Throwable $e) {
            Log::warning('Turnstile verify transport error — treating as failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Turnstile verify non-2xx — treating as failed', [
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 500),
            ]);

            return false;
        }

        $body = $response->json();
        $success = (bool) ($body['success'] ?? false);

        if (! $success) {
            // Not noisy — a legitimate "failed challenge" from a real bot
            // ends up here on every single try and we don't want to flood
            // logs. Debug level keeps it available during an incident.
            Log::debug('Turnstile verify returned success=false', [
                'error-codes' => $body['error-codes'] ?? [],
            ]);
        }

        return $success;
    }
}
