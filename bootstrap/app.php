<?php

use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\ResolveSalon;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustCloudflareClientIp;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cloudflare fronts the Hostinger origin (client → Cloudflare →
        // Hostinger → PHP-FPM), so isSecure()/url()/ip() must read the
        // X-Forwarded-* chain. The default '*' trusts whichever proxy
        // connects — right for this deployment (the origin only receives
        // platform/Cloudflare traffic, and Hostinger's internal proxy
        // addresses aren't published) and immune to Cloudflare range
        // updates. TRUSTED_PROXIES can pin explicit ranges instead; that
        // override applies in AppServiceProvider (config isn't loaded yet
        // here, and env() goes empty under config:cache). Real visitor IPs
        // are made spoof-proof separately by TrustCloudflareClientIp, so
        // '*' cannot be abused to fake rate-limit identities via the edge.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Adopt Cloudflare's authoritative client IP before anything keys
        // off ip() — must run ahead of every throttle.
        $middleware->prepend(TrustCloudflareClientIp::class);

        // Force first-login password change before any other authenticated page.
        // SecurityHeaders adds the CSP + hardening headers to every web response.
        $middleware->web(append: [
            EnsurePasswordChanged::class,
            SecurityHeaders::class,
        ]);

        // The GHL inbound webhook and the Voice-AI Booking API are
        // server-to-server POSTs authenticated by their own secrets — no
        // session, no CSRF token.
        $middleware->validateCsrfTokens(except: ['webhooks/*', 'api/*']);

        // Resolves + authorises the active salon (tenant-isolation boundary).
        $middleware->alias([
            'resolve.salon' => ResolveSalon::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
