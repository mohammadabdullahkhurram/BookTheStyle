<?php

use App\Actions\Bookings\TransitionBookingStatus;
use App\Enums\BookingStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC'))); // Mon 08:00 EDT
afterEach(fn () => Carbon::setTestNow());

// makeBooking() / serviceFor() live in tests/Pest.php.

it('transitions a booking and records the timeline', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);
    $owner = salonOwnerOf($salon);
    $booking = makeBooking($salon, $owner, $stylist, $service);

    app(TransitionBookingStatus::class)->handle($owner, $salon, $booking, BookingStatus::Arrived);

    expect($booking->fresh()->status)->toBe(BookingStatus::Arrived);
    expect($booking->statusEvents()->count())->toBe(2); // booked (create) + arrived
});

it('rejects an invalid status transition', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);
    $owner = salonOwnerOf($salon);
    $booking = makeBooking($salon, $owner, $stylist, $service);

    // booked → completed is not allowed.
    expect(fn () => app(TransitionBookingStatus::class)->handle($owner, $salon, $booking, BookingStatus::Completed))
        ->toThrow(ValidationException::class);
});

it('forbids transitioning a booking from another salon', function () {
    $salonA = bookingSalon();
    $salonB = bookingSalon();
    $stylist = stylistWithHours($salonA, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salonA, $stylist, 60);
    $booking = makeBooking($salonA, salonOwnerOf($salonA), $stylist, $service);

    expect(fn () => app(TransitionBookingStatus::class)->handle(salonOwnerOf($salonB), $salonB, $booking, BookingStatus::Arrived))
        ->toThrow(AuthorizationException::class);
});

it('lets a stylist manage their own booking but not another stylist\'s', function () {
    $salon = bookingSalon();
    $stylistA = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $stylistB = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $serviceA = serviceFor($salon, $stylistA, 60);
    $booking = makeBooking($salon, salonOwnerOf($salon), $stylistA, $serviceA);

    // Stylist A (assigned) can.
    app(TransitionBookingStatus::class)->handle($stylistA, $salon, $booking, BookingStatus::Arrived);
    expect($booking->fresh()->status)->toBe(BookingStatus::Arrived);

    // Stylist B (not assigned) cannot.
    expect(fn () => app(TransitionBookingStatus::class)->handle($stylistB, $salon, $booking, BookingStatus::InService))
        ->toThrow(AuthorizationException::class);
});

it('marks a booking arrived through the appointments screen', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);
    $owner = salonOwnerOf($salon);
    $booking = makeBooking($salon, $owner, $stylist, $service);

    $this->actingAs($owner);
    Livewire::test('pages::salon.appointments.index', ['salon' => $salon])
        ->call('changeStatus', $booking->id, 'arrived');

    expect($booking->fresh()->status)->toBe(BookingStatus::Arrived);
});

it('shows a stylist only their own appointments', function () {
    $salon = bookingSalon();
    $stylistA = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $stylistB = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $serviceA = serviceFor($salon, $stylistA, 60);
    $serviceB = serviceFor($salon, $stylistB, 60);
    $owner = salonOwnerOf($salon);

    makeBooking($salon, $owner, $stylistA, $serviceA, '2026-06-22 10:00', 'Alice Anderson');
    makeBooking($salon, $owner, $stylistB, $serviceB, '2026-06-22 11:00', 'Bob Brown');

    // Manager sees both.
    $this->actingAs($owner);
    Livewire::test('pages::salon.appointments.index', ['salon' => $salon])
        ->assertSee('Alice Anderson')->assertSee('Bob Brown');

    // Stylist A sees only their own.
    $this->actingAs($stylistA);
    Livewire::test('pages::salon.appointments.index', ['salon' => $salon])
        ->assertSee('Alice Anderson')->assertDontSee('Bob Brown');
});

it('forbids front-deskless access (no membership) to appointments', function () {
    $salon = bookingSalon();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->get(route('salon.appointments', $salon))->assertForbidden();
});

it('creates a booking through the new-booking screen', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);
    $owner = salonOwnerOf($salon);

    $this->actingAs($owner);
    Livewire::test('pages::salon.bookings.create', ['salon' => $salon])
        ->set('clientMode', 'new')
        ->set('newName', 'New Booking Client')
        ->set('items.0.service_id', (string) $service->id)
        ->set('items.0.stylist_id', (string) $stylist->id)
        ->set('date', '2026-06-22')
        ->set('startTime', '13:00')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('salon.appointments', $salon));

    $booking = $salon->bookings()->first();
    expect($booking)->not->toBeNull();
    expect($booking->client->name)->toBe('New Booking Client');
    expect($booking->items()->first()->stylist_id)->toBe($stylist->id);
});
