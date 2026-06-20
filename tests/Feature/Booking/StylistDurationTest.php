<?php

use App\Actions\Bookings\CreateBooking;
use App\Models\Salon;
use App\Models\Service;
use App\Services\Booking\DurationResolver;
use App\Services\Booking\SlotEngine;
use App\Services\Calendar\CalendarData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| Per-stylist-per-service duration + cleanup buffer: resolution, the slot engine
| / booking driven by resolved values, "any available" per candidate, buffers,
| multi-service sums, and a backfill regression guard. Time is frozen to a
| Monday 08:00 EDT, matching the slot-engine tests.
*/

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

/**
 * A service with the given default duration, qualified by stylists with optional
 * per-stylist overrides: [stylistId => ['duration' => ?int, 'buffer' => ?int]].
 *
 * @param  array<int, array{duration?: int|null, buffer?: int|null}>  $stylists
 */
function serviceWith(Salon $salon, int $defaultMin, array $stylists): Service
{
    $service = Service::factory()->create(['salon_id' => $salon->id, 'duration_min' => $defaultMin]);

    foreach ($stylists as $id => $ov) {
        $service->stylists()->attach($id, [
            'salon_id' => $salon->id,
            'duration_override' => $ov['duration'] ?? null,
            'buffer_override' => $ov['buffer'] ?? null,
        ]);
    }

    return $service;
}

/** Map slot instants to H:i in the salon timezone. */
function nyTimes(array $slots): array
{
    return array_map(fn (CarbonImmutable $s) => $s->setTimezone('America/New_York')->format('H:i'), $slots);
}

// --- Resolver ----------------------------------------------------------------

it('resolves override vs default, null buffer to zero, blocked = service+buffer, client-facing excludes buffer', function () {
    $r = (new DurationResolver)->from(30, null, null);
    expect([$r->serviceMinutes, $r->bufferMinutes, $r->blockedMinutes(), $r->clientFacingMinutes()])->toBe([30, 0, 30, 30]);

    $r = (new DurationResolver)->from(30, 45, 15);
    expect([$r->serviceMinutes, $r->bufferMinutes, $r->blockedMinutes(), $r->clientFacingMinutes()])->toBe([45, 15, 60, 45]);
});

it('reads per-stylist pivot overrides, with buffers gated by the salon flag', function () {
    $salon = bookingSalon();
    $stylist = stylistOf($salon);
    $service = serviceWith($salon, 30, [$stylist->id => ['duration' => 45, 'buffer' => 10]]);

    // Flag off (default): override duration applies; buffer ignored (0).
    $r = app(DurationResolver::class)->resolve($salon, $service, $stylist->id);
    expect($r->serviceMinutes)->toBe(45);
    expect($r->bufferMinutes)->toBe(0);

    // Flag on: the buffer applies.
    $salon->update(['feature_flags' => ['stylist_buffers' => true]]);
    $r = app(DurationResolver::class)->resolve($salon->fresh(), $service, $stylist->id);
    expect($r->bufferMinutes)->toBe(10);
    expect($r->blockedMinutes())->toBe(55);
});

// --- Two stylists, same service, different durations -------------------------

it('gives two stylists different slot sets and end times for the same service', function () {
    $salon = bookingSalon();
    $a = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $b = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceWith($salon, 30, [$a->id => ['duration' => 30], $b->id => ['duration' => 45]]);

    $engine = app(SlotEngine::class);
    $resolver = app(DurationResolver::class);

    $slotsA = nyTimes($engine->slotsFor($salon, $a->id, $resolver->resolve($salon, $service, $a->id)->blockedMinutes(), '2026-06-22'));
    $slotsB = nyTimes($engine->slotsFor($salon, $b->id, $resolver->resolve($salon, $service, $b->id)->blockedMinutes(), '2026-06-22'));

    expect(end($slotsA))->toBe('16:30'); // 30 min fits to 16:30
    expect(end($slotsB))->toBe('16:15'); // 45 min only to 16:15
    expect($slotsA)->not->toEqual($slotsB);

    // Booking each yields the correct end time.
    $owner = salonOwnerOf($salon);
    $bA = app(CreateBooking::class)->handle($owner, $salon, bookingData(['items' => [['service_id' => $service->id, 'stylist_id' => $a->id]], 'start' => '2026-06-22 10:00']));
    $bB = app(CreateBooking::class)->handle($owner, $salon, bookingData(['items' => [['service_id' => $service->id, 'stylist_id' => $b->id]], 'start' => '2026-06-22 13:00']));

    expect($bA->items()->first()->ends_at->setTimezone('America/New_York')->format('H:i'))->toBe('10:30');
    expect($bB->items()->first()->ends_at->setTimezone('America/New_York')->format('H:i'))->toBe('13:45');
});

// --- Any available -----------------------------------------------------------

it('evaluates each "any available" candidate with their own duration and binds the chosen stylist', function () {
    $salon = bookingSalon();
    $a = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $b = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceWith($salon, 30, [$a->id => ['duration' => 30], $b->id => ['duration' => 45]]);
    $owner = salonOwnerOf($salon);

    // A is busy at 10:00, so "any available" must pick B for that slot.
    app(CreateBooking::class)->handle($owner, $salon, bookingData(['items' => [['service_id' => $service->id, 'stylist_id' => $a->id]], 'start' => '2026-06-22 10:00']));

    $booking = app(CreateBooking::class)->handle($owner, $salon, bookingData(['items' => [['service_id' => $service->id, 'stylist_id' => null]], 'start' => '2026-06-22 10:00']));
    $item = $booking->items()->first();

    expect($item->stylist_id)->toBe($b->id);                                              // bound to the free candidate
    expect($item->ends_at->setTimezone('America/New_York')->format('H:i'))->toBe('10:45'); // B's 45-min duration
});

it('labels any-available slots with the stylist and their client-facing minutes, and binds on pick', function () {
    $salon = bookingSalon();
    $a = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $a->update(['name' => 'Maya']);
    $service = serviceWith($salon, 30, [$a->id => ['duration' => 45, 'buffer' => 99]]); // buffer ignored (flag off)
    $this->actingAs(salonOwnerOf($salon));

    $component = Livewire::test('pages::salon.bookings.create', ['salon' => $salon])
        ->set('items.0.service_id', (string) $service->id)
        ->set('date', '2026-06-22');

    // Client-facing minutes (excludes buffer) + the stylist's name.
    expect($component->html())->toContain('Maya · 45 min');

    // Picking binds that stylist so the booking validates against them.
    $component->call('pickSlot', '10:00', $a->id)
        ->assertSet('startTime', '10:00')
        ->assertSet('items.0.stylist_id', (string) $a->id);
});

// --- Buffer blocks the stylist's time ---------------------------------------

it('blocks the buffer so the next appointment cannot start during it, and shows it on the calendar', function () {
    $salon = bookingSalon(['feature_flags' => ['stylist_buffers' => true]]);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceWith($salon, 30, [$stylist->id => ['duration' => 30, 'buffer' => 30]]); // blocked 60
    $owner = salonOwnerOf($salon);

    app(CreateBooking::class)->handle($owner, $salon, bookingData(['items' => [['service_id' => $service->id, 'stylist_id' => $stylist->id]], 'start' => '2026-06-22 10:00']));

    // 10:30 is inside the buffer (10:30–11:00) → not bookable, conflict rejected.
    expect(app(SlotEngine::class)->isAvailable($salon, $stylist->id, CarbonImmutable::parse('2026-06-22 10:30', 'America/New_York'), 30))->toBeFalse();
    expect(fn () => app(CreateBooking::class)->handle($owner, $salon, bookingData(['items' => [['service_id' => $service->id, 'stylist_id' => $stylist->id]], 'start' => '2026-06-22 10:30'])))
        ->toThrow(ValidationException::class);

    // 11:00 (after the buffer) is fine.
    app(CreateBooking::class)->handle($owner, $salon, bookingData(['items' => [['service_id' => $service->id, 'stylist_id' => $stylist->id]], 'start' => '2026-06-22 11:00']));

    // Calendar: the 10:00 block is 30 min (client-facing) with a 30-min buffer tail.
    $grid = app(CalendarData::class)->day($salon, CarbonImmutable::parse('2026-06-22', 'America/New_York'), null);
    $col = collect($grid['columns'])->firstWhere('stylistId', $stylist->id);
    $block = collect($col['bookings'])->firstWhere('startMin', 600); // 10:00
    expect($block['endMin'])->toBe(630);   // 10:30 visible (service only)
    expect($block['bufferMin'])->toBe(30); // muted, non-bookable tail to 11:00
});

// --- Multi-service sums ------------------------------------------------------

it('sums each chosen stylist\'s resolved durations back-to-back across a multi-service booking', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $s1 = serviceWith($salon, 30, [$stylist->id => ['duration' => 20]]);
    $s2 = serviceWith($salon, 60, [$stylist->id => ['duration' => 50]]);
    $owner = salonOwnerOf($salon);

    $booking = app(CreateBooking::class)->handle($owner, $salon, bookingData([
        'items' => [
            ['service_id' => $s1->id, 'stylist_id' => $stylist->id],
            ['service_id' => $s2->id, 'stylist_id' => $stylist->id],
        ],
        'start' => '2026-06-22 10:00',
    ]));

    $items = $booking->items()->orderBy('starts_at')->get();
    expect($items[0]->ends_at->setTimezone('America/New_York')->format('H:i'))->toBe('10:20');   // 20 min
    expect($items[1]->starts_at->setTimezone('America/New_York')->format('H:i'))->toBe('10:20'); // back-to-back
    expect($items[1]->ends_at->setTimezone('America/New_York')->format('H:i'))->toBe('11:10');   // +50 min
});

it('places the buffer before the next service in a multi-service booking', function () {
    $salon = bookingSalon(['feature_flags' => ['stylist_buffers' => true]]);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $s1 = serviceWith($salon, 30, [$stylist->id => ['duration' => 30, 'buffer' => 15]]);
    $s2 = serviceWith($salon, 30, [$stylist->id => ['duration' => 30]]);
    $owner = salonOwnerOf($salon);

    $booking = app(CreateBooking::class)->handle($owner, $salon, bookingData([
        'items' => [
            ['service_id' => $s1->id, 'stylist_id' => $stylist->id],
            ['service_id' => $s2->id, 'stylist_id' => $stylist->id],
        ],
        'start' => '2026-06-22 10:00',
    ]));

    $items = $booking->items()->orderBy('starts_at')->get();
    expect($items[0]->ends_at->setTimezone('America/New_York')->format('H:i'))->toBe('10:30'); // service only
    expect($items[0]->buffer_min)->toBe(15);
    expect($items[1]->starts_at->setTimezone('America/New_York')->format('H:i'))->toBe('10:45'); // after service + buffer
});

// --- Backfill regression + DST ----------------------------------------------

it('behaves exactly as before for rows with no overrides (backfill regression)', function () {
    $salon = bookingSalon(); // flag off by default
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60); // null overrides, default 60
    $owner = salonOwnerOf($salon);

    $r = app(DurationResolver::class)->resolve($salon, $service, $stylist->id);
    expect([$r->serviceMinutes, $r->bufferMinutes, $r->blockedMinutes()])->toBe([60, 0, 60]);

    $booking = app(CreateBooking::class)->handle($owner, $salon, bookingData(['items' => [['service_id' => $service->id, 'stylist_id' => $stylist->id]], 'start' => '2026-06-22 10:00']));
    $item = $booking->items()->first();
    expect($item->ends_at->setTimezone('America/New_York')->format('H:i'))->toBe('11:00'); // start + service default
    expect($item->buffer_min)->toBe(0);
});

it('saves a per-stylist duration override through the services screen', function () {
    $salon = bookingSalon();
    $stylist = stylistOf($salon);
    $service = serviceWith($salon, 30, [$stylist->id => ['duration' => null]]); // assigned, no override
    $this->actingAs(salonOwnerOf($salon));

    Livewire::test('pages::salon.services.index', ['salon' => $salon])
        ->call('startEdit', $service->id)
        ->set("editDurations.{$stylist->id}", '50')
        ->call('saveEdit')
        ->assertHasNoErrors();

    expect(app(DurationResolver::class)->resolve($salon->fresh(), $service->fresh(), $stylist->id)->serviceMinutes)->toBe(50);
});

it('stores override-duration times correctly across the salon timezone (DST-safe)', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceWith($salon, 30, [$stylist->id => ['duration' => 45]]);
    $owner = salonOwnerOf($salon);

    $booking = app(CreateBooking::class)->handle($owner, $salon, bookingData(['items' => [['service_id' => $service->id, 'stylist_id' => $stylist->id]], 'start' => '2026-06-22 10:00']));
    $item = $booking->items()->first();

    // 10:00 EDT (-4) → 14:00 UTC; +45 → 14:45 UTC.
    expect($item->starts_at->utc()->format('H:i'))->toBe('14:00');
    expect($item->ends_at->utc()->format('H:i'))->toBe('14:45');
});
