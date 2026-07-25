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

// ---------------------------------------------------------------------------
// Item 3 — My calendar: the personal feed link cannot be generated in demo
// ---------------------------------------------------------------------------

it('blocks calendar-feed link generation in demo and hides the control', function () {
    $salon = enterDemoSalon($this);

    $this->get(route('salon.account', $salon))
        ->assertOk()
        ->assertSee(__('Calendar links are disabled in the demo.'))
        ->assertDontSee(__('Generate calendar link'));

    // The action itself is a no-op even when invoked directly.
    Livewire\Livewire::actingAs(auth()->user())
        ->test('pages::settings.calendar-feed')
        ->call('generate')
        ->assertSet('subscribeUrl', null)
        ->assertSet('connected', false);

    expect(auth()->user()->calendarConnection()->first()?->hasFeed())->not->toBeTrue();
});

it('keeps calendar-feed generation working for real users', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $this->actingAs($owner)
        ->get(route('salon.account', $salon))
        ->assertOk()
        ->assertSee(__('Generate calendar link'));

    Livewire\Livewire::actingAs($owner)
        ->test('pages::settings.calendar-feed')
        ->call('generate')
        ->assertSet('connected', true);

    expect($owner->fresh()->calendarConnection()->first()?->hasFeed())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Item 2 — salon settings: plumbing removed, the showcase read-only
// ---------------------------------------------------------------------------

it('removes the integrations plumbing from demo salon settings entirely', function () {
    $salon = enterDemoSalon($this);

    $this->get(route('salon.settings', $salon))
        ->assertOk()
        ->assertDontSee(__('Integrations'))
        ->assertDontSee(__('Voice AI booking API'))
        ->assertDontSee(__('Load from GoHighLevel'));

    // The gate itself denies — every GHL action authorizes against it.
    expect(auth()->user()->can('manageGhlConnection', $salon))->toBeFalse();

    Livewire\Livewire::actingAs(auth()->user())
        ->test('pages::salon.settings', ['salon' => $salon])
        ->call('saveGhlConnection')
        ->assertForbidden();
});

it('keeps the showcase settings visible but read-only in demo', function () {
    $salon = enterDemoSalon($this);
    $before = $salon->accentColor();

    $this->get(route('salon.settings', $salon))
        ->assertOk()
        ->assertSee(__('Branding'))
        ->assertSee(__('Play with the accent and themes freely — saving is disabled in the demo.'));

    Livewire\Livewire::actingAs(auth()->user())
        ->test('pages::salon.settings', ['salon' => $salon])
        ->set('accent', '#123456')
        ->call('saveBranding')
        ->assertHasNoErrors();

    expect($salon->fresh()->accentColor())->toBe($before);

    // Hours and staff pages render with the note; their writes are no-ops.
    $this->get(route('salon.availability', $salon))
        ->assertOk()
        ->assertSee(__('Browse every schedule freely — editing hours is disabled in the demo.'));
    $this->get(route('salon.users', $salon))
        ->assertOk()
        ->assertSee(__('Browse the team setup freely — staff changes are disabled in the demo.'));

    $staffBefore = $salon->memberships()->count();
    Livewire\Livewire::actingAs(auth()->user())
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('invite')
        ->assertHasNoErrors();
    expect($salon->memberships()->count())->toBe($staffBefore);
});

it('keeps every salon setting fully editable for real salons', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $this->actingAs($owner)
        ->get(route('salon.settings', $salon))
        ->assertOk()
        ->assertSee(__('Integrations'))
        ->assertDontSee(__('saving is disabled in the demo'));

    expect($owner->can('manageGhlConnection', $salon))->toBeTrue();

    Livewire\Livewire::actingAs($owner)
        ->test('pages::salon.settings', ['salon' => $salon])
        ->set('accent', '#123456')
        ->call('saveBranding')
        ->assertHasNoErrors();

    expect($salon->fresh()->accentColor())->toBe('#123456');
});

it('never flags real accounts as demo accounts', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    expect($owner->isDemoAccount())->toBeFalse();

    $demo = enterDemoSalon($this);
    expect(auth()->user()->isDemoAccount())->toBeTrue();
    expect($demo->is_demo)->toBeTrue();
});
