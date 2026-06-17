<?php

use App\Models\User;

/*
| Every web response carries the baseline security headers, including a CSP
| that is tightened now that the session cookie is shared across subdomains.
*/

it('sends the CSP and hardening headers on web responses', function () {
    $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

    $response->assertOk();

    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)
        ->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'self'")
        ->toContain("base-uri 'self'")
        ->toContain("object-src 'none'");

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');
    expect($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
});

it('keeps the production CSP strict — no Vite dev server origins', function () {
    // The test environment is not "local", so the dev allowances never appear.
    $csp = $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain("script-src 'self' 'unsafe-inline' 'unsafe-eval';")
        ->not->toContain(':5173')
        ->not->toContain('localhost:*')
        ->not->toContain('ws://');
});

it('opens the CSP to the Vite dev server in local only', function () {
    // Simulate `npm run dev` locally. The dev server is served from a loopback
    // host (commonly the IPv6 [::1]) on its own port.
    $this->app->detectEnvironment(fn () => 'local');

    $csp = $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->headers->get('Content-Security-Policy');

    // Module scripts, the HMR websocket, and — the regression that broke styling
    // — the injected <link> stylesheet must all permit the dev server, across
    // every loopback spelling.
    foreach (['script-src', 'style-src', 'connect-src', 'font-src'] as $directive) {
        expect($csp)->toMatch("/{$directive}[^;]*http:\/\/\[::1\]:\*/");
    }
    expect($csp)
        ->toMatch('/connect-src[^;]*ws:\/\/\[::1\]:\*/')
        ->toContain('http://127.0.0.1:*')
        ->toContain('http://localhost:*');
});
