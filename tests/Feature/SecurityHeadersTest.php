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
