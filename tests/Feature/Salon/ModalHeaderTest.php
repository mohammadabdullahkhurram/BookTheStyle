<?php

use App\Actions\Bookings\TransitionBookingStatus;
use App\Enums\BookingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
| The shared x-ui.modal gives every dialog a header zone with end padding
| (pe-12) wider than Flux's absolute close (×) button, so the × never overlaps
| the title or status pills at any name/status length. These tests assert that
| clearance is present in the rendered modals, alongside Flux's labelled close
| control.
*/

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

it('reserves close-button space in the calendar booking-detail header at a long name and wide status', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);
    $owner = salonOwnerOf($salon);

    $longName = 'Genevieve Alexandria Montgomery-Fitzgerald';
    $booking = makeBooking($salon, $owner, $stylist, $service, '2026-06-22 10:00', $longName);

    // Push to a wide status label ("In service") to stress the header row.
    app(TransitionBookingStatus::class)->handle($owner, $salon, $booking, BookingStatus::Arrived);
    $booking->update(['status' => BookingStatus::InService]); // legacy wide label, set directly

    $this->actingAs($owner);

    Livewire::test('pages::salon.calendar', ['salon' => $salon])
        ->call('openBooking', $booking->id)
        ->assertSee($longName)
        ->assertSee('In service')
        ->assertSee('pe-12', escape: false)     // shared header clearance
        ->assertSee('Close modal', escape: false); // Flux's labelled × control
});

it('reserves close-button space in the temporary-password dialog header', function () {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);

    $this->actingAs($owner);

    Livewire::test('pages::salon.staff.index', ['salon' => $salon])
        ->set('name', 'Annaliese')
        ->set('email', 'annaliese@example.com')
        ->set('role', 'user')
        ->set('staff_type', 'stylist')
        ->call('invite')
        ->assertSet('showTempPassword', true)
        ->assertSee('Temporary password for Annaliese')
        ->assertSee('pe-12', escape: false)
        ->assertSee('Close modal', escape: false);
});

it('reserves close-button space in the edit-staff dialog header', function () {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistOf($salon);
    $membership = $stylist->membershipFor($salon);

    $this->actingAs($owner);

    Livewire::test('pages::salon.staff.index', ['salon' => $salon])
        ->call('startEdit', $membership->id)
        ->assertSee('Edit staff member')
        ->assertSee('pe-12', escape: false);
});

it('reserves close-button space in the edit-service dialog header', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);
    $owner = salonOwnerOf($salon);

    $this->actingAs($owner);

    Livewire::test('pages::salon.services.index', ['salon' => $salon])
        ->call('startEdit', $service->id)
        ->assertSee('Edit service')
        ->assertSee('pe-12', escape: false);
});
