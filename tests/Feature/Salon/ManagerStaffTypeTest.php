<?php

use App\Actions\Availability\SaveWeeklyHours;
use App\Actions\Services\SyncServiceStylists;
use App\Actions\Staff\InviteStaff;
use App\Actions\Staff\UpdateStaffMembership;
use App\Enums\BookedByType;
use App\Enums\SalonRole;
use App\Enums\SalonType;
use App\Enums\StaffType;
use App\Enums\StylistArrangement;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use App\Services\Calendar\CalendarData;
use App\Support\Permissions\AvailabilityAccess;
use App\Support\Permissions\SalonStaffRoles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| The Manager ROLE under the owner/manager/stylist taxonomy (SPEC §2):
| managers run the salon and are non-bookable BY DEFAULT — staff_type (the
| bookability flag) is NULL unless they opt into takes-bookings, the same
| capability owners carry. A non-bookable manager stays off every stylist
| surface; a bookable one gains them while keeping manager authority.
*/

// ---------------------------------------------------------------------------
// Creation + the type → role mapping
// ---------------------------------------------------------------------------

it('creates a manager through the Users screen — never bookable', function () {
    Mail::fake();
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    Livewire::actingAs($owner)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->set('name', 'Morgan Manager')
        ->set('email', 'morgan@example.com')
        ->set('role', 'salon_manager')
        ->call('invite')
        ->assertHasNoErrors();

    $user = User::where('email', 'morgan@example.com')->firstOrFail();
    $membership = $salon->memberships()->where('user_id', $user->id)->firstOrFail();

    expect($membership->salon_role)->toBe(SalonRole::Manager);
    expect($membership->staff_type)->toBeNull();
});

it('accepts a bookable manager — takes-bookings is a valid pairing now', function () {
    Mail::fake();
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $result = app(InviteStaff::class)->handle($owner, $salon, [
        'name' => 'Booking Manager', 'email' => 'booking-manager@example.com',
        'salon_role' => 'salon_manager', 'staff_type' => 'stylist',
    ]);

    $membership = $result->user->membershipFor($salon);
    expect($membership->salon_role)->toBe(SalonRole::Manager);
    expect($membership->staff_type)->toBe(StaffType::Stylist);
});

it('still rejects the one impossible pairing: a stylist without the bookable flag', function () {
    $roles = new SalonStaffRoles;

    expect(fn () => $roles->assertRoleMatchesType(SalonRole::Stylist, null))
        ->toThrow(ValidationException::class);

    // Owner and manager accept both: takes-bookings is optional for them.
    foreach ([SalonRole::Owner, SalonRole::Manager] as $role) {
        $roles->assertRoleMatchesType($role, null);
        $roles->assertRoleMatchesType($role, StaffType::Stylist);
    }
    expect(true)->toBeTrue(); // no throw above
});

it('rejects an unknown staff type outright — the enum has one case', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    expect(fn () => app(InviteStaff::class)->handle($owner, $salon, [
        'name' => 'Sneaky', 'email' => 'sneaky@example.com',
        'salon_role' => 'stylist', 'staff_type' => 'superuser',
    ]))->toThrow(ValueError::class);
});

it('keeps the bookability flag consistent: owners and managers none by default, stylists always', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $stylist = stylistOf($salon);
    $manager = frontDeskOf($salon);

    expect($owner->membershipFor($salon)->staff_type)->toBeNull();
    expect($stylist->membershipFor($salon)->staff_type)->toBe(StaffType::Stylist);
    expect($manager->membershipFor($salon)->staff_type)->toBeNull();
    expect($manager->membershipFor($salon)->salon_role)->toBe(SalonRole::Manager);
});

// ---------------------------------------------------------------------------
// Excluded from every stylist-only surface (the type stays functional)
// ---------------------------------------------------------------------------

it('excludes a NON-BOOKABLE manager from the stylist roster and per-stylist calendar columns', function () {
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

it('never gives a NON-BOOKABLE manager stylist availability', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $manager = managerOf($salon);
    $week = [1 => [['start_minute' => 9 * 60, 'end_minute' => 17 * 60]]];

    // Without takes-bookings, a manager has no stylist surface to schedule.
    expect(fn () => app(SaveWeeklyHours::class)->handle($owner, $salon, $manager->id, $week))
        ->toThrow(ValidationException::class);
    expect(fn () => app(SaveWeeklyHours::class)->handle($manager, $salon, $manager->id, $week))
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// Full admin surface via the role
// ---------------------------------------------------------------------------

it('gives a manager the full salon admin surface', function () {
    $salon = Salon::factory()->create();
    $manager = managerOf($salon);

    expect($manager->can('manage', $salon))->toBeTrue();
    expect($manager->can('manageBookings', $salon))->toBeTrue();
    expect($manager->can('viewMasterCalendar', $salon))->toBeTrue();
    expect($manager->can('manageGhlConnection', $salon))->toBeTrue();
    expect(BookedByType::fromActor($manager, $salon))->toBe(BookedByType::SalonAdmin);
    expect((new AvailabilityAccess)->canManage($manager, $salon, stylistOf($salon)->id))->toBeTrue();

    $this->actingAs($manager);
    $this->get(route('salon.services', $salon))->assertOk();
    $this->get(route('salon.users', $salon))->assertOk();

    // Managing the salon still gives them no stylist surface of their own.
    expect($salon->stylistUsers()->pluck('users.id')->all())->not->toContain($manager->id);
});

it('keeps stylist behavior unchanged; desk-running members hold the manager role', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    $frontDesk = frontDeskOf($salon);
    managerOf($salon);

    expect($frontDesk->can('manageBookings', $salon))->toBeTrue();
    expect($frontDesk->can('viewMasterCalendar', $salon))->toBeTrue();
    expect($stylist->can('manageBookings', $salon))->toBeFalse();
    expect($stylist->can('manage', $salon))->toBeFalse();
    expect((new AvailabilityAccess)->canManage($stylist, $salon, $stylist->id))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Tenant isolation
// ---------------------------------------------------------------------------

it('confines a manager to their own salon', function () {
    $salonA = Salon::factory()->create();
    $salonB = Salon::factory()->create();
    $manager = managerOf($salonA);

    expect($manager->can('manage', $salonB))->toBeFalse();

    $this->actingAs($manager);
    $this->get(route('salon.users', $salonA))->assertOk();
    $this->get(route('salon.users', $salonB))->assertForbidden();
});

// ---------------------------------------------------------------------------
// Takes bookings — the owner capability, extended to managers
// ---------------------------------------------------------------------------

it('lets a manager opt into taking bookings from their own row — flag persists, badge renders, authority kept', function () {
    $salon = Salon::factory()->create();
    salonOwnerOf($salon);
    $manager = managerOf($salon);
    $membership = $manager->membershipFor($salon);

    Livewire::actingAs($manager)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->assertSee(__('Take bookings'))
        ->call('toggleOwnerBookable', $membership->id)
        ->assertHasNoErrors();

    expect($membership->fresh()->staff_type)->toBe(StaffType::Stylist);

    // The badge shows for a bookable manager, and manager authority is kept.
    Livewire::actingAs($manager)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->assertSee(__('Takes bookings'))
        ->assertSee(__('Stop taking bookings'));
    expect($manager->can('manage', $salon))->toBeTrue();
    expect($manager->can('manageBookings', $salon))->toBeTrue();

    // And back out again.
    Livewire::actingAs($manager)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('toggleOwnerBookable', $membership->id)
        ->assertHasNoErrors();
    expect($membership->fresh()->staff_type)->toBeNull();
});

it('keeps the switch self-only: nobody flips a manager, stylists have no switch', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $manager = managerOf($salon);
    $managerMembership = $manager->membershipFor($salon);
    $stylistMembership = stylistOf($salon)->membershipFor($salon);

    // Even the owner cannot flip the manager's bookability.
    Livewire::actingAs($owner)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('toggleOwnerBookable', $managerMembership->id)
        ->assertForbidden();
    expect($managerMembership->fresh()->staff_type)->toBeNull();

    // A stylist is inherently bookable and has no switch — they cannot even
    // reach the Users screen (scope-down at mount).
    $this->actingAs($stylistMembership->user)
        ->get(route('salon.users', $salon))
        ->assertForbidden();
});

it('gives a bookable manager the full stylist surfaces: roster, calendar column, services, availability', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $stylist = stylistOf($salon);
    $manager = managerOf($salon);
    $manager->membershipFor($salon)->update(['staff_type' => StaffType::Stylist]);

    // Roster + per-stylist calendar columns.
    expect($salon->stylistUsers()->pluck('users.id')->all())
        ->toEqualCanonicalizing([$stylist->id, $manager->id]);
    $grid = app(CalendarData::class)->day($salon, CarbonImmutable::now($salon->timezone), null);
    expect(array_column($grid['columns'], 'stylistId'))->toContain($manager->id);

    // Service assignment now accepts them.
    $service = Service::factory()->for($salon)->create();
    app(SyncServiceStylists::class)->handle($salon, $service, [$stylist->id, $manager->id]);
    expect($service->stylists()->pluck('users.id')->all())
        ->toEqualCanonicalizing([$stylist->id, $manager->id]);

    // A schedule can be saved — by themselves and by the owner.
    $week = [1 => [['start_minute' => 9 * 60, 'end_minute' => 17 * 60]]];
    app(SaveWeeklyHours::class)->handle($manager, $salon, $manager->id, $week);
    app(SaveWeeklyHours::class)->handle($owner, $salon, $manager->id, $week);
    expect(true)->toBeTrue(); // no throw above
});

it('keeps bookability sticky on role edits and clears it on explicit opt-out', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $manager = managerOf($salon);
    $membership = $manager->membershipFor($salon);
    $membership->update(['staff_type' => StaffType::Stylist]);

    // A role-untouched edit (arrangement only) keeps the flag.
    app(UpdateStaffMembership::class)->handle($owner, $salon, $membership, [
        'salon_role' => 'salon_manager',
    ]);
    expect($membership->fresh()->staff_type)->toBe(StaffType::Stylist);

    // Explicit null clears it back to the manager default.
    app(UpdateStaffMembership::class)->handle($owner, $salon, $membership, [
        'salon_role' => 'salon_manager', 'staff_type' => null,
    ]);
    expect($membership->fresh()->staff_type)->toBeNull();
});

it('renders the demo showcase manager read-only: the switch is a blocked no-op there', function () {
    $salon = demoShowcase();
    $managerMembership = $salon->memberships()
        ->where('salon_role', SalonRole::Manager->value)
        ->firstOrFail();

    Livewire::actingAs($managerMembership->user)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('toggleOwnerBookable', $managerMembership->id)
        ->assertHasNoErrors(); // blocked no-op, not an error

    expect($managerMembership->fresh()->staff_type)->toBeNull();
});

// ---------------------------------------------------------------------------
// Takes bookings at invite time (the Add user modal)
// ---------------------------------------------------------------------------

it('shows the takes-bookings box only for the manager role — reactively', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon); // non-bookable: no badge to collide with

    $component = Livewire::actingAs($owner)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startAdd');

    // Default role is stylist: inherently bookable, no box.
    $component->assertSet('role', 'stylist')->assertDontSee(__('Takes bookings'));

    // Switching to manager reveals it; switching back hides it again.
    $component->set('role', 'salon_manager')->assertSee(__('Takes bookings'));
    $component->set('role', 'stylist')->assertDontSee(__('Takes bookings'));
});

it('creates a bookable manager straight from the Add user modal', function () {
    Mail::fake();
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    Livewire::actingAs($owner)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startAdd')
        ->set('name', 'Bookable Blake')
        ->set('email', 'blake@example.com')
        ->set('role', 'salon_manager')
        ->set('takesBookings', true)
        ->call('invite')
        ->assertHasNoErrors();

    $membership = User::where('email', 'blake@example.com')->firstOrFail()->membershipFor($salon);
    expect($membership->salon_role)->toBe(SalonRole::Manager);
    expect($membership->staff_type)->toBe(StaffType::Stylist); // the same flag the edit switch writes

    // They land on the bookable roster immediately.
    expect($salon->stylistUsers()->pluck('users.id')->all())->toContain($membership->user_id);
});

it('creates a non-bookable manager when the box stays unchecked — and stylists stay inherent', function () {
    Mail::fake();
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    Livewire::actingAs($owner)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startAdd')
        ->set('name', 'Deskbound Drew')
        ->set('email', 'drew@example.com')
        ->set('role', 'salon_manager')
        ->call('invite')
        ->assertHasNoErrors();

    expect(User::where('email', 'drew@example.com')->firstOrFail()
        ->membershipFor($salon)->staff_type)->toBeNull();

    // A stylist invite is untouched by the box — inherently bookable, even
    // if the toggle was flipped while manager was selected and role changed.
    Livewire::actingAs($owner)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startAdd')
        ->set('name', 'Standard Sky')
        ->set('email', 'sky@example.com')
        ->set('role', 'salon_manager')
        ->set('takesBookings', true)
        ->set('role', 'stylist')
        ->call('invite')
        ->assertHasNoErrors();

    $stylist = User::where('email', 'sky@example.com')->firstOrFail()->membershipFor($salon);
    expect($stylist->salon_role)->toBe(SalonRole::Stylist);
    expect($stylist->staff_type)->toBe(StaffType::Stylist);
});

// ---------------------------------------------------------------------------
// Takes bookings in the Edit modal + on role changes into Manager
// ---------------------------------------------------------------------------

it('shows the takes-bookings box in the Edit modal for a manager — toggling persists both ways', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $membership = managerOf($salon)->membershipFor($salon);

    // Renders for the manager, reflecting their current (non-bookable) state.
    Livewire::actingAs($owner)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startEdit', $membership->id)
        ->assertSet('editTakesBookings', false)
        ->assertSee(__('Takes bookings'))
        ->set('editTakesBookings', true)
        ->call('saveEdit')
        ->assertHasNoErrors();
    expect($membership->fresh()->staff_type)->toBe(StaffType::Stylist);

    // Re-opening reflects the bookable state; unticking clears it.
    Livewire::actingAs($owner)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startEdit', $membership->id)
        ->assertSet('editTakesBookings', true)
        ->set('editTakesBookings', false)
        ->call('saveEdit')
        ->assertHasNoErrors();
    $fresh = $membership->fresh();
    expect($fresh->staff_type)->toBeNull();
    expect($fresh->salon_role)->toBe(SalonRole::Manager);
});

it('surfaces a hittable box on stylist → manager, defaulting to their prior bookable state', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    // Keeping the box on: the promoted stylist stays bookable.
    $keeps = stylistOf($salon)->membershipFor($salon);
    Livewire::actingAs($owner)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startEdit', $keeps->id)
        ->assertSet('editTakesBookings', true) // stylists are bookable, so the default is ON
        ->set('editRole', 'salon_manager')
        ->assertSee(__('Takes bookings'))
        ->call('saveEdit')
        ->assertHasNoErrors();
    $fresh = $keeps->fresh();
    expect($fresh->salon_role)->toBe(SalonRole::Manager);
    expect($fresh->staff_type)->toBe(StaffType::Stylist);

    // Unticking it in the same save: promoted, but off the calendar.
    $drops = stylistOf($salon)->membershipFor($salon);
    Livewire::actingAs($owner)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startEdit', $drops->id)
        ->set('editRole', 'salon_manager')
        ->set('editTakesBookings', false)
        ->call('saveEdit')
        ->assertHasNoErrors();
    $fresh = $drops->fresh();
    expect($fresh->salon_role)->toBe(SalonRole::Manager);
    expect($fresh->staff_type)->toBeNull();
});

it('sets a bookable manager up identically to an owner-as-stylist in every salon type', function () {
    foreach (SalonType::cases() as $type) {
        $salon = Salon::factory()->create(['salon_type' => $type]);
        $owner = salonOwnerOf($salon);
        $ownerMembership = $owner->membershipFor($salon);
        $managerMembership = managerOf($salon)->membershipFor($salon);
        $stylist = stylistOf($salon);

        // The owner reference: their self-row switch makes them bookable.
        Livewire::actingAs($owner)
            ->test('pages::salon.users.index', ['salon' => $salon])
            ->call('toggleOwnerBookable', $ownerMembership->id)
            ->assertHasNoErrors();

        // The manager, through the Edit modal.
        Livewire::actingAs($owner)
            ->test('pages::salon.users.index', ['salon' => $salon])
            ->call('startEdit', $managerMembership->id)
            ->set('editTakesBookings', true)
            ->call('saveEdit')
            ->assertHasNoErrors();

        $ownerFresh = $ownerMembership->fresh();
        $managerFresh = $managerMembership->fresh();

        // Same flag, same arrangement semantics as the owner in this salon
        // type: employee-style shared calendar; booth-rental scoping stays
        // a Stylist-role concept (boothRenterMembershipFor excludes both).
        expect($managerFresh->staff_type)->toBe(StaffType::Stylist);
        expect($managerFresh->arrangement)->toBe($ownerFresh->arrangement);
        expect($managerFresh->arrangement)->toBe(StylistArrangement::Employee);
        expect($managerFresh->user->boothRenterMembershipFor($salon))->toBeNull();
        expect($owner->boothRenterMembershipFor($salon))->toBeNull();

        // Both join the bookable roster and are service-assignable, exactly
        // like a regular stylist in this salon type.
        expect($salon->stylistUsers()->pluck('users.id')->all())
            ->toEqualCanonicalizing([$owner->id, $managerFresh->user_id, $stylist->id]);
        $service = Service::factory()->for($salon)->create();
        app(SyncServiceStylists::class)->handle($salon, $service, [$managerFresh->user_id, $owner->id, $stylist->id]);
        expect($service->stylists()->pluck('users.id')->all())
            ->toEqualCanonicalizing([$managerFresh->user_id, $owner->id, $stylist->id]);
    }
});
