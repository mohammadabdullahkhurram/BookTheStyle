<?php

use App\Models\Salon;

/*
| TEMPORARY widget-design gallery: owner/admin-only surface showing five
| complete visual takes on the embeddable booking flow. Deleted once a
| design is chosen and applied to the real widget.
*/

it('renders the widget gallery with all five designs and the full flow for managers', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $this->actingAs($owner)->get(route('salon.widgetdesigns', $salon))
        ->assertOk()
        ->assertSee('Widget designs')
        ->assertSee('Frost — light glass premium')
        ->assertSee('Swift — clean minimal')
        ->assertSee('Maison — warm boutique')
        ->assertSee('Punch — bold modern')
        ->assertSee('Folio — elegant editorial')
        // Every design walks the same five steps.
        ->assertSee('Choose a service')
        ->assertSee('Choose a stylist')
        ->assertSee('Pick a time')
        ->assertSee('Your details')
        ->assertSee("You're booked", false);
});

it('gates the widget gallery from non-managers', function () {
    $salon = Salon::factory()->create();

    $this->actingAs(stylistOf($salon))->get(route('salon.widgetdesigns', $salon))->assertForbidden();
    $this->actingAs(frontDeskOf($salon))->get(route('salon.widgetdesigns', $salon))->assertForbidden();
});

it('shows the temporary nav tab only to managers', function () {
    $salon = Salon::factory()->create();

    $this->actingAs(salonOwnerOf($salon))->get(route('salon.show', $salon))
        ->assertOk()->assertSee('aria-label="Widget designs"', false);

    $this->actingAs(stylistOf($salon))->get(route('salon.show', $salon))
        ->assertOk()->assertDontSee('aria-label="Widget designs"', false);
});
