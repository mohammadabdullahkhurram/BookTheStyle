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

        // The public MARKETING hosts (the apex site and the register page)
        // embed Bluejaypro's GHL widgets — the booking calendar, the Google
        // reviews widget, and later a contact form. Those load an iframe AND
        // a helper script (form_embed.js / review-widget.js) from the embed
        // origins, so frame/script/connect/img widen there — and ONLY there;
        // the app, agency and tenant hosts keep the strict policy.
        $marketingHosts = [config('app.domain'), 'register.'.config('app.domain')];
        if (in_array($request->getHost(), $marketingHosts, true)) {
            $embed = trim((string) config('app.marketing_embed_src', ''));
            if ($embed !== '') {
                $frame .= ' '.$embed;
                $script .= ' '.$embed;
                $connect .= ' '.$embed;
                $img .= ' '.$embed;
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

        // The public booking widget page is the ONE surface built to be
        // iframed by external sites (salon websites on Wix/WordPress/etc.),
        // so it alone allows any frame ancestor. Everything else keeps the
        // strict self-only framing (clickjacking protection).
        $embeddable = $request->route()?->getName() === 'salon.widget';

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
            $embeddable ? 'frame-ancestors *' : "frame-ancestors 'self'",
        ]);

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if ($embeddable) {
            // X-Frame-Options cannot express "allow all" — omit it and let
            // frame-ancestors govern (every current browser honours CSP).
            $response->headers->remove('X-Frame-Options');
        } else {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
