<?php

use App\Actions\Availability\AddAvailabilityWindow;
use App\Actions\Services\SyncServiceStylists;
use App\Actions\Staff\InviteStaff;
use App\Actions\Staff\UpdateStaffMembership;
use App\Enums\BookedByType;
use App\Enums\SalonRole;
use App\Enums\StaffType;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use App\Services\Calendar\CalendarData;
use App\Support\Permissions\AvailabilityAccess;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| The manager staff type (SPEC §3 extension): a member who runs the salon in
| the app but performs no operational function — not a stylist (no calendar
| column, no services, no availability) and not front desk (no check-ins).
| Type is orthogonal to role; everything a manager may DO comes from their
| role, and the manager type itself grants nothing.
*/

// ---------------------------------------------------------------------------
// Creation + persistence
// ---------------------------------------------------------------------------

it('creates a staff member with type manager through the staff screen', function () {
    Mail::fake();
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    Livewire::actingAs($owner)
        ->test('pages::salon.staff.index', ['salon' => $salon])
        ->set('name', 'Morgan Manager')
        ->set('email', 'morgan@example.com')
        ->set('role', 'user')
        ->set('staff_type', 'manager')
        ->call('invite')
        ->assertHasNoErrors();

    $user = User::where('email', 'morgan@example.com')->firstOrFail();
    $membership = $salon->memberships()->where('user_id', $user->id)->firstOrFail();

    expect($membership->salon_role)->toBe(SalonRole::User);
    expect($membership->staff_type)->toBe(StaffType::Manager);
});

it('persists the manager type alongside the admin role (office manager)', function () {
    Mail::fake();
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $result = app(InviteStaff::class)->handle($owner, $salon, [
        'name' => 'Olive Office',
        'email' => 'olive@example.com',
        'salon_role' => 'salon_admin',
        'staff_type' => 'manager',
    ]);

    $membership = $salon->memberships()->where('user_id', $result->user->id)->firstOrFail();

    expect($membership->salon_role)->toBe(SalonRole::Admin);
    expect($membership->staff_type)->toBe(StaffType::Manager);
});

it('rejects an unknown staff type at the form boundary', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    Livewire::actingAs($owner)
        ->test('pages::salon.staff.index', ['salon' => $salon])
        ->set('name', 'Sneaky')
        ->set('email', 'sneaky@example.com')
        ->set('role', 'user')
        ->set('staff_type', 'superuser')
        ->call('invite')
        ->assertHasErrors(['staff_type']);
});

it('leaves existing rows untouched — no-type owners and typed staff keep their values', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $stylist = stylistOf($salon);
    $frontDesk = frontDeskOf($salon);

    // Pre-manager rows read back exactly as stored (nullable string column,
    // no migration needed for the new value).
    expect($owner->membershipFor($salon)->staff_type)->toBeNull();
    expect($stylist->membershipFor($salon)->staff_type)->toBe(StaffType::Stylist);
    expect($frontDesk->membershipFor($salon)->staff_type)->toBe(StaffType::FrontDesk);

    // Editing an owner without choosing a type keeps the historical null.
    $membership = $salon->memberships()->where('user_id', $owner->id)->firstOrFail();
    app(UpdateStaffMembership::class)->handle($owner, $salon, $membership, [
        'salon_role' => 'salon_owner',
        'staff_type' => null,
    ]);

    expect($membership->fresh()->staff_type)->toBeNull();
});

// ---------------------------------------------------------------------------
// Excluded from every stylist-only surface
// ---------------------------------------------------------------------------

it('excludes a manager from the stylist roster and per-stylist calendar columns', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    $manager = managerOf($salon);

    expect($salon->stylistUsers()->pluck('users.id')->all())->toBe([$stylist->id]);

    $grid = app(CalendarData::class)->day($salon, CarbonImmutable::now($salon->timezone), null);
    $columnIds = array_column($grid['columns'], 'stylistId');

    expect($columnIds)->toContain($stylist->id);
    expect($columnIds)->not->toContain($manager->id);
});

it('ignores a forged manager id in service stylist assignment', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    $manager = managerOf($salon);
    $service = Service::factory()->for($salon)->create();

    app(SyncServiceStylists::class)->handle($salon, $service, [$stylist->id, $manager->id]);

    expect($service->stylists()->pluck('users.id')->all())->toBe([$stylist->id]);
});

it('rejects stylist availability for a manager, as target and as self', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $manager = managerOf($salon);
    $window = ['weekday' => 1, 'kind' => 'work', 'start_minute' => 9 * 60, 'end_minute' => 17 * 60];

    // Even an owner cannot give a manager stylist availability.
    expect(fn () => app(AddAvailabilityWindow::class)->handle($owner, $salon, $manager->id, $window))
        ->toThrow(ValidationException::class);

    // A member-manager may not manage anyone's availability — their own included.
    expect((new AvailabilityAccess)->canManage($manager, $salon, $manager->id))->toBeFalse();
    expect(fn () => app(AddAvailabilityWindow::class)->handle($manager, $salon, $manager->id, $window))
        ->toThrow(AuthorizationException::class);
});

// ---------------------------------------------------------------------------
// Permissions come from the role, never from the manager type
// ---------------------------------------------------------------------------

it('gives a member-manager minimal access: no check-ins, no calendars, no management', function () {
    $salon = Salon::factory()->create();
    $manager = managerOf($salon);

    expect($manager->can('manage', $salon))->toBeFalse();
    expect($manager->can('manageBookings', $salon))->toBeFalse(); // check-ins stay owner/admin/front-desk
    expect($manager->can('accessBookings', $salon))->toBeFalse();
    expect($manager->can('viewMasterCalendar', $salon))->toBeFalse();

    $this->actingAs($manager);
    $this->get(route('salon.appointments', $salon))->assertForbidden();
    $this->get(route('salon.calendar', $salon))->assertForbidden();
    $this->get(route('salon.services', $salon))->assertForbidden();
    $this->get(route('salon.staff', $salon))->assertForbidden();
    $this->get(route('salon.availability', $salon))->assertForbidden();
});

it('gives an admin-manager full management per the admin role', function () {
    $salon = Salon::factory()->create();
    $adminManager = managerOf($salon, SalonRole::Admin);

    expect($adminManager->can('manage', $salon))->toBeTrue();
    expect($adminManager->can('manageBookings', $salon))->toBeTrue(); // check-ins via role, not type
    expect($adminManager->can('viewMasterCalendar', $salon))->toBeTrue();
    expect(BookedByType::fromActor($adminManager, $salon))->toBe(BookedByType::SalonAdmin);

    $this->actingAs($adminManager);
    $this->get(route('salon.services', $salon))->assertOk();
    $this->get(route('salon.staff', $salon))->assertOk();

    // Managing the salon still gives them no stylist surface of their own.
    expect($salon->stylistUsers()->pluck('users.id')->all())->not->toContain($adminManager->id);
});

it('keeps stylist and front-desk behavior unchanged alongside managers', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    $frontDesk = frontDeskOf($salon);
    managerOf($salon);

    expect($frontDesk->can('manageBookings', $salon))->toBeTrue();
    expect($frontDesk->can('viewMasterCalendar', $salon))->toBeTrue();
    expect($stylist->can('manageBookings', $salon))->toBeFalse();
    expect((new AvailabilityAccess)->canManage($stylist, $salon, $stylist->id))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Tenant isolation
// ---------------------------------------------------------------------------

it('confines an admin-manager to their own salon', function () {
    $salonA = Salon::factory()->create();
    $salonB = Salon::factory()->create();
    $adminManager = managerOf($salonA, SalonRole::Admin);

    expect($adminManager->can('manage', $salonB))->toBeFalse();

    $this->actingAs($adminManager);
    $this->get(route('salon.staff', $salonA))->assertOk();
    $this->get(route('salon.staff', $salonB))->assertForbidden();
});
