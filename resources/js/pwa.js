// Register the SAPRF service worker so browsers (Chrome/Edge/Android/iOS
// Safari) can offer "Add to Home Screen" / "Install app". Guarded so we
// don't even try in unsupported browsers, over insecure connections, or
// during local `vite dev` (which serves over http on non-localhost).
//
// This is Level 1 PWA + Web Push — see public/sw.js.

if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', () => {
        navigator.serviceWorker
            .register('/sw.js', { scope: '/' })
            .catch((error) => {
                console.warn('[SAPRF] Service worker registration failed:', error);
            });
    });
}

// ── Web Push helpers ──────────────────────────────────────────────────
//
// Exposed on `window.saprfPush` so profile / preferences pages can wire
// them to a "Enable push on this device" toggle without importing a
// module. Guarded so it's a no-op in environments that lack push.

window.saprfPush = (() => {
    const supported = 'serviceWorker' in navigator
        && 'PushManager' in window
        && window.isSecureContext;

    // Stable string codes are used as error `.name` on thrown Errors so
    // callers can either display the friendly `.message` or branch on the
    // code. Keep this list in sync with `friendlyError` below and with the
    // `reason` enum returned by `PushSubscriptionController::test`.
    const CODES = {
        UNSUPPORTED: 'PushUnsupported',
        PERMISSION_DENIED: 'NotificationPermissionDenied',
        PERMISSION_DEFAULT: 'NotificationPermissionDismissed',
        VAPID_UNAVAILABLE: 'VapidUnavailable',
        SUBSCRIBE_FAILED: 'SubscribeFailed',
        PERSIST_FAILED: 'PersistFailed',
    };

    const urlBase64ToUint8Array = (base64String) => {
        const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const raw = window.atob(base64);
        const out = new Uint8Array(raw.length);
        for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    };

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    // User-facing translations of every failure mode. Raw errors also go
    // to console.warn for developer diagnostics so support can still get
    // a technical trace out of a member's browser dev tools.
    const friendlyMessages = {
        [CODES.UNSUPPORTED]: 'This browser does not support push notifications. Install the app from Chrome on Android or Safari on iPhone/iPad.',
        [CODES.PERMISSION_DENIED]: 'Notifications are blocked for saprf.co.za. Open your browser\u2019s site settings for this address and switch Notifications to Allow, then try again.',
        [CODES.PERMISSION_DEFAULT]: 'Notification prompt was dismissed. Tap the button again and choose Allow when the browser asks.',
        [CODES.VAPID_UNAVAILABLE]: 'Push notifications are not fully configured on the server yet. Please try again later or contact us via the contact form.',
        [CODES.SUBSCRIBE_FAILED]: 'Your browser refused to register for push notifications. Reinstalling the app from Chrome usually fixes this.',
        [CODES.PERSIST_FAILED]: 'The server could not save your device. Please try again in a moment.',
    };

    function pushError(code, technicalDetail) {
        const err = new Error(friendlyMessages[code] || 'Something went wrong with push notifications.');
        err.name = code;
        if (technicalDetail) {
            console.warn(`[SAPRF push] ${code}:`, technicalDetail);
        }
        return err;
    }

    async function currentSubscription() {
        if (!supported) return null;
        const reg = await navigator.serviceWorker.ready;
        return reg.pushManager.getSubscription();
    }

    async function subscribe() {
        if (!supported) throw pushError(CODES.UNSUPPORTED);

        const permission = await Notification.requestPermission();
        if (permission === 'denied') throw pushError(CODES.PERMISSION_DENIED, 'Notification.requestPermission → denied');
        if (permission !== 'granted') throw pushError(CODES.PERMISSION_DEFAULT, `Notification.requestPermission → ${permission}`);

        let publicKey = null;
        try {
            const vapidResp = await fetch('/push/vapid-public-key', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!vapidResp.ok) {
                throw pushError(CODES.VAPID_UNAVAILABLE, `HTTP ${vapidResp.status} from /push/vapid-public-key`);
            }
            const body = await vapidResp.json();
            publicKey = body.public_key;
        } catch (e) {
            if (e.name && friendlyMessages[e.name]) throw e;
            throw pushError(CODES.VAPID_UNAVAILABLE, e.message);
        }

        if (!publicKey) throw pushError(CODES.VAPID_UNAVAILABLE, 'Server responded with public_key=null');

        const reg = await navigator.serviceWorker.ready;

        let subscription = await reg.pushManager.getSubscription();
        if (!subscription) {
            try {
                subscription = await reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(publicKey),
                });
            } catch (e) {
                throw pushError(CODES.SUBSCRIBE_FAILED, `pushManager.subscribe → ${e.message}`);
            }
        }

        const persistResp = await fetch('/push/subscribe', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
                Accept: 'application/json',
            },
            body: JSON.stringify(subscription),
        });

        if (!persistResp.ok) throw pushError(CODES.PERSIST_FAILED, `HTTP ${persistResp.status} from /push/subscribe`);

        return subscription;
    }

    async function unsubscribe() {
        if (!supported) return false;

        const subscription = await currentSubscription();
        if (!subscription) return false;

        await fetch('/push/subscribe', {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
                Accept: 'application/json',
            },
            body: JSON.stringify({ endpoint: subscription.endpoint }),
        });

        try { await subscription.unsubscribe(); } catch (e) { /* best-effort */ }

        return true;
    }

    /**
     * Fire a server-side test push to every subscription this user has.
     * Returns { sent, failed, pruned, message } — `message` is a friendly
     * one-liner the profile page can display verbatim regardless of
     * outcome.
     */
    async function sendTest() {
        if (!supported) throw pushError(CODES.UNSUPPORTED);

        const resp = await fetch('/push/test', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-CSRF-TOKEN': csrf(),
                Accept: 'application/json',
            },
        });

        if (!resp.ok) {
            throw pushError(CODES.PERSIST_FAILED, `HTTP ${resp.status} from /push/test`);
        }

        const body = await resp.json();
        const { sent = 0, failed = 0, pruned = 0, reason = null } = body;

        let message;
        if (sent > 0) {
            message = `Test notification sent to ${sent} device${sent === 1 ? '' : 's'}. It should arrive within a few seconds — check your phone.`;
        } else if (reason === 'vapid_missing' || reason === 'vapid_malformed') {
            message = friendlyMessages[CODES.VAPID_UNAVAILABLE];
        } else if (reason === 'no_subscriptions') {
            message = 'No devices are subscribed to push on this account yet. Turn on "Enable push on this device" first.';
        } else if (reason === 'library_missing') {
            message = 'Push is temporarily unavailable on the server. Please try again in a few minutes.';
        } else if (failed > 0) {
            message = `Could not deliver to ${failed} device${failed === 1 ? '' : 's'}. Try disabling and re-enabling push on this device.`;
        } else {
            message = 'No push notifications went out. Please try again.';
        }

        return { sent, failed, pruned, reason, message };
    }

    return { supported, CODES, currentSubscription, subscribe, unsubscribe, sendTest };
})();
