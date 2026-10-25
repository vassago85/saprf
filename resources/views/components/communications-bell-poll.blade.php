@props([
    'interval' => 30000,     // 30s base interval between polls
    'maxInterval' => 300000, // Cap back-off at 5 minutes
])
{{--
    Single global poller for the authenticated user's unread
    communications count. Broadcasts the count via a window-level
    `CustomEvent('saprf:unread-count', { detail: <int> })`. Bell UI
    components listen with `x-init="window.addEventListener(...)"`
    rather than each spinning up their own setInterval.

    Why one shared poller instead of inline polls per bell:
    - On an iOS PWA both the mobile header bell AND the ios-pwa-nav
      bell render simultaneously. With per-component intervals that
      doubled the number of hits on `/communications/unread-count`
      and, more visibly, doubled the number of 502 Bad Gateway lines
      spilled into the console whenever a prod rebuild caught the
      poll clock mid-deploy.
    - fetch() only rejects on genuine network failures; a 502 is a
      resolved response with r.ok === false. So the inline poll's
      `try { ... } catch (e) {}` never caught deploy-window 502s —
      Chrome logged every single one. Centralising the poll lets us
      actually detect !r.ok and back off instead of hammering.

    Back-off: 30s → 60s → 2min → 4min → 5min (capped). Resets to the
    base interval on the first successful poll. During a typical
    60-90s deploy window a tab sees 1 failed poll (not 2-3) before
    going quiet; the next poll five minutes later picks the new
    container back up and resumes the normal cadence.

    Guards:
    - `window.__saprfUnreadPollerBooted` prevents a second poller even
      if the partial is somehow rendered twice in the same request.
    - `@once` prevents the script tag emitting twice from a single
      render pass if the parent happens to include this component
      more than once.
--}}
@once
<script>
    (function () {
        if (window.__saprfUnreadPollerBooted) return;
        window.__saprfUnreadPollerBooted = true;

        const url = @json(route('communications.unread-count'));
        const baseInterval = {{ (int) $interval }};
        const maxInterval = {{ (int) $maxInterval }};
        let currentInterval = baseInterval;

        async function poll() {
            let ok = false;
            try {
                const r = await fetch(url, {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                });
                if (r.ok) {
                    const j = await r.json();
                    window.dispatchEvent(new CustomEvent('saprf:unread-count', { detail: j.unread }));
                    ok = true;
                }
            } catch (e) {
                // Genuine network failure (DNS / connection refused /
                // page offline). Fall through to back-off.
            }

            // Exponential back-off on failure, instant reset on success.
            // Capped at maxInterval so a long outage doesn't push the
            // next retry hours into the future.
            currentInterval = ok ? baseInterval : Math.min(currentInterval * 2, maxInterval);
            setTimeout(poll, currentInterval);
        }

        setTimeout(poll, baseInterval);
    })();
</script>
@endonce
