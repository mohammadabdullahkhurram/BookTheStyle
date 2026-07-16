<?php

use App\Actions\Staff\InviteStaff;
use App\Actions\Staff\ResetStaffPassword;
use App\Actions\Staff\SetMembershipActive;
use App\Actions\Staff\UpdateStaffMembership;
use App\Enums\AgencyRole;
use App\Enums\SalonRole;
use App\Enums\StaffType;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\User;
use App\Support\Permissions\SalonStaffRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

// salonOwnerOf / salonAdminOf / stylistOf / frontDeskOf live in tests/Pest.php.

/*
| Authorization rules for who may create salons and add/remove owners/admins vs
| stylists — covering both ALLOW and DENY, enforced server-side.
*/

// --- Rule 1: create a salon → agency owner/admin only --------------------------

it('lets only agency owner/admin reach the create-salon screen', function () {
    $agency = Agency::factory()->create();
    $salon = Salon::factory()->for($agency)->create();

    $agencyOwner = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Owner]);
    $agencyAdmin = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Admin]);

    $this->actingAs($agencyOwner)->get(route('agency.salons.create'))->assertOk();
    $this->actingAs($agencyAdmin)->get(route('agency.salons.create'))->assertOk();

    // Salon roles cannot create salons.
    $this->actingAs(salonOwnerOf($salon))->get(route('agency.salons.create'))->assertForbidden();
    $this->actingAs(salonAdminOf($salon))->get(route('agency.salons.create'))->assertForbidden();
    $this->actingAs(stylistOf($salon))->get(route('agency.salons.create'))->assertForbidden();
});

// --- Rule 2: add/remove a salon OWNER or ADMIN ---------------------------------

it('refuses an Owner invite from EVERYONE — even agency operators on an ownerless salon', function () {
    $agency = Agency::factory()->create();

    // Owners are assigned from the agency console (SetSalonOwner) or
    // provisioned at salon creation; the invite path refuses categorically.
    foreach ([AgencyRole::Owner, AgencyRole::Admin] as $i => $role) {
        $salon = Salon::factory()->for($agency)->create(); // ownerless
        $actor = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => $role]);

        expect(fn () => app(InviteStaff::class)->handle($actor, $salon, [
            'name' => 'New Owner', 'email' => "owner-{$i}@example.com", 'salon_role' => 'salon_owner',
        ]))->toThrow(AuthorizationException::class);
    }
});

it('lets an existing salon owner add another owner or admin', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $result = app(InviteStaff::class)->handle($owner, $salon, [
        'name' => 'Co Admin', 'email' => 'coadmin@example.com', 'salon_role' => 'salon_manager',
    ]);

    expect($result->user->membershipFor($salon)->salon_role)->toBe(SalonRole::Manager);
});

it('forbids a salon admin from deactivating or resetting an owner', function () {
    $salon = Salon::factory()->create();
    $admin = salonAdminOf($salon);
    $ownerMembership = salonOwnerOf($salon)->membershipFor($salon);

    expect(fn () => app(SetMembershipActive::class)->handle($admin, $salon, $ownerMembership, false))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ResetStaffPassword::class)->handle($admin, $salon, $ownerMembership))
        ->toThrow(AuthorizationException::class);

    expect($ownerMembership->fresh()->active)->toBeTrue();
});

it('forbids a salon admin from overwriting an existing owner via the invite form', function () {
    $salon = Salon::factory()->create();
    $admin = salonAdminOf($salon);
    $owner = salonOwnerOf($salon);

    // Admin tries to "invite" the existing owner as a stylist (a demote).
    expect(fn () => app(InviteStaff::class)->handle($admin, $salon, [
        'name' => 'x', 'email' => $owner->email, 'salon_role' => 'stylist', 'staff_type' => 'stylist',
    ]))->toThrow(AuthorizationException::class);

    expect($owner->fresh()->membershipFor($salon)->salon_role)->toBe(SalonRole::Owner);
});

it('lets a salon admin promote staff to admin — with the matching staff type', function () {
    $salon = Salon::factory()->create();
    $admin = salonAdminOf($salon);
    $membership = stylistOf($salon)->membershipFor($salon);

    // Full manager surface: promoting stylist→manager is allowed, but the
    // bookability flag must follow the role (a manager is never bookable).
    expect(fn () => app(UpdateStaffMembership::class)->handle($admin, $salon, $membership, [
        'salon_role' => 'salon_manager', 'staff_type' => 'stylist',
    ]))->toThrow(ValidationException::class);

    app(UpdateStaffMembership::class)->handle($admin, $salon, $membership, [
        'salon_role' => 'salon_manager',
    ]);

    expect($membership->fresh()->salon_role)->toBe(SalonRole::Manager);
});

// --- Rule 3: add/remove a STYLIST / front desk --------------------------------

it('lets a salon owner or admin add a stylist, but a stylist cannot add staff', function () {
    $salon = Salon::factory()->create();
    $admin = salonAdminOf($salon);
    $stylist = stylistOf($salon);

    $added = app(InviteStaff::class)->handle($admin, $salon, [
        'name' => 'Stylist One', 'email' => 's1@example.com', 'salon_role' => 'stylist', 'staff_type' => 'stylist',
    ]);
    expect($added->user->membershipFor($salon)->salon_role)->toBe(SalonRole::Stylist);
    expect($added->user->membershipFor($salon)->staff_type)->toBe(StaffType::Stylist);

    expect(fn () => app(InviteStaff::class)->handle($stylist, $salon, [
        'name' => 'Sneaky', 'email' => 'sneaky@example.com', 'salon_role' => 'stylist', 'staff_type' => 'stylist',
    ]))->toThrow(AuthorizationException::class);
});

// --- Rule 4: multi-salon membership reuses the account ------------------------

it('supports a multi-salon user with a role per salon, reusing the account', function () {
    $salonA = Salon::factory()->create();
    $salonB = Salon::factory()->create();
    $ownerB = salonOwnerOf($salonB);

    // A person who is already an owner in salon A.
    $person = User::factory()->create(['email' => 'multi@example.com', 'must_change_password' => false]);
    SalonMembership::factory()->for($person)->for($salonA)->owner()->create();
    $passwordBefore = $person->password;

    // ...is added as a stylist in salon B by B's owner.
    $result = app(InviteStaff::class)->handle($ownerB, $salonB, [
        'name' => 'Ignored', 'email' => 'multi@example.com', 'salon_role' => 'stylist', 'staff_type' => 'stylist',
    ]);

    expect($result->existing)->toBeTrue();
    expect($result->user->id)->toBe($person->id);                 // same account
    expect(User::where('email', 'multi@example.com')->count())->toBe(1); // not duplicated
    expect($person->fresh()->password)->toBe($passwordBefore);    // not reset
    expect($person->fresh()->must_change_password)->toBeFalse();

    // Role is resolved per salon.
    expect($person->membershipFor($salonA)->salon_role)->toBe(SalonRole::Owner);
    expect($person->membershipFor($salonB)->salon_role)->toBe(SalonRole::Stylist);
    expect($person->membershipFor($salonB)->staff_type)->toBe(StaffType::Stylist);
});

// --- Tenant isolation: membership in A grants nothing in B --------------------

it('grants a salon owner no staff authority in another salon', function () {
    $salonA = Salon::factory()->create();
    $salonB = Salon::factory()->create();
    $ownerA = salonOwnerOf($salonA);

    expect((new SalonStaffRoles)->assignable($ownerA, $salonB))->toBe([]);

    expect(fn () => app(InviteStaff::class)->handle($ownerA, $salonB, [
        'name' => 'x', 'email' => 'x@example.com', 'salon_role' => 'stylist', 'staff_type' => 'stylist',
    ]))->toThrow(AuthorizationException::class);
});

it('forbids an agency operator from acting on another agency\'s salon', function () {
    $agencyA = Agency::factory()->create();
    $salonB = Salon::factory()->create(); // different agency
    $ownerA = User::factory()->create(['agency_id' => $agencyA->id, 'agency_role' => AgencyRole::Owner]);

    expect((new SalonStaffRoles)->assignable($ownerA, $salonB))->toBe([]);

    expect(fn () => app(InviteStaff::class)->handle($ownerA, $salonB, [
        'name' => 'x', 'email' => 'cross@example.com', 'salon_role' => 'salon_manager',
    ]))->toThrow(AuthorizationException::class);
});
