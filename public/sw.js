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
const SW_VERSION = '2026-08-18-1';

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

// ── Web Push ──────────────────────────────────────────────────────────
//
// Payload shape sent by WebPushChannel:
//   { title: 'SAPRF: …', body: '…', url: 'https://…/communications/1', category: 'urgent' }
//
// The URL is used by the notificationclick handler below to open the
// exact announcement rather than dumping the user onto the dashboard.

self.addEventListener('push', (event) => {
    let payload = { title: 'SAPRF', body: 'You have a new announcement.', url: '/communications' };

    if (event.data) {
        try {
            payload = { ...payload, ...event.data.json() };
        } catch (e) {
            payload.body = event.data.text() || payload.body;
        }
    }

    const options = {
        body: payload.body,
        icon: '/icons/pwa-192.png',
        badge: '/icons/pwa-96.png',
        data: { url: payload.url },
        tag: payload.category || 'announcement',
        renotify: true,
    };

    event.waitUntil(self.registration.showNotification(payload.title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const target = event.notification.data?.url || '/communications';

    event.waitUntil((async () => {
        const clientsArr = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

        // If we already have a portal tab open, focus and navigate it —
        // avoids piling up empty duplicates every time push fires.
        for (const client of clientsArr) {
            if ('focus' in client) {
                try { await client.navigate(target); } catch (e) {}
                return client.focus();
            }
        }

        return self.clients.openWindow(target);
    })());
});
