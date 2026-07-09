<?php

use App\Actions\Bookings\RescheduleBooking;
use App\Actions\Bookings\TransitionBookingStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Client;
use App\Models\Salon;
use App\Models\SalonGhlConnection;
use App\Models\Service;
use App\Models\StylistProfile;
use App\Models\User;
use App\Services\Ghl\GhlStatusMap;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
| The salon status model: four actions (checked in / no show / cancel +
| reschedule), auto-confirm on create, the two-way GHL mapping, and the
| auto-no-show command. Frozen clock: Mon 2026-06-22 12:00 UTC (08:00 EDT).
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC'));
});
afterEach(fn () => Carbon::setTestNow());

function statusGhlSalon(): Salon
{
    $salon = bookingSalon();
    SalonGhlConnection::factory()->for($salon)->create([
        'location_id' => 'loc_1', 'private_integration_token' => 'pit-secret', 'calendar_id' => 'cal_master',
    ]);

    return $salon;
}

function elapsedBooking(Salon $salon, User $stylist, string $start, string $name = 'Elapsed Ella'): Booking
{
    $client = Client::factory()->for($salon)->create(['name' => $name]);
    $booking = Booking::factory()->for($salon)->for($client)->create(['status' => BookingStatus::Booked]);
    BookingItem::factory()->create([
        'salon_id' => $salon->id,
        'booking_id' => $booking->id,
        'service_id' => Service::factory()->for($salon)->create()->id,
        'stylist_id' => $stylist->id,
        'starts_at' => CarbonImmutable::parse($start, $salon->timezone),
        'ends_at' => CarbonImmutable::parse($start, $salon->timezone)->addMinutes(30),
    ]);

    return $booking;
}

function fakeGhlOnce(): void
{
    Http::fake([
        'services.leadconnectorhq.com/contacts/upsert' => Http::response(['contact' => ['id' => 'ghl_c1']]),
        'services.leadconnectorhq.com/calendars/events/appointments*' => Http::response(['id' => 'ghl_a1']),
    ]);
}

it('pushes every new booking to GHL as confirmed — no manual confirm step', function () {
    fakeGhlOnce();
    $salon = statusGhlSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    StylistProfile::updateOrCreate(['salon_id' => $salon->id, 'user_id' => $stylist->id], ['ghl_user_id' => 'prov_1']);

    makeBooking($salon, salonOwnerOf($salon), $stylist, serviceFor($salon, $stylist, 60), '2026-06-22 10:00');

    Http::assertSent(fn ($r): bool => $r->method() === 'POST'
        && str_contains($r->url(), '/calendars/events/appointments')
        && $r['appointmentStatus'] === 'confirmed');
});

it('maps each status action to the right GHL status: showed, noshow, cancelled', function () {
    fakeGhlOnce();
    $salon = statusGhlSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    StylistProfile::updateOrCreate(['salon_id' => $salon->id, 'user_id' => $stylist->id], ['ghl_user_id' => 'prov_1']);
    $service = serviceFor($salon, $stylist, 60);

    // Checked in → GHL showed.
    $a = makeBooking($salon, $owner, $stylist, $service, '2026-06-22 10:00');
    app(TransitionBookingStatus::class)->handle($owner, $salon, $a, BookingStatus::Arrived);
    Http::assertSent(fn ($r): bool => $r->method() === 'PUT'
        && str_ends_with($r->url(), '/calendars/events/appointments/ghl_a1')
        && $r['appointmentStatus'] === 'showed');

    // No show → GHL noshow.
    $b = makeBooking($salon, $owner, $stylist, $service, '2026-06-22 12:00');
    app(TransitionBookingStatus::class)->handle($owner, $salon, $b, BookingStatus::NoShow);
    Http::assertSent(fn ($r): bool => $r->method() === 'PUT' && $r['appointmentStatus'] === 'noshow');

    // Cancelled → GHL cancelled.
    $c = makeBooking($salon, $owner, $stylist, $service, '2026-06-22 14:00');
    app(TransitionBookingStatus::class)->handle($owner, $salon, $c, BookingStatus::Cancelled);
    Http::assertSent(fn ($r): bool => $r->method() === 'PUT' && $r['appointmentStatus'] === 'cancelled');
});

it('offers exactly the four actions on both tabs — never a confirmed button', function () {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    makeBooking($salon, $owner, $stylist, serviceFor($salon, $stylist, 60), '2026-06-22 10:00');

    $booking = $salon->bookings()->firstOrFail();

    foreach (['pages::salon.appointments.index', 'pages::salon.appointments.all'] as $page) {
        Livewire::actingAs($owner)
            ->test($page, ['salon' => $salon])
            ->assertSee('Checked in')
            ->assertSee('No-show')
            ->assertSee('Cancelled')
            ->assertSee('Reschedule')
            // No confirm action anywhere (the legacy label may still appear
            // in the status FILTER; the button wiring must not exist).
            ->assertDontSeeHtml("changeStatus({$booking->id}, 'confirmed')");
    }
});

it('never lets a booked status transition to the removed confirmed action', function () {
    expect(BookingStatus::Booked->allowedTransitions())
        ->toBe([BookingStatus::Arrived, BookingStatus::NoShow, BookingStatus::Cancelled]);
    expect(BookingStatus::Booked->canTransitionTo(BookingStatus::Confirmed))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Auto no-show
// ---------------------------------------------------------------------------

it('auto-flips only genuinely elapsed still-booked bookings, idempotently, and pushes noshow', function () {
    fakeGhlOnce();
    $salon = statusGhlSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 7 * 60, 23 * 60);
    StylistProfile::updateOrCreate(['salon_id' => $salon->id, 'user_id' => $stylist->id], ['ghl_user_id' => 'prov_1']);
    $service = serviceFor($salon, $stylist, 30);

    // Frozen now = 08:00 EDT. One elapsed booked, one checked in (elapsed),
    // one cancelled (elapsed), one still in the future.
    $elapsed = elapsedBooking($salon, $stylist, '2026-06-22 07:00', 'Elapsed Ella');
    $elapsed->update(['ghl_appointment_id' => 'ghl_a1']); // pushed earlier — the flip must PUT noshow
    $checkedIn = elapsedBooking($salon, $stylist, '2026-06-22 07:00', 'Present Pam');
    $checkedIn->update(['status' => BookingStatus::Arrived]);
    $cancelled = elapsedBooking($salon, $stylist, '2026-06-22 06:00', 'Gone Gina');
    $cancelled->update(['status' => BookingStatus::Cancelled]);
    $future = makeBooking($salon, $owner, $stylist, $service, '2026-06-22 15:00', 'Future Fay');

    $this->artisan('bookings:close-elapsed')
        ->expectsOutputToContain('Marked 1 booking(s) as no-show.')
        ->assertSuccessful();

    expect($elapsed->fresh()->status)->toBe(BookingStatus::NoShow);
    expect($checkedIn->fresh()->status)->toBe(BookingStatus::Completed); // elapsed check-in auto-completes
    expect($cancelled->fresh()->status)->toBe(BookingStatus::Cancelled); // untouched
    expect($future->fresh()->status)->toBe(BookingStatus::Booked);       // untouched

    // The flip pushed GHL noshow and left an attributed system event.
    Http::assertSent(fn ($r): bool => $r->method() === 'PUT' && $r['appointmentStatus'] === 'noshow');
    $event = $elapsed->statusEvents()->latest('id')->first();
    expect($event->to_status)->toBe(BookingStatus::NoShow);
    expect($event->actor_user_id)->toBeNull();
    expect($event->note)->toContain('Automatically marked as no-show');

    // Idempotent: a second run flips nothing.
    $this->artisan('bookings:close-elapsed')
        ->expectsOutputToContain('Marked 0 booking(s) as no-show.')
        ->assertSuccessful();
});

it('auto-completes elapsed checked-in bookings without any GHL push', function () {
    fakeGhlOnce();
    $salon = statusGhlSalon();
    $stylist = stylistOf($salon);
    StylistProfile::updateOrCreate(['salon_id' => $salon->id, 'user_id' => $stylist->id], ['ghl_user_id' => 'prov_1']);

    // Checked in earlier today, appointment over (frozen now = 08:00 EDT is
    // irrelevant here — use yesterday to be unambiguous), already in GHL as
    // showed from the check-in push.
    $done = elapsedBooking($salon, $stylist, '2026-06-21 10:00', 'Done Dana');
    $done->update(['status' => BookingStatus::Arrived, 'ghl_appointment_id' => 'ghl_a1', 'ghl_payload_hash' => 'h']);

    // A checked-in booking still in progress (future end) stays untouched.
    $inProgress = elapsedBooking($salon, $stylist, '2026-06-22 12:00', 'Present Pia');
    $inProgress->update(['status' => BookingStatus::Arrived]);

    $this->artisan('bookings:close-elapsed')
        ->expectsOutputToContain('Completed 1 checked-in booking(s).')
        ->assertSuccessful();

    expect($done->fresh()->status)->toBe(BookingStatus::Completed);
    expect($inProgress->fresh()->status)->toBe(BookingStatus::Arrived);

    // Completed maps to the SAME GHL status (showed) the check-in pushed —
    // the promotion makes no outbound call at all: no push, no loop.
    Http::assertNothingSent();

    $event = $done->statusEvents()->latest('id')->first();
    expect($event->to_status)->toBe(BookingStatus::Completed);
    expect($event->actor_user_id)->toBeNull();
    expect($event->note)->toContain('Automatically completed');

    // Idempotent: completed never matches again.
    $this->artisan('bookings:close-elapsed')
        ->expectsOutputToContain('Completed 0 checked-in booking(s).')
        ->assertSuccessful();
});

it('retires in-service: no transition reaches it and no button renders it', function () {
    foreach (BookingStatus::cases() as $case) {
        expect($case->canTransitionTo(BookingStatus::InService))->toBeFalse();
    }

    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $booking = makeBooking($salon, $owner, $stylist, serviceFor($salon, $stylist, 60), '2026-06-22 10:00');

    Livewire::actingAs($owner)
        ->test('pages::salon.appointments.index', ['salon' => $salon])
        ->assertDontSeeHtml("changeStatus({$booking->id}, 'in_service')");
});

it('maps outbound statuses exactly: showed for arrived AND completed, noshow, cancelled', function () {
    expect(GhlStatusMap::toGhl(BookingStatus::Arrived))->toBe('showed');
    expect(GhlStatusMap::toGhl(BookingStatus::Completed))->toBe('showed');
    expect(GhlStatusMap::toGhl(BookingStatus::InService))->toBe('showed'); // legacy, like arrived
    expect(GhlStatusMap::toGhl(BookingStatus::NoShow))->toBe('noshow');
    expect(GhlStatusMap::toGhl(BookingStatus::Cancelled))->toBe('cancelled');
    expect(GhlStatusMap::toGhl(BookingStatus::Booked))->toBe('confirmed');

    // Inbound: showed is CHECKED IN, never completed (the bug this pins).
    expect(GhlStatusMap::toApp('showed'))->toBe(BookingStatus::Arrived);
});

it('never auto-no-shows a booking rescheduled to the future', function () {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 7 * 60, 23 * 60);

    // Originally elapsed… then rescheduled to this afternoon.
    $booking = elapsedBooking($salon, $stylist, '2026-06-22 07:00');
    app(RescheduleBooking::class)->handle($owner, $salon, $booking->fresh(), '2026-06-22 16:00');

    $this->artisan('bookings:close-elapsed')->assertSuccessful();

    expect($booking->fresh()->status)->toBe(BookingStatus::Booked); // future end — untouched
});
