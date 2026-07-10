<?php

use App\Actions\Availability\AddAvailabilityWindow;
use App\Actions\Bookings\TransitionBookingStatus;
use App\Enums\BookingStatus;
use App\Models\Salon;
use App\Support\Permissions\AvailabilityAccess;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
| End-to-end role enforcement (SPEC §3, tightened per the target role model):
|
|   Stylist        — own calendar + own availability/bio only. No check-in/
|                     status, no master calendar, no services/staff/settings,
|                     no other stylist's availability.
|   Front desk     — check-in/status + bookings; no services/staff; no
|                     availability editing.
|   Owner / admin  — full salon management.
|
| Every rule is enforced server-side (policy/gate + action), never just hidden.
*/

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

// ---------------------------------------------------------------------------
// Stylist — allow
// ---------------------------------------------------------------------------

it('lets a stylist load their own calendar (scoped to themselves, not master)', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);

    $this->actingAs($stylist)->get(route('salon.calendar', $salon))->assertOk();

    Livewire::actingAs($stylist)
        ->test('pages::salon.calendar', ['salon' => $salon])
        ->assertSet('isMaster', false)
        ->assertSet('stylistId', $stylist->id);
});

it('lets a stylist load their own availability with no stylist picker', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);

    $html = $this->actingAs($stylist)->get(route('salon.availability', $salon))->assertOk()->getContent();

    // Locked to themselves: the manager-only stylist picker is absent.
    expect($html)->not->toContain('wire:model.live="selectedStylistId"');

    Livewire::actingAs($stylist)
        ->test('pages::salon.availability.index', ['salon' => $salon])
        ->assertSet('selectedStylistId', $stylist->id);
});

// ---------------------------------------------------------------------------
// Stylist — deny (403 on the URL, not just a hidden link)
// ---------------------------------------------------------------------------

it('forbids a stylist the check-in, services, staff and settings screens', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);

    $this->actingAs($stylist);
    $this->get(route('salon.appointments', $salon))->assertForbidden();
    $this->get(route('salon.services', $salon))->assertForbidden();
    $this->get(route('salon.staff', $salon))->assertForbidden();
    $this->get(route('salon.settings', $salon))->assertForbidden();
});

it('denies a stylist the master calendar view (own column only)', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);

    expect($stylist->can('viewMasterCalendar', $salon))->toBeFalse();
    expect($stylist->can('manageBookings', $salon))->toBeFalse();
});

it('hides status actions from a stylist and denies the change server-side', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $booking = makeBooking($salon, salonOwnerOf($salon), $stylist, serviceFor($salon, $stylist, 60));

    // UI mirror: the stylist's own booking detail offers no check-in buttons.
    Livewire::actingAs($stylist)
        ->test('pages::salon.calendar', ['salon' => $salon])
        ->call('openBooking', $booking->id)
        ->assertSee($booking->client->name)
        ->assertDontSee('Checked in'); // no status buttons for a stylist

    // Server truth: the transition action rejects the stylist outright.
    expect(fn () => app(TransitionBookingStatus::class)->handle($stylist, $salon, $booking, BookingStatus::Arrived))
        ->toThrow(AuthorizationException::class);

    expect($booking->fresh()->status)->toBe(BookingStatus::Booked);
});

it('forbids a stylist editing another stylist\'s availability', function () {
    $salon = bookingSalon();
    $stylistA = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $stylistB = stylistWithHours($salon, 0, 9 * 60, 17 * 60);

    // Even if the client forged the target id, the action rejects it.
    expect(fn () => app(AddAvailabilityWindow::class)->handle($stylistA, $salon, $stylistB->id, [
        'weekday' => 1, 'kind' => 'work', 'start_minute' => 9 * 60, 'end_minute' => 17 * 60,
    ]))->toThrow(AuthorizationException::class);
});

// ---------------------------------------------------------------------------
// Front desk
// ---------------------------------------------------------------------------

it('lets front desk check in but not manage services, staff or availability', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $booking = makeBooking($salon, salonOwnerOf($salon), $stylist, serviceFor($salon, $stylist, 60));
    $frontDesk = frontDeskOf($salon);

    // Check-in: allowed.
    $this->actingAs($frontDesk)->get(route('salon.appointments', $salon))->assertOk();
    app(TransitionBookingStatus::class)->handle($frontDesk, $salon, $booking, BookingStatus::Arrived);
    expect($booking->fresh()->status)->toBe(BookingStatus::Arrived);

    // Services / staff: denied. Availability: read-only view (no editing).
    $this->get(route('salon.services', $salon))->assertForbidden();
    $this->get(route('salon.staff', $salon))->assertForbidden();
    $this->get(route('salon.availability', $salon))->assertOk();
    expect((new AvailabilityAccess)->canManage($frontDesk, $salon, $stylist->id))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Owner / admin — full access unchanged
// ---------------------------------------------------------------------------

it('gives owner and admin full salon management', function () {
    $salon = bookingSalon();
    stylistWithHours($salon, 0, 9 * 60, 17 * 60);

    foreach ([salonOwnerOf($salon), salonAdminOf($salon)] as $manager) {
        $this->actingAs($manager);
        $this->get(route('salon.appointments', $salon))->assertOk();
        $this->get(route('salon.services', $salon))->assertOk();
        $this->get(route('salon.staff', $salon))->assertOk();
        $this->get(route('salon.availability', $salon))->assertOk();
        $this->get(route('salon.settings', $salon))->assertOk();
    }
});

it('shows a manager the staff cards with a panel opener per stylist', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);

    $html = $this->actingAs(salonOwnerOf($salon))->get(route('salon.availability', $salon))->assertOk()->getContent();

    // The old dropdown picker is gone; every stylist is a clickable card.
    expect($html)->not->toContain('wire:model.live="selectedStylistId"');
    expect($html)->toContain('openPanel('.$stylist->id.')')->toContain($stylist->name);
});

// ---------------------------------------------------------------------------
// Role-aware sidebar — link visibility mirrors the policies
// ---------------------------------------------------------------------------

it('renders only the links each role may use', function () {
    $salon = bookingSalon();
    stylistWithHours($salon, 0, 9 * 60, 17 * 60);

    $calendar = route('salon.calendar', $salon);
    // Anchored on the closing quote so /appointments does not match the
    // stylist-visible /appointments/all link.
    $checkin = route('salon.appointments', $salon).'"';
    $services = route('salon.services', $salon);
    $staff = route('salon.staff', $salon);
    $availability = route('salon.availability', $salon);

    // Stylist: own calendar + own availability; no check-in/services/staff.
    $stylistHtml = $this->actingAs(stylistOf($salon))->get(route('salon.show', $salon))->assertOk()->getContent();
    expect($stylistHtml)->toContain($calendar)->toContain($availability)
        ->not->toContain($checkin)->not->toContain($services)->not->toContain($staff);

    // Front desk: calendar + check-in + read-only availability; no services/staff.
    $frontHtml = $this->actingAs(frontDeskOf($salon))->get(route('salon.show', $salon))->assertOk()->getContent();
    expect($frontHtml)->toContain($calendar)->toContain($checkin)->toContain($availability)
        ->not->toContain($services)->not->toContain($staff);

    // Owner: the full management set.
    $ownerHtml = $this->actingAs(salonOwnerOf($salon))->get(route('salon.show', $salon))->assertOk()->getContent();
    expect($ownerHtml)->toContain($calendar)->toContain($checkin)
        ->toContain($services)->toContain($staff)->toContain($availability);
});
