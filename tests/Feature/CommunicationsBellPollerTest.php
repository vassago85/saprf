<?php

use App\Models\User;

beforeEach(function () {
    seedRoles();
});

/**
 * Pins the 25 Oct 2026 "unread-count poller spills 502s during deploys"
 * fix. The old inline setInterval sat in two places (mobile header bell
 * + ios-pwa-nav bell) and had no back-off, so a 60-90s prod rebuild
 * window surfaced 2-3 "GET /communications/unread-count 502 (Bad
 * Gateway)" lines per tab in the browser console. The replacement is
 * a single shared poller with exponential back-off (30s → 60s → 2m →
 * 4m → 5m cap, reset on success), broadcast via a window CustomEvent.
 */

it('authenticated layout mounts the shared poller exactly once and omits the inline fetch loop', function () {
    $user = User::factory()->create();

    $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

    // The shared poller sets its window-level singleton flag exactly
    // once per mounted script. If the component is accidentally
    // rendered twice (@once + window guard would still catch it at
    // runtime, but we don't want to lean on the guards) this line
    // appears twice and the test flags it.
    expect(substr_count($html, 'window.__saprfUnreadPollerBooted = true;'))->toBe(1);

    // Back-off logic must be present — this is the whole point of the
    // rewrite. If a future refactor goes back to a flat setInterval
    // the deploy-window console noise returns and this test fails.
    expect($html)->toContain('currentInterval * 2');
    expect($html)->toContain('saprf:unread-count');

    // Bell components MUST NOT run their own setInterval against the
    // unread-count endpoint any more. They listen on the CustomEvent
    // dispatched by the shared poller. The regex matches the exact
    // inline shape the old code used — `setInterval(async () => { try`.
    expect($html)->not->toMatch('/setInterval\s*\(\s*async\s*\(\s*\)\s*=>\s*\{\s*try/');
});

it('bell UI components listen on saprf:unread-count instead of fetching directly', function () {
    $user = User::factory()->create();

    $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

    // Both bells (mobile header + iOS PWA nav) attach a listener in
    // x-init. We don't check both separately because the ios-pwa-nav
    // only renders on authenticated pages too; the aggregate count
    // should be at least 2 (one per bell on a logged-in dashboard).
    expect(substr_count($html, "addEventListener('saprf:unread-count'"))
        ->toBeGreaterThanOrEqual(2);
});

it('guest layout does not mount the poller (no unread count without a session)', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('__saprfUnreadPollerBooted');
    expect($html)->not->toContain(route('communications.unread-count'));
});
