<?php

use App\Models\Salon;

/*
| THE HOSTING CONSTRAINT THIS GUARDS (a live 525 regression): this host can
| never serve a hostname that was not hand-created in hPanel. The origin
| holds certificates only for human-created subdomains (wildcard origin SSL
| is VPS-only on this plan) and Cloudflare runs Full (strict), so a
| runtime-minted subdomain answers 525 (SSL handshake failed) for every
| visitor. Hostnames are therefore a CLOSED, human-curated set — the demo
| once minted demo-{random}.{domain} per visitor and was unreachable in
| production. These tests fail the build if any route or URL-generation
| path starts inventing hostnames again. Rule: CLAUDE.md golden rule 11;
| docs/ARCHITECTURE.md §4; docs/DEPLOY.md (DNS + Cloudflare).
*/

it('registers every route on the fixed, hand-created host allowlist', function () {
    $central = config('app.domain');

    $allowed = [
        null,                 // host-agnostic framework routes (/up, livewire, storage)
        $central,             // apex — marketing
        'app.'.$central,      // the application (hand-created in hPanel)
        'register.'.$central, // book-a-call (hand-created in hPanel)
        '{salon}.'.$central,  // tenant wildcard — each REAL salon slug is itself a
        // subdomain a human creates in hPanel at onboarding
        // (docs/OPERATIONS.md); the static demo.{domain}
        // host resolves through this group too (session-
        // scoped, ResolveSalon), so no demo group exists.
    ];

    foreach (app('router')->getRoutes() as $route) {
        $this->assertContains(
            $route->getDomain(),
            $allowed,
            'Route ['.($route->getName() ?? $route->uri()).'] lives on unexpected host ['
            .var_export($route->getDomain(), true).'] — hostnames are hand-created in '
            .'hPanel, never introduced by code. If a human created this host, extend '
            .'the allowlist here AND document it in docs/DEPLOY.md.',
        );
    }
});

it('generates every demo-salon URL on the static demo host — never a per-visitor hostname', function () {
    $this->get('http://app.'.config('app.domain').'/demo')->assertRedirect();
    $salon = Salon::query()->whereKey(session('demo_salon_id'))->firstOrFail();

    $salonRoutes = collect(app('router')->getRoutes()->getRoutesByName())
        ->keys()
        ->filter(fn (string $name) => str_starts_with($name, 'salon.'))
        ->values()
        ->all();
    expect($salonRoutes)->not->toBeEmpty();

    foreach ($salonRoutes as $name) {
        // Surplus params (clientId/widget) satisfy the routes that need one
        // and fall away as query string on the rest.
        $host = (string) parse_url(route($name, ['salon' => $salon, 'clientId' => 1, 'widget' => 'w']), PHP_URL_HOST);

        $this->assertSame('demo.'.config('app.domain'), $host,
            "[{$name}] must generate onto the static demo host, got [{$host}].");
        $this->assertStringNotContainsString($salon->slug, $host,
            "[{$name}] leaked the demo salon slug into a hostname.");
    }
});

it('walks the whole demo entry flow without touching a non-existent host', function () {
    $response = $this->get('http://app.'.config('app.domain').'/demo');

    $location = (string) $response->headers->get('Location');
    expect(parse_url($location, PHP_URL_HOST))->toBe('demo.'.config('app.domain'));

    // Following the redirect renders the visitor's dashboard on that host.
    $this->get($location)->assertOk();
});
