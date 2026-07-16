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
| The Manager ROLE under the owner/manager/stylist taxonomy (SPEC §2):
| managers run the salon and are never bookable — staff_type (the
| bookability flag) stays NULL for them, enforced server-side.
*/

// ---------------------------------------------------------------------------
// Creation + the type → role mapping
// ---------------------------------------------------------------------------

it('creates a manager through the Users screen — never bookable', function () {
    Mail::fake();
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    Livewire::actingAs($owner)
        ->test('pages::salon.staff.index', ['salon' => $salon])
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

it('rejects the impossible pairing: a bookable manager', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    expect(fn () => app(InviteStaff::class)->handle($owner, $salon, [
        'name' => 'Mismatched', 'email' => 'mismatch@example.com',
        'salon_role' => 'salon_manager', 'staff_type' => 'stylist',
    ]))->toThrow(ValidationException::class);
});

it('rejects an unknown staff type outright — the enum has one case', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    expect(fn () => app(InviteStaff::class)->handle($owner, $salon, [
        'name' => 'Sneaky', 'email' => 'sneaky@example.com',
        'salon_role' => 'stylist', 'staff_type' => 'superuser',
    ]))->toThrow(ValueError::class);
});

it('keeps the bookability flag consistent: owners none by default, stylists always, managers never', function () {
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
    $this->get(route('salon.staff', $salonA))->assertOk();
    $this->get(route('salon.staff', $salonB))->assertForbidden();
});
