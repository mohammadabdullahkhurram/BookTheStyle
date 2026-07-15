<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Behind Cloudflare every request reaches the origin from a Cloudflare
 * address with the real visitor in CF-Connecting-IP — a header Cloudflare
 * always overwrites at the edge, so clients cannot forge it THROUGH
 * Cloudflare. Making it authoritative for $request->ip() keeps per-IP rate
 * limiting (widget API, booking API, webhook, login, calendar feed)
 * meaningful: with proxies trusted as '*', ip() would otherwise resolve to
 * the LEFTMOST X-Forwarded-For entry, and that one is client-supplied
 * (spoofable straight through the edge).
 *
 * Once CF-Connecting-IP is adopted, X-Forwarded-For is dropped so no
 * upstream-injected hop can override the decision; X-Forwarded-Proto/Host/
 * Port stay untouched (scheme + host still come from the proxy chain).
 * Without Cloudflare (local dev, direct-to-Hostinger) this is a no-op.
 *
 * Residual risk, accepted + documented in docs/DEPLOY.md: a caller that can
 * reach the ORIGIN directly (bypassing Cloudflare) could present a fake
 * CF-Connecting-IP. Mitigate by keeping the origin closed to non-Cloudflare
 * traffic, or by pinning TRUSTED_PROXIES to Cloudflare's published ranges.
 */
class TrustCloudflareClientIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->headers->get('CF-Connecting-IP');

        if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            $request->server->set('REMOTE_ADDR', $ip);
            $request->headers->remove('X-Forwarded-For');
        }

        return $next($request);
    }
}
