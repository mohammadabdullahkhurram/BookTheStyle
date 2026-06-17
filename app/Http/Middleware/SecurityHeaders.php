<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security response headers, including a Content-Security-Policy.
 *
 * Now that the session cookie is shared across every salon subdomain
 * (SESSION_DOMAIN scoped to the parent domain), the CSP is tightened to bound
 * what those first-party origins may load and to block framing/base-tag tricks:
 *
 *   - default-src 'self'           — same-origin by default (each subdomain).
 *   - object-src 'none'            — no plugins.
 *   - base-uri 'self'              — block <base> tag hijacking.
 *   - frame-ancestors 'self'       — clickjacking protection (with X-Frame-Options).
 *
 * script-src/style-src keep 'unsafe-inline' + 'unsafe-eval' because Livewire,
 * Alpine and Flux evaluate inline expressions, and @fonts / the per-salon accent
 * emit inline <style>. form-action is intentionally left unset so the logout
 * form on a salon subdomain may POST to the central (apex) logout route.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $script = "'self' 'unsafe-inline' 'unsafe-eval'";
        $style = "'self' 'unsafe-inline'";
        $img = "'self' data:";
        $font = "'self' data:";
        $connect = "'self'";

        // Local dev only: allow the Vite dev server (npm run dev). It serves the
        // module scripts, the injected <link> stylesheets, fonts/source maps, and
        // the HMR websocket — across whichever loopback host it resolves to
        // (localhost / 127.0.0.1 / [::1]; the hot file commonly uses [::1]) on
        // whatever port it picked. Production CSP is never widened by this.
        if (app()->environment('local')) {
            $http = 'http://localhost:* http://127.0.0.1:* http://[::1]:*';
            $ws = 'ws://localhost:* ws://127.0.0.1:* ws://[::1]:*';
            $script .= " {$http}";
            $style .= " {$http}";
            $img .= " {$http}";
            $font .= " {$http}";
            $connect .= " {$http} {$ws}";
        }

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src {$script}",
            "style-src {$style}",
            "img-src {$img}",
            "font-src {$font}",
            "connect-src {$connect}",
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'self'",
        ]);

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
