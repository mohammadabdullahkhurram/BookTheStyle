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
 *   - frame-src 'self'             — strict, except the register host adds the
 *                                    configured book-a-call embed origin.
 *
 * script-src/style-src keep 'unsafe-inline' + 'unsafe-eval' because Livewire,
 * Alpine and Flux evaluate inline expressions, and @fonts / the per-salon accent
 * emit inline <style>. style-src-elem is set explicitly (it otherwise falls back
 * to style-src) so stylesheet <link>s resolve correctly. form-action is
 * intentionally left unset so the logout form on a salon subdomain may POST to
 * the central (apex) logout route.
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

        // frame-src is strict ('self') everywhere EXCEPT the public register
        // (book-a-call) host, which embeds a GoHighLevel calendar iframe. The
        // allowed embed origin is configurable; production stays strict elsewhere.
        $frame = "'self'";
        if ($request->getHost() === 'register.'.config('app.domain')) {
            $embed = trim((string) config('app.register_embed_frame_src', ''));
            if ($embed !== '') {
                $frame .= ' '.$embed;
            }
        }

        // Local dev only: widen the allow-list to the origins a local browser
        // actually hits. These are NOT 'self' on a salon subdomain:
        //   - the Vite dev server (npm run dev) on a loopback host/port,
        //   - assets/fonts served from the apex (config('app.domain')) but loaded
        //     on a {slug}.{domain} subdomain page (cross-origin),
        //   - the HMR websocket (ws://) on the dev server or the app domain.
        // Derived from the configured local domain so it follows APP_DOMAIN
        // (lvh.me today) and won't break if that changes. Production is untouched.
        if (app()->environment('local')) {
            $domain = (string) config('app.domain', 'localhost');
            $http = implode(' ', [
                'http://localhost:*', 'http://127.0.0.1:*', 'http://[::1]:*',
                "http://{$domain}:*", "http://*.{$domain}:*",
            ]);
            $ws = implode(' ', [
                'ws://localhost:*', 'ws://127.0.0.1:*', 'ws://[::1]:*',
                "ws://{$domain}:*", "ws://*.{$domain}:*",
            ]);
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
            "style-src-elem {$style}",
            "img-src {$img}",
            "font-src {$font}",
            "connect-src {$connect}",
            "frame-src {$frame}",
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
