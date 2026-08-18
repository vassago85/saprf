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

    const urlBase64ToUint8Array = (base64String) => {
        const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const raw = window.atob(base64);
        const out = new Uint8Array(raw.length);
        for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    };

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    async function currentSubscription() {
        if (!supported) return null;
        const reg = await navigator.serviceWorker.ready;
        return reg.pushManager.getSubscription();
    }

    async function subscribe() {
        if (!supported) throw new Error('Web Push is not supported on this device.');

        const permission = await Notification.requestPermission();
        if (permission !== 'granted') throw new Error('Notification permission denied.');

        const vapidResp = await fetch('/push/vapid-public-key', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!vapidResp.ok) throw new Error('Could not fetch VAPID public key.');
        const { public_key: publicKey } = await vapidResp.json();
        if (!publicKey) throw new Error('Server did not return a VAPID public key.');

        const reg = await navigator.serviceWorker.ready;

        let subscription = await reg.pushManager.getSubscription();
        if (!subscription) {
            subscription = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(publicKey),
            });
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

        if (!persistResp.ok) throw new Error('Server rejected the subscription.');
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

    return { supported, currentSubscription, subscribe, unsubscribe };
})();
