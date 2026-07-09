<?php

use App\Actions\Bookings\TransitionBookingStatus;
use App\Enums\BookingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
| Check-in (strictly today, one-tap status) vs the full Appointments list
| (all dates, search + range + status filters, permission-scoped).
| Frozen clock: Mon 2026-06-22 12:00 UTC (08:00 EDT).
*/

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

it('shows only today on check-in — other dates never appear', function () {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    stylistWithHours($salon, 1, 9 * 60, 17 * 60, $stylist); // Tuesday hours too
    $service = serviceFor($salon, $stylist, 60);

    makeBooking($salon, $owner, $stylist, $service, '2026-06-22 10:00', 'Today Tina');
    makeBooking($salon, $owner, $stylist, $service, '2026-06-23 10:00', 'Tomorrow Tom');

    Livewire::actingAs($owner)
        ->test('pages::salon.appointments.index', ['salon' => $salon])
        ->assertSee('Today Tina')
        ->assertDontSee('Tomorrow Tom');
});

it('lists every date on the appointments view, newest first', function () {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    stylistWithHours($salon, 1, 9 * 60, 17 * 60, $stylist);
    $service = serviceFor($salon, $stylist, 60);

    makeBooking($salon, $owner, $stylist, $service, '2026-06-22 10:00', 'Today Tina');
    makeBooking($salon, $owner, $stylist, $service, '2026-06-23 10:00', 'Tomorrow Tom');
    makeBooking($salon, $owner, $stylist, $service, '2026-06-29 10:00', 'Nextweek Nia');

    Livewire::actingAs($owner)
        ->test('pages::salon.appointments.all', ['salon' => $salon])
        ->assertSee('Today Tina')
        ->assertSee('Tomorrow Tom')
        ->assertSee('Nextweek Nia');
});

it('filters the appointments list by search, date range, and status', function () {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    stylistWithHours($salon, 1, 9 * 60, 17 * 60, $stylist);
    $service = serviceFor($salon, $stylist, 60);

    $today = makeBooking($salon, $owner, $stylist, $service, '2026-06-22 10:00', 'Alice Anderson');
    $today->client->update(['phone' => '+15550001111']);
    makeBooking($salon, $owner, $stylist, $service, '2026-06-23 10:00', 'Bob Brown');

    $component = Livewire::actingAs($owner)->test('pages::salon.appointments.all', ['salon' => $salon]);

    // Search by name…
    $component->set('search', 'Alice')->assertSee('Alice Anderson')->assertDontSee('Bob Brown');
    // …and by phone.
    $component->set('search', '5550001111')->assertSee('Alice Anderson')->assertDontSee('Bob Brown');

    // Date range: only Tuesday.
    $component->set('search', '')->set('from', '2026-06-23')->set('to', '2026-06-23')
        ->assertSee('Bob Brown')->assertDontSee('Alice Anderson');

    // Status: cancel Alice's, filter cancelled.
    app(TransitionBookingStatus::class)
        ->handle($owner, $salon, $today, BookingStatus::Cancelled);
    $component->set('from', '')->set('to', '')->set('status', 'cancelled')
        ->assertSee('Alice Anderson')->assertDontSee('Bob Brown');
});

it('scopes the appointments list: stylists see only their own, front desk sees all', function () {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $mine = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $other = stylistWithHours($salon, 0, 9 * 60, 17 * 60);

    makeBooking($salon, $owner, $mine, serviceFor($salon, $mine, 60), '2026-06-22 10:00', 'Mine Client');
    makeBooking($salon, $owner, $other, serviceFor($salon, $other, 60), '2026-06-22 11:00', 'Other Client');

    // A stylist can open the list but sees only their own bookings.
    Livewire::actingAs($mine)
        ->test('pages::salon.appointments.all', ['salon' => $salon])
        ->assertSee('Mine Client')
        ->assertDontSee('Other Client');

    // Front desk sees everything (and check-in stays theirs too).
    Livewire::actingAs(frontDeskOf($salon))
        ->test('pages::salon.appointments.all', ['salon' => $salon])
        ->assertSee('Mine Client')
        ->assertSee('Other Client');
});

it('still lets check-in change status while the appointments list stays read-only', function () {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $booking = makeBooking($salon, $owner, $stylist, serviceFor($salon, $stylist, 60), '2026-06-22 10:00');

    // One-tap from check-in works as before…
    Livewire::actingAs($owner)
        ->test('pages::salon.appointments.index', ['salon' => $salon])
        ->call('changeStatus', $booking->id, 'arrived')
        ->assertHasNoErrors();
    expect($booking->fresh()->status)->toBe(BookingStatus::Arrived);

    // …and the list page offers history, not status buttons.
    Livewire::actingAs($owner)
        ->test('pages::salon.appointments.all', ['salon' => $salon])
        ->assertSee('History')
        ->assertDontSee('Mark arrived');
});

it('keeps a stylist out of check-in but not out of the appointments list', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);

    $this->actingAs($stylist);
    $this->get(route('salon.appointments', $salon))->assertForbidden();
    $this->get(route('salon.appointments.all', $salon))->assertOk();
});
