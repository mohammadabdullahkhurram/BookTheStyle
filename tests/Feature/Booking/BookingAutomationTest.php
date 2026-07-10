<?php

use App\Actions\Bookings\TransitionBookingStatus;
use App\Enums\BookingStatus;
use App\Jobs\SyncBookingToGhl;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Client;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| Per-salon booking automation: auto-no-show is OPT-IN with a grace period;
| auto-complete is ON by default but toggleable. The scheduled command reads
| each salon's settings. Frozen clock: Mon 2026-06-22 12:00 UTC (08:00 EDT) —
| all end-time comparisons are UTC instants, so grace math is DST-safe.
*/

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC'));
    Bus::fake([SyncBookingToGhl::class]);
});
afterEach(fn () => Carbon::setTestNow());

/** A booked appointment whose single item ends at the given UTC instant. */
function automationBooking(Salon $salon, User $stylist, string $endsAtUtc, BookingStatus $status = BookingStatus::Booked): Booking
{
    $end = CarbonImmutable::parse($endsAtUtc, 'UTC');
    $client = Client::factory()->for($salon)->create();
    $booking = Booking::factory()->for($salon)->for($client)->create(['status' => $status]);
    BookingItem::factory()->create([
        'salon_id' => $salon->id,
        'booking_id' => $booking->id,
        'service_id' => Service::factory()->for($salon)->create()->id,
        'stylist_id' => $stylist->id,
        'starts_at' => $end->subMinutes(30),
        'ends_at' => $end,
    ]);

    return $booking;
}

// ---------------------------------------------------------------------------
// Defaults / backfill
// ---------------------------------------------------------------------------

it('defaults every salon to auto-no-show OFF, 15 min grace, auto-complete ON', function () {
    // A row inserted WITHOUT the automation columns carries the migration's
    // DB defaults — exactly what every pre-existing salon was backfilled
    // with when the columns were added.
    $template = Salon::factory()->create();
    $id = DB::table('salons')->insertGetId(
        collect(DB::table('salons')->where('id', $template->id)->first())
            ->except(['id', 'slug', 'auto_no_show', 'auto_no_show_grace_minutes', 'auto_complete'])
            ->put('slug', 'backfill-probe')
            ->all()
    );
    $salon = Salon::query()->findOrFail($id);

    expect($salon->auto_no_show)->toBeFalse();
    expect($salon->auto_no_show_grace_minutes)->toBe(15);
    expect($salon->auto_complete)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Auto-no-show OFF (the default)
// ---------------------------------------------------------------------------

it('never auto-no-shows when the salon toggle is off, and manual no-show still works', function () {
    $salon = bookingSalon(); // defaults: auto_no_show OFF
    $stylist = stylistOf($salon);
    $owner = salonOwnerOf($salon);

    // Ended two hours ago — would have flipped under the old always-on rule.
    $elapsed = automationBooking($salon, $stylist, '2026-06-22 10:00');

    $this->artisan('bookings:close-elapsed')
        ->expectsOutputToContain('Marked 0 booking(s) as no-show.')
        ->assertSuccessful();

    expect($elapsed->fresh()->status)->toBe(BookingStatus::Booked);

    // The manual action is untouched by the automation settings.
    app(TransitionBookingStatus::class)->handle($owner, $salon, $elapsed->fresh(), BookingStatus::NoShow);
    expect($elapsed->fresh()->status)->toBe(BookingStatus::NoShow);
});

// ---------------------------------------------------------------------------
// Auto-no-show ON + grace period
// ---------------------------------------------------------------------------

it('with auto-no-show on, respects the grace period and skips non-booked statuses', function () {
    $salon = bookingSalon(['auto_no_show' => true, 'auto_no_show_grace_minutes' => 30, 'auto_complete' => false]);
    $stylist = stylistOf($salon);

    $withinGrace = automationBooking($salon, $stylist, '2026-06-22 11:45'); // ended 15 min ago < 30 grace
    $pastGrace = automationBooking($salon, $stylist, '2026-06-22 11:15');   // ended 45 min ago > 30 grace
    $checkedIn = automationBooking($salon, $stylist, '2026-06-22 10:00', BookingStatus::Arrived);
    $cancelled = automationBooking($salon, $stylist, '2026-06-22 10:00', BookingStatus::Cancelled);
    $future = automationBooking($salon, $stylist, '2026-06-22 15:00');

    $this->artisan('bookings:close-elapsed')
        ->expectsOutputToContain('Marked 1 booking(s) as no-show.')
        ->assertSuccessful();

    expect($withinGrace->fresh()->status)->toBe(BookingStatus::Booked);   // grace protects it
    expect($pastGrace->fresh()->status)->toBe(BookingStatus::NoShow);
    expect($checkedIn->fresh()->status)->toBe(BookingStatus::Arrived);    // auto-complete off here
    expect($cancelled->fresh()->status)->toBe(BookingStatus::Cancelled);
    expect($future->fresh()->status)->toBe(BookingStatus::Booked);

    // Only the flipped booking was pushed to GHL, with a system event.
    Bus::assertDispatchedTimes(SyncBookingToGhl::class, 1);
    $event = $pastGrace->statusEvents()->latest('id')->first();
    expect($event->to_status)->toBe(BookingStatus::NoShow);
    expect($event->actor_user_id)->toBeNull();

    // Idempotent — the same run five minutes later flips only what newly
    // crossed the grace line (nothing here: withinGrace still inside it).
    $this->artisan('bookings:close-elapsed')
        ->expectsOutputToContain('Marked 0 booking(s) as no-show.')
        ->assertSuccessful();
    expect($withinGrace->fresh()->status)->toBe(BookingStatus::Booked);
});

it('settings are per salon: an opted-in salon flips while a default salon is untouched', function () {
    $optedIn = bookingSalon(['auto_no_show' => true, 'auto_no_show_grace_minutes' => 0]);
    $default = bookingSalon();

    $flipped = automationBooking($optedIn, stylistOf($optedIn), '2026-06-22 10:00');
    $kept = automationBooking($default, stylistOf($default), '2026-06-22 10:00');

    $this->artisan('bookings:close-elapsed')
        ->expectsOutputToContain('Marked 1 booking(s) as no-show.')
        ->assertSuccessful();

    expect($flipped->fresh()->status)->toBe(BookingStatus::NoShow);
    expect($kept->fresh()->status)->toBe(BookingStatus::Booked);
});

// ---------------------------------------------------------------------------
// Auto-complete toggle
// ---------------------------------------------------------------------------

it('auto-complete respects its per-salon toggle', function () {
    $on = bookingSalon(); // default: auto_complete ON
    $off = bookingSalon(['auto_complete' => false]);

    $completes = automationBooking($on, stylistOf($on), '2026-06-22 10:00', BookingStatus::Arrived);
    $stays = automationBooking($off, stylistOf($off), '2026-06-22 10:00', BookingStatus::Arrived);

    $this->artisan('bookings:close-elapsed')
        ->expectsOutputToContain('Completed 1 checked-in booking(s).')
        ->assertSuccessful();

    expect($completes->fresh()->status)->toBe(BookingStatus::Completed);
    expect($stays->fresh()->status)->toBe(BookingStatus::Arrived);

    // Auto-complete never pushes to GHL (arrived and completed both map to
    // "showed"), and no auto-no-show ran (both salons have it off).
    Bus::assertNotDispatched(SyncBookingToGhl::class);
});

// ---------------------------------------------------------------------------
// Settings UI round-trip
// ---------------------------------------------------------------------------

it('lets an owner save booking automation from salon settings', function () {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);

    Livewire::actingAs($owner)
        ->test('pages::salon.settings', ['salon' => $salon])
        ->assertSet('auto_no_show', false) // seeded from the salon's defaults
        ->assertSet('auto_no_show_grace_minutes', 15)
        ->assertSet('auto_complete', true)
        ->set('auto_no_show', true)
        ->set('auto_no_show_grace_minutes', 30)
        ->set('auto_complete', false)
        ->call('savePolicy')
        ->assertHasNoErrors();

    $salon->refresh();
    expect($salon->auto_no_show)->toBeTrue();
    expect($salon->auto_no_show_grace_minutes)->toBe(30);
    expect($salon->auto_complete)->toBeFalse();
});

it('rejects an out-of-range grace period', function () {
    $salon = bookingSalon();

    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.settings', ['salon' => $salon])
        ->set('auto_no_show', true)
        ->set('auto_no_show_grace_minutes', 5000)
        ->call('savePolicy')
        ->assertHasErrors(['auto_no_show_grace_minutes']);

    expect($salon->refresh()->auto_no_show)->toBeFalse(); // nothing saved
});
