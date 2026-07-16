<?php

use App\Actions\AgencyUsers\CreateAgencyUser;
use App\Enums\AgencyRole;
use App\Enums\SalonRole;
use App\Enums\StaffType;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
| The salon Users screen (né Staff): owner listed with everyone else, rows
| the viewer can't manage expose no edit affordance (and 403 server-side),
| the add form is exactly name/email/phone/role, and the owner alone can
| flip their own bookability (the owner-who-cuts-hair switch).
*/

it('lists the owner alongside everyone else, first', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $stylist = stylistOf($salon);

    Livewire::actingAs(salonAdminOf($salon))
        ->test('pages::salon.staff.index', ['salon' => $salon])
        ->assertSee($owner->name)
        ->assertSee($stylist->name)
        ->assertSee(__('Owner'));
});

it('offers no edit affordance on the owner row and 403s server-side edits of the owner', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $ownerMembership = $salon->memberships()->where('user_id', $owner->id)->firstOrFail();

    $component = Livewire::actingAs(salonAdminOf($salon))
        ->test('pages::salon.staff.index', ['salon' => $salon]);

    // No action reaches the owner row from the UI…
    expect($component->instance()->canManageMembership($ownerMembership))->toBeFalse();

    // …and the server refuses regardless of what the client sends.
    $component->call('startEdit', $ownerMembership->id)->assertForbidden();
});

it('collects exactly name, email, phone and role — and persists the phone', function () {
    Mail::fake();
    $salon = Salon::factory()->create();

    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.staff.index', ['salon' => $salon])
        ->set('name', 'Pia Phone')
        ->set('email', 'pia@example.com')
        ->set('phone', '+1 555 010 3344')
        ->set('role', 'stylist')
        ->call('invite')
        ->assertHasNoErrors();

    $user = User::where('email', 'pia@example.com')->firstOrFail();
    expect($user->phone)->toBe('+1 555 010 3344');

    // Role stylist ⇒ bookable; role manager ⇒ not.
    $membership = $salon->memberships()->where('user_id', $user->id)->firstOrFail();
    expect($membership->salon_role)->toBe(SalonRole::Stylist);
    expect($membership->staff_type)->toBe(StaffType::Stylist);
});

it('assigns manager and stylist only — owner is never on offer', function () {
    $salon = Salon::factory()->create();

    $component = Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.staff.index', ['salon' => $salon]);

    expect($component->instance()->assignableRoles())
        ->toBe([SalonRole::Manager, SalonRole::Stylist]);
});

it('lets only the owner flip their own bookability', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $ownerMembership = $salon->memberships()->where('user_id', $owner->id)->firstOrFail();
    expect($ownerMembership->staff_type)->toBeNull();

    // The owner-who-cuts-hair switch, on their own row.
    Livewire::actingAs($owner)
        ->test('pages::salon.staff.index', ['salon' => $salon])
        ->call('toggleOwnerBookable', $ownerMembership->id)
        ->assertHasNoErrors();

    expect($ownerMembership->fresh()->staff_type)->toBe(StaffType::Stylist);

    // A manager may NOT flip the owner's bookability.
    Livewire::actingAs(salonAdminOf($salon))
        ->test('pages::salon.staff.index', ['salon' => $salon])
        ->call('toggleOwnerBookable', $ownerMembership->id)
        ->assertForbidden();

    expect($ownerMembership->fresh()->staff_type)->toBe(StaffType::Stylist);
});

it('keeps a bookable owner bookable through the role migration', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $membership = $salon->memberships()->where('user_id', $owner->id)->firstOrFail();
    $membership->update(['staff_type' => StaffType::Stylist]);

    $migration = require database_path('migrations/2026_07_27_000003_remap_salon_roles_to_owner_manager_stylist.php');
    $migration->up();

    expect($membership->fresh()->salon_role)->toBe(SalonRole::Owner);
    expect($membership->fresh()->staff_type)->toBe(StaffType::Stylist);
});

// ---------------------------------------------------------------------------
// Agency side
// ---------------------------------------------------------------------------

it('shows salon owners in the agency Users tab with their salon and role', function () {
    $agency = Agency::factory()->create();
    $salon = Salon::factory()->for($agency)->create(['name' => 'Glow Studio']);
    $owner = salonOwnerOf($salon);
    $agencyOwner = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Owner]);

    Livewire::actingAs($agencyOwner)
        ->test('pages::agency.users.index')
        ->assertSee($owner->name)
        ->assertSee('Glow Studio');

    // The Salon: Owner filter narrows to them.
    Livewire::actingAs($agencyOwner)
        ->test('pages::agency.users.index')
        ->set('role', SalonRole::Owner->value)
        ->assertSee($owner->name);
});

it('persists the phone on agency-created users', function () {
    Mail::fake();
    $agency = Agency::factory()->create();
    $agencyOwner = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Owner]);

    $result = app(CreateAgencyUser::class)->handle($agencyOwner, $agency, [
        'name' => 'Ana Agency',
        'email' => 'ana@example.com',
        'phone' => '+1 555 010 7788',
        'agency_role' => 'agency_user',
    ]);

    expect($result->user->phone)->toBe('+1 555 010 7788');
});
