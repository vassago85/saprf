<?php

use App\Services\SettingsService;
use App\Services\TurnstileService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Clear SettingsService cache so each test's set() values take effect
    // without having to restart the whole kernel.
    \Illuminate\Support\Facades\Cache::forget('saprf_settings');
    config()->set('services.turnstile.site_key', null);
    config()->set('services.turnstile.secret', null);
});

function turnstile(): TurnstileService
{
    return app(TurnstileService::class);
}

function configureTurnstile(string $siteKey = 'site-key', string $secret = 'secret-key'): void
{
    $settings = app(SettingsService::class);
    $settings->set('turnstile_site_key', $siteKey, 'test');
    $settings->set('turnstile_secret', $secret, 'test');
    $settings->set('turnstile_enabled', '1', 'test');
    \Illuminate\Support\Facades\Cache::forget('saprf_settings');
}

test('verify() returns true when Turnstile is not configured', function () {
    // No keys, kill switch off.
    expect(turnstile()->isEnabled())->toBeFalse();
    expect(turnstile()->verify('whatever-token'))->toBeTrue();
    expect(turnstile()->verify(null))->toBeTrue();
    expect(turnstile()->verify(''))->toBeTrue();
});

test('verify() returns true when the kill switch is off even if keys are configured', function () {
    configureTurnstile();
    app(SettingsService::class)->set('turnstile_enabled', '0', 'test');
    \Illuminate\Support\Facades\Cache::forget('saprf_settings');

    expect(turnstile()->isEnabled())->toBeFalse();
    expect(turnstile()->verify('any-token'))->toBeTrue();
});

test('verify() returns false on empty token when Turnstile is enabled', function () {
    configureTurnstile();

    // Must NOT call Cloudflare with an empty token.
    Http::preventStrayRequests();

    expect(turnstile()->verify(''))->toBeFalse();
    expect(turnstile()->verify(null))->toBeFalse();
});

test('verify() returns true when Cloudflare says success', function () {
    configureTurnstile();

    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => true], 200),
    ]);

    expect(turnstile()->verify('good-token', '203.0.113.5'))->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
            && $request['response'] === 'good-token'
            && $request['secret'] === 'secret-key'
            && $request['remoteip'] === '203.0.113.5';
    });
});

test('verify() returns false when Cloudflare says success=false', function () {
    configureTurnstile();

    Http::fake([
        'challenges.cloudflare.com/*' => Http::response([
            'success' => false,
            'error-codes' => ['invalid-input-response'],
        ], 200),
    ]);

    expect(turnstile()->verify('bad-token'))->toBeFalse();
});

test('verify() fails closed when Cloudflare returns 5xx', function () {
    configureTurnstile();

    Http::fake([
        'challenges.cloudflare.com/*' => Http::response('upstream down', 503),
    ]);

    // Fail CLOSED (false) — the alternative is letting a bot that
    // black-holes its own egress to challenges.cloudflare.com bypass
    // the challenge every single time.
    expect(turnstile()->verify('any-token'))->toBeFalse();
});

test('verify() fails closed when the HTTP call throws', function () {
    configureTurnstile();

    Http::fake(function () {
        throw new \Illuminate\Http\Client\ConnectionException('DNS failed');
    });

    expect(turnstile()->verify('any-token'))->toBeFalse();
});

test('siteKey() prefers DB settings over the env config fallback', function () {
    config()->set('services.turnstile.site_key', 'env-site-key');
    expect(turnstile()->siteKey())->toBe('env-site-key');

    configureTurnstile(siteKey: 'db-site-key');
    expect(turnstile()->siteKey())->toBe('db-site-key');
});
