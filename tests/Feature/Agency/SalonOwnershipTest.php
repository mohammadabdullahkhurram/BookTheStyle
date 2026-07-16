<?php

use App\Actions\Salons\SetSalonOwner;
use App\Actions\Staff\InviteStaff;
use App\Enums\AgencyRole;
use App\Enums\SalonRole;
use App\Enums\StaffType;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\User;
use App\Support\Permissions\SalonStaffRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
| Salon ownership is granted exactly two ways: auto-provisioned at salon
| creation, or assigned/transferred by the AGENCY OWNER from the agency
| console (SetSalonOwner). The invite path refuses Owner from everyone, and
| no path can ever leave a salon with two owners.
*/

function ownershipAgency(): array
{
    $agency = Agency::factory()->create();
    $agencyOwner = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Owner]);

    return [$agency, $agencyOwner];
}

function activeOwners(Salon $salon): int
{
    return $salon->memberships()
        ->where('salon_role', SalonRole::Owner->value)
        ->where('active', true)
        ->count();
}

// ---------------------------------------------------------------------------
// The picker and the invite path
// ---------------------------------------------------------------------------

it('never offers Owner in the role picker — ownerless salon and agency owner included', function () {
    [$agency, $agencyOwner] = ownershipAgency();
    $ownerless = Salon::factory()->for($agency)->create();
    $owned = Salon::factory()->for($agency)->create();
    salonOwnerOf($owned);

    foreach ([$ownerless, $owned] as $salon) {
        expect((new SalonStaffRoles)->assignable($agencyOwner, $salon))
            ->toBe([SalonRole::Manager, SalonRole::Stylist]);
        expect((new SalonStaffRoles)->assignable(salonAdminOf($salon), $salon))
            ->toBe([SalonRole::Manager, SalonRole::Stylist]);
    }
});

it('rejects an Owner invite server-side from every caller', function () {
    Mail::fake();
    [$agency, $agencyOwner] = ownershipAgency();
    $salon = Salon::factory()->for($agency)->create();
    $salonOwner = salonOwnerOf($salon);
    $manager = salonAdminOf($salon);

    foreach ([$agencyOwner, $salonOwner, $manager] as $actor) {
        expect(fn () => app(InviteStaff::class)->handle($actor, $salon, [
            'name' => 'Pretender', 'email' => 'pretender@example.com', 'salon_role' => 'salon_owner',
        ]))->toThrow(AuthorizationException::class);
    }

    expect(activeOwners($salon))->toBe(1);
});

// ---------------------------------------------------------------------------
// Assignment and transfer (the agency console path)
// ---------------------------------------------------------------------------

it('lets the agency owner give the ownerless salon an owner by provisioning', function () {
    Mail::fake();
    [$agency, $agencyOwner] = ownershipAgency();
    $salon = Salon::factory()->for($agency)->create();
    expect(activeOwners($salon))->toBe(0);

    $result = app(SetSalonOwner::class)->handle($agencyOwner, $salon, [
        'name' => 'Nadia New', 'email' => 'nadia@example.com', 'phone' => '+1 555 010 2020',
    ]);

    expect(activeOwners($salon))->toBe(1);
    expect($result->temporaryPassword)->not->toBeNull(); // new account, credentials shown once
    $membership = $salon->memberships()->where('salon_role', SalonRole::Owner->value)->firstOrFail();
    expect($membership->user->email)->toBe('nadia@example.com');
    expect($membership->user->phone)->toBe('+1 555 010 2020');
});

it('transfers ownership by promoting a member — a bookable one stays bookable', function () {
    [$agency, $agencyOwner] = ownershipAgency();
    $salon = Salon::factory()->for($agency)->create();
    $oldOwner = salonOwnerOf($salon);
    $stylist = stylistOf($salon);
    $stylistMembership = $salon->memberships()->where('user_id', $stylist->id)->firstOrFail();

    app(SetSalonOwner::class)->handle($agencyOwner, $salon, [
        'membership_id' => $stylistMembership->id,
    ]);

    expect(activeOwners($salon))->toBe(1);

    // The promoted stylist owns the salon AND keeps taking bookings.
    $fresh = $stylistMembership->fresh();
    expect($fresh->salon_role)->toBe(SalonRole::Owner);
    expect($fresh->staff_type)->toBe(StaffType::Stylist);

    // The previous (non-bookable) owner became a manager — never deleted.
    $demoted = $salon->memberships()->where('user_id', $oldOwner->id)->firstOrFail();
    expect($demoted->salon_role)->toBe(SalonRole::Manager);
    expect($demoted->staff_type)->toBeNull();
});

it('demotes a bookable ex-owner to stylist so their calendar column survives', function () {
    Mail::fake();
    [$agency, $agencyOwner] = ownershipAgency();
    $salon = Salon::factory()->for($agency)->create();
    $oldOwner = salonOwnerOf($salon);
    $salon->memberships()->where('user_id', $oldOwner->id)->update(['staff_type' => StaffType::Stylist]);

    app(SetSalonOwner::class)->handle($agencyOwner, $salon, [
        'name' => 'Nadia New', 'email' => 'nadia@example.com',
    ]);

    $demoted = $salon->memberships()->where('user_id', $oldOwner->id)->firstOrFail();
    expect($demoted->salon_role)->toBe(SalonRole::Stylist);
    expect($demoted->staff_type)->toBe(StaffType::Stylist);
    expect(activeOwners($salon))->toBe(1);
});

it('links an existing account as owner without new credentials', function () {
    Mail::fake();
    [$agency, $agencyOwner] = ownershipAgency();
    $salon = Salon::factory()->for($agency)->create();
    $existing = User::factory()->create();

    $result = app(SetSalonOwner::class)->handle($agencyOwner, $salon, [
        'name' => $existing->name, 'email' => $existing->email,
    ]);

    expect($result->temporaryPassword)->toBeNull();
    expect($existing->fresh()->membershipFor($salon)->salon_role)->toBe(SalonRole::Owner);
    expect(activeOwners($salon))->toBe(1);
});

it('re-assigning the incumbent owner is a no-op that keeps their bookability', function () {
    [$agency, $agencyOwner] = ownershipAgency();
    $salon = Salon::factory()->for($agency)->create();
    $owner = salonOwnerOf($salon);
    $membership = $salon->memberships()->where('user_id', $owner->id)->firstOrFail();
    $membership->update(['staff_type' => StaffType::Stylist]);

    app(SetSalonOwner::class)->handle($agencyOwner, $salon, ['membership_id' => $membership->id]);

    $fresh = $membership->fresh();
    expect($fresh->salon_role)->toBe(SalonRole::Owner);
    expect($fresh->staff_type)->toBe(StaffType::Stylist);
    expect(activeOwners($salon))->toBe(1);
});

// ---------------------------------------------------------------------------
// Who may assign
// ---------------------------------------------------------------------------

it('refuses everyone but the agency owner: salon manager, agency admin, cross-agency', function () {
    [$agency] = ownershipAgency();
    $salon = Salon::factory()->for($agency)->create();
    salonOwnerOf($salon);
    $stylistMembership = $salon->memberships()->whereKey(
        $salon->memberships()->where('user_id', stylistOf($salon)->id)->value('id')
    )->firstOrFail();

    $manager = salonAdminOf($salon);
    $agencyAdmin = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Admin]);
    [, $foreignAgencyOwner] = ownershipAgency();

    foreach ([$manager, $agencyAdmin, $foreignAgencyOwner] as $actor) {
        expect(fn () => app(SetSalonOwner::class)->handle($actor, $salon, [
            'membership_id' => $stylistMembership->id,
        ]))->toThrow(AuthorizationException::class);
    }

    expect(activeOwners($salon))->toBe(1);
});

it('exposes the ownership controls only to the agency owner, and 403s others', function () {
    [$agency, $agencyOwner] = ownershipAgency();
    $salon = Salon::factory()->for($agency)->create();
    $stylistMembership = $salon->memberships()->where('user_id', stylistOf($salon)->id)->firstOrFail();
    $agencyAdmin = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Admin]);

    // Agency owner: the assign flow works end to end through the screen.
    Livewire::actingAs($agencyOwner)
        ->test('pages::agency.salons.edit', ['salon' => $salon])
        ->assertSee(__('Ownership'))
        ->assertSee(__('This salon has no owner yet. Assign one below — every salon needs exactly one.'))
        ->set('promoteMembershipId', (string) $stylistMembership->id)
        ->call('promoteToOwner')
        ->assertHasNoErrors();

    expect(activeOwners($salon->fresh()))->toBe(1);

    // Agency admin: sees the page, not the controls; the action 403s.
    Livewire::actingAs($agencyAdmin)
        ->test('pages::agency.salons.edit', ['salon' => $salon])
        ->assertSee(__('Only the agency owner can assign or transfer salon ownership.'))
        ->set('promoteMembershipId', (string) $stylistMembership->id)
        ->call('promoteToOwner')
        ->assertForbidden();
});
