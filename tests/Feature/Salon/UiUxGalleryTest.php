<?php

use App\Models\Salon;

/*
| TEMPORARY design-direction gallery: owner/admin-only exploration surface
| showing five complete aesthetics as self-contained mockups. Deleted once a
| direction is chosen and implemented app-wide.
*/

it('renders the gallery with all ten directions for managers', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $this->actingAs($owner)->get(route('salon.uiux', $salon))
        ->assertOk()
        ->assertSee('Design directions')
        ->assertSee('Lumen — light glass premium')
        ->assertSee('Journal — editorial warm')
        ->assertSee('Meridian — clean modern dashboard')
        ->assertSee('Halo — soft depth')
        ->assertSee('Velvet — boutique tactile')
        ->assertSee('Studio — mono minimal')
        ->assertSee('Aurora — vibrant gradient')
        ->assertSee('Manor — warm neutral pro')
        ->assertSee('Bloom — playful boutique')
        ->assertSee('Vertex — sharp contemporary');
});

it('gates the gallery from non-managers', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    $frontDesk = frontDeskOf($salon);

    $this->actingAs($stylist)->get(route('salon.uiux', $salon))->assertForbidden();
    $this->actingAs($frontDesk)->get(route('salon.uiux', $salon))->assertForbidden();
});

it('shows the temporary nav tab only to managers', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $stylist = stylistOf($salon);

    $this->actingAs($owner)->get(route('salon.show', $salon))
        ->assertOk()->assertSee('aria-label="UI/UX"', false);

    $this->actingAs($stylist)->get(route('salon.show', $salon))
        ->assertOk()->assertDontSee('aria-label="UI/UX"', false);
});
