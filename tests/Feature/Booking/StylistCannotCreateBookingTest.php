<?php

use App\Actions\Bookings\CreateBooking;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
| Booking creation is a MANAGER surface. Every path that can create a booking
| is asserted here for a stylist — the form page, its Livewire submit, the
| calendar's click-to-book (empty-slot) action, walk-ins, and the action
| class itself — plus the affordances (nav button, slot buttons) being
| absent. System callers (voice AI / widget / GHL inbound pass a null actor)
| are untouched, covered by their own suites.
*/

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC'));
});
afterEach(fn () => Carbon::setTestNow());

function bookingScenario(): array
{
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);

    return [$salon, $stylist, $service];
}

it('blocks a stylist on every booking-creation path', function () {
    [$salon, $stylist, $service] = bookingScenario();

    // 1. The booking form page (route + Livewire mount).
    $this->actingAs($stylist)
        ->get(route('salon.bookings.create', $salon))
        ->assertForbidden();

    // 2. The calendar's empty-slot click-to-book action.
    Livewire::actingAs($stylist)
        ->test('pages::salon.calendar', ['salon' => $salon])
        ->call('selectSlot', '2026-06-22T14:00:00Z', $stylist->id)
        ->assertForbidden();

    // 3. The action class itself — even booking THEMSELVES (walk-in or not).
    foreach ([false, true] as $walkin) {
        expect(fn () => app(CreateBooking::class)->handle($stylist, $salon, [
            'client' => ['name' => 'Walk In'],
            'items' => [['service_id' => $service->id, 'stylist_id' => $stylist->id]],
            'start' => '2026-06-22 14:00',
            'is_walkin' => $walkin,
            'notes' => null,
        ]))->toThrow(AuthorizationException::class);
    }
});

it('offers a stylist no creation affordances: no New booking nav, no slot buttons', function () {
    [$salon, $stylist] = bookingScenario();

    // Nav: no "New booking" button, no link to the create route.
    $dashboard = $this->actingAs($stylist)->get(route('salon.show', $salon))->assertOk()->getContent();
    expect($dashboard)->not->toContain(__('New booking'));
    expect($dashboard)->not->toContain(route('salon.bookings.create', $salon));

    // Calendar: the stylist still SEES their calendar, but the empty-slot
    // click-to-book buttons are gone.
    $calendar = $this->actingAs($stylist)->get(route('salon.calendar', $salon))->assertOk()->getContent();
    expect($calendar)->not->toContain('selectSlot(');
});

it('keeps owner and manager booking creation fully working', function () {
    [$salon, $stylist, $service] = bookingScenario();

    foreach (['owner' => salonOwnerOf($salon), 'manager' => salonAdminOf($salon)] as $label => $actor) {
        // The page, the calendar affordance, and the action all work.
        $this->actingAs($actor)->get(route('salon.bookings.create', $salon))->assertOk();

        $calendar = $this->actingAs($actor)->get(route('salon.calendar', $salon))->assertOk()->getContent();
        expect($calendar)->toContain('selectSlot(');

        $booking = app(CreateBooking::class)->handle($actor, $salon, [
            'client' => ['name' => 'Client of '.$label],
            'items' => [['service_id' => $service->id, 'stylist_id' => $stylist->id]],
            'start' => $label === 'owner' ? '2026-06-22 14:00' : '2026-06-22 15:30',
            'is_walkin' => false,
            'notes' => null,
        ]);
        expect($booking->exists)->toBeTrue();
    }
});

it('leaves stylists their read surfaces: Today, calendar, own appointments', function () {
    [$salon, $stylist] = bookingScenario();

    $this->actingAs($stylist)->get(route('salon.show', $salon))->assertOk();
    $this->actingAs($stylist)->get(route('salon.calendar', $salon))->assertOk();
    $this->actingAs($stylist)->get(route('salon.appointments.all', $salon))->assertOk();
});
