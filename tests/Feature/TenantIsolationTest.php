<?php

use App\Enums\AgencyRole;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\User;

/*
| Tenant isolation is the core security boundary: a user must never reach a
| salon they don't belong to by changing an id in the URL (IDOR). These tests
| exercise the ResolveSalon middleware on the /salons/{salon} route.
*/

it('lets a member open their own salon', function () {
    $agency = Agency::factory()->create();
    $salon = Salon::factory()->forAgency($agency)->create();
    $user = User::factory()->create();
    SalonMembership::factory()->for($user)->for($salon)->stylist()->create();

    $this->actingAs($user)
        ->get(route('salon.show', $salon))
        ->assertOk()
        ->assertSee($salon->name);
});

it('forbids a member from opening a salon they do not belong to', function () {
    $agency = Agency::factory()->create();
    $salonA = Salon::factory()->forAgency($agency)->create();
    $salonB = Salon::factory()->forAgency($agency)->create();

    $user = User::factory()->create();
    SalonMembership::factory()->for($user)->for($salonA)->stylist()->create();

    // The user belongs to salon A only. Reaching salon B must be denied.
    $this->actingAs($user)
        ->get(route('salon.show', $salonB))
        ->assertForbidden();
});

it('forbids a member whose membership is inactive', function () {
    $salon = Salon::factory()->create();
    $user = User::factory()->create();
    SalonMembership::factory()->for($user)->for($salon)->stylist()->inactive()->create();

    $this->actingAs($user)
        ->get(route('salon.show', $salon))
        ->assertForbidden();
});

it('lets a privileged agency user reach any salon in their own agency', function () {
    $agency = Agency::factory()->create();
    $salon = Salon::factory()->forAgency($agency)->create();

    // Agency owner with no explicit salon membership.
    $agencyOwner = User::factory()->create([
        'agency_id' => $agency->id,
        'agency_role' => AgencyRole::Owner,
    ]);

    $this->actingAs($agencyOwner)
        ->get(route('salon.show', $salon))
        ->assertOk();
});

it('forbids an agency user from another agency\'s salon', function () {
    $agencyA = Agency::factory()->create();
    $agencyB = Agency::factory()->create();
    $salonB = Salon::factory()->forAgency($agencyB)->create();

    $agencyAOwner = User::factory()->create([
        'agency_id' => $agencyA->id,
        'agency_role' => AgencyRole::Owner,
    ]);

    $this->actingAs($agencyAOwner)
        ->get(route('salon.show', $salonB))
        ->assertForbidden();
});

it('redirects guests to login before any salon resolution', function () {
    $salon = Salon::factory()->create();

    $this->get(route('salon.show', $salon))
        ->assertRedirect(route('login'));
});

it('honours the SalonPolicy ability checks per role', function () {
    $salon = Salon::factory()->create();

    $owner = User::factory()->create();
    SalonMembership::factory()->for($owner)->for($salon)->owner()->create();

    $frontDesk = User::factory()->create();
    SalonMembership::factory()->for($frontDesk)->for($salon)->frontDesk()->create();

    $stylist = User::factory()->create();
    SalonMembership::factory()->for($stylist)->for($salon)->stylist()->create();

    // Only the owner connects GHL.
    expect($owner->can('connectGhl', $salon))->toBeTrue();
    expect($frontDesk->can('connectGhl', $salon))->toBeFalse();

    // Front desk + managers see the master calendar; stylists do not.
    expect($frontDesk->can('viewMasterCalendar', $salon))->toBeTrue();
    expect($stylist->can('viewMasterCalendar', $salon))->toBeFalse();
});
