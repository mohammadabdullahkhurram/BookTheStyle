<?php

use App\Models\Salon;

/*
| A stylist's whole world is {Today, Calendar (own view), own appointments,
| own availability}. Every other salon surface must 403 server-side — a
| hidden nav link that still resolves is a hole. This asserts the COMPLETE
| salon route table, not a sample, so any new route must be classified here.
*/

/** Every salon-host route → whether a stylist may reach it. */
function stylistRouteMatrix(): array
{
    return [
        // Allowed: the stylist's four surfaces (+ their own account page).
        'salon.show' => true,             // Today
        'salon.calendar' => true,         // own-column calendar view
        'salon.appointments.all' => true, // scoped to their own bookings
        'salon.availability' => true,     // their own availability
        'salon.account' => true,          // personal account settings

        // Everything else: 403.
        'salon.appointments' => false,    // check-in desk
        'salon.bookings.create' => false, // booking clients in
        'salon.clients' => false,
        'salon.users' => false,           // the Users screen
        'salon.services' => false,
        'salon.reports' => false,
        'salon.settings' => false,
        'salon.widgets' => false,
        'salon.onboarding' => false,      // setup wizard
    ];
}

it('classifies every salon route in the stylist matrix (no route unaccounted)', function () {
    $salonRoutes = collect(app('router')->getRoutes()->getRoutesByName())
        ->keys()
        ->filter(fn (string $name) => str_starts_with($name, 'salon.'))
        // Public/tokenized surfaces outside the signed-in stylist question:
        // the booking widget (guest) and its API, and the client param route.
        ->reject(fn (string $name) => $name === 'salon.widget' || str_starts_with($name, 'salon.widget.'))
        ->reject(fn (string $name) => $name === 'salon.client') // covered via salon.clients + param below
        ->values()
        ->all();

    expect($salonRoutes)->toEqualCanonicalizing(array_keys(stylistRouteMatrix()));
});

it('gives a stylist exactly their four surfaces and 403s the rest', function () {
    $salon = Salon::factory()->create(['slug' => 'scope-check']);
    $stylist = stylistOf($salon);

    foreach (stylistRouteMatrix() as $route => $allowed) {
        $response = $this->actingAs($stylist)->get(route($route, $salon));

        if ($allowed) {
            $response->assertOk();
        } else {
            $response->assertForbidden();
        }
    }

    // The client detail route (needs a param) is a manager surface too.
    $this->actingAs($stylist)
        ->get(route('salon.client', ['salon' => $salon, 'clientId' => 1]))
        ->assertForbidden();
});

it('keeps managers and owners on their full surface (untouched by the scope-down)', function () {
    $salon = Salon::factory()->create(['slug' => 'manager-check']);

    foreach ([salonOwnerOf($salon), salonAdminOf($salon)] as $actor) {
        foreach (['salon.show', 'salon.calendar', 'salon.clients', 'salon.services', 'salon.users', 'salon.reports', 'salon.settings', 'salon.appointments', 'salon.bookings.create'] as $route) {
            $this->actingAs($actor)->get(route($route, $salon))->assertOk();
        }
    }
});

it('keeps tenant isolation: a stylist cannot reach another salon at all', function () {
    $salonA = Salon::factory()->create(['slug' => 'salon-a']);
    $salonB = Salon::factory()->create(['slug' => 'salon-b']);
    $stylist = stylistOf($salonA);

    $this->actingAs($stylist)->get(route('salon.show', $salonB))->assertForbidden();
});
