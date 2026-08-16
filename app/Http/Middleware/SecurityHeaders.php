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
 * `form-action` must list the Payfast process URLs because the checkout page
 * POSTs the signed payload straight to the gateway.
 */
class SecurityHeaders
{
    private const PERMISSIONS_POLICY = 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()';

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
        $gateways = implode(' ', array_map(
            fn (string $url): string => (string) parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST),
            array_values((array) config('payfast.urls', [])),
        ));

        // `npm run dev` serves assets and the HMR socket from the Vite dev
        // server, so allow it only while the hot file is present.
        $dev = file_exists(public_path('hot')) ? ' http://localhost:* http://127.0.0.1:* ws://localhost:* ws://127.0.0.1:*' : '';

        $directives = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'".$dev,
            "style-src 'self' 'unsafe-inline' {$fonts}".$dev,
            "font-src 'self' data: {$fonts}",
            "img-src 'self' data: blob:",
            "connect-src 'self'".$dev,
            "form-action 'self' {$gateways}",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "object-src 'none'",
        ];

        return implode('; ', $directives);
    }
}
