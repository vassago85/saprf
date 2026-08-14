// SAPRF service worker — Level 1 PWA (installable, no offline caching)
//
// Deliberately minimal: browsers require a service worker with a fetch
// handler for the "installable PWA" install prompt, but we do NOT want to
// aggressively cache anything on this iteration. Livewire, Flux, and the
// Vite bundle change on every deploy; a stale cache would silently ship
// broken UIs. If/when we move to Level 2 (cached app shell), swap the
// pass-through fetch handler for a proper Workbox / cache-first strategy
// and precache the built asset manifest.
//
// Bump SW_VERSION on every deploy that changes public/sw.js so browsers
// detect the change and swap in the new worker. (Byte-level diff in this
// file already triggers an update — the constant is here so we can force
// one without editing logic, e.g. to flush a client-side cache later.)
const SW_VERSION = '2026-08-14-1';

self.addEventListener('install', (event) => {
    // Take over immediately so a returning user isn't stuck on the
    // previously-registered worker until every tab closes.
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    // Claim all open clients so the new SW controls them without a reload.
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
    // Pass-through. Presence of a fetch handler is what qualifies the site
    // for the browser "Install app" prompt on Chromium — we don't need to
    // intercept anything until Level 2.
    event.respondWith(fetch(event.request));
});
