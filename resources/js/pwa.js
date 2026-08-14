// Register the SAPRF service worker so browsers (Chrome/Edge/Android/iOS
// Safari) can offer "Add to Home Screen" / "Install app". Guarded so we
// don't even try in unsupported browsers, over insecure connections, or
// during local `vite dev` (which serves over http on non-localhost).
//
// This is Level 1 PWA only — no offline caching. See public/sw.js.

if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', () => {
        navigator.serviceWorker
            .register('/sw.js', { scope: '/' })
            .catch((error) => {
                // Registration failure shouldn't break the app — the site
                // works identically without a SW, users just don't get the
                // install prompt. Log for debugging but never throw.
                console.warn('[SAPRF] Service worker registration failed:', error);
            });
    });
}
