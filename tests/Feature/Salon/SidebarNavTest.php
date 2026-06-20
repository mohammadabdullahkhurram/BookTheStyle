<?php

use App\Models\Salon;

/*
| The salon sidebar exposes the management screens (Services, Staff, Availability)
| with role-mirrored visibility — links never appear for a role that would 403.
*/

it('shows Services, Staff and Availability links to a salon manager', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $html = $this->actingAs($owner)->get(route('salon.show', $salon))->assertOk()->getContent();

    expect($html)->toContain(route('salon.services', $salon))->toContain('Services');
    expect($html)->toContain(route('salon.staff', $salon))->toContain('Staff');
    expect($html)->toContain(route('salon.availability', $salon))->toContain('Availability');
});

it('shows a stylist only Availability (their own), not Services or Staff', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);

    $html = $this->actingAs($stylist)->get(route('salon.show', $salon))->assertOk()->getContent();

    expect($html)->not->toContain(route('salon.services', $salon));
    expect($html)->not->toContain(route('salon.staff', $salon));
    expect($html)->toContain(route('salon.availability', $salon));
});

it('shows front desk none of the management links', function () {
    $salon = Salon::factory()->create();
    $frontDesk = frontDeskOf($salon);

    $html = $this->actingAs($frontDesk)->get(route('salon.show', $salon))->assertOk()->getContent();

    expect($html)->not->toContain(route('salon.services', $salon));
    expect($html)->not->toContain(route('salon.staff', $salon));
    expect($html)->not->toContain(route('salon.availability', $salon)); // front desk is not a stylist
});

it('marks the active nav item on each management route', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $this->actingAs($owner);

    $services = $this->get(route('salon.services', $salon))->assertOk()->getContent();
    expect($services)->toMatch('#href="[^"]*/services"[^>]*bts-nav-item-active#');

    $staff = $this->get(route('salon.staff', $salon))->assertOk()->getContent();
    expect($staff)->toMatch('#href="[^"]*/staff"[^>]*bts-nav-item-active#');

    $availability = $this->get(route('salon.availability', $salon))->assertOk()->getContent();
    expect($availability)->toMatch('#href="[^"]*/availability"[^>]*bts-nav-item-active#');
});

it('serves /services on a clean schema (per-stylist override columns migrated, no 500)', function () {
    // RefreshDatabase runs every migration from scratch, including the
    // service_stylist override columns — the screen loads instead of 500-ing
    // with "no such column: service_stylist.duration_override".
    $salon = Salon::factory()->create();

    $this->actingAs(salonOwnerOf($salon))->get(route('salon.services', $salon))->assertOk();
});
