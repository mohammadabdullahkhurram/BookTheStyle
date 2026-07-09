<?php

use App\Actions\Bookings\TransitionBookingStatus;
use App\Enums\BookingStatus;
use App\Jobs\SyncBookingToGhl;
use App\Models\Booking;
use App\Models\BookingGhlAppointment;
use App\Models\BookingItem;
use App\Models\Client;
use App\Models\Salon;
use App\Models\SalonGhlConnection;
use App\Models\Service;
use App\Models\StylistProfile;
use App\Models\User;
use App\Services\Ghl\GhlApiException;
use App\Services\Ghl\GhlBookingPusher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
| Phase 6b: queued push of app bookings to GHL — one appointment PER DISTINCT
| STYLIST on that stylist's mapped provider, at that stylist's own item
| times. The booking is the source of truth and always succeeds; the mirror
| happens after commit via the queue (sync driver in tests, so dispatches
| run inline unless faked). All GHL HTTP is faked.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC')); // Mon 08:00 EDT
});
afterEach(fn () => Carbon::setTestNow());

/** A booking-ready salon with a complete GHL connection. */
function ghlBookingSalon(): Salon
{
    $salon = bookingSalon();
    SalonGhlConnection::factory()->for($salon)->create([
        'location_id' => 'loc_1',
        'private_integration_token' => 'pit-secret',
        'calendar_id' => 'cal_master',
    ]);

    return $salon;
}

function mapProvider(Salon $salon, User $stylist, string $providerId = 'prov_1'): void
{
    StylistProfile::updateOrCreate(
        ['salon_id' => $salon->id, 'user_id' => $stylist->id],
        ['ghl_user_id' => $providerId],
    );
}

/** A factory-built booking (skips the create action) with no items yet. */
function bareBooking(Salon $salon, array $attrs = []): Booking
{
    $client = Client::factory()->for($salon)->create(['name' => 'Casey Client', 'email' => 'casey@example.com']);

    return Booking::factory()->for($salon)->for($client)->create(array_merge([
        'status' => BookingStatus::Booked,
    ], $attrs));
}

function addItem(Booking $booking, User $stylist, CarbonImmutable $start, int $minutes = 45, string $service = 'Cut & Style'): BookingItem
{
    return BookingItem::factory()->create([
        'salon_id' => $booking->salon_id,
        'booking_id' => $booking->id,
        'service_id' => Service::factory()->for($booking->salon)->create(['name' => $service])->id,
        'stylist_id' => $stylist->id,
        'starts_at' => $start,
        'ends_at' => $start->addMinutes($minutes),
    ]);
}

function sliceFor(Booking $booking, User $stylist): ?BookingGhlAppointment
{
    return BookingGhlAppointment::query()
        ->where('booking_id', $booking->id)
        ->where('stylist_id', $stylist->id)
        ->first();
}

function fakeContactAndAppointments(array $appointmentIds): void
{
    $sequence = Http::sequence();
    foreach ($appointmentIds as $id) {
        $sequence->push(['id' => $id]);
    }

    Http::fake([
        'services.leadconnectorhq.com/contacts/upsert' => Http::response(['contact' => ['id' => 'ghl_c1']]),
        'services.leadconnectorhq.com/calendars/events/appointments*' => $sequence,
    ]);
}

// ---------------------------------------------------------------------------
// Trigger points
// ---------------------------------------------------------------------------

it('queues a sync job on creation and again on cancellation', function () {
    Queue::fake();
    $salon = ghlBookingSalon();
    $owner = salonOwnerOf($salon);
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    mapProvider($salon, $stylist);

    $booking = makeBooking($salon, $owner, $stylist, serviceFor($salon, $stylist, 60));

    Queue::assertPushed(SyncBookingToGhl::class, fn (SyncBookingToGhl $job) => $job->bookingId === $booking->id);

    app(TransitionBookingStatus::class)->handle($owner, $salon, $booking, BookingStatus::Cancelled);

    Queue::assertPushed(SyncBookingToGhl::class, 2);
});

// ---------------------------------------------------------------------------
// Per-stylist appointments
// ---------------------------------------------------------------------------

it('creates one appointment per stylist, back-to-back, on the right providers', function () {
    fakeContactAndAppointments(['ghl_a1', 'ghl_a2']);
    $salon = ghlBookingSalon(); // America/New_York
    $anna = stylistOf($salon);
    $ben = stylistOf($salon);
    mapProvider($salon, $anna, 'prov_anna');
    mapProvider($salon, $ben, 'prov_ben');

    $booking = bareBooking($salon);
    addItem($booking, $anna, CarbonImmutable::parse('2026-06-23 11:30', $salon->timezone), 45, 'Dying');
    addItem($booking, $ben, CarbonImmutable::parse('2026-06-23 12:15', $salon->timezone), 45, 'Nails');

    app(GhlBookingPusher::class)->push($booking);

    expect(sliceFor($booking, $anna)->ghl_appointment_id)->toBe('ghl_a1');
    expect(sliceFor($booking, $ben)->ghl_appointment_id)->toBe('ghl_a2');
    expect(sliceFor($booking, $anna)->sync_status)->toBe(GhlBookingPusher::STATUS_SYNCED);
    expect(sliceFor($booking, $ben)->sync_status)->toBe(GhlBookingPusher::STATUS_SYNCED);

    Http::assertSent(fn ($r): bool => $r->method() === 'POST'
        && str_contains($r->url(), '/calendars/events/appointments')
        && $r['assignedUserId'] === 'prov_anna'
        && $r['startTime'] === '2026-06-23T11:30:00-04:00'
        && $r['endTime'] === '2026-06-23T12:15:00-04:00');
    Http::assertSent(fn ($r): bool => $r->method() === 'POST'
        && str_contains($r->url(), '/calendars/events/appointments')
        && $r['assignedUserId'] === 'prov_ben'
        && $r['startTime'] === '2026-06-23T12:15:00-04:00'
        && $r['endTime'] === '2026-06-23T13:00:00-04:00');

    // The contact is upserted once and shared by both appointments.
    Http::assertSentCount(3);
});

it('creates same-time appointments for different stylists', function () {
    fakeContactAndAppointments(['ghl_a1', 'ghl_a2']);
    $salon = ghlBookingSalon();
    $anna = stylistOf($salon);
    $ben = stylistOf($salon);
    mapProvider($salon, $anna, 'prov_anna');
    mapProvider($salon, $ben, 'prov_ben');

    $booking = bareBooking($salon);
    $start = CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone);
    addItem($booking, $anna, $start, 60, 'Color');
    addItem($booking, $ben, $start, 60, 'Manicure');

    app(GhlBookingPusher::class)->push($booking);

    foreach (['prov_anna', 'prov_ben'] as $provider) {
        Http::assertSent(fn ($r): bool => $r->method() === 'POST'
            && str_contains($r->url(), '/calendars/events/appointments')
            && $r['assignedUserId'] === $provider
            && $r['startTime'] === '2026-06-23T10:00:00-04:00');
    }

    expect(BookingGhlAppointment::where('booking_id', $booking->id)->count())->toBe(2);
});

it('keeps one spanning appointment for two services by the same stylist', function () {
    fakeContactAndAppointments(['ghl_a1']);
    $salon = ghlBookingSalon();
    $anna = stylistOf($salon);
    mapProvider($salon, $anna, 'prov_anna');

    $booking = bareBooking($salon);
    addItem($booking, $anna, CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone), 60, 'Color');
    addItem($booking, $anna, CarbonImmutable::parse('2026-06-23 11:00', $salon->timezone), 30, 'Blow-dry');

    app(GhlBookingPusher::class)->push($booking);

    Http::assertSent(fn ($r): bool => $r->method() === 'POST'
        && str_contains($r->url(), '/calendars/events/appointments')
        && $r['startTime'] === '2026-06-23T10:00:00-04:00'
        && $r['endTime'] === '2026-06-23T11:30:00-04:00'
        && str_contains($r['title'], 'Color')
        && str_contains($r['title'], 'Blow-dry'));
    Http::assertSentCount(2); // one upsert + ONE appointment
    expect(BookingGhlAppointment::where('booking_id', $booking->id)->count())->toBe(1);
});

it('skips an unmapped stylist slice without touching the mapped one', function () {
    fakeContactAndAppointments(['ghl_a1']);
    $salon = ghlBookingSalon();
    $anna = stylistOf($salon);
    $ben = stylistOf($salon); // never mapped
    mapProvider($salon, $anna, 'prov_anna');

    $booking = bareBooking($salon);
    addItem($booking, $anna, CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone));
    addItem($booking, $ben, CarbonImmutable::parse('2026-06-23 11:00', $salon->timezone));

    app(GhlBookingPusher::class)->push($booking);

    expect(sliceFor($booking, $anna)->sync_status)->toBe(GhlBookingPusher::STATUS_SYNCED);
    expect(sliceFor($booking, $anna)->ghl_appointment_id)->toBe('ghl_a1');
    expect(sliceFor($booking, $ben)->sync_status)->toBe(GhlBookingPusher::STATUS_SKIPPED);
    expect(sliceFor($booking, $ben)->ghl_appointment_id)->toBeNull();
    expect($booking->fresh()->status)->toBe(BookingStatus::Booked);
});

// ---------------------------------------------------------------------------
// Reschedule / edit diffs per stylist
// ---------------------------------------------------------------------------

it('updates only the changed stylist on reschedule, never duplicating', function () {
    fakeContactAndAppointments(['ghl_a1', 'ghl_a2', 'updated']);
    $salon = ghlBookingSalon();
    $anna = stylistOf($salon);
    $ben = stylistOf($salon);
    mapProvider($salon, $anna, 'prov_anna');
    mapProvider($salon, $ben, 'prov_ben');

    $booking = bareBooking($salon);
    addItem($booking, $anna, CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone));
    $benItem = addItem($booking, $ben, CarbonImmutable::parse('2026-06-23 11:00', $salon->timezone));

    $pusher = app(GhlBookingPusher::class);
    $pusher->push($booking); // upsert + 2 creates = 3 requests

    // Reschedule only Ben's service an hour later, then re-push.
    $benItem->update([
        'starts_at' => $benItem->starts_at->addHour(),
        'ends_at' => $benItem->ends_at->addHour(),
    ]);
    $pusher->push($booking->fresh());

    // Exactly ONE extra request: a PUT to Ben's appointment. Anna untouched.
    Http::assertSentCount(4);
    Http::assertSent(fn ($r): bool => $r->method() === 'PUT'
        && str_ends_with($r->url(), '/calendars/events/appointments/ghl_a2')
        && $r['startTime'] === '2026-06-23T12:00:00-04:00');
    expect(sliceFor($booking, $anna)->ghl_appointment_id)->toBe('ghl_a1');
    expect(sliceFor($booking, $ben)->ghl_appointment_id)->toBe('ghl_a2');
    expect(BookingGhlAppointment::where('booking_id', $booking->id)->count())->toBe(2);
});

it('cancels only the removed stylist\'s appointment and adds a new stylist\'s', function () {
    // Sequence: create a1, create a2, the cancel PUT's response, create a3.
    fakeContactAndAppointments(['ghl_a1', 'ghl_a2', 'ghl_a2', 'ghl_a3']);
    $salon = ghlBookingSalon();
    $anna = stylistOf($salon);
    $ben = stylistOf($salon);
    $cara = stylistOf($salon);
    mapProvider($salon, $anna, 'prov_anna');
    mapProvider($salon, $ben, 'prov_ben');
    mapProvider($salon, $cara, 'prov_cara');

    $booking = bareBooking($salon);
    addItem($booking, $anna, CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone));
    $benItem = addItem($booking, $ben, CarbonImmutable::parse('2026-06-23 11:00', $salon->timezone));

    $pusher = app(GhlBookingPusher::class);
    $pusher->push($booking); // 3 requests

    // Ben is swapped out for Cara at the same time.
    $benItem->delete();
    addItem($booking, $cara, CarbonImmutable::parse('2026-06-23 11:00', $salon->timezone));
    $pusher->push($booking->fresh());

    // + cancel PUT on ghl_a2 and a POST for Cara — Anna untouched by hash.
    Http::assertSentCount(5);
    Http::assertSent(fn ($r): bool => $r->method() === 'PUT'
        && str_ends_with($r->url(), '/calendars/events/appointments/ghl_a2')
        && $r['appointmentStatus'] === 'cancelled');
    Http::assertSent(fn ($r): bool => $r->method() === 'POST'
        && str_contains($r->url(), '/calendars/events/appointments')
        && $r['assignedUserId'] === 'prov_cara');

    expect(sliceFor($booking, $ben))->toBeNull();          // row dropped
    expect(sliceFor($booking, $cara)->ghl_appointment_id)->toBe('ghl_a3');
    expect(sliceFor($booking, $anna)->ghl_appointment_id)->toBe('ghl_a1');
});

// ---------------------------------------------------------------------------
// Cancel
// ---------------------------------------------------------------------------

it('cancels every stylist\'s GHL appointment when the booking is cancelled', function () {
    fakeContactAndAppointments(['ghl_a1', 'ghl_a2', 'x', 'y']);
    $salon = ghlBookingSalon();
    $anna = stylistOf($salon);
    $ben = stylistOf($salon);
    mapProvider($salon, $anna, 'prov_anna');
    mapProvider($salon, $ben, 'prov_ben');

    $booking = bareBooking($salon);
    addItem($booking, $anna, CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone));
    addItem($booking, $ben, CarbonImmutable::parse('2026-06-23 11:00', $salon->timezone));

    $pusher = app(GhlBookingPusher::class);
    $pusher->push($booking);

    $booking->update(['status' => BookingStatus::Cancelled]);
    $pusher->push($booking->fresh());

    foreach (['ghl_a1', 'ghl_a2'] as $id) {
        Http::assertSent(fn ($r): bool => $r->method() === 'PUT'
            && str_ends_with($r->url(), '/calendars/events/appointments/'.$id)
            && $r['appointmentStatus'] === 'cancelled');
    }
});

it('does nothing remote when a never-pushed booking is cancelled', function () {
    $salon = ghlBookingSalon();
    $anna = stylistOf($salon);
    mapProvider($salon, $anna);

    $booking = bareBooking($salon, ['status' => BookingStatus::Cancelled]);
    addItem($booking, $anna, CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone));

    app(GhlBookingPusher::class)->push($booking);

    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------
// Skips: booking always succeeds
// ---------------------------------------------------------------------------

it('books successfully with no push when the salon is not connected', function () {
    $salon = bookingSalon(); // no GHL connection at all
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);

    // Sync driver runs the job inline; preventStrayRequests proves no HTTP.
    $booking = makeBooking($salon, salonOwnerOf($salon), $stylist, serviceFor($salon, $stylist, 60));

    expect(sliceFor($booking, $stylist)->sync_status)->toBe(GhlBookingPusher::STATUS_SKIPPED);
    expect($booking->fresh()->status)->toBe(BookingStatus::Booked);
    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------
// Contact reuse + DST
// ---------------------------------------------------------------------------

it('reuses a stored contact id without upserting again', function () {
    Http::fake([
        'services.leadconnectorhq.com/calendars/events/appointments*' => Http::response(['id' => 'ghl_a2']),
    ]);
    $salon = ghlBookingSalon();
    $anna = stylistOf($salon);
    mapProvider($salon, $anna);

    $booking = bareBooking($salon);
    $booking->client->update(['ghl_contact_id' => 'ghl_c9']);
    addItem($booking, $anna, CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone));

    app(GhlBookingPusher::class)->push($booking);

    Http::assertNotSent(fn ($r): bool => str_contains($r->url(), '/contacts/upsert'));
    Http::assertSent(fn ($r): bool => str_contains($r->url(), '/calendars/events/appointments')
        && $r['contactId'] === 'ghl_c9');
});

it('sends wall-clock times with DST-correct offsets', function () {
    Http::fake([
        'services.leadconnectorhq.com/contacts/upsert' => Http::response(['contact' => ['id' => 'ghl_c1']]),
        'services.leadconnectorhq.com/calendars/events/appointments*' => Http::sequence()
            ->push(['id' => 'ghl_a_summer'])->push(['id' => 'ghl_a_winter']),
    ]);
    $salon = ghlBookingSalon(); // America/New_York
    $anna = stylistOf($salon);
    mapProvider($salon, $anna);

    $summer = bareBooking($salon);
    addItem($summer, $anna, CarbonImmutable::parse('2026-07-15 10:00', 'America/New_York'), 60);
    $winter = bareBooking($salon);
    addItem($winter, $anna, CarbonImmutable::parse('2026-12-15 10:00', 'America/New_York'), 60);

    $pusher = app(GhlBookingPusher::class);
    $pusher->push($summer);
    $pusher->push($winter);

    Http::assertSent(fn ($r): bool => str_contains($r->url(), 'appointments')
        && $r['startTime'] === '2026-07-15T10:00:00-04:00'); // EDT
    Http::assertSent(fn ($r): bool => str_contains($r->url(), 'appointments')
        && $r['startTime'] === '2026-12-15T10:00:00-05:00'); // EST
});

// ---------------------------------------------------------------------------
// Failure handling
// ---------------------------------------------------------------------------

it('retries through a 429 and still syncs', function () {
    Http::fake([
        'services.leadconnectorhq.com/contacts/upsert' => Http::sequence()
            ->push(['message' => 'rate limited'], 429)
            ->push(['contact' => ['id' => 'ghl_c1']]),
        'services.leadconnectorhq.com/calendars/events/appointments*' => Http::response(['id' => 'ghl_a1']),
    ]);
    $salon = ghlBookingSalon();
    $anna = stylistOf($salon);
    mapProvider($salon, $anna);

    $booking = bareBooking($salon);
    addItem($booking, $anna, CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone));

    app(GhlBookingPusher::class)->push($booking);

    expect(sliceFor($booking, $anna)->sync_status)->toBe(GhlBookingPusher::STATUS_SYNCED);
    Http::assertSentCount(3); // 429 + retried upsert + appointment
});

it('records a per-slice error after exhausted retries, booking intact', function () {
    Http::fake(['services.leadconnectorhq.com/*' => Http::response(['message' => 'no'], 401)]);
    $salon = ghlBookingSalon();
    $anna = stylistOf($salon);
    mapProvider($salon, $anna);

    $booking = bareBooking($salon);
    addItem($booking, $anna, CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone));

    $job = new SyncBookingToGhl($booking->id);

    try {
        $job->handle(app(GhlBookingPusher::class));
        $this->fail('Expected GhlApiException');
    } catch (GhlApiException $exception) {
        $job->failed($exception); // what the queue does after the final attempt
    }

    $slice = sliceFor($booking, $anna);
    expect($slice->sync_status)->toBe(GhlBookingPusher::STATUS_FAILED);
    expect($slice->sync_error)->toContain('GoHighLevel');
    expect($slice->sync_error)->not->toContain('pit-secret');
    expect($booking->fresh()->status)->toBe(BookingStatus::Booked); // untouched
    expect($slice->ghl_appointment_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// Backfill migration
// ---------------------------------------------------------------------------

it('backfills a legacy single appointment id onto the first stylist', function () {
    $salon = ghlBookingSalon();
    $anna = stylistOf($salon);
    $ben = stylistOf($salon);

    $booking = bareBooking($salon);
    addItem($booking, $ben, CarbonImmutable::parse('2026-06-23 11:00', $salon->timezone));  // later
    addItem($booking, $anna, CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone)); // FIRST by time

    // Rewind just the per-stylist migration, restore the legacy shape…
    $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();
    DB::table('bookings')->where('id', $booking->id)->update([
        'ghl_appointment_id' => 'legacy_a1',
        'ghl_sync_status' => 'synced',
    ]);

    // …then re-run it and confirm the backfill.
    $this->artisan('migrate')->assertSuccessful();

    $slice = sliceFor($booking, $anna);
    expect($slice)->not->toBeNull();
    expect($slice->ghl_appointment_id)->toBe('legacy_a1');
    expect($slice->sync_status)->toBe('synced');
    expect(sliceFor($booking, $ben))->toBeNull();
});
