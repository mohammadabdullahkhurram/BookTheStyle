<?php

use App\Actions\AgencyUsers\CreateAgencyUser;
use App\Enums\AgencyRole;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\User;
use App\Support\Permissions\AgencyUserRoles;
use Illuminate\Auth\Access\AuthorizationException;

function agencyOwner(Agency $agency): User
{
    return User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Owner]);
}

function agencyAdmin(Agency $agency): User
{
    return User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Admin]);
}

/*
| Agency console access + agency_user salon scope + role-grant rules.
*/

it('only lets agency owners/admins reach the console', function () {
    $agency = Agency::factory()->create();
    $salon = Salon::factory()->for($agency)->create();

    $owner = agencyOwner($agency);
    $admin = agencyAdmin($agency);

    $agencyUser = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::User]);
    $agencyUser->assignedSalons()->attach($salon);

    $salonStaff = User::factory()->create();
    SalonMembership::factory()->for($salonStaff)->for($salon)->admin()->create();

    $this->actingAs($owner)->get(route('agency.overview'))->assertOk();
    $this->actingAs($admin)->get(route('agency.overview'))->assertOk();

    // Agency users and salon staff are not agency operators → no console.
    $this->actingAs($agencyUser)->get(route('agency.overview'))->assertForbidden();
    $this->actingAs($salonStaff)->get(route('agency.users.create'))->assertForbidden();
    $this->actingAs($salonStaff)->get(route('agency.salons.create'))->assertForbidden();
});

it('scopes an agency_user to assigned salons only', function () {
    $agency = Agency::factory()->create();
    $assigned = Salon::factory()->for($agency)->create();
    $unassigned = Salon::factory()->for($agency)->create();

    $agencyUser = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::User]);
    $agencyUser->assignedSalons()->attach($assigned);

    // Can manage the assigned salon...
    $this->actingAs($agencyUser)->get(route('salon.users', $assigned))->assertOk();
    $this->actingAs($agencyUser)->get(route('salon.settings', $assigned))->assertOk();

    // ...but is forbidden on an unassigned salon in the same agency.
    $this->actingAs($agencyUser)->get(route('salon.users', $unassigned))->assertForbidden();
    $this->actingAs($agencyUser)->get(route('salon.show', $unassigned))->assertForbidden();
});

it('lets an agency_owner create admins — and NOBODY create a second owner', function () {
    $agency = Agency::factory()->create();
    $owner = agencyOwner($agency);
    $admin = agencyAdmin($agency);

    // Exactly one agency owner, ever: Owner is never assignable, by anyone.
    expect((new AgencyUserRoles)->assignable($owner))
        ->toEqualCanonicalizing([AgencyRole::Admin, AgencyRole::User]);
    expect((new AgencyUserRoles)->assignable($admin))->toBe([AgencyRole::User]);

    expect(fn () => app(CreateAgencyUser::class)->handle($owner, $agency, [
        'name' => 'Second Owner', 'email' => 'second@example.com', 'agency_role' => 'agency_owner',
    ]))->toThrow(AuthorizationException::class);

    // An admin creating an admin is rejected server-side.
    expect(fn () => app(CreateAgencyUser::class)->handle($admin, $agency, [
        'name' => 'X', 'email' => 'x@example.com', 'agency_role' => 'agency_admin',
    ]))->toThrow(AuthorizationException::class);
});

it('lets an admin create an agency_user scoped to agency salons only', function () {
    $agency = Agency::factory()->create();
    $admin = agencyAdmin($agency);
    $salon = Salon::factory()->for($agency)->create();
    $foreignSalon = Salon::factory()->create(); // different agency

    $result = app(CreateAgencyUser::class)->handle($admin, $agency, [
        'name' => 'Scoped User',
        'email' => 'scoped@example.com',
        'agency_role' => 'agency_user',
        'salon_ids' => [$salon->id, $foreignSalon->id],
    ]);

    expect($result->user->agency_role)->toBe(AgencyRole::User);
    expect($result->user->must_change_password)->toBeTrue();
    expect($result->temporaryPassword)->not->toBeNull();

    // The foreign-agency salon id was dropped (cross-agency safe).
    $assigned = $result->user->assignedSalons()->pluck('salons.id')->all();
    expect($assigned)->toEqualCanonicalizing([$salon->id]);
});

it('forbids an operator from acting on another agency', function () {
    $agencyA = Agency::factory()->create();
    $agencyB = Agency::factory()->create();
    $salonB = Salon::factory()->for($agencyB)->create();

    $ownerA = agencyOwner($agencyA);

    // Owner of agency A cannot edit agency B's salon.
    $this->actingAs($ownerA)->get(route('agency.salons.edit', $salonB))->assertForbidden();
});
