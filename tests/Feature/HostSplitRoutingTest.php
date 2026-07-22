<?php

use App\Models\Salon;
use App\Models\User;
use App\Support\ReservedSlugs;

/*
| The platform is split four ways by host: apex (marketing), app. (the
| application + auth), register. (book a call), and {slug}. (tenants). Under
| test APP_DOMAIN=lvh.me, so the hosts are lvh.me / app.lvh.me / register.lvh.me
| / {slug}.lvh.me.
*/

function apex(): string
{
    return config('app.domain');
}

function appHost(): string
{
    return 'app.'.config('app.domain');
}

function registerHost(): string
{
    return 'register.'.config('app.domain');
}

it('serves the public marketing landing on the apex (no auth, no login redirect)', function () {
    $response = $this->get('http://'.apex().'/');

    $response->assertOk();
    expect($response->isRedirect())->toBeFalse();
    $response
        ->assertSee('Book a call')
        ->assertSee('Log in')
        // The CTAs point at the right hosts.
        ->assertSee(route('book-call'), false)
        ->assertSee(route('login'), false);
});

it('serves the public book-a-call page on register. with the embed placeholder', function () {
    $response = $this->get('http://'.registerHost().'/');

    $response->assertOk();
    expect($response->isRedirect())->toBeFalse();
    $response
        ->assertSee('Book a call')
        ->assertSee('id="ghl-embed"', false); // the clearly-marked embed slot
});

it('serves login on app. and bounces an unauthenticated app. visitor to login', function () {
    // Login screen is on app.
    $this->get(route('login'))->assertOk();
    expect(route('login'))->toBe('http://'.appHost().'/login');

    // app./ with no session → the auth middleware redirects to login (never to
    // the marketing landing).
    $this->get('http://'.appHost().'/')
        ->assertRedirect(route('login'));
});

it('sends an authenticated app. visitor to the salon picker', function () {
    $user = User::factory()->create();

    // app./ → /dashboard (the salon picker shell) for an authenticated user.
    $this->actingAs($user)
        ->get('http://'.appHost().'/')
        ->assertRedirect(route('dashboard'));

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Choose a salon'); // the picker
});

it('still resolves a salon subdomain and rejects app/register as slugs', function () {
    $salon = Salon::factory()->create(['slug' => 'glamour', 'name' => 'Demo Salon']);
    $owner = salonOwnerOf($salon);

    $this->actingAs($owner)->get('http://glamour.'.apex().'/')->assertOk()->assertSee('Today at the salon');

    // app. / register. never resolve as tenants (explicit groups win; ResolveSalon
    // also rejects reserved slugs as a safety net).
    expect(ReservedSlugs::isReserved('app'))->toBeTrue();
    expect(ReservedSlugs::isReserved('register'))->toBeTrue();
});

it('adds the GHL embed origins only on the marketing hosts (register + apex)', function () {
    // The register host and the apex marketing site both embed Bluejaypro's
    // GHL widgets (booking calendar, reviews, contact form).
    foreach (['http://'.registerHost().'/', 'http://'.apex().'/'] as $url) {
        $csp = $this->get($url)->headers->get('Content-Security-Policy');
        expect($csp)
            ->toContain('frame-src')
            ->toContain('leadconnectorhq.com')
            ->toContain('https://app.bluejaypro.com');
    }

    // The APP host keeps the strict policy — no external embed origin.
    $appCsp = $this->get(route('login'))->headers->get('Content-Security-Policy');
    expect($appCsp)
        ->toContain("frame-src 'self'")
        ->not->toContain('leadconnectorhq')
        ->not->toContain('bluejaypro.com');
});
