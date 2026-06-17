<?php

use App\Models\Salon;
use App\Models\User;

/*
| Subdomain tenancy: the active salon is resolved from the request Host
| ({slug}.{app.domain}), ResolveSalon 404s unknown/inactive slugs and 403s
| non-members, and a central login carries to the salon subdomain. APP_DOMAIN
| is "localhost" under test, so salons live at http://{slug}.localhost/.
*/

it('resolves a salon from its subdomain slug', function () {
    $salon = Salon::factory()->create(['slug' => 'demo', 'name' => 'Demo Salon']);
    $owner = salonOwnerOf($salon);

    // route() builds the subdomain URL from the slug route key.
    expect(route('salon.show', $salon))->toBe('http://demo.localhost');

    $this->actingAs($owner)
        ->get(route('salon.show', $salon))
        ->assertOk()
        ->assertSee('Demo Salon');
});

it('404s an unknown subdomain slug for an authenticated user', function () {
    $this->actingAs(User::factory()->create())
        ->get('http://no-such-salon.localhost/')
        ->assertNotFound();
});

it('404s an inactive salon subdomain even for a member', function () {
    $salon = Salon::factory()->create(['slug' => 'dormant', 'active' => false]);
    $owner = salonOwnerOf($salon);

    $this->actingAs($owner)
        ->get('http://dormant.localhost/')
        ->assertNotFound();
});

it('403s a logged-in user on a salon subdomain they do not belong to', function () {
    $salonA = Salon::factory()->create(['slug' => 'salon-a']);
    $salonB = Salon::factory()->create(['slug' => 'salon-b']);
    $memberOfA = salonOwnerOf($salonA);

    // Cross-tenant: a member of A hitting B's subdomain is forbidden, not 404 —
    // B exists and is active, but membership fails.
    $this->actingAs($memberOfA)
        ->get('http://salon-b.localhost/')
        ->assertForbidden();
});

it('redirects a guest on a salon subdomain to login rather than revealing the tenant', function () {
    Salon::factory()->create(['slug' => 'guarded']);

    $this->get('http://guarded.localhost/')
        ->assertRedirect(route('login'));
});

it('carries the session from the central login to a salon subdomain', function () {
    $salon = Salon::factory()->create(['slug' => 'demo']);
    $owner = salonOwnerOf($salon); // factory users use the password "password"

    // Authenticate on the central (apex) login route...
    $this->post(route('login.store'), [
        'email' => $owner->email,
        'password' => 'password',
    ])->assertRedirect();

    $this->assertAuthenticatedAs($owner);

    // ...then the same session is recognised on the salon subdomain.
    $this->get('http://demo.localhost/')->assertOk();
});
