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
        $connect = "'self'";

        // Allow the Vite dev server (npm run dev) when developing locally so HMR
        // over http + websocket is not blocked. Never widened in production.
        if (app()->environment('local')) {
            $script .= ' http://localhost:5173 http://[::1]:5173';
            $style .= ' http://localhost:5173';
            $connect .= ' http://localhost:5173 ws://localhost:5173 ws://[::1]:5173';
        }

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src {$script}",
            "style-src {$style}",
            "img-src 'self' data:",
            "font-src 'self' data:",
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
