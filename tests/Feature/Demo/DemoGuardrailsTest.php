<?php

use App\Models\Salon;

/*
| Demo UX guardrails: surfaces that are meaningless or leaky inside the demo
| are guarded at the ROUTE/ACTION level (hiding nav is cosmetic, never the
| guard), while everything kept visible stays read-only with an honest note.
| Real salons keep full edit access everywhere — every block here asserts
| both directions.
*/

function enterDemoSalon($test): Salon
{
    $test->get('http://app.'.config('app.domain').'/demo')->assertRedirect();

    return Salon::query()
        ->whereKey(session('demo_salon_id'))
        ->where('is_demo', true)
        ->firstOrFail();
}

// ---------------------------------------------------------------------------
// Item 1 — personal/account settings: gone in demo, untouched for real users
// ---------------------------------------------------------------------------

it('bounces demo visitors off every personal-settings route', function () {
    enterDemoSalon($this);

    $app = 'http://app.'.config('app.domain');

    $this->get($app.'/settings/profile')->assertRedirect(route('demo.enter'));
    $this->get($app.'/settings/security')->assertRedirect(route('demo.enter'));
    // The bare /settings redirect is inside the guarded group too.
    $this->get($app.'/settings')->assertRedirect(route('demo.enter'));
});

it('hides the personal-settings nav entries from demo visitors', function () {
    $salon = enterDemoSalon($this);

    $this->get(route('salon.show', $salon))
        ->assertOk()
        ->assertDontSee(__('Account settings'));
});

it('keeps personal settings fully reachable for real users', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $this->actingAs($owner)->get(route('profile.edit'))->assertOk();
    $this->actingAs($owner)->get(route('security.edit'))->assertRedirect(); // password.confirm gate, not a demo bounce

    $this->actingAs($owner)
        ->get(route('salon.show', $salon))
        ->assertOk()
        ->assertSee(__('Account settings'));
});

it('never flags real accounts as demo accounts', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    expect($owner->isDemoAccount())->toBeFalse();

    $demo = enterDemoSalon($this);
    expect(auth()->user()->isDemoAccount())->toBeTrue();
    expect($demo->is_demo)->toBeTrue();
});
