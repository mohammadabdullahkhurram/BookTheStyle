<?php

use App\Enums\BookingStatus;
use App\Models\Availability;
use App\Models\TimeOff;
use App\Models\User;
use App\Services\Calendar\CalendarData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Livewire;

/*
| The per-stylist column calendar. CalendarData builds a server-side, salon-
| scoped, role-filtered day/week grid; the Livewire component gates master vs
| per-stylist, handles click-to-book (re-validated by Phase 3) and polling.
| Mon 2026-06-22, "now" = 08:00 America/New_York.
*/

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

/** Monday 2026-06-22 in the salon's timezone. */
function calDay(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-06-22', 'America/New_York');
}

/** All booking client names across every column. */
function gridClients(array $grid): Collection
{
    return collect($grid['columns'])->flatMap(fn ($c) => collect($c['bookings'])->pluck('client'));
}

it('builds a master day grid with one column per stylist and their bookings', function () {
    $salon = bookingSalon();
    $stylistA = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $stylistB = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $owner = salonOwnerOf($salon);
    makeBooking($salon, $owner, $stylistA, serviceFor($salon, $stylistA, 60), '2026-06-22 10:00', 'Alice Anderson');
    makeBooking($salon, $owner, $stylistB, serviceFor($salon, $stylistB, 60), '2026-06-22 11:00', 'Bob Brown');

    $grid = app(CalendarData::class)->day($salon, calDay(), null);

    expect($grid['columns'])->toHaveCount(2);
    expect(gridClients($grid))->toContain('Alice Anderson')->toContain('Bob Brown');

    // Each booking lands in its own stylist's column.
    $colA = collect($grid['columns'])->firstWhere('stylistId', $stylistA->id);
    $colB = collect($grid['columns'])->firstWhere('stylistId', $stylistB->id);
    expect(collect($colA['bookings'])->pluck('client'))->toContain('Alice Anderson')->not->toContain('Bob Brown');
    expect(collect($colB['bookings'])->pluck('client'))->toContain('Bob Brown')->not->toContain('Alice Anderson');
});

it('scopes a stylist grid to a single column of their own bookings', function () {
    $salon = bookingSalon();
    $stylistA = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $stylistB = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $owner = salonOwnerOf($salon);
    makeBooking($salon, $owner, $stylistA, serviceFor($salon, $stylistA, 60), '2026-06-22 10:00', 'Alice Anderson');
    makeBooking($salon, $owner, $stylistB, serviceFor($salon, $stylistB, 60), '2026-06-22 11:00', 'Bob Brown');

    $grid = app(CalendarData::class)->day($salon, calDay(), $stylistA->id);

    expect($grid['columns'])->toHaveCount(1);
    expect($grid['columns'][0]['stylistId'])->toBe($stylistA->id);
    expect(collect($grid['columns'][0]['bookings'])->pluck('client'))
        ->toContain('Alice Anderson')
        ->not->toContain('Bob Brown');
});

it('never leaks another salon\'s bookings into the grid', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    makeBooking($salon, salonOwnerOf($salon), $stylist, serviceFor($salon, $stylist, 60), '2026-06-22 10:00', 'Mine Client');

    $other = bookingSalon();
    $otherStylist = stylistWithHours($other, 0, 9 * 60, 17 * 60);
    makeBooking($other, salonOwnerOf($other), $otherStylist, serviceFor($other, $otherStylist, 60), '2026-06-22 10:00', 'Other Salon Client');

    $grid = app(CalendarData::class)->day($salon, calDay(), null);

    expect(gridClients($grid))->toContain('Mine Client')->not->toContain('Other Salon Client');
});

it('reflects working hours, breaks and time off in the grid', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);

    Availability::factory()->for($salon)->break()->create(['user_id' => $stylist->id, 'weekday' => 0]); // 12:00–13:00
    TimeOff::factory()->for($salon)->create([
        'user_id' => $stylist->id,
        'starts_at' => CarbonImmutable::parse('2026-06-22 15:00', 'America/New_York'),
        'ends_at' => CarbonImmutable::parse('2026-06-22 16:00', 'America/New_York'),
    ]);

    $grid = app(CalendarData::class)->day($salon, calDay(), $stylist->id);

    // Envelope framed to the 9–17 work window.
    expect($grid['hourStart'])->toBe(9);
    expect($grid['hourEnd'])->toBe(17);

    $column = $grid['columns'][0];
    expect(collect($column['blocked'])->pluck('label'))->toContain('Time off')->toContain('Break');

    // Slot bookability reflects work / break / time off.
    $slots = collect($column['slots']);
    expect($slots->firstWhere('min', 10 * 60)['bookable'])->toBeTrue();   // 10:00 working
    expect($slots->firstWhere('min', 12 * 60)['bookable'])->toBeFalse();  // 12:00 break
    expect($slots->firstWhere('min', 15 * 60)['bookable'])->toBeFalse();  // 15:00 time off
});

it('builds a week grid of seven day columns', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    makeBooking($salon, salonOwnerOf($salon), $stylist, serviceFor($salon, $stylist, 60), '2026-06-22 10:00', 'Weekly Wendy');

    $grid = app(CalendarData::class)->week($salon, calDay(), null);

    expect($grid['view'])->toBe('week');
    expect($grid['columns'])->toHaveCount(7);
    expect(gridClients($grid))->toContain('Weekly Wendy');
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
        ->assertSee('Today'); // the toolbar

    $this->actingAs($stylist)
        ->get(route('salon.calendar', $salon))
        ->assertOk()
        ->assertSee('My calendar');
});

it('shows current state on poll refresh (near-real-time)', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);
    $owner = salonOwnerOf($salon);

    $component = Livewire::actingAs($owner)->test('pages::salon.calendar', ['salon' => $salon]);

    // A booking appears after the initial render (today, within working hours)...
    makeBooking($salon, $owner, $stylist, $service, '2026-06-22 13:00', 'Polly Poll');

    // ...and the next poll renders it in the grid.
    $component->call('refresh')->assertSee('Polly Poll');
});

it('opens the prefilled booking form on click-to-book', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $owner = salonOwnerOf($salon);
    $iso = CarbonImmutable::parse('2026-06-22 14:00', 'America/New_York')->utc()->toIso8601ZuluString();

    // No stylist column hint → date + time only.
    Livewire::actingAs($owner)
        ->test('pages::salon.calendar', ['salon' => $salon])
        ->call('selectSlot', $iso)
        ->assertRedirect(route('salon.bookings.create', ['salon' => $salon, 'date' => '2026-06-22', 'time' => '14:00']));

    // Clicking inside a stylist's column prefills that stylist too.
    Livewire::actingAs($owner)
        ->test('pages::salon.calendar', ['salon' => $salon])
        ->call('selectSlot', $iso, $stylist->id)
        ->assertRedirect(route('salon.bookings.create', ['salon' => $salon, 'date' => '2026-06-22', 'time' => '14:00', 'stylist' => $stylist->id]));
});

it('clears a prefilled time that is already taken, and still rejects a genuine race server-side', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);
    $owner = salonOwnerOf($salon);
    $client = makeBooking($salon, $owner, $stylist, $service, '2026-06-22 10:00', 'First Client')->client;

    // The calendar prefills 10:00 with that stylist (already taken).
    $component = Livewire::actingAs($owner)
        ->withQueryParams(['date' => '2026-06-22', 'time' => '10:00', 'stylist' => (string) $stylist->id])
        ->test('pages::salon.bookings.create', ['salon' => $salon])
        ->assertSet('items.0.time', '10:00')
        ->assertSet('items.0.stylist_id', (string) $stylist->id)
        ->assertSet('date', '2026-06-22');

    // Completing the line clears the stale time — 10:00 is not a real slot,
    // so the UI never lets it be submitted blindly.
    $component->set('items.0.service_id', (string) $service->id)
        ->assertSet('items.0.time', '');

    // Pick a genuinely free slot… then lose the race to another booker.
    $component
        ->set('clientMode', 'existing')
        ->set('clientId', $client->id)
        ->call('pickTime', 0, '11:00')
        ->assertSet('items.0.time', '11:00');

    makeBooking($salon, $owner, $stylist, $service, '2026-06-22 11:00', 'Race Winner');

    // The slot engine (source of truth) rejects the conflict on save.
    $component->call('save')->assertHasErrors('start');

    expect($salon->bookings()->count())->toBe(2); // first client + race winner only
});

it('renders the booking-detail header cleanly at every status and a long name (close × clear of the status pill)', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $owner = salonOwnerOf($salon);
    $longName = 'Alexandra Featherstonehaugh-Worthington';
    $booking = makeBooking($salon, $owner, $stylist, serviceFor($salon, $stylist, 60), '2026-06-22 10:00', $longName);

    $statuses = [
        BookingStatus::Booked,
        BookingStatus::Confirmed,
        BookingStatus::Arrived,
        BookingStatus::InService,
        BookingStatus::Completed,
        BookingStatus::NoShow,
        BookingStatus::Cancelled,
    ];

    foreach ($statuses as $status) {
        $booking->update(['status' => $status]);

        $html = Livewire::actingAs($owner)
            ->test('pages::salon.calendar', ['salon' => $salon])
            ->call('openBooking', $booking->id)
            ->assertSet('showDetail', true)
            ->html();

        expect($html)->toContain($longName);          // title renders for a long name
        expect($html)->toContain($status->label());   // the status pill renders ("In service", "No-show"…)
        // The shared x-ui.modal header reserves room for the corner × (pe-12,
        // wider than Flux's close button), with the status pill on its own row
        // below the title — so they cannot collide at any name/status length.
        expect($html)->toContain('pe-12');
    }
});
