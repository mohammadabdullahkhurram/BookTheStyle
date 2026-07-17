<?php

use App\Actions\Bookings\CreateBooking;
use App\Actions\Bookings\TransitionBookingStatus;
use App\Actions\Salons\ChangeSalonType;
use App\Actions\Salons\CreateSalon;
use App\Actions\Staff\InviteStaff;
use App\Enums\AgencyRole;
use App\Enums\BookingStatus;
use App\Enums\SalonType;
use App\Enums\StylistArrangement;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Salon;
use App\Models\User;
use App\Services\Reporting\SalonReport;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
| Salon types (employee · booth_rental · mix) govern each stylist's
| ARRANGEMENT. Booth renters are separate businesses: they book and manage
| their own clients and see only their own book, clients, and revenue —
| and never each other's. Employee stylists keep today's behavior plus the
| shared (read-only) salon calendar. Owners/managers see everything, always.
*/

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC'));
});
afterEach(fn () => Carbon::setTestNow());

function boothSalon(): Salon
{
    return bookingSalon(['salon_type' => SalonType::BoothRental]);
}

function boothRenterOf(Salon $salon): User
{
    $user = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $salon->memberships()->where('user_id', $user->id)
        ->update(['arrangement' => StylistArrangement::BoothRental->value]);

    return $user;
}

function bookFor(Salon $salon, User $actor, User $stylist, string $start, ?Client $client = null): Booking
{
    $service = serviceFor($salon, $stylist, 60);

    return app(CreateBooking::class)->handle($actor, $salon, [
        'client' => $client ? ['id' => $client->id] : ['name' => 'Client of '.$stylist->name],
        'items' => [['service_id' => $service->id, 'stylist_id' => $stylist->id]],
        'start' => $start,
        'is_walkin' => false,
        'notes' => null,
    ]);
}

// ---------------------------------------------------------------------------
// Schema, backfill, creation, transitions
// ---------------------------------------------------------------------------

it('backfills to employee: existing salons and memberships change nothing', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);

    expect($salon->salon_type)->toBe(SalonType::Employee);
    expect($stylist->membershipFor($salon)->arrangement)->toBe(StylistArrangement::Employee);
});

it('persists the salon type chosen at creation', function () {
    Mail::fake();
    $agency = Agency::factory()->create();
    $agencyOwner = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Owner]);

    $salon = app(CreateSalon::class)->handle($agencyOwner, $agency, salonProfileInput([
        'name' => 'Booths', 'slug' => 'booths', 'timezone' => 'UTC', 'salon_type' => 'booth_rental',
    ]));

    expect($salon->salon_type)->toBe(SalonType::BoothRental);
});

it('locks the arrangement to the salon type — mix alone chooses per stylist', function () {
    Mail::fake();
    $agency = Agency::factory()->create();

    // Employee salon: a requested booth_rental is overridden to employee.
    $employee = Salon::factory()->for($agency)->create(['salon_type' => SalonType::Employee]);
    $r1 = app(InviteStaff::class)->handle(salonOwnerOf($employee), $employee, [
        'name' => 'E', 'email' => 'e@example.com', 'salon_role' => 'stylist', 'arrangement' => 'booth_rental',
    ]);
    expect($r1->user->membershipFor($employee)->arrangement)->toBe(StylistArrangement::Employee);

    // Booth salon: employee request overridden to booth_rental.
    $booth = Salon::factory()->for($agency)->create(['salon_type' => SalonType::BoothRental]);
    $r2 = app(InviteStaff::class)->handle(salonOwnerOf($booth), $booth, [
        'name' => 'B', 'email' => 'b@example.com', 'salon_role' => 'stylist', 'arrangement' => 'employee',
    ]);
    expect($r2->user->membershipFor($booth)->arrangement)->toBe(StylistArrangement::BoothRental);

    // Mix salon: the request sticks.
    $mix = Salon::factory()->for($agency)->create(['salon_type' => SalonType::Mix]);
    $r3 = app(InviteStaff::class)->handle(salonOwnerOf($mix), $mix, [
        'name' => 'M', 'email' => 'm@example.com', 'salon_role' => 'stylist', 'arrangement' => 'booth_rental',
    ]);
    expect($r3->user->membershipFor($mix)->arrangement)->toBe(StylistArrangement::BoothRental);
});

it('flips every stylist on employee/booth transitions and preserves them on mix', function () {
    $salon = Salon::factory()->create(['salon_type' => SalonType::Mix]);
    $a = stylistOf($salon);
    $b = stylistOf($salon);
    $salon->memberships()->where('user_id', $b->id)->update(['arrangement' => 'booth_rental']);
    salonAdminOf($salon); // managers untouched by transitions

    // → booth_rental: everyone becomes a renter.
    expect(app(ChangeSalonType::class)->handle($salon, SalonType::BoothRental))->toBe(1);
    expect($a->membershipFor($salon)->arrangement)->toBe(StylistArrangement::BoothRental);
    expect($b->membershipFor($salon)->arrangement)->toBe(StylistArrangement::BoothRental);

    // → mix: nothing changes.
    expect(app(ChangeSalonType::class)->handle($salon->fresh(), SalonType::Mix))->toBe(0);
    expect($b->membershipFor($salon)->arrangement)->toBe(StylistArrangement::BoothRental);

    // → employee: everyone becomes an employee (visibility narrows, no data lost).
    expect(app(ChangeSalonType::class)->handle($salon->fresh(), SalonType::Employee))->toBe(2);
    expect($a->membershipFor($salon)->arrangement)->toBe(StylistArrangement::Employee);
    expect($b->membershipFor($salon)->arrangement)->toBe(StylistArrangement::Employee);
});

// ---------------------------------------------------------------------------
// Booth renter capabilities
// ---------------------------------------------------------------------------

it('lets a booth renter create and manage their own bookings — never someone else\'s', function () {
    $salon = boothSalon();
    $renter = boothRenterOf($salon);
    $other = boothRenterOf($salon);

    $own = bookFor($salon, $renter, $renter, '2026-06-22 14:00');
    expect($own->exists)->toBeTrue();

    // Their own status transitions work…
    app(TransitionBookingStatus::class)
        ->handle($renter, $salon, $own, BookingStatus::Arrived);
    expect($own->fresh()->status)->toBe(BookingStatus::Arrived);

    // …but another renter's booking is out of reach, both to create and manage.
    $service = serviceFor($salon, $other, 60);
    expect(fn () => app(CreateBooking::class)->handle($renter, $salon, [
        'client' => ['name' => 'Sneak'],
        'items' => [['service_id' => $service->id, 'stylist_id' => $other->id]],
        'start' => '2026-06-22 16:00', 'is_walkin' => false, 'notes' => null,
    ]))->toThrow(AuthorizationException::class);

    $others = bookFor($salon, salonOwnerOf($salon), $other, '2026-06-22 15:00');
    expect(fn () => app(TransitionBookingStatus::class)
        ->handle($renter, $salon, $others, BookingStatus::Arrived))
        ->toThrow(AuthorizationException::class);
});

it('scopes the calendar: booth renter sees only their own column; employee sees the shared board', function () {
    $salon = boothSalon();
    $renterA = boothRenterOf($salon);
    $renterB = boothRenterOf($salon);
    bookFor($salon, salonOwnerOf($salon), $renterB, '2026-06-22 14:00');

    // Renter A's grid: one column (their own), and B's booking is absent.
    $component = Livewire::actingAs($renterA)->test('pages::salon.calendar', ['salon' => $salon]);
    $grid = $component->instance()->grid();
    expect(array_column($grid['columns'], 'stylistId'))->toBe([$renterA->id]);
    $component->call('openBooking', $salon->bookings()->firstOrFail()->id)->assertForbidden();

    // An EMPLOYEE stylist in an employee salon sees every column, read-only.
    $employeeSalon = bookingSalon();
    $e = stylistWithHours($employeeSalon, 0, 9 * 60, 17 * 60);
    $colleague = stylistWithHours($employeeSalon, 0, 9 * 60, 17 * 60);
    $shared = Livewire::actingAs($e)->test('pages::salon.calendar', ['salon' => $employeeSalon]);
    $ids = array_column($shared->instance()->grid()['columns'], 'stylistId');
    expect($ids)->toContain($e->id);
    expect($ids)->toContain($colleague->id);
    // Read-only: the empty-slot click is refused server-side.
    $shared->call('selectSlot', '2026-06-22T14:00:00Z', $e->id)->assertForbidden();
});

it('scopes clients to those the renter has served — including profiles and notes', function () {
    $salon = boothSalon();
    $renterA = boothRenterOf($salon);
    $renterB = boothRenterOf($salon);

    $clientA = Client::factory()->for($salon)->create(['name' => 'Ava A']);
    $clientB = Client::factory()->for($salon)->create(['name' => 'Bea B']);
    bookFor($salon, salonOwnerOf($salon), $renterA, '2026-06-22 10:00', $clientA);
    bookFor($salon, salonOwnerOf($salon), $renterB, '2026-06-22 11:00', $clientB);

    // Directory: A sees Ava, not Bea.
    Livewire::actingAs($renterA)
        ->test('pages::salon.clients.index', ['salon' => $salon])
        ->assertSee('Ava A')
        ->assertDontSee('Bea B');

    // Profile + notes: served → open; unserved → 403 (notes unreachable with it).
    $this->actingAs($renterA)
        ->get(route('salon.client', ['salon' => $salon, 'clientId' => $clientA->id]))
        ->assertOk();
    $this->actingAs($renterA)
        ->get(route('salon.client', ['salon' => $salon, 'clientId' => $clientB->id]))
        ->assertForbidden();

    // Owner/manager still see everyone.
    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.clients.index', ['salon' => $salon])
        ->assertSee('Ava A')->assertSee('Bea B');
});

it('scopes reports to the renter\'s own business', function () {
    $salon = boothSalon();
    $renterA = boothRenterOf($salon);
    $renterB = boothRenterOf($salon);
    $owner = salonOwnerOf($salon);

    $a = bookFor($salon, $owner, $renterA, '2026-06-22 10:00');
    bookFor($salon, $owner, $renterB, '2026-06-22 11:00');
    app(TransitionBookingStatus::class)->handle($owner, $salon, $a, BookingStatus::Arrived);

    $start = CarbonImmutable::parse('2026-06-22', $salon->timezone);
    $end = $start->addDay();

    $scoped = app(SalonReport::class)->build($salon, $start, $end, $renterA->id);
    expect($scoped['totals']['total'] ?? array_sum($scoped['statusCounts'] ?? []) ?: $scoped['total'] ?? null)->not->toBeNull();

    // A's report contains only A's booking; the full report has both.
    $full = app(SalonReport::class)->build($salon, $start, $end);
    $countOf = function (array $report): int {
        foreach (['total', 'totals', 'statusCounts'] as $k) {
            if (isset($report[$k])) {
                return is_array($report[$k]) ? (int) array_sum($report[$k]) : (int) $report[$k];
            }
        }

        return -1;
    };
    expect($countOf($scoped))->toBe(1);
    expect($countOf($full))->toBe(2);

    // The page: renter allowed (scoped), employee stylist 403.
    $this->actingAs($renterA)->get(route('salon.reports', $salon))->assertOk();
    $employeeSalon = Salon::factory()->create();
    $this->actingAs(stylistOf($employeeSalon))->get(route('salon.reports', $employeeSalon))->assertForbidden();
});

it('still 403s a booth renter from every management surface', function () {
    $salon = boothSalon();
    $renter = boothRenterOf($salon);

    foreach (['salon.users', 'salon.services', 'salon.settings', 'salon.widgets', 'salon.onboarding', 'salon.appointments'] as $route) {
        $this->actingAs($renter)->get(route($route, $salon))->assertForbidden();
    }
});

// ---------------------------------------------------------------------------
// Employee stylists unchanged; managers unaffected in all types
// ---------------------------------------------------------------------------

it('keeps employee stylists exactly as before: no booking creation, clients, or reports', function () {
    $salon = bookingSalon(); // employee type by default
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);

    expect(fn () => app(CreateBooking::class)->handle($stylist, $salon, [
        'client' => ['name' => 'X'],
        'items' => [['service_id' => $service->id, 'stylist_id' => $stylist->id]],
        'start' => '2026-06-22 14:00', 'is_walkin' => false, 'notes' => null,
    ]))->toThrow(AuthorizationException::class);

    foreach (['salon.clients', 'salon.reports', 'salon.bookings.create'] as $route) {
        $this->actingAs($stylist)->get(route($route, $salon))->assertForbidden();
    }
});

it('gives owners and managers the full surface in every salon type', function () {
    foreach (SalonType::cases() as $type) {
        $salon = Salon::factory()->create(['salon_type' => $type]);

        foreach ([salonOwnerOf($salon), salonAdminOf($salon)] as $actor) {
            foreach (['salon.calendar', 'salon.clients', 'salon.reports', 'salon.users', 'salon.services', 'salon.bookings.create'] as $route) {
                $this->actingAs($actor)->get(route($route, $salon))->assertOk();
            }
        }
    }
});
