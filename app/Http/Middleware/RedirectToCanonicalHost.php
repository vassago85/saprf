<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirect the `www.` variant of the canonical host to the apex derived from
 * APP_URL.
 *
 * Leaving `www.saprf.co.za` and `saprf.co.za` both live as independent
 * origins breaks the site in two ways:
 *
 *  1. Vite emits every asset URL against APP_URL (the apex). The strict CSP
 *     shipped by App\Http\Middleware\SecurityHeaders only lists `'self'` for
 *     `style-src` and `script-src`, so a browser landing on the `www.` origin
 *     refuses to apply any CSS or JS from the sibling apex origin. The page
 *     renders with browser-default styles — the SAPRF logo balloons to its
 *     native size and everything falls back to Times New Roman.
 *  2. Session cookies and CSRF tokens split between origins, so login and
 *     any authenticated POST from a `www.` tab silently fails.
 *
 * Only GET/HEAD requests are redirected. Payfast, Mailgun, and future
 * webhooks POST to absolute routes generated from APP_URL, so they already
 * hit the apex. On the off chance one is misconfigured and hits `www.`, we
 * let the POST land on the correct controller rather than downgrading it to
 * a browser-side GET via 301.
 */
class RedirectToCanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        $canonicalHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if ($canonicalHost === null || $canonicalHost === '') {
            return $next($request);
        }

        if ($request->getHost() !== 'www.'.$canonicalHost) {
            return $next($request);
        }

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: $request->getScheme();

        return redirect()->away(
            $scheme.'://'.$canonicalHost.$request->getRequestUri(),
            301,
        );
    }
}
