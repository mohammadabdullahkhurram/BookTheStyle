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
        ->toContain("object-src 'none'")
        ->toContain('style-src-elem '); // set explicitly, not left to fall back

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');
    expect($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
});

it('keeps the production CSP strict — no dev/app origins, no external CDNs', function () {
    // The test environment is not "local", so the dev allowances never appear.
    $csp = $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain("script-src 'self' 'unsafe-inline' 'unsafe-eval';")
        ->toContain("style-src-elem 'self' 'unsafe-inline';")
        ->not->toContain(':5173')
        ->not->toContain(':*')        // no wildcard-port host allowances
        ->not->toContain('ws://')
        ->not->toContain('googleapis')
        ->not->toContain('http://');  // no plaintext external origins at all
});

it('opens the local CSP to the dev server AND the app domain', function () {
    // Simulate `npm run dev` locally. config('app.domain') is the registrable
    // local domain (lvh.me under test); assets/fonts can be served from the apex
    // and loaded on a {slug}.{domain} subdomain page, and HMR runs over ws://.
    $this->app->detectEnvironment(fn () => 'local');
    $domain = config('app.domain'); // lvh.me
    $quoted = preg_quote($domain, '/');

    $csp = $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->headers->get('Content-Security-Policy');

    // The app domain + its subdomains AND the loopback dev server must be allowed
    // for scripts, both style directives, fonts, images, and connections.
    foreach (['script-src', 'style-src', 'style-src-elem', 'font-src', 'img-src', 'connect-src'] as $directive) {
        expect($csp)->toMatch("/{$directive}[^;]*http:\/\/{$quoted}:\*/");          // apex
        expect($csp)->toMatch("/{$directive}[^;]*http:\/\/\\*\.{$quoted}:\*/");      // subdomains
        expect($csp)->toMatch("/{$directive}[^;]*http:\/\/\[::1\]:\*/");            // Vite dev server
    }
    // HMR websocket for both the dev server and the app domain.
    expect($csp)
        ->toMatch('/connect-src[^;]*ws:\/\/\[::1\]:\*/')
        ->toMatch("/connect-src[^;]*ws:\/\/{$quoted}:\*/")
        ->toMatch("/connect-src[^;]*ws:\/\/\\*\.{$quoted}:\*/");
});
