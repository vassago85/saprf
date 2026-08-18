<?php

/**
 * Level 1 PWA smoke tests.
 *
 * These lock down the small set of moving parts that make the SAPRF site
 * installable on Android / iOS home screens: the manifest file, the
 * service worker, the icons, and the meta tags in the head. Static asset
 * routing is handled by nginx in production and by php artisan serve
 * locally, so we test the artefacts themselves rather than hitting them
 * over HTTP.
 */

it('serves a valid web manifest file with all Level 1 PWA required keys', function () {
    $path = public_path('manifest.webmanifest');
    expect(file_exists($path))->toBeTrue();

    $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    // Required by the Web App Manifest spec + browser install prompts.
    expect($manifest)->toHaveKeys(['name', 'short_name', 'start_url', 'display', 'icons', 'theme_color', 'background_color']);
    expect($manifest['display'])->toBe('standalone');
    // start_url carries ?source=pwa so the / route can redirect an installed
    // launch to the app dashboard for authenticated members. See routes/web.php.
    expect($manifest['start_url'])->toStartWith('/');
    expect($manifest['start_url'])->toContain('source=pwa');

    // Chromium's install prompt is satisfied by icons declared with
    // sizes="any" (spec-scalable). We used to require '192x192' + '512x512'
    // but that lied about the actual pixel dimensions of the shipped PNGs.
    $sizes = collect($manifest['icons'])->pluck('sizes')->all();
    expect($sizes)->not->toBeEmpty();
    expect(collect($sizes)->contains(fn ($s) => $s === 'any' || str_contains($s, '512')))->toBeTrue();

    // At least one maskable icon so Android's circular/rounded-square masks
    // don't clip our SA logo.
    $maskable = collect($manifest['icons'])->filter(fn ($i) => str_contains($i['purpose'] ?? '', 'maskable'));
    expect($maskable)->not->toBeEmpty();
});

it('ships every icon referenced by the manifest', function () {
    $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    foreach ($manifest['icons'] as $icon) {
        $file = public_path(ltrim($icon['src'], '/'));
        expect(file_exists($file))
            ->toBeTrue("Manifest icon missing on disk: {$icon['src']}");
    }
});

it('ships the service worker so browsers can flag the site as installable', function () {
    $path = public_path('sw.js');
    expect(file_exists($path))->toBeTrue();

    $sw = file_get_contents($path);
    // The Chromium install-prompt criteria require a service worker with a
    // fetch handler. If someone strips it out during a "cleanup", the
    // install prompt silently disappears — this test guards against that.
    expect($sw)->toContain("addEventListener('fetch'");
});

it('injects the PWA meta tags into the authed app layout head', function () {
    // Render the app layout in isolation rather than hitting a real authed
    // route: pretty much every dashboard route also fires QualificationService,
    // which uses MySQL's YEAR() function and blows up on the SQLite test
    // connection. The PWA meta wiring lives entirely in the layout head,
    // so a direct render is sufficient (and much faster).
    $user = \App\Models\User::factory()->create();
    $this->actingAs($user);

    $html = view('components.layouts.app', ['slot' => new \Illuminate\Support\HtmlString('<div>test</div>')])->render();

    // Manifest link is the browser's entry point into every other PWA
    // artefact — without this line the site is a regular website.
    expect($html)->toContain('rel="manifest"');
    expect($html)->toContain('href="/manifest.webmanifest"');

    // theme-color drives the Android address bar tint and the status bar
    // colour of the installed PWA — must match the manifest's theme_color.
    expect($html)->toContain('name="theme-color"');
    expect($html)->toContain('content="#0e6b2f"');

    // iOS-specific tags — Safari ignores the manifest for full-screen
    // launch behaviour and uses these instead.
    expect($html)->toContain('apple-mobile-web-app-capable');
    expect($html)->toContain('apple-touch-icon');
});

it('injects the PWA meta tags into the public guest layout head', function () {
    // Unauthenticated visitors browsing standings / event listings must
    // also see the install prompt, otherwise casual users can't install
    // the app without first creating an account.
    $response = $this->get('/');
    $response->assertOk();

    $response->assertSee('rel="manifest"', false);
    $response->assertSee('href="/manifest.webmanifest"', false);
    $response->assertSee('name="theme-color"', false);
    $response->assertSee('apple-mobile-web-app-capable', false);
});
