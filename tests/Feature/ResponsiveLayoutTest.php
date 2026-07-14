<?php

use App\Enums\AgencyRole;
use App\Models\Client;
use App\Models\Salon;
use App\Models\User;

/*
| Batch 2 responsiveness: the mobile top bar + off-canvas nav drawer in the
| app layout, accessible names on the (collapsible) sidebar links, horizontal
| scroll wrappers on every data table, and stacked-card fallbacks on the
| highest-traffic screens. Rendered-HTML assertions — the breakpoint classes
| are the contract the CSS acts on.
*/

function responsiveAgencyOwner(Salon $salon): User
{
    return User::factory()->create([
        'agency_id' => $salon->agency_id,
        'agency_role' => AgencyRole::Owner,
    ]);
}

it('renders the mobile top bar with an accessible hamburger and the nav drawer', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $response = $this->actingAs($owner)->get(route('salon.show', $salon))->assertOk();

    // Top bar: hamburger with a name + expanded state, hidden from lg up.
    $response->assertSee('aria-label="Open navigation"', false);
    $response->assertSee(':aria-expanded="mobileNav', false);

    // Off-canvas drawer: dialog semantics, left slide-in, scrim, close button.
    $response->assertSee('aria-label="Navigation"', false);
    $response->assertSee('aria-modal="true"', false);
    $response->assertSee('bts-drawer-left', false);
    $response->assertSee('bts-scrim', false);
    $response->assertSee('aria-label="Close navigation"', false);

    // The desktop sidebar hides below lg (the drawer replaces it).
    $response->assertSee('hidden h-svh shrink-0 flex-col border-e border-border bg-card transition-[width] duration-200 lg:flex', false);
});

it('gives every sidebar nav link an accessible name that survives the collapsed state', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $response = $this->actingAs($owner)->get(route('salon.show', $salon))->assertOk();

    foreach (['Today', 'Calendar', 'Appointments', 'Clients', 'Check-in', 'Services', 'Staff', 'Reports', 'Availability', 'New booking', 'Settings'] as $label) {
        $response->assertSee('aria-label="'.$label.'"', false);
    }
});

it('wraps every data table in a keyboard-scrollable overflow container', function (string $routeName) {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $this->actingAs($owner)
        ->get(route($routeName, $salon))
        ->assertOk()
        ->assertSee('<div class="overflow-x-auto" tabindex="0">', false);
})->with([
    'services' => 'salon.services',
    'staff' => 'salon.staff',
]);

it('wraps the clients directory table in a scroll container and keeps its stacked fallback', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    Client::factory()->create(['salon_id' => $salon->id, 'name' => 'Casey Client']);

    $this->actingAs($owner)
        ->get(route('salon.clients', $salon))
        ->assertOk()
        ->assertSee('<div class="overflow-x-auto" tabindex="0">', false)
        ->assertSee('md:hidden', false);
});

it('wraps the agency tables in scroll containers', function (string $routeName) {
    $salon = Salon::factory()->create();
    $owner = responsiveAgencyOwner($salon);

    $this->actingAs($owner)
        ->get(route($routeName))
        ->assertOk()
        ->assertSee('<div class="overflow-x-auto" tabindex="0">', false);
})->with([
    // The Dashboard carries stat cards only now, and Salons defaults to the
    // gallery — the LIST view's table is covered below.
    'users' => 'agency.users.index',
]);

it('wraps the Salons LIST view table in a scroll container (gallery is card-based)', function () {
    $salon = Salon::factory()->create();
    $owner = responsiveAgencyOwner($salon);

    Livewire\Livewire::actingAs($owner)
        ->test('pages::dashboard')
        ->set('view', 'list')
        ->assertSee('<div class="overflow-x-auto" tabindex="0">', false);
});

it('gives Today a stacked-card fallback beside the desktop table', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $this->actingAs($owner)
        ->get(route('salon.show', $salon))
        ->assertOk()
        // Table only from md up (scrollable + focusable); stacked rows below md.
        ->assertSee('<div class="hidden overflow-x-auto md:block" tabindex="0">', false)
        ->assertSee('divide-y divide-row border-t border-divider md:hidden', false);
});

it('serves check-in as stacked cards (no fixed table to squish on a phone)', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $response = $this->actingAs($owner)->get(route('salon.appointments', $salon))->assertOk();

    // The booking list is a single-column stack of cards, not a <table>.
    $response->assertSee('flex flex-col gap-3', false);
    expect(str_contains($response->getContent(), '<table'))->toBeFalse();
});
