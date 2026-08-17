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
 * `form-action` must list every Payfast host the browser can end up on during
 * checkout. The initial POST goes to `www.payfast.co.za` (or the sandbox
 * host), but Payfast immediately 302s to one of its redundancy hosts —
 * `w1w.payfast.co.za` / `w2w.payfast.co.za`. Chrome enforces `form-action`
 * across the entire redirect chain and, when a later hop is disallowed,
 * blocks the request while reporting the violation against the *first* URL
 * in the chain. So the allowlist must include every host in
 * `config('payfast.valid_hosts')` plus the sandbox host used in dev.
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
        $gateways = $this->payfastFormActionSources();

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

    /**
     * Every https:// host the browser can be navigated to during a Payfast
     * checkout, deduplicated and space-separated for `form-action`.
     */
    private function payfastFormActionSources(): string
    {
        $hosts = [];

        foreach ((array) config('payfast.urls', []) as $url) {
            $host = parse_url((string) $url, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $hosts[$host] = true;
            }
        }

        foreach ((array) config('payfast.valid_hosts', []) as $host) {
            if (is_string($host) && $host !== '') {
                $hosts[$host] = true;
            }
        }

        return implode(' ', array_map(
            static fn (string $host): string => 'https://'.$host,
            array_keys($hosts),
        ));
    }
}
