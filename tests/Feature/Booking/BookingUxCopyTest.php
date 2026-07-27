<?php

use App\Enums\BookingSource;
use App\Support\TimeDisplay;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
| Booking-flow copy + formatting: times display 12-hour with AM/PM (values
| stay 'H:i' internally), the in-app booking source reads "Staff member",
| and the client selector pairs "New client" / "Existing client".
*/

it('formats display times as 12-hour with AM/PM', function () {
    expect(TimeDisplay::twelveHour('14:00'))->toBe('2:00 PM');
    expect(TimeDisplay::twelveHour('09:30'))->toBe('9:30 AM');
    expect(TimeDisplay::twelveHour('00:15'))->toBe('12:15 AM');
    expect(TimeDisplay::twelveHour('12:00'))->toBe('12:00 PM');
});

it('shows 12-hour slot chips in the booking wizard — never 24-hour', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-22 08:00:00', 'America/New_York'));
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60); // Monday 9–5
    $service = serviceFor($salon, $stylist, 60);

    $component = Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.bookings.create', ['salon' => $salon])
        ->set('items.0.service_id', (string) $service->id)
        ->set('items.0.stylist_id', (string) $stylist->id)
        ->set('date', '2026-06-22');

    // The afternoon slot renders as 2:00 PM; the raw 24-hour form never shows.
    $component->assertSeeHtml('2:00 PM')
        ->assertDontSeeHtml('>14:00<');

    // The VALUE stays 'H:i' — picking by wire value still works.
    expect($component->instance()->slotsForLine(0))->toContain('14:00');

    CarbonImmutable::setTestNow();
});

it('labels the in-app booking source "Staff member" everywhere the enum renders', function () {
    expect(BookingSource::InApp->label())->toBe('Staff member');
    expect(BookingSource::InApp->value)->toBe('in_app'); // stored value unchanged
});

it('shows Staff member in the reports source mix', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC'));
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);
    makeBooking($salon, salonOwnerOf($salon), $stylist, $service, '2026-06-22 10:00');

    $this->actingAs(salonOwnerOf($salon))
        ->get(route('salon.reports', $salon))
        ->assertOk()
        ->assertSee(__('Staff member'))
        ->assertDontSee(__('In app'));

    CarbonImmutable::setTestNow();
});

it('pairs New client / Existing client in the create-appointment selector', function () {
    $salon = bookingSalon();

    $this->actingAs(salonOwnerOf($salon))
        ->get(route('salon.bookings.create', $salon))
        ->assertOk()
        ->assertSee(__('New client'))
        ->assertSee(__('Existing client'))
        ->assertDontSee(__('Quick add'));
});
