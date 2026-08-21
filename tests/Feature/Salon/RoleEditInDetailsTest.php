<?php

use App\Enums\AgencyRole;
use App\Enums\SalonRole;
use App\Enums\StaffType;
use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\User;
use Livewire\Livewire;

/*
| Salon → Users → Edit details: the ROLE is editable there — for agency
| owner, agency admin, salon owner, and salon manager, within their
| assignable roles, enforced server-side (canManage to open, canManage +
| canAssign inside UpdateStaffMembership to save). Stripping Owner still
| runs the ownership-transfer flow (exactly one active owner, always), and
| the cross-agency shared-account guard is untouched.
*/

/** @return array{0: Salon, 1: SalonMembership} salon + a stylist row */
function detailsFixture(): array
{
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);

    return [$salon, $stylist->membershipFor($salon)];
}

it('lets all four privileged roles change a member\'s role from the Edit details modal', function () {
    [$salon, $membership] = detailsFixture();

    $actors = [
        'agency owner' => User::factory()->create(['agency_id' => $salon->agency_id, 'agency_role' => AgencyRole::Owner]),
        'agency admin' => User::factory()->create(['agency_id' => $salon->agency_id, 'agency_role' => AgencyRole::Admin]),
        'salon owner' => salonOwnerOf($salon),
        'salon manager' => salonAdminOf($salon),
    ];

    foreach ($actors as $label => $actor) {
        // Stylist → Manager…
        Livewire::actingAs($actor)
            ->test('pages::salon.users.index', ['salon' => $salon])
            ->call('startOwnerEdit', $membership->id)
            ->assertSet('showOwnerEdit', true)
            ->assertSet('ownerEditRole', 'stylist')
            ->set('ownerEditRole', 'salon_manager')
            ->set('ownerEditTakesBookings', true)
            ->call('saveOwnerDetails')
            ->assertHasNoErrors();
        expect($membership->fresh()->salon_role)->toBe(SalonRole::Manager, "{$label} promote failed");
        expect($membership->fresh()->staff_type)->toBe(StaffType::Stylist);

        // …and back, so the next actor starts clean.
        Livewire::actingAs($actor)
            ->test('pages::salon.users.index', ['salon' => $salon])
            ->call('startOwnerEdit', $membership->id)
            ->set('ownerEditRole', 'stylist')
            ->call('saveOwnerDetails')
            ->assertHasNoErrors();
        expect($membership->fresh()->salon_role)->toBe(SalonRole::Stylist, "{$label} demote failed");
    }
});

it('blocks everyone else: stylists have no page, delegated users no authority, demo no writes', function () {
    [$salon, $membership] = detailsFixture();

    // Stylists cannot reach the Users screen at all.
    $this->actingAs(stylistOf($salon))->get(route('salon.users', $salon))->assertForbidden();

    // A delegated agency_user manages stylists only — they can open a
    // stylist row but CANNOT grant Manager (assignable = stylist only).
    $delegated = User::factory()->create(['agency_id' => $salon->agency_id, 'agency_role' => AgencyRole::User]);
    $delegated->assignedSalons()->attach($salon->id);
    Livewire::actingAs($delegated)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startOwnerEdit', $membership->id)
        ->set('ownerEditRole', 'salon_manager')
        ->call('saveOwnerDetails')
        ->assertHasErrors(['ownerEditRole']);
    expect($membership->fresh()->salon_role)->toBe(SalonRole::Stylist);

    // Demo: the write gate stops the save cold.
    $demo = demoShowcase();
    $demoMembership = $demo->memberships()->where('salon_role', SalonRole::Stylist->value)->firstOrFail();
    Livewire::actingAs(salonOwnerOf($demo))
        ->test('pages::salon.users.index', ['salon' => $demo])
        ->call('startOwnerEdit', $demoMembership->id)
        ->set('ownerEditRole', 'salon_manager')
        ->call('saveOwnerDetails');
    expect($demoMembership->fresh()->salon_role)->toBe(SalonRole::Stylist);
});

it('routes an Owner demotion from the details modal through the transfer flow — exactly one owner, always', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $ownerMembership = $salon->memberships()->where('user_id', $owner->id)->firstOrFail();
    $operator = User::factory()->create(['agency_id' => $salon->agency_id, 'agency_role' => AgencyRole::Owner]);

    // Demoting the owner does NOT change anything yet — the transfer modal
    // opens instead, demanding a replacement first.
    $component = Livewire::actingAs($operator)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startOwnerEdit', $ownerMembership->id)
        ->set('ownerEditRole', 'salon_manager')
        ->call('saveOwnerDetails')
        ->assertSet('showTransfer', true);
    expect($ownerMembership->fresh()->salon_role)->toBe(SalonRole::Owner); // untouched so far

    // Completing the transfer (operator takes over) demotes the old owner
    // and leaves EXACTLY ONE active owner.
    $component->set('transferChoice', 'me')->call('confirmTransfer')->assertHasNoErrors();

    expect($ownerMembership->fresh()->salon_role)->toBe(SalonRole::Manager);
    $owners = $salon->memberships()->where('salon_role', SalonRole::Owner->value)->where('active', true)->get();
    expect($owners)->toHaveCount(1);
    expect($owners->first()->user_id)->toBe($operator->id);
});

it('keeps account fields agency-only inside the modal: a salon owner\'s save never writes them', function () {
    [$salon, $membership] = detailsFixture();
    $user = $membership->user;
    $before = ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone];

    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startOwnerEdit', $membership->id)
        ->set('ownerName', 'Hijacked Name')
        ->set('ownerEmail', 'hijacked@example.com')
        ->set('ownerEditRole', 'salon_manager')
        ->call('saveOwnerDetails')
        ->assertHasNoErrors();

    // The role changed; the account did not.
    expect($membership->fresh()->salon_role)->toBe(SalonRole::Manager);
    $fresh = $user->fresh();
    expect($fresh->name)->toBe($before['name']);
    expect($fresh->email)->toBe($before['email']);

    // An agency operator's save still writes account fields as before.
    $operator = User::factory()->create(['agency_id' => $salon->agency_id, 'agency_role' => AgencyRole::Admin]);
    Livewire::actingAs($operator)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startOwnerEdit', $membership->id)
        ->set('ownerName', 'Properly Renamed')
        ->call('saveOwnerDetails')
        ->assertHasNoErrors();
    expect($user->fresh()->name)->toBe('Properly Renamed');
});
