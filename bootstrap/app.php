<?php

use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\ResolveSalon;
use App\Http\Middleware\SecurityHeaders;
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
