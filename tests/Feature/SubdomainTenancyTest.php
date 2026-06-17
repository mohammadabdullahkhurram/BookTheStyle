<?php

use App\Models\Salon;
use App\Models\User;

/*
| Subdomain tenancy: the active salon is resolved from the request Host
| ({slug}.{app.domain}), ResolveSalon 404s unknown/inactive slugs and 403s
| non-members, and a central login carries to the salon subdomain. The test
| env uses a registrable loopback domain (APP_DOMAIN=lvh.me, like local dev),
| so salons live at http://{slug}.lvh.me/.
*/

it('resolves a salon from its subdomain slug', function () {
    $salon = Salon::factory()->create(['slug' => 'demo', 'name' => 'Demo Salon']);
    $owner = salonOwnerOf($salon);

    // route() builds the subdomain URL from the slug route key.
    expect(route('salon.show', $salon))->toBe('http://demo.lvh.me');

    $this->actingAs($owner)
        ->get(route('salon.show', $salon))
        ->assertOk()
        ->assertSee('Demo Salon');
});

it('404s an unknown subdomain slug for an authenticated user', function () {
    $this->actingAs(User::factory()->create())
        ->get('http://no-such-salon.lvh.me/')
        ->assertNotFound();
});

it('404s an inactive salon subdomain even for a member', function () {
    $salon = Salon::factory()->create(['slug' => 'dormant', 'active' => false]);
    $owner = salonOwnerOf($salon);

    $this->actingAs($owner)
        ->get('http://dormant.lvh.me/')
        ->assertNotFound();
});

it('403s a logged-in user on a salon subdomain they do not belong to', function () {
    $salonA = Salon::factory()->create(['slug' => 'salon-a']);
    $salonB = Salon::factory()->create(['slug' => 'salon-b']);
    $memberOfA = salonOwnerOf($salonA);

    // Cross-tenant: a member of A hitting B's subdomain is forbidden, not 404 —
    // B exists and is active, but membership fails.
    $this->actingAs($memberOfA)
        ->get('http://salon-b.lvh.me/')
        ->assertForbidden();
});

it('redirects a guest on a salon subdomain to login rather than revealing the tenant', function () {
    Salon::factory()->create(['slug' => 'guarded']);

    $this->get('http://guarded.lvh.me/')
        ->assertRedirect(route('login'));
});

it('shares the login session from the central domain to a salon subdomain', function () {
    $salon = Salon::factory()->create(['slug' => 'demo', 'name' => 'Demo Salon']);
    $owner = salonOwnerOf($salon); // factory users use the password "password"

    $apex = config('app.domain');     // lvh.me  (registrable loopback)
    $sub = 'demo.'.$apex;             // demo.lvh.me

    // 1) Log in on the central (apex) domain.
    $this->post(route('login.store'), [
        'email' => $owner->email,
        'password' => 'password',
    ])->assertRedirect();
    $this->assertAuthenticatedAs($owner);

    // 2) The session cookie must be scoped so a real browser shares it from the
    //    apex to the salon subdomain. This is the part Laravel's test client does
    //    not enforce — and exactly what .localhost gets wrong.
    $cookieDomain = config('session.domain');
    expect($cookieDomain)->not->toBeNull();
    expect($cookieDomain)->not->toBe('.localhost');
    expect(browserSharesCookie($cookieDomain, $apex, $sub))->toBeTrue();

    // 3) The SAME session is authenticated on the salon subdomain — a rendered
    //    dashboard, NOT a bounce to the login screen.
    $this->get('http://'.$sub.'/')
        ->assertOk()
        ->assertSee('Demo Salon');
    $this->assertAuthenticatedAs($owner);
});

it('would NOT share a *.localhost session cookie (the bug this guards against)', function () {
    // Documents the root cause: a Domain cookie for localhost / *.localhost is
    // refused by browsers, so the apex login never reaches the subdomain.
    expect(browserSharesCookie('.localhost', 'localhost', 'demo.localhost'))->toBeFalse();
    expect(browserSharesCookie('localhost', 'localhost', 'demo.localhost'))->toBeFalse();
    expect(browserSharesCookie(null, 'lvh.me', 'demo.lvh.me'))->toBeFalse();

    // ...whereas a registrable loopback domain is shared.
    expect(browserSharesCookie('.lvh.me', 'lvh.me', 'demo.lvh.me'))->toBeTrue();
});
