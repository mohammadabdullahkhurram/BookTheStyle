<?php

use App\Models\Availability;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Client;
use App\Models\Salon;
use App\Models\Service;
use App\Models\TimeOff;
use App\Models\User;
use App\Services\Booking\BookingPolicy;
use App\Services\Booking\SlotEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/*
| The slot engine is the heart of booking. Time is frozen to a Monday 08:00 in
| the salon's timezone so policy (min-notice/same-day/advance) is deterministic.
*/

const TZ = 'America/New_York';

beforeEach(function () {
    // 2026-06-22 12:00 UTC = 08:00 EDT, a Monday (weekday 0 in our 0=Mon scheme).
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC'));
});

afterEach(fn () => Carbon::setTestNow());

function bookingSalon(array $overrides = []): Salon
{
    return Salon::factory()->create(array_merge([
        'timezone' => TZ,
        'allow_walkins' => true,
        'allow_same_day' => true,
        'max_advance_days' => 90,
        'min_notice_minutes' => 0,
    ], $overrides));
}

function stylistWithHours(Salon $salon, int $weekday, int $startMin, int $endMin, ?User $stylist = null): User
{
    $stylist ??= stylistOf($salon);
    Availability::factory()->create([
        'salon_id' => $salon->id, 'user_id' => $stylist->id,
        'weekday' => $weekday, 'kind' => 'work',
        'start_minute' => $startMin, 'end_minute' => $endMin,
    ]);

    return $stylist;
}

/** @return list<string> slot start times as H:i in the salon tz */
function slotTimes(array $slots): array
{
    return array_map(fn (CarbonImmutable $s) => $s->setTimezone(TZ)->format('H:i'), $slots);
}

function engine(int $granularity = 15): SlotEngine
{
    return new SlotEngine(new BookingPolicy, $granularity);
}

it('generates 15-minute grid slots across a work window', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60); // 09:00–17:00 Monday

    $slots = slotTimes(engine()->slotsFor($salon, $stylist->id, 60, '2026-06-22'));

    expect($slots)->toHaveCount(29);     // 09:00 … 16:00 by 15 min
    expect($slots[0])->toBe('09:00');
    expect(end($slots))->toBe('16:00');
});

it('subtracts breaks from the window', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    Availability::factory()->break()->create([
        'salon_id' => $salon->id, 'user_id' => $stylist->id, 'weekday' => 0,
        'start_minute' => 12 * 60, 'end_minute' => 13 * 60, // 12:00–13:00
    ]);

    $slots = slotTimes(engine()->slotsFor($salon, $stylist->id, 60, '2026-06-22'));

    expect($slots)->toContain('11:00');  // ends 12:00, no overlap
    expect($slots)->not->toContain('11:15'); // ends 12:15, overlaps break
    expect($slots)->not->toContain('12:00');
    expect($slots)->toContain('13:00');
});

it('subtracts one-off time off', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    TimeOff::factory()->create([
        'salon_id' => $salon->id, 'user_id' => $stylist->id,
        'starts_at' => CarbonImmutable::parse('2026-06-22 10:00', TZ),
        'ends_at' => CarbonImmutable::parse('2026-06-22 11:00', TZ),
    ]);

    $slots = slotTimes(engine()->slotsFor($salon, $stylist->id, 30, '2026-06-22'));

    expect($slots)->not->toContain('10:00');
    expect($slots)->not->toContain('10:30');
    expect($slots)->toContain('09:30'); // ends 10:00
    expect($slots)->toContain('11:00');
});

it('subtracts existing non-cancelled bookings but not cancelled ones', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $client = Client::factory()->create(['salon_id' => $salon->id]);
    $service = Service::factory()->create(['salon_id' => $salon->id]);

    $booked = Booking::factory()->create(['salon_id' => $salon->id, 'client_id' => $client->id, 'status' => 'booked']);
    BookingItem::factory()->create([
        'salon_id' => $salon->id, 'booking_id' => $booked->id, 'service_id' => $service->id, 'stylist_id' => $stylist->id,
        'starts_at' => CarbonImmutable::parse('2026-06-22 14:00', TZ),
        'ends_at' => CarbonImmutable::parse('2026-06-22 15:00', TZ),
    ]);

    $slots = slotTimes(engine()->slotsFor($salon, $stylist->id, 60, '2026-06-22'));
    expect($slots)->not->toContain('14:00');
    expect($slots)->toContain('13:00'); // ends 14:00
    expect($slots)->toContain('15:00');

    // Cancel it → the slot frees up.
    $cancelled = Booking::factory()->create(['salon_id' => $salon->id, 'client_id' => $client->id, 'status' => 'cancelled']);
    BookingItem::factory()->create([
        'salon_id' => $salon->id, 'booking_id' => $cancelled->id, 'service_id' => $service->id, 'stylist_id' => $stylist->id,
        'starts_at' => CarbonImmutable::parse('2026-06-22 16:00', TZ),
        'ends_at' => CarbonImmutable::parse('2026-06-22 17:00', TZ),
    ]);
    $slots = slotTimes(engine()->slotsFor($salon, $stylist->id, 60, '2026-06-22'));
    expect($slots)->toContain('16:00');
});

it('handles split shifts (two windows, gap between)', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 12 * 60); // 09–12
    stylistWithHours($salon, 0, 14 * 60, 17 * 60, $stylist);  // 14–17

    $slots = slotTimes(engine()->slotsFor($salon, $stylist->id, 60, '2026-06-22'));

    expect($slots)->toContain('11:00'); // last fit in morning
    expect($slots)->not->toContain('11:15'); // ends 12:15 > 12:00
    expect($slots)->not->toContain('12:00'); // gap
    expect($slots)->not->toContain('13:00'); // gap
    expect($slots)->toContain('14:00');
});

it('returns nothing for a day with no work window', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60); // Monday only

    // 2026-06-23 is a Tuesday — no availability.
    expect(engine()->slotsFor($salon, $stylist->id, 60, '2026-06-23'))->toBe([]);
});

it('enforces same-day off', function () {
    $salon = bookingSalon(['allow_same_day' => false]);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);

    expect(engine()->slotsFor($salon, $stylist->id, 60, '2026-06-22'))->toBe([]); // today
    // but next Monday is fine
    expect(engine()->slotsFor($salon, $stylist->id, 60, '2026-06-29'))->not->toBe([]);
});

it('enforces minimum notice', function () {
    $salon = bookingSalon(['min_notice_minutes' => 120]); // now is 08:00 → earliest 10:00
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);

    $slots = slotTimes(engine()->slotsFor($salon, $stylist->id, 60, '2026-06-22'));
    expect($slots)->not->toContain('09:00');
    expect($slots)->not->toContain('09:45');
    expect($slots)->toContain('10:00');
});

it('enforces max advance window', function () {
    $salon = bookingSalon(['max_advance_days' => 7]);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);

    // 2026-07-13 is a Monday ~21 days out → beyond 7.
    expect(engine()->slotsFor($salon, $stylist->id, 60, '2026-07-13'))->toBe([]);
});

it('keeps wall-clock times correct across a DST transition day', function () {
    // 2026-11-01 is the US fall-back Sunday (weekday 6).
    $salon = bookingSalon(['max_advance_days' => 400]);
    $stylist = stylistWithHours($salon, 6, 9 * 60, 17 * 60);

    $slots = engine()->slotsFor($salon, $stylist->id, 60, '2026-11-01');
    $times = slotTimes($slots);

    expect($times[0])->toBe('09:00'); // setTime gives the correct local wall time
    expect(end($times))->toBe('16:00');
});

it('isAvailable validates a specific block', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);

    $engine = engine();
    expect($engine->isAvailable($salon, $stylist->id, CarbonImmutable::parse('2026-06-22 10:00', TZ), 60))->toBeTrue();
    // outside the window
    expect($engine->isAvailable($salon, $stylist->id, CarbonImmutable::parse('2026-06-22 16:30', TZ), 60))->toBeFalse();
});
