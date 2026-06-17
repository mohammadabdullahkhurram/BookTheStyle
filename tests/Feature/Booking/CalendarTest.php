<?php

use App\Models\Availability;
use App\Models\TimeOff;
use App\Models\User;
use App\Services\Calendar\CalendarData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
| The booking calendar. Data is built server-side, salon-scoped and role-
| filtered by CalendarData; the Livewire component gates master vs per-stylist,
| handles click-to-book (re-validated by Phase 3), and re-pushes the feed on
| poll. Mon 2026-06-22, "now" = 08:00 America/New_York.
*/

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

/** The visible range covering Monday 2026-06-22 in the salon's timezone. */
function calRange(): array
{
    $from = CarbonImmutable::parse('2026-06-22 00:00', 'America/New_York');

    return [$from, $from->addDay()];
}

it('builds a master feed with every salon booking, coloured per stylist', function () {
    $salon = bookingSalon();
    $stylistA = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $stylistB = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $owner = salonOwnerOf($salon);
    makeBooking($salon, $owner, $stylistA, serviceFor($salon, $stylistA, 60), '2026-06-22 10:00', 'Alice Anderson');
    makeBooking($salon, $owner, $stylistB, serviceFor($salon, $stylistB, 60), '2026-06-22 11:00', 'Bob Brown');

    [$from, $to] = calRange();
    $data = app(CalendarData::class)->build($salon, $from, $to, null);

    expect(collect($data['events'])->pluck('title'))
        ->toContain('Alice Anderson')
        ->toContain('Bob Brown');
    expect($data['calendars'])->toHaveCount(2);
    // Each booking event is tagged with its stylist's calendar (colour) id.
    expect(collect($data['events'])->pluck('calendarId')->unique()->sort()->values()->all())
        ->toEqual([(string) $stylistA->id, (string) $stylistB->id]);
});

it('scopes a stylist feed to only their own bookings', function () {
    $salon = bookingSalon();
    $stylistA = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $stylistB = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $owner = salonOwnerOf($salon);
    makeBooking($salon, $owner, $stylistA, serviceFor($salon, $stylistA, 60), '2026-06-22 10:00', 'Alice Anderson');
    makeBooking($salon, $owner, $stylistB, serviceFor($salon, $stylistB, 60), '2026-06-22 11:00', 'Bob Brown');

    [$from, $to] = calRange();
    $data = app(CalendarData::class)->build($salon, $from, $to, $stylistA->id);

    $titles = collect($data['events'])->pluck('title');
    expect($titles)->toContain('Alice Anderson');
    expect($titles)->not->toContain('Bob Brown');
    expect(collect($data['events'])->every(fn ($e) => $e['calendarId'] === (string) $stylistA->id))->toBeTrue();
});

it('never leaks another salon\'s bookings into the feed', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    makeBooking($salon, salonOwnerOf($salon), $stylist, serviceFor($salon, $stylist, 60), '2026-06-22 10:00', 'Mine Client');

    $other = bookingSalon();
    $otherStylist = stylistWithHours($other, 0, 9 * 60, 17 * 60);
    makeBooking($other, salonOwnerOf($other), $otherStylist, serviceFor($other, $otherStylist, 60), '2026-06-22 10:00', 'Other Salon Client');

    [$from, $to] = calRange();
    $data = app(CalendarData::class)->build($salon, $from, $to, null);

    expect(collect($data['events'])->pluck('title'))
        ->toContain('Mine Client')
        ->not->toContain('Other Salon Client');
});

it('reflects working hours, time off, and breaks in the feed', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);

    // A break (weekly) and a one-off time-off block on the visible day.
    Availability::factory()->for($salon)->break()->create(['user_id' => $stylist->id, 'weekday' => 0]);
    TimeOff::factory()->for($salon)->create([
        'user_id' => $stylist->id,
        'starts_at' => CarbonImmutable::parse('2026-06-22 15:00', 'America/New_York'),
        'ends_at' => CarbonImmutable::parse('2026-06-22 16:00', 'America/New_York'),
    ]);

    [$from, $to] = calRange();
    $data = app(CalendarData::class)->build($salon, $from, $to, $stylist->id);

    // The grid is framed to the 9–17 work window.
    expect($data['hourStart'])->toBe(9);
    expect($data['hourEnd'])->toBe(17);

    $blockTitles = collect($data['blocks'])->pluck('title');
    expect($blockTitles)->toContain('Time off');
    expect($blockTitles)->toContain('Break');
});

it('lets a manager see the master calendar and a stylist see only their own', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);

    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.calendar', ['salon' => $salon])
        ->assertSet('isMaster', true)
        ->assertSet('stylistId', null);

    Livewire::actingAs(frontDeskOf($salon))
        ->test('pages::salon.calendar', ['salon' => $salon])
        ->assertSet('isMaster', true);

    Livewire::actingAs($stylist)
        ->test('pages::salon.calendar', ['salon' => $salon])
        ->assertSet('isMaster', false)
        ->assertSet('stylistId', $stylist->id);
});

it('forbids a non-member from the calendar route', function () {
    $salon = bookingSalon();

    $this->actingAs(User::factory()->create())
        ->get(route('salon.calendar', $salon))
        ->assertForbidden();
});

it('renders the calendar page with the right heading per role', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);

    $this->actingAs(salonOwnerOf($salon))
        ->get(route('salon.calendar', $salon))
        ->assertOk()
        ->assertSee('Master calendar')
        ->assertSee('bookingCalendar'); // the Alpine calendar mounts

    $this->actingAs($stylist)
        ->get(route('salon.calendar', $salon))
        ->assertOk()
        ->assertSee('My calendar');
});

it('re-pushes the current feed on poll (near-real-time)', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);
    $owner = salonOwnerOf($salon);

    [$from, $to] = calRange();

    $component = Livewire::actingAs($owner)
        ->test('pages::salon.calendar', ['salon' => $salon])
        ->call('setRange', $from->utc()->toIso8601ZuluString(), $to->utc()->toIso8601ZuluString());

    // A booking appears after the initial render...
    makeBooking($salon, $owner, $stylist, $service, '2026-06-22 13:00', 'Polly Poll');

    // ...and the next poll carries it in the dispatched payload.
    $component->call('refresh')->assertDispatched('calendar:data', function ($event, $params) {
        return collect($params['payload']['events'])->pluck('title')->contains('Polly Poll');
    });
});

it('opens the prefilled booking form on click-to-book', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $owner = salonOwnerOf($salon);

    Livewire::actingAs($owner)
        ->test('pages::salon.calendar', ['salon' => $salon])
        ->call('selectSlot', CarbonImmutable::parse('2026-06-22 14:00', 'America/New_York')->utc()->toIso8601ZuluString())
        ->assertRedirect(route('salon.bookings.create', ['salon' => $salon, 'date' => '2026-06-22', 'time' => '14:00']));
});

it('still rejects a conflicting slot even when the form was prefilled', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);
    $owner = salonOwnerOf($salon);
    $client = makeBooking($salon, $owner, $stylist, $service, '2026-06-22 10:00', 'First Client')->client;

    // The calendar prefills 10:00 with that stylist (already taken).
    $component = Livewire::actingAs($owner)
        ->withQueryParams(['date' => '2026-06-22', 'time' => '10:00', 'stylist' => (string) $stylist->id])
        ->test('pages::salon.bookings.create', ['salon' => $salon])
        ->assertSet('startTime', '10:00')
        ->assertSet('date', '2026-06-22');

    // Server re-validates and rejects the conflict — no second booking is made.
    $component
        ->set('clientMode', 'existing')
        ->set('clientId', $client->id)
        ->set('items.0.service_id', (string) $service->id)
        ->set('items.0.stylist_id', (string) $stylist->id)
        ->call('save')
        ->assertHasErrors('start');

    expect($salon->bookings()->count())->toBe(1);
});
