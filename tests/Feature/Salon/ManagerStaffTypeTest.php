<?php

use App\Actions\Availability\SaveWeeklyHours;
use App\Actions\Services\SyncServiceStylists;
use App\Actions\Staff\InviteStaff;
use App\Enums\BookedByType;
use App\Enums\SalonRole;
use App\Enums\StaffType;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use App\Services\Calendar\CalendarData;
use App\Support\Permissions\AvailabilityAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| The manager staff type under the role taxonomy (SPEC §2): the TYPE is
| functional (managers run the salon; they are never bookable) and MAPS to
| the ADMIN role — enforced server-side, so a "member-manager" cannot exist.
| Everything a manager may do comes from the admin role.
*/

// ---------------------------------------------------------------------------
// Creation + the type → role mapping
// ---------------------------------------------------------------------------

it('creates a manager as a salon ADMIN through the staff screen', function () {
    Mail::fake();
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    Livewire::actingAs($owner)
        ->test('pages::salon.staff.index', ['salon' => $salon])
        ->set('name', 'Morgan Manager')
        ->set('email', 'morgan@example.com')
        ->set('role', 'salon_admin')
        ->set('staff_type', 'manager')
        ->call('invite')
        ->assertHasNoErrors();

    $user = User::where('email', 'morgan@example.com')->firstOrFail();
    $membership = $salon->memberships()->where('user_id', $user->id)->firstOrFail();

    expect($membership->salon_role)->toBe(SalonRole::Admin);
    expect($membership->staff_type)->toBe(StaffType::Manager);
});

it('rejects the impossible pairings: staff+manager, staff+front-desk, admin+stylist', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    foreach ([
        ['staff', 'manager'],
        ['staff', 'front_desk'],
        ['salon_admin', 'stylist'],
    ] as [$role, $type]) {
        expect(fn () => app(InviteStaff::class)->handle($owner, $salon, [
            'name' => 'Mismatched', 'email' => 'mismatch-'.$type.'@example.com',
            'salon_role' => $role, 'staff_type' => $type,
        ]))->toThrow(ValidationException::class);
    }
});

it('rejects an unknown staff type at the form boundary', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    Livewire::actingAs($owner)
        ->test('pages::salon.staff.index', ['salon' => $salon])
        ->set('name', 'Sneaky')
        ->set('email', 'sneaky@example.com')
        ->set('role', 'staff')
        ->set('staff_type', 'superuser')
        ->call('invite')
        ->assertHasErrors(['staff_type']);
});

it('leaves existing rows untouched — no-type owners and typed staff keep their values', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $stylist = stylistOf($salon);
    $frontDesk = frontDeskOf($salon);

    expect($owner->membershipFor($salon)->staff_type)->toBeNull();
    expect($stylist->membershipFor($salon)->staff_type)->toBe(StaffType::Stylist);
    expect($frontDesk->membershipFor($salon)->staff_type)->toBe(StaffType::FrontDesk);
    expect($frontDesk->membershipFor($salon)->salon_role)->toBe(SalonRole::Admin);
});

// ---------------------------------------------------------------------------
// Excluded from every stylist-only surface (the type stays functional)
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

it('never gives a manager stylist availability — they are not bookable', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $manager = managerOf($salon);
    $week = [1 => [['start_minute' => 9 * 60, 'end_minute' => 17 * 60]]];

    // Even an owner cannot give a manager stylist availability (not a stylist).
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
    $this->get(route('salon.staff', $salon))->assertOk();

    // Managing the salon still gives them no stylist surface of their own.
    expect($salon->stylistUsers()->pluck('users.id')->all())->not->toContain($manager->id);
});

it('keeps stylist behavior unchanged; front desk holds the admin role', function () {
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
    $this->get(route('salon.staff', $salonA))->assertOk();
    $this->get(route('salon.staff', $salonB))->assertForbidden();
});
