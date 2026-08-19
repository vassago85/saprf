<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser-hardening headers for every web response.
 *
 * The CSP is deliberately permissive on inline/eval for scripts: Alpine (via
 * Livewire) compiles attribute expressions with `new Function`, and Blade
 * templates carry inline `style` attributes. Tightening those two would mean
 * moving to Alpine's CSP build first. Everything else — framing, sniffing,
 * base URI, plugin objects, and where forms may post — is locked down.
 *
 * `form-action` allow-lists Payfast's entire infrastructure via wildcard,
 * not individual hostnames. Payfast (now "Payfast by Network", fronted by
 * AWS CloudFront) redirects the initial POST to `www.payfast.co.za/eng/process`
 * through a chain of internal hosts — the documented `w1w` / `w2w` redundancy
 * pair plus at least one edge/region host that varies. Chrome enforces
 * `form-action` across the *entire* redirect chain and, when a later hop is
 * disallowed, blocks the request while confusingly reporting the violation
 * against the first URL in the chain. Enumerating hosts was fragile (Payfast
 * ships new infrastructure without notice) and unnecessary: `form-action`
 * exists to stop a form on our page from being hijacked to a third party,
 * and we already trust Payfast as the entire form target, so allowing
 * anywhere inside `payfast.co.za` / `payfast.io` is exactly the right
 * posture. See commit history: SecurityHeaders was added in 2082c02, which
 * only listed `www` + `sandbox`; the redirect chain was silently broken
 * from then on.
 */
class SecurityHeaders
{
    private const PERMISSIONS_POLICY = 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()';

    /**
     * Sources for `form-action` covering every host Payfast may bounce the
     * browser through during checkout, across both the transaction domain
     * (payfast.co.za) and the newer branded domain (payfast.io). Bare + `*.`
     * pair is required because `*.example.com` does not match `example.com`
     * itself per the CSP host-source spec.
     */
    private const PAYFAST_FORM_ACTION_SOURCES = 'https://payfast.co.za https://*.payfast.co.za https://payfast.io https://*.payfast.io';

    /**
     * Hosts required by the GA4 Google tag (gtag.js). Wildcard form matches
     * region1.google-analytics.com and similar collector hostnames.
     *
     * @see https://developers.google.com/tag-platform/security/guides/csp
     */
    private const GOOGLE_TAG_SCRIPT_SOURCES = 'https://*.googletagmanager.com';

    private const GOOGLE_TAG_IMG_SOURCES = 'https://*.google-analytics.com https://*.googletagmanager.com';

    private const GOOGLE_TAG_CONNECT_SOURCES = 'https://*.google-analytics.com https://*.analytics.google.com https://*.googletagmanager.com';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', self::PERMISSIONS_POLICY);
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if (config('security.csp_enabled', true)) {
            $headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        $fonts = 'https://fonts.bunny.net';

        // `npm run dev` serves assets and the HMR socket from the Vite dev
        // server, so allow it only while the hot file is present.
        $dev = file_exists(public_path('hot')) ? ' http://localhost:* http://127.0.0.1:* ws://localhost:* ws://127.0.0.1:*' : '';

        $directives = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' ".self::GOOGLE_TAG_SCRIPT_SOURCES.$dev,
            "style-src 'self' 'unsafe-inline' {$fonts}".$dev,
            "font-src 'self' data: {$fonts}",
            "img-src 'self' data: blob: ".self::GOOGLE_TAG_IMG_SOURCES,
            "connect-src 'self' ".self::GOOGLE_TAG_CONNECT_SOURCES.$dev,
            "form-action 'self' ".self::PAYFAST_FORM_ACTION_SOURCES,
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "object-src 'none'",
        ];

        return implode('; ', $directives);
    }
}
