<?php

use App\Actions\Bookings\CreateBooking;
use App\Enums\BookedByType;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Client;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC'))); // Mon 08:00 EDT
afterEach(fn () => Carbon::setTestNow());

// serviceFor() / bookingData() / makeBooking() live in tests/Pest.php.

it('creates a multi-service booking with sequential back-to-back blocks', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $cut = serviceFor($salon, $stylist, 60);
    $treat = serviceFor($salon, $stylist, 30);
    $owner = salonOwnerOf($salon);

    $booking = app(CreateBooking::class)->handle($owner, $salon, bookingData([
        'items' => [
            ['service_id' => $cut->id, 'stylist_id' => $stylist->id],
            ['service_id' => $treat->id, 'stylist_id' => $stylist->id],
        ],
    ]));

    $items = $booking->items()->orderBy('starts_at')->get();
    expect($items)->toHaveCount(2);
    expect($items[0]->starts_at->setTimezone('America/New_York')->format('H:i'))->toBe('10:00');
    expect($items[0]->ends_at->setTimezone('America/New_York')->format('H:i'))->toBe('11:00');
    expect($items[1]->starts_at->setTimezone('America/New_York')->format('H:i'))->toBe('11:00');
    expect($items[1]->ends_at->setTimezone('America/New_York')->format('H:i'))->toBe('11:30');
    expect($booking->status)->toBe(BookingStatus::Booked);
});

it('captures booked_by from the actor role', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);

    $cases = [
        [salonOwnerOf($salon), BookedByType::SalonOwner, '2026-06-22 10:00'],
        [salonAdminOf($salon), BookedByType::SalonAdmin, '2026-06-22 12:00'],
        [frontDeskOf($salon), BookedByType::FrontDesk, '2026-06-22 14:00'],
    ];

    foreach ($cases as [$actor, $expected, $start]) {
        $booking = app(CreateBooking::class)->handle($actor, $salon, bookingData([
            'items' => [['service_id' => $service->id, 'stylist_id' => $stylist->id]],
            'start' => $start,
        ]));
        expect($booking->booked_by_type)->toBe($expected);
        expect($booking->booked_by_user_id)->toBe($actor->id);
    }
});

it('rejects an item without a stylist — "any available" is gone', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);
    $owner = salonOwnerOf($salon);

    // Staff choose the stylist deliberately; nothing is auto-assigned.
    expect(fn () => app(CreateBooking::class)->handle($owner, $salon, bookingData([
        'items' => [['service_id' => $service->id, 'stylist_id' => null]],
    ])))->toThrow(ValidationException::class);

    expect($salon->bookings()->count())->toBe(0);
});

it('rejects a conflicting (concurrent) booking for the same slot', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);
    $owner = salonOwnerOf($salon);

    app(CreateBooking::class)->handle($owner, $salon, bookingData([
        'items' => [['service_id' => $service->id, 'stylist_id' => $stylist->id]],
    ]));

    // A second booking overlapping the same stylist/time is rejected under
    // re-validation (the same check the row lock guards under real concurrency).
    expect(fn () => app(CreateBooking::class)->handle($owner, $salon, bookingData([
        'items' => [['service_id' => $service->id, 'stylist_id' => $stylist->id]],
        'start' => '2026-06-22 10:30',
    ])))->toThrow(ValidationException::class);

    expect($salon->bookings()->count())->toBe(1);
});

it('rejects a server-side-invalid time (outside availability)', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);
    $owner = salonOwnerOf($salon);

    // 16:30 + 60 min runs past the 17:00 window.
    expect(fn () => app(CreateBooking::class)->handle($owner, $salon, bookingData([
        'items' => [['service_id' => $service->id, 'stylist_id' => $stylist->id]],
        'start' => '2026-06-22 16:30',
    ])))->toThrow(ValidationException::class);
});

it('rejects an unqualified stylist', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $other = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60); // only $stylist qualifies
    $owner = salonOwnerOf($salon);

    expect(fn () => app(CreateBooking::class)->handle($owner, $salon, bookingData([
        'items' => [['service_id' => $service->id, 'stylist_id' => $other->id]],
    ])))->toThrow(ValidationException::class);
});

it('enforces booking policy: same-day off + walk-in toggle', function () {
    $salon = bookingSalon(['allow_same_day' => false, 'allow_walkins' => false]);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);
    $owner = salonOwnerOf($salon);

    // Same-day scheduled booking is rejected.
    expect(fn () => app(CreateBooking::class)->handle($owner, $salon, bookingData([
        'items' => [['service_id' => $service->id, 'stylist_id' => $stylist->id]],
    ])))->toThrow(ValidationException::class);

    // Walk-in rejected when walk-ins are off.
    expect(fn () => app(CreateBooking::class)->handle($owner, $salon, bookingData([
        'items' => [['service_id' => $service->id, 'stylist_id' => $stylist->id]],
        'is_walkin' => true,
    ])))->toThrow(ValidationException::class);
});

it('creates a walk-in and checks it in immediately', function () {
    // Walk-in starts "now" — move now inside the stylist's working hours.
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 14:00:00', 'UTC')); // 10:00 EDT
    $salon = bookingSalon(['allow_walkins' => true]);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);
    $client = Client::factory()->create(['salon_id' => $salon->id]);
    $frontDesk = frontDeskOf($salon);

    $booking = app(CreateBooking::class)->handle($frontDesk, $salon, bookingData([
        'client' => ['id' => $client->id],
        'items' => [['service_id' => $service->id, 'stylist_id' => $stylist->id]],
        'is_walkin' => true,
        'start' => null,
    ]));

    expect($booking->is_walkin)->toBeTrue();
    expect($booking->status)->toBe(BookingStatus::Arrived);
    // Timeline: booked → arrived.
    expect($booking->statusEvents()->count())->toBe(2);
});

it('restricts a stylist to booking only their own items', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $other = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = Service::factory()->create(['salon_id' => $salon->id, 'duration_min' => 60]);
    $service->stylists()->attach([$stylist->id => ['salon_id' => $salon->id], $other->id => ['salon_id' => $salon->id]]);

    // Booking another stylist's item is forbidden.
    expect(fn () => app(CreateBooking::class)->handle($stylist, $salon, bookingData([
        'items' => [['service_id' => $service->id, 'stylist_id' => $other->id]],
    ])))->toThrow(AuthorizationException::class);

    // Their own is allowed.
    $booking = app(CreateBooking::class)->handle($stylist, $salon, bookingData([
        'items' => [['service_id' => $service->id, 'stylist_id' => $stylist->id]],
    ]));
    expect($booking->items()->first()->stylist_id)->toBe($stylist->id);
});

it('keeps bookings + items tenant-scoped', function () {
    $salonA = bookingSalon();
    $salonB = bookingSalon();
    $stylist = stylistWithHours($salonA, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salonA, $stylist, 60);

    app(CreateBooking::class)->handle(salonOwnerOf($salonA), $salonA, bookingData([
        'items' => [['service_id' => $service->id, 'stylist_id' => $stylist->id]],
    ]));

    app()->instance('currentSalon', $salonB);
    expect(Booking::count())->toBe(0);
    expect(BookingItem::count())->toBe(0);

    app()->instance('currentSalon', $salonA);
    expect(Booking::count())->toBe(1);
    expect(BookingItem::count())->toBe(1);
});
